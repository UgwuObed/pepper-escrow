# Client-facing API documentation

Two self-contained Word documents, one per payment rail. Both are generated
from the original `Pepper_Escrow_API_Documentation_v1.docx` rather than
hand-edited, so the shared sections cannot drift apart.

| File | For | Contains |
|---|---|---|
| `Pepper_CPS_API_Nigeria_v2.docx` | Local rail | All 12 sections, including bank codes and virtual accounts |
| `Pepper_CPS_API_International_v2.docx` | Stripe rail | Same platform sections, Nigeria-only ones removed, plus Stripe Conditional Settlements |

## What changed from v1

Headings, the cover block and the table of contents only. URL paths, JSON
values and every other technical string are untouched, so nothing anyone
has already integrated against has changed.

- "Pepper Escrow" became "Pepper" as the product name
- The documents are titled "Conditional Payments & Settlements"
- Section 1 "Escrow Transactions" became "1. Transactions"
- `/api/escrow/...` paths are unchanged, as is `"transaction_type": "escrow"`

## Rebuilding

```bash
cd docs/client-api
python build_docs.py      # needs original.docx alongside it
```

`section13.py` holds the Stripe section as reusable XML, so the same content
can be inserted at whatever section number a document needs.

## Known gap

The international document still carries Paystack and Flutterwave references
inside the shared Settlements and Overview sections. Removing the two
Nigeria-only sections does not remove local-gateway mentions elsewhere. If
an international reader should see nothing suggesting a Nigerian operation,
those sections need a prose pass.
