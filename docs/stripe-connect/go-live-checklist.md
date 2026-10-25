# Go-live checklist

In order. Several of these cannot be undone, so the order matters.

---

## Before anything else

**Not yet verified in production configuration.** Everything below has been proven in test
mode, but the live account configuration has never carried a real charge. The one gap is a
seller fully onboarded on the production controller properties — onboarding cannot be
automated, so this needs a real person completing Stripe's flow once.

Do that first, on a real seller, for a small amount, before opening it to anyone else.

---

## 1. Stripe account

- [ ] Connect enabled on the live account
- [ ] Platform profile completed
- [ ] **The word "escrow" does not appear** in the profile, product description, or anything
      Stripe reviews. Stripe states they do not provide escrow services; the term invites a
      review you do not want. "Conditional settlement" is accurate and is Stripe's own
      framing for this pattern.
- [ ] Platform review passed — Stripe scrutinises platforms holding funds for third parties
      more closely than ordinary merchants, and this can take time
- [ ] Payout schedule for the *platform* account decided (separate from seller payouts)

## 2. Configuration

- [ ] `STRIPE_SECRET_KEY` is `sk_live_`
- [ ] `STRIPE_PUBLIC_KEY` is `pk_live_`
- [ ] `STRIPE_CONNECT_WEBHOOK_SECRET` set from the **Connect** endpoint, not the platform one
- [ ] `STRIPE_WEBHOOK_SECRET` set
- [ ] `STRIPE_ONBOARDING_REFRESH_URL` and `STRIPE_ONBOARDING_RETURN_URL` point at real pages
- [ ] Test keys are gone from the production environment
- [ ] `.env` permissions are `600`

## 3. Webhooks

- [ ] Connect endpoint in Stripe points at `/api/stripe/connect/webhook`
- [ ] Subscribed to `account.updated` at minimum
- [ ] Test delivery returns 200
- [ ] A deliberately unsigned request returns 401

## 4. Database

- [ ] Migrations run: `connected_accounts`, `settlement_rules`, `settlement_holds`,
      `hold_events`
- [ ] Backups cover these four tables. `hold_events` is the record of why money moved and
      is append-only; losing it loses every explanation.

## 5. The scheduler

This is the one most likely to be missed, and the consequences are compliance rather than
inconvenience.

- [ ] Laravel's scheduler is actually running:
      `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`
- [ ] `php artisan schedule:list` shows `settlements:process-holds` hourly
- [ ] It has run at least once and written to `storage/logs/settlement-holds.log`
- [ ] Alerting on a non-zero exit — the command fails loudly when a release fails, and
      nobody will notice unless something is watching

**Without the sweep, timer rules never fire and nothing force-releases before Stripe's
deadline.** A silently broken scheduler is how you end up in breach.

## 6. Rules

- [ ] Each partner has at least one active rule
- [ ] At least one is a timer, so payments cannot sit indefinitely
- [ ] No timer is close to the holding limit for that partner's sellers
- [ ] Someone owns manual approvals and disputes, by name

## 7. Monitoring

- [ ] Reconciliation runs daily — **nothing schedules it today**, it is manual from the
      admin screen
- [ ] Alerting on `over_paid` and `orphan_payout`, which mean money moved that Peppa did
      not authorise
- [ ] Alerting on holds within 7 days of their deadline
- [ ] Someone checks Admin → Held Funds for the red "Past Stripe limit" count

## 8. First live payment

Do this deliberately, with a small amount, before any volume.

- [ ] Onboard one real seller and confirm `can_hold_funds` becomes true
- [ ] Take one small real payment
- [ ] Confirm the money is in the **seller's** balance, not Peppa's — Peppa should hold only
      the application fee
- [ ] Confirm no automatic payout fired
- [ ] Release it and confirm it reaches the seller's bank
- [ ] Run reconciliation and confirm it is clean
- [ ] Read the hold's event trail end to end and check it tells the story

## 9. People

- [ ] Someone can answer "where is my money" from the seller dashboard
- [ ] Someone knows a disputed hold has a deadline and cannot be left
- [ ] Support know Peppa **cannot** help a seller with onboarding — no pre-filling, no typo
      fixes, no accepting terms. A fresh link is the only lever.
- [ ] The runbook is somewhere support can find it

---

## Known gaps at go-live

Not blockers, but know them:

- Reconciliation is manual, not scheduled
- Reconciliation does not cover charges, refunds or application fees
- Admin screens are read-only; releases and dispute resolution go through the API
- Rules cannot combine conditions
- One seller per payment; splitting across sellers would have to happen at charge time
- No automated chase-up for sellers stalled in onboarding

## Things that cannot be undone

- **Dashboard type is immutable.** A seller account created with the wrong controller
  properties cannot be fixed, only replaced.
- **Payouts cannot be reversed.** Once in transit, recovery is commercial, not technical.
- **Sellers cannot bring an existing Stripe account.** Any account they already own has a
  full dashboard and could pay itself out.
