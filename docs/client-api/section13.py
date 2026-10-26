"""
Append Section 13 to the existing API documentation.

Writes XML in exactly the idiom the original uses: Heading1/2/3 pStyles,
TableGrid tables with two 4320 dxa columns, sz 19 runs in cells. Nothing in
sections 1 to 12 is touched, and no new style, numbering or relationship is
introduced, so the merge cannot disturb what is already there.
"""

import re
from xml.sax.saxutils import escape



def t(s):
    """A text run."""
    return f'<w:r><w:t xml:space="preserve">{escape(s)}</w:t></w:r>'


def tb(s):
    """A bold run."""
    return f'<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">{escape(s)}</w:t></w:r>'


def para(*runs):
    return '<w:p>' + ''.join(runs) + '</w:p>'


def p(s):
    return para(t(s))


def pb(label, rest=''):
    return para(tb(label), t(rest))


def h(level, s):
    return f'<w:p><w:pPr><w:pStyle w:val="Heading{level}"/></w:pPr>{t(s)}</w:p>'


def mono(lines):
    """A code block: one Consolas paragraph per line, as the original does."""
    out = []
    for line in lines:
        out.append(
            '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr>'
            '<w:r><w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:sz w:val="18"/></w:rPr>'
            f'<w:t xml:space="preserve">{escape(line) if line else " "}</w:t></w:r></w:p>'
        )
    out.append('<w:p/>')
    return ''.join(out)


def cell(text, bold=False, w=4320):
    rpr = '<w:rPr>' + ('<w:b/>' if bold else '') + '<w:sz w:val="19"/></w:rPr>'
    return (
        f'<w:tc><w:tcPr><w:tcW w:type="dxa" w:w="{w}"/></w:tcPr>'
        f'<w:p><w:r>{rpr}<w:t xml:space="preserve">{escape(text)}</w:t></w:r></w:p></w:tc>'
    )


def table(rows, header=True, widths=(4320, 4320)):
    grid = ''.join(f'<w:gridCol w:w="{w}"/>' for w in widths)
    body = []
    for i, row in enumerate(rows):
        cells = ''.join(cell(c, bold=(header and i == 0), w=widths[j]) for j, c in enumerate(row))
        body.append(f'<w:tr>{cells}</w:tr>')
    return (
        '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:type="auto" w:w="0"/>'
        '<w:tblLook w:firstColumn="1" w:firstRow="1" w:lastColumn="0" w:lastRow="0" '
        'w:noHBand="0" w:noVBand="1" w:val="04A0"/></w:tblPr>'
        f'<w:tblGrid>{grid}</w:tblGrid>' + ''.join(body) + '</w:tbl>'
    )


def endpoint(method, url):
    return table([[method, url]], header=True)


def auth():
    return p('Authentication: Bearer token required (Authorization: Bearer {api_key})')



def build(number='13'):
    """Return the Conditional Payments & Settlements section as XML."""
    x = []
    a = x.append

    # ── Section opening ───────────────────────────────────────────────────
    a(h(1, '13. Stripe Conditional Settlements'))

    a(p('This section is new and entirely additive. No endpoint documented in sections 1 to 12 has '
        'changed, and no existing integration is affected by it. Everything here lives under new paths '
        'and can be adopted whenever you are ready, or ignored.'))

    a(h(2, 'What it does'))

    a(p('Conditional settlement holds a payment until an agreed condition is met, then releases it to '
        'the seller. It differs from the escrow flow in sections 1 and 4 in one important way: the money '
        'is never held by Peppa.'))

    a(p('When a buyer pays, the payment is made directly onto the seller’s own Stripe account, so the '
        'funds sit in the seller’s balance. That account is set to manual payouts, which means Stripe '
        'will not send anything to the seller’s bank until Peppa creates a payout. Peppa holds the '
        'release switch, not the money.'))

    a(pb('Why this matters for you: ',
         'because the money settles with the seller, card fees, refunds and chargebacks come out of the '
         'seller’s balance rather than yours. Your fee is taken from each payment as it arrives.'))

    a(h(2, 'The flow'))
    a(table([
        ['Step', 'What happens'],
        ['1', 'You create the seller’s account and send them the onboarding link'],
        ['2', 'The seller completes Stripe’s verification and adds their bank account'],
        ['3', 'You configure at least one release rule'],
        ['4', 'A buyer pays; you open a hold against that payment'],
        ['5', 'Your condition is met; Peppa pays the seller'],
    ], widths=(1080, 7560)))
    a('<w:p/>')

    a(h(2, 'Before you build'))
    a(p('Five constraints shape any integration against this section.'))

    a(pb('Holds have a hard deadline. ',
         'Stripe limits how long money may sit in a seller’s account, based on the seller’s country: '
         '90 days in the United Kingdom, two years in the United States, ten days in Thailand. Every hold '
         'returns a release_by date. Shortly before it, Peppa releases the money whether or not your '
         'condition was met, because holding beyond that point breaches Peppa’s agreement with Stripe.'))

    a(pb('A dispute does not pause that deadline. ',
         'Raising a dispute freezes the release, but the clock keeps running. Resolve disputes well inside it.'))

    a(pb('Sellers get no Stripe dashboard. ',
         'This is deliberate: a dashboard would let a seller pay themselves out before your condition is '
         'met. It means you show them their balance and payouts in your own product, using the dashboard '
         'endpoint below.'))

    a(pb('Nobody can help a seller through onboarding. ',
         'Stripe collects every field directly. Neither you nor Peppa can pre-fill a name, correct a typo, '
         'or accept terms on a seller’s behalf. A fresh onboarding link is the only remedy. The upside '
         'is that seller identity documents and bank details never pass through your systems.'))

    a(pb('One payment goes to one seller. ',
         'Splitting a payment between several sellers has to happen when the buyer pays, not at release, '
         'because Stripe only pays a balance out to the account that owns it.'))

    a(h(2, 'Supported countries'))
    a(p('Sellers may be based in the United Kingdom or the United States. Other countries are a '
        'configuration change rather than a rebuild, provided Stripe supports them.'))

    # ── 13.1 Sellers ──────────────────────────────────────────────────────
    a(h(2, '13.1 Sellers'))

    a(h(3, 'Create Seller Account'))
    a(endpoint('POST', '{{base_url}}/api/connected-accounts'))
    a(p('Creates the seller’s Stripe account, sets it to manual payouts, and returns a link for the '
        'seller to complete Stripe’s onboarding. The link expires after roughly five minutes.'))
    a(auth())
    a(pb('Request Body'))
    a(mono(['{', '  "seller_email": "seller@example.com",', '  "country": "GB"', '}']))
    a(pb('Example Request (cURL)'))
    a(mono([
        'curl -X POST "https://oloja.store/api/connected-accounts" \\',
        '  -H "Authorization: Bearer YOUR_API_KEY" \\',
        '  -H "Content-Type: application/json" \\',
        '  -d \'{"seller_email":"seller@example.com","country":"GB"}\'',
    ]))
    a(pb('Response (201)'))
    a(mono([
        '{',
        '  "status": "success",',
        '  "data": {',
        '    "account": {',
        '      "account_id": "acct_1A2b3C",',
        '      "country": "GB",',
        '      "can_hold_funds": false,',
        '      "hold_blockers": ["Stripe has not enabled charges on this account yet."],',
        '      "holding_period_days": 90',
        '    },',
        '    "onboarding_url": "https://connect.stripe.com/setup/c/...",',
        '    "onboarding_expires_at": 1793000000',
        '  }',
        '}',
    ]))
    a(pb('Note: ', 'can_hold_funds is false until Stripe approves the seller. Do not open a hold before it is true.'))

    a(h(3, 'Get Seller'))
    a(endpoint('GET', '{{base_url}}/api/connected-accounts/{id}'))
    a(p('Returns the seller’s current state. can_hold_funds is the only field you need before taking a '
        'payment; when it is false, hold_blockers explains why in plain language.'))
    a(auth())

    a(h(3, 'List Sellers'))
    a(endpoint('GET', '{{base_url}}/api/connected-accounts'))
    a(auth())

    a(h(3, 'New Onboarding Link'))
    a(endpoint('POST', '{{base_url}}/api/connected-accounts/{id}/onboarding-link'))
    a(p('For a seller who abandoned the flow or whose link expired. Generate one per visit rather than storing it.'))
    a(auth())

    a(h(3, 'Re-sync From Stripe'))
    a(endpoint('POST', '{{base_url}}/api/connected-accounts/{id}/sync'))
    a(p('Normally unnecessary — Peppa keeps seller state current from Stripe’s webhook. Useful when debugging.'))
    a(auth())

    a(h(3, 'Seller Dashboard'))
    a(endpoint('GET', '{{base_url}}/api/connected-accounts/{id}/dashboard'))
    a(p('Everything a seller can see about their own money: balance, payout history, every hold against '
        'them with a plain-language explanation, and their onboarding state. Render this in your own product.'))
    a(auth())
    a(pb('Response (200)'))
    a(mono([
        '{',
        '  "status": "success",',
        '  "data": {',
        '    "onboarding": { "state": "complete",',
        '                    "message": "Your account is fully set up." },',
        '    "holds": { "currently_held": 250.00,',
        '               "released_to_date": 1200.00,',
        '               "disputed_count": 0,',
        '               "items": [ { "reference": "ORDER-1234",',
        '                            "remaining": 100.0,',
        '                            "status": "held",',
        '                            "explanation": "Held until the release condition is met." } ] },',
        '    "balance": { "available": { "GBP": 95.00 }, "source": "stripe" },',
        '    "payouts": [ { "id": "po_1...", "amount": 95.00, "status": "paid" } ]',
        '  }',
        '}',
    ]))
    a(pb('Important: ',
         'if Stripe is temporarily unreachable, balance.source is "unavailable" and balance.available is '
         'null, not zero. Held funds still return, because those come from Peppa’s own records. Display '
         'the null as “temporarily unavailable” rather than as an empty balance.'))

    a(h(3, 'Embedded Component Session'))
    a(endpoint('POST', '{{base_url}}/api/connected-accounts/{id}/account-session'))
    a(p('Returns a short-lived client secret for Stripe’s embedded components, so a seller can manage '
        'their bank details and identity inside your product. Peppa cannot handle those fields itself.'))
    a(auth())
    a(pb('Request Body'))
    a(mono(['{', '  "components": ["payouts", "account_management"]', '}']))

    # ── 13.2 Rules ────────────────────────────────────────────────────────
    a(h(2, '13.2 Release Rules'))

    a(p('Rules decide what releases a held payment. They belong to your account and are tried in '
        'priority order, lowest number first. The first rule that matches wins; conditions do not combine.'))

    a(table([
        ['condition_type', 'Fires when'],
        ['buyer_confirmation', 'You call the confirm endpoint'],
        ['elapsed_days', 'The configured number of days has passed since the hold opened'],
        ['manual_approval', 'You call the approve endpoint'],
    ]))
    a('<w:p/>')

    a(pb('Always configure a timer. ',
         'Without one, a payment where nobody confirms and nobody approves sits until Stripe’s deadline '
         'forces it out. That works, but it is not a decision anyone made.'))

    a(h(3, 'Create Rule'))
    a(endpoint('POST', '{{base_url}}/api/settlement-rules'))
    a(auth())
    a(pb('Request Body'))
    a(mono([
        '{',
        '  "name": "Release 7 days after delivery",',
        '  "condition_type": "elapsed_days",',
        '  "days": 7,',
        '  "release_percentage": 100,',
        '  "priority": 100',
        '}',
    ]))
    a(p('release_percentage below 100 releases a share of the hold, capped at what remains. Two rules of '
        '60 percent release 100 percent in total, not 120.'))
    a(pb('Rejected with 422: ',
         'a days value of 90 or more. Stripe would force the release before such a rule could fire, so it '
         'is refused at configuration time rather than silently never firing.'))

    a(h(3, 'List Rules'))
    a(endpoint('GET', '{{base_url}}/api/settlement-rules'))
    a(auth())

    a(h(3, 'Update Rule'))
    a(endpoint('PUT', '{{base_url}}/api/settlement-rules/{id}'))
    a(auth())

    a(h(3, 'Deactivate Rule'))
    a(endpoint('DELETE', '{{base_url}}/api/settlement-rules/{id}'))
    a(p('Deactivates rather than deletes, so holds released by this rule keep the record of why they were released.'))
    a(auth())

    # ── 13.3 Holds ────────────────────────────────────────────────────────
    a(h(2, '13.3 Holds'))

    a(h(3, 'Open a Hold'))
    a(endpoint('POST', '{{base_url}}/api/holds'))
    a(p('Records a payment that has landed in a seller’s balance and should be held. The reference is '
        'yours — use your own order number. You never need to store an identifier of Peppa’s.'))
    a(auth())
    a(pb('Request Body'))
    a(mono([
        '{',
        '  "reference": "ORDER-1234",',
        '  "connected_account_id": "acct_1A2b3C",',
        '  "amount": 100.00,',
        '  "currency": "GBP",',
        '  "payment_intent_id": "pi_3Xy..."',
        '}',
    ]))
    a(pb('Response (201)'))
    a(mono([
        '{',
        '  "status": "success",',
        '  "data": {',
        '    "reference": "ORDER-1234",',
        '    "status": "held",',
        '    "amount": 100.0,',
        '    "remaining": 100.0,',
        '    "currency": "GBP",',
        '    "release_by": "2027-01-23T10:00:00+00:00"',
        '  }',
        '}',
    ]))
    a(pb('Idempotent. ',
         'Opening the same reference twice returns the existing hold with 200 rather than creating a '
         'second one, so retries are safe.'))
    a(pb('Returns 409 ',
         'with a blockers array when the seller is not yet able to hold funds. This is the expected '
         'response until the seller completes Stripe onboarding.'))

    a(h(3, 'List Holds'))
    a(endpoint('GET', '{{base_url}}/api/holds'))
    a(p('Query parameters: status, expiring=1 (within seven days of the Stripe deadline), per_page up to 100.'))
    a(auth())

    a(h(3, 'Get Hold'))
    a(endpoint('GET', '{{base_url}}/api/holds/{reference}'))
    a(p('Includes the full event trail: every state change, who caused it, and when. This is the record '
        'of why money moved.'))
    a(auth())
    a(pb('Response extract'))
    a(mono([
        '"events": [',
        '  { "at": "2026-10-25T10:00:00+00:00", "event": "held",            "actor": "system" },',
        '  { "at": "2026-10-27T14:22:00+00:00", "event": "buyer_confirmed", "actor": "buyer" },',
        '  { "at": "2026-10-27T14:22:01+00:00", "event": "released",        "actor": "system",',
        '    "amount": 100.0, "note": "Buyer confirmed receipt" }',
        ']',
    ]))

    a(h(3, 'Hold Statuses'))
    a(table([
        ['Status', 'Meaning'],
        ['held', 'Waiting on a condition'],
        ['part_released', 'Some has been paid out, the rest is still held'],
        ['released', 'Paid out in full'],
        ['disputed', 'Frozen; no rule will fire'],
        ['refunded', 'Returned to the buyer'],
    ]))
    a('<w:p/>')

    # ── 13.4 Releasing ────────────────────────────────────────────────────
    a(h(2, '13.4 Releasing'))

    a(h(3, 'Buyer Confirms'))
    a(endpoint('POST', '{{base_url}}/api/holds/{reference}/confirm'))
    a(p('Call this when the buyer confirms they received what they paid for.'))
    a(auth())

    a(h(3, 'Manual Approval'))
    a(endpoint('POST', '{{base_url}}/api/holds/{reference}/approve'))
    a(auth())
    a(pb('Request Body'))
    a(mono(['{', '  "actor": "ops:jane"', '}']))
    a(p('The actor is a label you assert. It is recorded on the audit trail and is not used for authorisation.'))
    a(p('Timers need no call. They fire automatically.'))
    a(pb('If no active rule matches ',
         'the condition, nothing is released and the attempt is recorded in the event trail, so a missing '
         'rule is visible rather than silent.'))

    a(h(3, 'Refund'))
    a(endpoint('POST', '{{base_url}}/api/holds/{reference}/refund'))
    a(p('Omit amount to refund everything remaining. Refunds come out of the seller’s balance.'))
    a(auth())
    a(pb('Request Body'))
    a(mono(['{', '  "amount": 30.00,', '  "reason": "One item missing"', '}']))

    # ── 13.5 Disputes ─────────────────────────────────────────────────────
    a(h(2, '13.5 Disputes'))

    a(p('Note: these are conditional-settlement disputes and are separate from the dispute endpoints in '
        'section 2, which apply to the escrow transaction flow.'))

    a(h(3, 'Raise Dispute'))
    a(endpoint('POST', '{{base_url}}/api/holds/{reference}/dispute'))
    a(p('Freezes the hold completely. No rule fires and no timer runs while a payment is disputed.'))
    a(auth())
    a(pb('Request Body'))
    a(mono(['{', '  "reason": "Item never arrived",', '  "actor": "buyer"', '}']))

    a(h(3, 'Resolve Dispute'))
    a(endpoint('POST', '{{base_url}}/api/holds/{reference}/dispute/resolve'))
    a(auth())
    a(pb('Request Body'))
    a(mono(['{', '  "outcome": "release",', '  "actor": "ops:jane"', '}']))
    a(table([
        ['outcome', 'Result'],
        ['release', 'Pays the seller'],
        ['refund', 'Refunds the buyer'],
        ['resume', 'Unfreezes the hold; normal rules apply again'],
    ]))
    a('<w:p/>')
    a(p('Returns 409 if the hold is not currently disputed.'))

    # ── 13.6 Response codes ───────────────────────────────────────────────
    a(h(2, '13.6 Response Codes'))
    a(p('This section follows the same response conventions as the rest of the API.'))
    a(table([
        ['Code', 'Meaning'],
        ['200 / 201', 'Successful'],
        ['401', 'Missing or invalid API key'],
        ['404', 'Not found, or belongs to another merchant'],
        ['409', 'The current state does not permit this action'],
        ['422', 'Validation failed; the message field explains what'],
    ]))
    a('<w:p/>')
    a(p('A 409 on opening a hold carries a blockers array naming exactly why the seller cannot hold funds yet.'))

    # ── 13.7 Hosted sign-up ───────────────────────────────────────────────
    a(h(2, '13.7 Hosted Seller Sign-up'))
    a(p('If you would rather not build seller onboarding yourself, Peppa provides a hosted page. Enable '
        'it from the API Keys screen in your dashboard and you will be given a link of the form:'))
    a(mono(['https://oloja.store/connect/{your-token}']))
    a(p('Share that link with your sellers. They enter an email address and a country, and are taken '
        'straight into Stripe’s onboarding. Sellers who return having not finished are sent back to the '
        'same account rather than given a second one.'))
    a(p('The page is disabled by default and can be replaced at any time if a link has been shared too widely.'))


    out = ''.join(x)
    if number != '13':
        out = out.replace('>13.', '>' + number + '.')
    return out
