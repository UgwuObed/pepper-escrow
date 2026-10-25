# Runbook — conditional settlement

What to do when something is wrong. Ordered by how urgent it is, not how often it happens.

---

## Holds are past the Stripe limit

**Admin → Held Funds shows a red "Past Stripe limit" count.**

This is a compliance breach in progress. Stripe permits funds to sit in a connected account
for a limited period based on the seller's country — 10 days for Thailand, 2 years for the
US, 90 days everywhere else — and these are past it.

1. Check the sweep is running: `php artisan schedule:list` should show
   `settlements:process-holds` hourly, and `storage/logs/settlement-holds.log` should have
   recent entries.
2. If it has not run, start it, then run once by hand:
   `php artisan settlements:process-holds`
3. For anything still stuck, open the hold and read the event trail. The usual cause is a
   release that failed, which leaves a `release_failed` event with the reason.

If the holds are **disputed**, the sweep deliberately will not release them. Those need a
decision — see *Disputed holds nearing the deadline* below.

---

## The sweep is failing

`settlements:process-holds` exits non-zero when any release failed.

```bash
php artisan settlements:process-holds --dry-run
```

This shows what it would do to every open hold without moving money. Run it first.

Common causes:

| Symptom | Cause | Fix |
|---|---|---|
| `connected account cannot be paid out` | Seller drifted off manual payouts | Re-arm: `POST /api/connected-accounts/{id}/sync` then re-assert the hold |
| `Stripe Connect is not configured` | Missing `STRIPE_SECRET_KEY` | Check the environment |
| Timeouts | Stripe degraded | Check status.stripe.com; the sweep is safe to re-run |

The sweep is `withoutOverlapping`, so a slow run will not have a second start on top of it.
If it is wedged, the lock expires after 30 minutes.

---

## Disputed holds nearing the deadline

**A dispute freezes the release. It does not pause Stripe's clock.**

An unresolved dispute approaching the holding limit raises an `expiry_warning` event once
and then does nothing further, because the engine will not release disputed money
automatically.

Somebody must decide before the deadline:

```http
POST /api/holds/{reference}/dispute/resolve
{ "outcome": "release" | "refund" | "resume", "actor": "admin:you" }
```

Doing nothing is the one option that is not available. Past the deadline you are in breach
with Stripe and the money is still sitting there.

---

## Reconciliation reports a discrepancy

**Admin → Held Funds → Reconciliation.**

Reconciliation is read-only. It tells you the two sides disagree; it never picks a side.

### `over_paid` / `orphan_payout` — look at these first

Stripe moved money Peppa did not record. Almost always a manual payout someone made in the
Stripe dashboard.

1. Find the payout in Stripe and see who created it and when.
2. If it was legitimate (an operator paying out by hand), record it against the hold so the
   books agree.
3. If it was not, that is an unauthorised movement of money and needs escalating, not
   reconciling.

**Do not** adjust Peppa's records to match Stripe without establishing why they differ. The
discrepancy is the signal.

### `under_paid`

Peppa recorded a release Stripe has not made. Usually a payout that failed after we wrote
our record. Check the hold's event trail for `released`, then look for that payout id in
Stripe. If the payout failed, the money is still with the seller and needs releasing again.

### `stale_hold`

Held past the limit. See the first section.

### `account_drifted`

The numbers agree but the seller account can no longer be held — off manual payouts, losses
moved to the platform, or a dashboard appeared. Funds in that account are not under our
control even though nothing looks wrong yet.

```http
POST /api/connected-accounts/{id}/sync
```

If it is genuinely off manual payouts, re-arm it and work out who changed it.

### `unreachable`

Stripe could not be reached. This is **not** a clean reconciliation — nothing was checked.
Re-run it.

---

## A seller cannot be paid

Open the hold. If the seller account is the problem, the detail page lists the blockers.

| Blocker | Means |
|---|---|
| `Stripe has not enabled charges` | Onboarding incomplete, or Stripe is still reviewing |
| `Payouts are not set to manual` | Account drifted; re-arm it |
| `Losses fall on the platform` | Account created with the wrong configuration — see below |
| `The seller has a ... dashboard` | Same |

A seller account created with the wrong controller properties **cannot be fixed**. Dashboard
type is immutable. The account has to be replaced and the seller re-onboarded. If you see
this on a live account, find out what created it before making another.

---

## A seller is stuck in onboarding

Peppa cannot help them directly. Stripe owns every KYC field — we cannot pre-fill a name,
correct a typo, or accept terms for them.

What you can do:

1. `GET /api/connected-accounts/{id}` and read `requirements_due` — it names the exact
   fields Stripe is waiting for.
2. `POST /api/connected-accounts/{id}/onboarding-link` for a fresh link and send it.
3. If they say they completed it and `details_submitted` is still false, the form did not
   save. Have them do it again in one sitting; part-completed data is not retained the way
   people expect.

If Stripe escalates to document or selfie verification, that is Stripe's decision and
usually means it could not verify from the data given. Peppa cannot bypass it.

---

## Webhooks have stopped

Seller state goes stale: sellers who finished onboarding still show as not ready.

1. Stripe dashboard → Developers → Webhooks → check the **Connect** endpoint for failures.
2. Confirm `STRIPE_CONNECT_WEBHOOK_SECRET` matches that endpoint's signing secret. A
   mismatch returns 401 and Stripe will show the failures.
3. Catch up by hand: `POST /api/connected-accounts/{id}/sync` per affected seller.

Note the Connect endpoint has a **different** signing secret from the ordinary platform
webhook. The gateway tries both, but configure the right one.

---

## Rolling back a release

You cannot. A payout to a seller's bank cannot be reversed by Peppa or by Stripe once it is
in transit.

If money went out that should not have, recovery is commercial: a refund from the seller, or
offsetting a future payment. This is why `canHoldFunds()` is checked before every release
and why the sweep refuses to release disputed holds.

---

## Useful commands

```bash
php artisan settlements:process-holds --dry-run   # what would happen
php artisan settlements:process-holds             # run the sweep now
php artisan schedule:list                         # is it scheduled
tail -f storage/logs/settlement-holds.log         # sweep output
```

## Who to escalate to

| Situation | Who |
|---|---|
| Unauthorised payout | Whoever owns the Stripe account, immediately |
| Holds past the Stripe limit | Engineering, same day — compliance |
| Stripe account restricted | Stripe support, with your platform ID |
| Seller disputes a release that was correct | Partner, with the hold's event trail |

The event trail on a hold is the record of why money moved. It answers most arguments
without anybody reading logs.
