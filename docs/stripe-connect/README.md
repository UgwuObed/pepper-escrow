# Conditional settlement on Stripe Connect

A buyer pays a seller, the money is held in the seller's own Stripe account, and it is
released when a condition is met. Peppa never holds the funds — it holds the release
switch.

## The documents

| | For | Read it when |
|---|---|---|
| [Integration guide](integration-guide.md) | Engineers | Picking this up for the first time |
| [API reference](api-reference.md) | Partners integrating | Wiring Peppa into your system |
| [Release rules](release-rules.md) | Partners, support | Deciding or explaining what releases money |
| [Reconciliation](reconciliation.md) | Finance, engineers | The two sides disagree |
| [Runbook](runbook.md) | Support, on-call | Something is wrong |
| [Go-live checklist](go-live-checklist.md) | Whoever ships it | Before production |

## The four things to know

**Peppa never holds the money.** Payments are made directly on the seller's Stripe account.
Peppa controls release because that account is on manual payouts.

**Stripe has no conditional release.** There is no setting for "pay out when the buyer
confirms". All of that is Peppa's code.

**Sellers have no Stripe dashboard.** That is deliberate — a dashboard would let them pay
themselves out before the condition is met. The consequence is that Peppa owes them a UI,
and Peppa cannot help them with onboarding at all.

**Holds have a hard deadline.** Stripe caps how long funds may sit in a seller's account:
10 days in Thailand, 2 years in the US, 90 days elsewhere. Overrunning is a compliance
breach, so the engine force-releases before it. A dispute freezes the release but does not
pause the clock.

## Status

Built and tested in Stripe test mode. **The production account configuration has not yet
carried a real charge** — that needs one seller onboarded by hand, since onboarding cannot
be automated. See the [go-live checklist](go-live-checklist.md).

## Testing

[Postman collection](Peppa-Settlements.postman_collection.json) and
[local environment](Peppa-Local.postman_environment.json) — 33 requests across 7 folders,
ordered so each one feeds the next.

Import both, set `base_url` and paste an `api_key` from the merchant dashboard, then work
through the folders in order. Ids are captured into collection variables as you go, so the
later requests need no editing.

Folder 6 covers the error shapes (401, 404, 409, 422) and folder 7 the pre-existing escrow
endpoints, so one collection covers the whole surface.
