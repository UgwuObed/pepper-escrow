"""
Build two self-contained API documents from the original.

  Nigeria       — the local rail: everything, including bank codes and
                  virtual accounts.
  International — the Stripe rail: the same platform sections, without the
                  Nigeria-only ones, plus Conditional Payments & Settlements.

Only headings, the cover block and the table of contents are reworded. URL
paths, JSON values and anything else an integration depends on are left
exactly as they are, so nothing anyone has built against changes.
"""

import re
import zipfile
from xml.sax.saxutils import escape, unescape

import section13  # the CP&S section, shared by both builds

SRC = 'original.docx'

TEXT_RE = r'<w:t(?: [^>]*)?>([^<]*)</w:t>'


def runs_text(block):
    return ''.join(re.findall(TEXT_RE, block))


def top_blocks(xml):
    """Split the body into top-level paragraphs and tables, in order."""
    out = []
    i = 0
    pat = re.compile(r'<w:(p|tbl)\b')
    while True:
        m = pat.search(xml, i)
        if not m:
            break
        tag = m.group(1)
        # Self-closing <w:p/>
        if xml[m.start():m.start() + 7] == '<w:p/>':
            out.append((m.start(), m.start() + 6, 'p'))
            i = m.start() + 6
            continue
        close = f'</w:{tag}>'
        depth = 0
        j = m.start()
        open_pat = re.compile(rf'<w:{tag}\b[^>]*?(/?)>')
        while True:
            om = open_pat.search(xml, j)
            cm = xml.find(close, j)
            if cm == -1:
                raise SystemExit(f'unclosed <w:{tag}>')
            if om and om.start() < cm:
                if om.group(1) != '/':
                    depth += 1
                j = om.end()
                continue
            depth -= 1
            j = cm + len(close)
            if depth <= 0:
                break
        out.append((m.start(), j, tag))
        i = j
    return out


def replace_text(xml, pairs):
    """Swap exact run text. Only touches <w:t> contents."""
    def sub(m):
        inner = m.group(1)
        # Stored text carries XML entities ("&amp;"), the pairs below are
        # written as a person reads them, so compare unescaped.
        plain = unescape(inner)
        for old, new in pairs:
            if plain == old:
                return m.group(0).replace(f'>{inner}<', f'>{escape(new)}<')
        return m.group(0)
    return re.sub(TEXT_RE, sub, xml)


def drop_blocks_with_text(xml, texts, limit):
    """Remove whole blocks whose text matches, before offset `limit`."""
    targets = set(texts)
    for s, e, _ in reversed([b for b in top_blocks(xml) if b[0] < limit]):
        if runs_text(xml[s:e]).strip() in targets:
            xml = xml[:s] + xml[e:]
    return xml


# Read every part once; a ZipFile handle cannot be re-read after writing.
with zipfile.ZipFile(SRC) as z:
    PARTS = [(i, z.read(i.filename)) for i in z.infolist()]

base = dict((i.filename, d) for i, d in PARTS)['word/document.xml'].decode('utf-8')


def write(out_name, xml):
    with zipfile.ZipFile(out_name, 'w', zipfile.ZIP_DEFLATED) as zout:
        for item, data in PARTS:
            if item.filename == 'word/document.xml':
                data = xml.encode('utf-8')
            zout.writestr(item, data)
    print('wrote', out_name, '| document.xml', len(xml), 'chars')

# Wording shared by both documents. Product name loses the word "escrow";
# technical strings never do.
COMMON = [
    ('Pepper Escrow', 'Pepper'),
    ('API Integration Guide', 'Conditional Payments & Settlements'),
    ('Client Integration Reference', 'Client Integration Reference'),
    ('1. Escrow Transactions', '1. Transactions'),
]

# ── Nigeria ───────────────────────────────────────────────────────────
ng = replace_text(base, COMMON + [
    ('Version 1.1', 'Version 2.0 — Nigeria'),
])
write('Pepper_CPS_API_Nigeria_v2.docx', ng)

# ── International ─────────────────────────────────────────────────────
intl = base

# 1. Drop the Nigeria-only sections from the body. Offsets come from the
#    Heading1 positions: section 3 starts at "3. Bank Codes", section 5 at
#    "5. Wallets", so everything between is sections 3 and 4.
blocks = top_blocks(intl)
start = end = None
for s, e, _ in blocks:
    txt = runs_text(intl[s:e]).strip()
    if txt.startswith('3. Bank Codes') and 'Heading1' in intl[s:e]:
        start = s
    if txt.startswith('5. Wallets') and 'Heading1' in intl[s:e]:
        end = s
        break
if start is None or end is None:
    raise SystemExit('could not locate the Nigeria sections')
intl = intl[:start] + intl[end:]

# 2. Drop their entries from the table of contents (static text, no field).
toc_limit = intl.index('Overview')
intl = drop_blocks_with_text(intl, [
    '3. Bank Codes & Misc',
    'Get Bank Codes',
    '4. Bank Transfer / Virtual Accounts',
    'Assign Virtual Account to Transaction',
    'List Virtual Accounts',
    'Get Virtual Account Info',
    'Deactivate Virtual Account',
], toc_limit)

# 3. Renumber what follows. Each pair carries the section name, so no
#    replacement can collide with one made earlier.
intl = replace_text(intl, COMMON + [
    ('Version 1.1', 'Version 2.0 — International'),
    ('5. Wallets', '3. Wallets'),
    ('6. Client Configuration', '4. Client Configuration'),
    ('7. Transaction Types & Commission Rules', '5. Transaction Types & Commission Rules'),
    ('8. Subscriptions', '6. Subscriptions'),
    ('9. Settlements', '7. Settlements'),
    ('10. Revenue Analytics', '8. Revenue Analytics'),
    ('11. Rewards & Listing Fees', '9. Rewards & Listing Fees'),
    ('12. Notifications', '10. Notifications'),
])

# 4. Append Conditional Payments & Settlements as section 11, and list it
#    in the contents.
cps = section13.build(number='11')
m = re.search(r'<w:sectPr\b', intl)
intl = intl[:m.start()] + cps + intl[m.start():]

toc_entries = ''.join(
    f'<w:p><w:r><w:t xml:space="preserve">{escape(s)}</w:t></w:r></w:p>'
    for s in ['11. Stripe Conditional Settlements', 'Sellers', 'Release Rules',
              'Holds', 'Releasing', 'Disputes', 'Response Codes', 'Hosted Seller Sign-up']
)
anchor = None
for s, e, _ in top_blocks(intl):
    if s > toc_limit:
        break
    if runs_text(intl[s:e]).strip() == '10. Notifications':
        anchor = e
if anchor:
    # Place after the last TOC child of section 10.
    nxt = [b for b in top_blocks(intl) if b[0] >= anchor and b[0] < toc_limit]
    tail = nxt[2][1] if len(nxt) > 2 else anchor
    intl = intl[:tail] + toc_entries + intl[tail:]

write('Pepper_CPS_API_International_v2.docx', intl)
