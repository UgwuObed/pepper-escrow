# Release rules

What releases held money, in plain language. For partners configuring Peppa and for anyone
explaining to a seller why they have not been paid.

## The idea

A payment sits in the seller's Stripe account until a rule says to release it. Rules belong
to a partner, and each partner sets their own.

Stripe does none of this. It holds the money and pays out when told. Everything about
*when* is Peppa.

## The three conditions

### Buyer confirms

The buyer says they received what they paid for. Releases immediately.

In practice this is the happy path people use when they remember. Most payments are not
released this way.

```json
{ "name": "Buyer confirmed receipt", "condition_type": "buyer_confirmation" }
```

### After a number of days

A timer. The money goes out once the period passes, unless a dispute was raised.

This is what fires most of the time, because most buyers never press the button.

```json
{ "name": "Release after 7 days", "condition_type": "elapsed_days", "days": 7 }
```

The clock starts when the hold opens, not when the buyer pays — in practice these are
moments apart.

**A timer of 90 days or more is rejected.** Stripe will not let funds sit that long outside
the US, so the hold would be force-released before the rule could ever fire. A rule that
can never fire is worse than no rule, because somebody is relying on it.

### Someone approves it

A person at the partner, or at Peppa, approves the release by hand. The catch-all for
anything unusual.

```json
{ "name": "Manual approval", "condition_type": "manual_approval" }
```

Who approved is recorded.

## Disputes

A dispute is not a condition. It **freezes** the hold: no rule fires, timers are ignored,
and the sweep will not touch it.

This matters more than it sounds. A timer that kept running through a dispute would pay out
the exact money being argued over, which is the one thing the product must never do.

Resolving a dispute has three outcomes:

| Outcome | Result |
|---|---|
| Release | The seller is paid |
| Refund | The buyer is refunded |
| Resume | Unfrozen; normal rules apply again |

**A dispute does not pause Stripe's holding deadline.** Stripe permits funds to sit in a
seller's account for a limited time regardless of what anyone is arguing about. An
unresolved dispute approaching that limit raises a warning for a human, and doing nothing is
not one of the options.

## How rules are chosen

Rules have a priority. For a given condition, the lowest priority number wins and the rest
are ignored.

Conditions do **not** combine. There is no "buyer confirms AND three days pass". Each
release is one condition. If a partner needs combined logic, that is a change to the engine
rather than a configuration.

## Partial releases

A rule can release a percentage instead of everything:

```json
{ "name": "Half on approval", "condition_type": "manual_approval", "release_percentage": 50 }
```

Shares are capped at what is left. Two 60% rules release 100%, not 120%.

The remainder stays held, under the same deadline, waiting for another rule or the sweep.

## The deadline that overrides everything

Every hold has a date by which the money must leave, set by the **seller's** country:

| Country | Limit |
|---|---|
| Thailand | 10 days |
| United States | 2 years |
| Everywhere else | 90 days |

Two days before that date, the money is released whether or not any condition was met.
Holding beyond it breaches Peppa's agreement with Stripe.

So a release has three possible explanations: a rule fired, a human decided, or the deadline
arrived. All three are recorded, and the hold's event trail says which.

## Writing rules that work

**Set a timer shorter than the deadline, with room to spare.** A 7-day timer against a
90-day limit is comfortable. An 85-day timer is not — anything that delays the sweep, and
the force-release fires first.

**Have a timer at all.** Without one, a payment where the buyer never confirms and nobody
approves sits until the deadline forces it out. That works, but nobody chose it.

**Keep manual approval as a backstop, not the main route.** If most payments need a human,
the rules are not describing the business.

## Changing rules

Changing a rule affects holds that have not yet released. It does not retroactively change
anything already paid out.

Deleting a rule **deactivates** it rather than removing it, so holds it released keep the
explanation of why they released. An audit trail pointing at a rule that no longer exists is
not an audit trail.
