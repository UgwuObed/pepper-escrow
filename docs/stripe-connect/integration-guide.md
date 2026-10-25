# Conditional settlement on Stripe Connect

How the Stripe side of Peppa is put together, for whoever picks this up next.

## The shape of it

A buyer pays a seller. The money is held. When a condition is met, it goes out.

The thing to understand first is that **Peppa never holds the money**. The payment is
created on the *seller's* own Stripe account, so the funds land in the seller's balance.
Peppa's control comes from that account being on manual payouts: Stripe will not pay the
seller's bank until we create a payout.

So Peppa holds the release switch, not the money.

```
buyer  ──pays──▶  seller's Stripe account  ──held──▶  rules engine decides  ──payout──▶  seller's bank
                  (payouts set to manual)             (our code)
```

Stripe has no conditional release of any kind. There is no setting for "pay out when the
buyer confirms". That decision is entirely ours and lives in
[`SettlementRulesEngine`](../../app/Services/SettlementRulesEngine.php).

### Why this shape and not the other one

The obvious alternative is *separate charges and transfers*: the buyer pays Peppa, the
money sits in Peppa's balance, and Peppa transfers it on. That was the first design and it
was abandoned, for one reason that matters:

Under that model Peppa carries every chargeback. If a buyer disputes after the seller has
been paid and withdrawn, Peppa is out of pocket with no way to recover. Under direct
charges the money never leaves the seller's account, so there is nothing to claw back.

## The account configuration, and why it is not negotiable

Seller accounts are created with **controller properties**, not a legacy account type:

```php
'controller' => [
    'losses' => ['payments' => 'stripe'],
    'fees' => ['payer' => 'account'],
    'requirement_collection' => 'stripe',
    'stripe_dashboard' => ['type' => 'none'],
],
```

Each line is load-bearing:

| Property | Value | Why |
|---|---|---|
| `losses.payments` | `stripe` | Stripe covers unrecoverable negative balances, not Peppa |
| `stripe_dashboard.type` | `none` | The seller has no Stripe UI, so no way to pay themselves out early |
| `requirement_collection` | `stripe` | Stripe runs KYC; seller identity data never touches Peppa |
| `fees.payer` | `account` | The seller pays Stripe's processing fees |

**Do not change `stripe_dashboard.type` to `full`.** A full dashboard is a Standard account,
and Stripe documents that such an account can still make its own manual payouts *after the
platform restricts its payout schedule*. The seller could release the money themselves and
the hold would be decorative. Closing that requires a bespoke arrangement with Stripe
support.

**`express` is not an option either.** Stripe rejects it outright with this combination:

> When `stripe_dashboard[type]=express`, your platform must collect fees and be liable for
> negative balances or refunds and chargebacks.

That restriction is not in Stripe's published list of unsupported combinations. It was found
by calling the API. If you are tempted to revisit this, verify against the sandbox rather
than the docs.

Dashboard type **cannot be changed after the account is created**. A seller cannot bring an
existing Stripe account either, because any account they already own has a full dashboard.
Every seller needs an account created through Peppa.

## What Peppa cannot do

Because `requirement_collection=stripe`, Stripe refuses these from the platform:

- Setting the seller's bank account
- Accepting the Terms of Service on their behalf
- Filling any `individual.*` field (name, date of birth, address, phone)

This is deliberate and good — seller PII and bank details never pass through Peppa, which
removes a large compliance surface. The practical consequence is that **onboarding cannot
be automated or completed on a seller's behalf, including in test mode**. A seller always
clicks through Stripe's hosted flow.

Expect onboarding drop-off to be entirely outside your control. Budget for chase-ups.

## Holding periods are a hard limit

Stripe caps how long funds may sit in a connected account on manual payouts, by the
**seller's** country:

| Country | Limit |
|---|---|
| Thailand | 10 days |
| United States | 2 years |
| Everywhere else | 90 days |

Overrunning is a compliance breach, not a soft deadline. The engine force-releases two days
before the limit regardless of whether any condition fired
(`SettlementRulesEngine::EXPIRY_SAFETY_DAYS`).

A dispute freezes the release but **does not pause Stripe's clock**. A disputed hold
approaching its deadline raises an expiry warning for a human, because the engine will not
release it automatically.

## The pieces

| File | Does |
|---|---|
| [`StripeConnectGateway`](../../app/PaymentGateways/StripeConnectGateway.php) | Everything that talks to Stripe |
| [`ConnectedAccountService`](../../app/Services/ConnectedAccountService.php) | Seller onboarding, status sync |
| [`SettlementRulesEngine`](../../app/Services/SettlementRulesEngine.php) | Decides when money is released |
| [`SettlementReconciliationService`](../../app/Services/SettlementReconciliationService.php) | Our records vs Stripe's |
| [`SellerDashboardService`](../../app/Services/SellerDashboardService.php) | What a seller sees |
| `ProcessSettlementHolds` | Hourly sweep; fires timers, force-releases at the limit |

### Tables

- `connected_accounts` — sellers, mirrored Stripe state, holding period
- `settlement_rules` — per merchant, what releases a hold
- `settlement_holds` — a payment waiting on a condition
- `hold_events` — append-only, why every release happened

## Lifecycle

1. **Onboard** — `ConnectedAccountService::onboard()` creates the account and arms manual
   payouts in the same breath. An account that takes a payment before being armed pays it
   straight out on Stripe's rolling schedule and there is no pulling it back.
2. **Check** — `ConnectedAccount::canHoldFunds()` must be true before any charge. It checks
   charges enabled, payouts manual, losses on Stripe, no dashboard.
3. **Charge** — a direct charge on the seller's account, Peppa's cut as
   `application_fee_amount`.
4. **Hold** — `SettlementRulesEngine::hold()` records it and computes the deadline from the
   seller's country.
5. **Release** — a condition fires, or the sweep force-releases near the deadline.

## Things that will bite you

**Amounts.** `toMinorUnits()` handles zero-decimal currencies. JPY and friends take whole
units; multiplying those by 100 overcharges a hundredfold. Not relevant for GBP/USD today,
relevant the day someone adds a currency.

**`Balance::retrieve()` is a singleton resource.** Its only argument is the request options.
Passing params first — as every other call in the gateway does — silently drops
`stripe_account` and returns the *platform's* balance. This shipped once and was caught only
because a brand-new account reported a non-zero balance.

**Account drift.** A seller account can be switched off manual payouts outside Peppa.
`release()` refuses when `canHoldFunds()` is false, and reconciliation reports it as
`account_drifted` even when the numbers agree.

**The sweep must actually run.** Without it, timer rules never fire and nothing force-releases
before the Stripe deadline. A silently broken scheduler is a compliance problem, not a
delayed payout. The command exits non-zero when any release fails.

## Configuration

```
STRIPE_SECRET_KEY=                  platform key, sk_test_ or sk_live_
STRIPE_PUBLIC_KEY=
STRIPE_WEBHOOK_SECRET=              ordinary platform events
STRIPE_CONNECT_WEBHOOK_SECRET=      Connect account and payout events
STRIPE_ONBOARDING_REFRESH_URL=
STRIPE_ONBOARDING_RETURN_URL=
```

Connect must be enabled on the platform account in the Stripe dashboard before any of this
works. When completing the platform profile, **do not describe the product as escrow**.
Stripe states plainly that they do not provide escrow services, and the word can trigger a
review. "Conditional settlement" is both accurate and the framing Stripe's own documentation
uses for this pattern.
