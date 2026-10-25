# API reference — conditional settlement

All endpoints authenticate with the partner's API key:

```
Authorization: Bearer <api_key>
```

Peppa is infrastructure. Your system calls these on behalf of your buyers and sellers; the
`actor` field is something you assert and is recorded on the audit trail, not used for
authorisation.

Responses are `{"status": "success", "data": …}` or `{"status": "error", "message": …}`.

| Code | Means |
|---|---|
| 200 / 201 | Done |
| 401 | Missing or bad API key |
| 404 | Not found, or belongs to another merchant |
| 409 | The resource's state does not allow this |
| 422 | Validation failed |

---

## Sellers

### Create a seller account

```http
POST /api/connected-accounts
{ "seller_email": "seller@example.com", "country": "GB" }
```

Creates the Stripe account, arms manual payouts, and returns a link for the seller to
complete Stripe's onboarding.

```json
{ "status": "success",
  "data": {
    "account": { "account_id": "acct_...", "can_hold_funds": false, "hold_blockers": [...] },
    "onboarding_url": "https://connect.stripe.com/setup/c/...",
    "onboarding_expires_at": 1793000000 } }
```

**The onboarding link expires in about five minutes.** Generate one per visit rather than
storing it. `can_hold_funds` is false until the seller finishes — do not charge before it is
true.

### List · Show

```http
GET /api/connected-accounts
GET /api/connected-accounts/{id}
```

### Fresh onboarding link

```http
POST /api/connected-accounts/{id}/onboarding-link
```

For sellers who abandoned the flow or let the link lapse.

### Re-sync from Stripe

```http
POST /api/connected-accounts/{id}/sync
```

Normally unnecessary — the webhook keeps state current. Useful for debugging.

### Seller dashboard

```http
GET /api/connected-accounts/{id}/dashboard
```

Everything a seller can see about their own money. They have no Stripe dashboard, so this is
the only place.

```json
{ "onboarding": { "state": "complete", "message": "Your account is fully set up." },
  "holds": { "currently_held": 250.00, "released_to_date": 1200.00, "items": [...] },
  "balance": { "available": {"GBP": 95.00}, "source": "stripe" },
  "payouts": [...] }
```

`onboarding.state` is `complete`, `in_review` or `incomplete`.

If Stripe is unreachable, `balance.source` is `"unavailable"` and `balance.available` is
`null` — **not zero**. Held funds still come through; they are from Peppa's own records.
Render the null as "temporarily unavailable", never as a zero balance.

### Embedded components

```http
POST /api/connected-accounts/{id}/account-session
{ "components": ["payouts", "account_management"] }
```

Returns a `client_secret` for Stripe's embedded components. Use these for bank details and
identity — Peppa is barred from touching them.

---

## Holds

### Open a hold

```http
POST /api/holds
{ "reference": "ORDER-1234",
  "connected_account_id": "acct_...",
  "amount": 100.00,
  "currency": "GBP",
  "payment_intent_id": "pi_..." }
```

`reference` is **yours**, and how you address the hold everywhere else. You never store an
id of ours.

**Idempotent.** The same reference twice returns the existing hold with `200` rather than
opening a second one, so retries are safe.

Returns `409` with a `blockers` array if the seller cannot hold funds yet.

```json
{ "reference": "ORDER-1234", "status": "held", "amount": 100.0, "remaining": 100.0,
  "release_by": "2027-01-23T10:00:00+00:00" }
```

`release_by` is Stripe's hard deadline for that seller's country. Past it is a compliance
breach, so the engine force-releases two days before.

### List · Show

```http
GET /api/holds?status=disputed&expiring=1&per_page=25
GET /api/holds/{reference}
```

`show` includes the full event trail — every state change, who caused it, and why.

---

## Releasing

Each of these evaluates the merchant's rules for that condition. If no active rule matches,
nothing is released and the attempt is recorded.

### Buyer confirms

```http
POST /api/holds/{reference}/confirm
```

### Manual approval

```http
POST /api/holds/{reference}/approve
{ "actor": "admin:42" }
```

### Timers

No endpoint. `elapsed_days` rules fire from the hourly sweep.

---

## Disputes

### Raise

```http
POST /api/holds/{reference}/dispute
{ "reason": "Item never arrived", "actor": "buyer" }
```

Freezes the hold. No rule will release it, and the sweep will not force-release it.

**A dispute does not pause Stripe's holding deadline.** An unresolved dispute approaching
the limit raises an expiry warning and needs a human. Do not treat disputes as open-ended.

### Resolve

```http
POST /api/holds/{reference}/dispute/resolve
{ "outcome": "release", "actor": "admin:42" }
```

| `outcome` | Does |
|---|---|
| `release` | Pays the seller |
| `refund` | Refunds the buyer |
| `resume` | Unfreezes; normal rules apply again |

`409` if the hold is not disputed.

### Refund

```http
POST /api/holds/{reference}/refund
{ "amount": 30.00, "reason": "Partial — one item missing" }
```

Omit `amount` to refund everything remaining. Refunds come out of the seller's balance,
which is where the money is.

---

## Rules

```http
GET    /api/settlement-rules
POST   /api/settlement-rules
PUT    /api/settlement-rules/{id}
DELETE /api/settlement-rules/{id}
```

```json
{ "name": "Release a week after delivery",
  "condition_type": "elapsed_days",
  "days": 7,
  "release_percentage": 100,
  "priority": 100 }
```

| `condition_type` | Fires when |
|---|---|
| `buyer_confirmation` | `/confirm` is called |
| `elapsed_days` | `days` have passed since the hold opened |
| `manual_approval` | `/approve` is called |

Rules are tried in `priority` order, lowest first. The first match wins — conditions do not
combine with AND.

`release_percentage` below 100 releases a share, capped at what remains. Two 60% rules pay
out 100%, not 120%.

**`days` of 90 or more is rejected.** Stripe would force-release before the rule fired, and
you would never work out why.

`DELETE` deactivates rather than removes, so released holds keep the explanation of why they
released.

---

## Webhook

```http
POST /api/stripe/connect/webhook
```

Point Stripe's **Connect** endpoint here and set `STRIPE_CONNECT_WEBHOOK_SECRET`. Handles
`account.updated` to keep seller state current. Unsigned requests get `401`.

---

## A worked flow

```
1. POST /api/connected-accounts            → onboarding_url
2. seller completes Stripe onboarding      → webhook fires, can_hold_funds becomes true
3. charge the buyer on the seller account  → pi_...
4. POST /api/holds                         → status: held
5. POST /api/holds/ORDER-1234/confirm      → status: released
```

Steps 1 and 2 happen once per seller. Steps 3 to 5 happen per payment.
