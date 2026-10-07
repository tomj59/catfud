#!/usr/bin/env python3
"""
Turn Royal Canin products/facets pages (rc-page1.json ...) into a draft ladder + draft product file in the catalogue format,
plus an audit of what the feed can and cannot give us.

    python3 scripts/royalcanin_extract.py <dir with rc-page*.json> database/catalogue/drafts/royalcanin-retail

The feed is a LISTING: no ingredients, nutrition, pack weight or description. One row per sellable pack (a 12-digit UPC for the
single, a 14-digit GTIN for a case); rows of the same recipe are grouped into one product with several barcodes.
Writes ladder.json, products.json, audit.json. Never touches database/catalogue/us/.
"""
import collections
import glob
import json
import re
import sys
from pathlib import Path

SRC, OUT = Path(sys.argv[1]), Path(sys.argv[2])
OUT.mkdir(parents=True, exist_ok=True)
pages = sorted(glob.glob(str(SRC / 'rc-page*.json')), key=lambda f: int(re.search(r'(\d+)\.json$', f).group(1)))
raw = []
for f in pages:
    raw += json.load(open(f))['products']

RANGES = {'fhn': 'Feline Health Nutrition', 'fcn': 'Feline Care Nutrition', 'fbn': 'Feline Breed Nutrition', 'sup': 'Supplements'}
PACKAGING = re.compile(r' - Zone North America - (.+?)(?: - Perfect Product Page Master)?$')
# (regex on the cleaned title, subline). First match wins. Life stage is a tag, never a rung.
SUBLINES = [
    (r'^mother & babycat', 'Mother & Babycat'), (r'^kitten spayed', 'Kitten'), (r'^kitten', 'Kitten'),
    (r'instinctive', 'Instinctive'), (r'^indoor', 'Indoor'), (r'^fit and active', 'Fit & Active'),
    (r'^sensitive digestion', 'Sensitive Digestion'), (r'^fussy', 'Fussy'), (r'^spayed', 'Spayed/Neutered'),
    (r'^aging 11\+|aging 11\+$', 'Aging 11+'),
    (r'^dental care', 'Dental Care'), (r'^urinary care', 'Urinary Care'), (r'^weight care', 'Weight Care'),
    (r'^digestive care', 'Digestive Care'), (r'^hairball care', 'Hairball Care'), (r'^hair ?& ?skin care', 'Hair & Skin Care'),
    (r'^appetite control', 'Appetite Control Care'),
    (r'^persian', 'Persian'), (r'^siamese', 'Siamese'), (r'^maine coon', 'Maine Coon'), (r'^ragdoll', 'Ragdoll'),
    (r'^american shorthair', 'American Shorthair'), (r'^bengal', 'Bengal'),
    (r'^probiotics', 'Probiotics'),
]


def clean(t):
    t = re.sub(r'\s+', ' ', t).strip()
    t = re.sub(r'\s*usa only$', '', t, flags=re.I)
    return t


def texture_tags(title):
    t = title.lower()
    out = []
    for pat, keys in (('ultra soft mousse', ['texture:mousse']), ('loaf', ['texture:loaf']), ('thin slices?', ['texture:sliced']),
                      ('morsels', ['texture:morsels']), ('chunks', ['texture:chunks'])):
        if re.search(pat, t):
            out += keys
    if re.search(r'in sauce', t):
        out.append('medium:sauce')
    if re.search(r'in gravy', t):
        out.append('medium:gravy')
    return out


def stage_tags(p, t):
    ls = set(p['lifestages'])
    tl = t.lower()
    tags = []
    if re.search(r'aging 11\+', tl):
        tags.append('life_stage:senior')
    elif re.search(r'\b7\+', tl):
        tags.append('life_stage:adult-7plus')
    elif re.search(r'kitten|babycat', tl):
        tags.append('life_stage:kitten')
    elif 'adult' in tl or ls == {'adult'}:
        tags.append('life_stage:adult')
    if re.search(r'mother & babycat', tl):
        tags.append('life_stage:kitten')
    if not tags and ls >= {'baby', 'kitten', 'adult', 'mature', 'ageing'}:
        tags.append('life_stage:all')
    elif not tags and ls >= {'adult', 'mature'}:
        tags.append('life_stage:adult')   # feed says adult + mature for the general range: the adult stage (inferred)
    return sorted(set(tags))


def packaging(p):
    m = PACKAGING.search(p['name'])
    return m.group(1) if m else None


def valid_gtin(g):
    g = g.zfill(14)
    return (10 - sum(int(c) * (3 if i % 2 == 0 else 1) for i, c in enumerate(g[:13])) % 10) % 10 == int(g[13])


groups = collections.OrderedDict()
for p in raw:
    title = clean(p['analyticsData']['titleUrl'])
    key = (p['range'], re.sub(r'[^a-z0-9+]+', ' ', title.lower()).strip(), p['technology'])
    groups.setdefault(key, []).append(p)

rows, ladder = [], collections.OrderedDict()
for (rng, norm, tech), ps in groups.items():
    p0 = ps[0]
    title = clean(p0['analyticsData']['titleUrl'])
    line = RANGES.get(rng, rng)
    sub = next((s for pat, s in SUBLINES if re.search(pat, title.lower())), None)
    flags = []
    if sub is None:
        flags.append('no sub-line matched')
    if rng == 'sup':
        flags.append('supplement (powder): decide food vs treat vs excluded')
    stage = stage_tags(p0, title)
    if not stage and rng != 'sup':
        flags.append('no life stage tag')
    tags = sorted(set(stage + (texture_tags(title) if tech == 'wet' else [])))
    if stage and not re.search(r'kitten|adult|7\+|aging|babycat', title.lower()) and rng != 'sup':
        flags.append('life stage inferred from the feed (adult + mature), not named in the title')
    ft = p0['title'].lower()
    if tech == 'wet' and (('mousse' in title.lower()) != ('mousse' in ft)):
        flags.append('feed title and internal title disagree on texture (loaf vs mousse)')
    if re.search(r'\bthis slices\b', ft):
        flags.append('typo in feed title ("This Slices")')
    barcodes = []
    for p in ps:
        g = p['sku']
        if not valid_gtin(g):
            flags.append(f'invalid GTIN {g}')
        barcodes.append({'gtin': g.zfill(13) if len(g) <= 13 else g, 'pack_label': ('case (GTIN-14)' if len(g) == 14 else 'unit (UPC-12)') + ' / ' + (packaging(p) or '?'),
                         'basis': 'feed sku', 'price': p['price'], 'in_stock': p['inStock']})
    # a plain name for people: the retail title, without the "Canned Cat Food" boilerplate where the feed repeats it
    name = clean(p0['title']) if p0['title'].strip() else title
    if len(ps) > 1:
        sizes = {packaging(p) for p in ps}
        if len(sizes) > 1:
            flags.append('several pack formats (' + ', '.join(sorted(s or '?' for s in sizes)) + '): sizes not given by the feed')
    ent = ladder.setdefault(line, collections.OrderedDict())
    if sub:
        ent.setdefault(sub, 0)
        ent[sub] += 1
    gtin_single = barcodes[0]['gtin'] if len(barcodes) == 1 and len(p0['sku']) == 12 else None
    rows.append({
        'path': ['Royal Canin', line] + ([sub] if sub else []), 'name': name, 'import_key': 'royalcanin|retail|' + '|'.join(key_part for key_part in (rng, norm, tech)),
        'species': 'cat', 'kind': 'food', 'form': {'wet': 'wet', 'dry': 'dry', 'powder': 'powder'}.get(tech), 'tags': tags,
        'gtin': gtin_single, 'barcodes': barcodes,
        'description': None, 'ingredients': None, 'nutrition': None,
        'image_url': p0['image'], 'source': 'royalcanin.com products/facets feed (retail pillar)', 'source_url': None,
        'audit_status': 'unreviewed',
        'meta': {'rc_ids': [p['id'] for p in ps], 'range': rng, 'lifestages_feed': p0['lifestages'], 'breeds_feed': p0['breeds'],
                 'territory': p0['territory'], 'feed_title': p0['title'].strip(), 'feed_title_url': title,
                 'internal_names': [p['name'] for p in ps], 'price_snapshot': [p['price'] for p in ps], 'in_stock': [p['inStock'] for p in ps]},
        '_flags': sorted(set(flags)),
    })

children = []
for line, subs in ladder.items():
    n = {'name': line, 'kind': 'line', 'species': ['cat']}
    if subs:
        n['children'] = [{'name': s, 'kind': 'subline'} for s in subs]
    children.append(n)
(OUT / 'ladder.json').write_text(json.dumps({'region': 'US', 'reviewed': False, 'ladder': {'name': 'Royal Canin', 'kind': 'brand', 'children': children}}, indent=2, ensure_ascii=False) + '\n')
(OUT / 'products.json').write_text(json.dumps({'region': 'US', 'reviewed': False, 'ladder': 'royal-canin', 'brand': 'Royal Canin', 'products': rows}, indent=2, ensure_ascii=False) + '\n')
C = collections.Counter
audit = {
    'feed_rows': len(raw), 'recipes_after_grouping': len(rows), 'pages': len(pages),
    'lines': {l: dict(s) for l, s in ladder.items()}, 'flags': dict(C(f for r in rows for f in r['_flags'])),
    'zero_or_missing_price_rows': sum(1 for p in raw if p['price'] in ('0', '$0.00', 0, None)),
    'out_of_stock_rows': sum(1 for p in raw if not p['inStock']),
    'case_gtin14_rows': sum(1 for p in raw if len(p['sku']) == 14),
}
(OUT / 'audit.json').write_text(json.dumps(audit, indent=2, ensure_ascii=False) + '\n')
print(json.dumps(audit, indent=2, ensure_ascii=False))
