# Reconciliation

How to check that Peppa's records and Stripe's agree, and what to do when they do not.

## Why it is needed at all

Peppa's `settlement_holds` table says what was released. Stripe says what was paid out. The
money lives in Stripe, so **Stripe is the truth and our table is a claim about it**.

Claims drift. Someone pays out by hand in the Stripe dashboard. A payout fails after we
recorded it. A release is written against the wrong reference. None of these announce
themselves, and all of them end with a seller paid twice or a buyer told their refund went
out when it did not.

## Running it

**Admin → Held Funds → Reconciliation**, pick a merchant, Run.

Or in code:

```php
$report = app(SettlementReconciliationService::class)->reconcileMerchant($merchantId);
```

It is **read-only by design**. It reports drift and never corrects a balance, because the
right correction depends on which side is wrong and that is a judgement call, not an
algorithm.

## What it compares

For each seller account:

- Every hold's `amount_released` against the sum of Stripe payouts carrying that reference
- Every Stripe payout against the holds that claim it
- Each hold's deadline against today
- The account's configuration against what a hold requires

Rounding differences up to £0.01 are tolerated.

Only payouts in `paid`, `pending` or `in_transit` count. A `failed` or `canceled` payout
would otherwise read as money we released and Stripe lost.

The payout list pages to the end rather than taking the first hundred. A truncated list
invents discrepancies, and a report that cries wolf gets ignored within a week.

## The six findings

| Type | Meaning | Urgency |
|---|---|---|
| `over_paid` | Stripe moved more than we authorised | **Immediate** |
| `orphan_payout` | A payout no hold claims | **Immediate** |
| `under_paid` | We recorded a release Stripe has not made | Same day |
| `stale_hold` | Held past the Stripe limit | Same day — compliance |
| `account_drifted` | Numbers agree but the account can no longer be held | Same day |
| `unreachable` | Stripe could not be reached | Re-run |

### over_paid and orphan_payout

Both mean money left a seller's account that Peppa did not authorise. In practice this is
nearly always a human paying out through the Stripe dashboard.

Establish **who and why** before touching any record. Adjusting Peppa's numbers to match
Stripe makes the discrepancy disappear without answering the question, and the question is
the point.

### under_paid

We think we released; Stripe has no record. Check the hold's event trail for the `released`
event, take the payout id from it, and look that up in Stripe. If the payout failed, the
money is still sitting with the seller and needs releasing again.

### account_drifted

The one that looks harmless. Every number agrees, but the account is off manual payouts, or
losses have moved to the platform, or a dashboard appeared. Funds in that account are no
longer under Peppa's control — it is a discrepancy about the *future*, not the past.

### unreachable

Not a clean bill of health. Nothing was checked. Re-run it.

## How often

Daily is sensible. Nothing schedules it today — it is run from the admin screen on demand.
Wiring it to a cron and alerting on `over_paid` and `orphan_payout` is the obvious next
step and has not been done.

## Totals

The report also gives three figures per merchant:

- **held** — still sitting in sellers' balances, by our records
- **released** — what we believe we have paid out
- **paid_out** — what Stripe says it has paid out

`released` and `paid_out` should track closely. A persistent gap between them, even with no
individual discrepancies raised, is worth investigating.

## What it does not check

Honest limits of the current implementation:

- **Charges.** It reconciles releases, not the money coming in. A charge recorded in Peppa
  but never made in Stripe would not be caught.
- **Refunds.** Not compared against Stripe's refund records.
- **Application fees.** Peppa's own revenue is not reconciled at all.
- **Cross-merchant.** It runs per merchant; a payout attributed to the wrong merchant's
  hold would show as an orphan on one side and nothing on the other.

These are real gaps rather than oversights deferred — worth closing before the volume gets
large enough that a manual check is impossible.
