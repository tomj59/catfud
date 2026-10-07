#!/usr/bin/env python3
"""
Turn the saved purina.com cat-food search pages (purina-page1..N.json, from /api/search/products) into a draft ladder and draft
product files in the catalogue format, plus an audit.

    python3 scripts/purina_extract.py <dir with purina-pageN.json> database/catalogue/us/ladders/purina.json database/catalogue/drafts/purina

Each page holds result.serverData.productData.search_results. The listing has no brand or line field, so both are read from the
product's URL slug and title with the explicit rule tables below (nothing else is guessed). Writes into the output dir:
ladder-purina.json (the current Purina ladder plus every rung a product needs), products-<brand>.json, audit.json.
Never touches database/catalogue/us/.
"""
import collections
import glob
import html
import json
import re
import sys
from pathlib import Path

SRC, BASE_LADDER, OUT = Path(sys.argv[1]), Path(sys.argv[2]), Path(sys.argv[3])
SITE = 'https://www.purina.com'
C = collections.Counter

# ---------------------------------------------------------------- reading
rows = []
for f in sorted(glob.glob(str(SRC / 'purina-page*.json')), key=lambda p: int(re.search(r'page(\d+)', p).group(1))):
    rows += json.load(open(f))['result']['serverData']['productData']['search_results']


def text(s):
    s = re.sub(r'<[^>]+>', ' ', s or '')
    return re.sub(r'\s+', ' ', html.unescape(s).replace('\xa0', ' ')).strip()


def clean_title(t):
    return re.sub(r'\s+', ' ', re.sub(r'[®™]', '', html.unescape(t))).strip()


# ---------------------------------------------------------------- brand and line rules
# (brand slug, rung name, aliases, root-relative: brand rungs sit directly under Purina)
BRANDS = [  # first match wins; tested against the lower-cased title and slug
    ('pro-plan-veterinary-diets', 'Pro Plan Veterinary Diets', ['veterinary diets']),
    ('pro-plan', 'Pro Plan', ['pro plan']),
    ('fancy-feast', 'Fancy Feast', ['fancy feast']),
    ('friskies', 'Friskies', ['friskies']),
    ('purina-one', 'Purina ONE', ['purina one']),
    ('cat-chow', 'Cat Chow', ['cat chow', 'kitten chow']),
    ('kit-kaboodle', 'Kit & Kaboodle', ['kit & kaboodle', 'kit and kaboodle', 'kit-and-kaboodle']),
]

# line rules per brand: (rung name, [words, any of which in lower-cased "slug title"]), first match wins
LINES = {
    'fancy-feast': [
        ('Dry', ['dry cat food']),
        ('Gravy Lovers', ['gravy lovers', 'gravy-lovers']), ('Medleys', ['medleys']), ('Gourmet Naturals', ['gourmet naturals', 'gourmet-naturals']),
        ('Delights with Cheddar', ['delights with cheddar', 'delights-with-cheddar']), ('Savory Centers', ['savory centers', 'savory-centers']),
        ('Petites', ['petites']), ('Gems', ['gems']), ('Filet Mignon', ['filet mignon', 'filet-mignon']), ('Feastivities', ['feastivities']),
        ('Classic', ['classic', 'timeless favorites', 'timeless-favorites', 'grilled', 'chunky', 'flaked', 'kitten', 'senior']),
    ],
    'friskies': [
        ('Extra Gravy', ['extra gravy']), ('Shreds', ['shreds']), ('Prime Filets', ['prime filets']), ('Meaty Bits', ['meaty bits']),
        ('Farm Favorites', ['farm favorites']), ('Tasty Treasures', ['tasty treasures']), ("Surfin' & Turfin'", ['surfin']),
        ("Glaz'd & Infuz'd", ['glaz', 'infuz']), ('Wild Favorites', ['wild favorites']), ('Gravy Sensations', ['gravy sensations']),
        ("Gravy Swirl'd", ['gravy swirl']), ('Ocean Favorites', ['ocean favorites']), ("Tender & Crunchy", ['tender crunch', 'tender-crunch']),
        ('Seafood Sensations', ['seafood sensations']), ('Indoor', ['indoor']), ('Oceans of Delight', ['oceans of delight']),
        ("Fully Load'd", ["fully load"]), ('Fish-a-Licious', ['fish a licious', 'fish-a-licious']), ('Land & Sea Adventures', ['land & sea']),
        ("Party Pack'd", ["party pack"]), ("Lil' Soups", ["lil' soups", 'lil soups']),
        ('Classic Paté', ['pate', 'paté', 'dinner', 'mixed grill', 'poultry platter', "mariner", 'country style', "sea captain"]),
    ],
    'pro-plan': [
        ('AdvantEDGE', ['advantedge']), ('LiveClear', ['liveclear']), ('Prime Plus', ['prime plus']), ('Development', ['development']),
        ('Indoor + Hairball', ['indoor + hairball']), ('Hairball', ['hairball']), ('Indoor Balance', ['indoor balance']),
        ('Urinary Tract Health', ['urinary tract']), ('Sensitive Skin & Stomach', ['sensitive skin']), ('Weight Management', ['weight management']),
        ('Healthy Metabolism', ['healthy metabolism']), ('Senior 11+', ['11+']), ('All Life Stages', ['all life stages']),
        ('Complete Essentials', ['complete essentials']),
    ],
    'purina-one': [
        ('+Plus', ['+plus', ' one plus']), ('LiveClear', ['liveclear']), ('Tender Selects', ['tender selects']), ('True Instinct', ['true instinct']),
        ('Grain Free', ['grain free']), ('Ideal Weight', ['ideal weight']), ('Urinary Tract', ['urinary tract']),
        ('Indoor+Hairball', ['indoor + hairball']), ('Indoor Advantage', ['indoor advantage']), ('Natural High Protein', ['natural']),
        ('Healthy Kitten', ['healthy kitten']),
    ],
    'cat-chow': [
        ('Kitten Chow', ['kitten chow']), ('Naturals', ['naturals']), ('Complete', ['complete']), ('Gentle', ['gentle']), ('Indoor', ['indoor']),
        ('Healthy Aging', ['healthy aging']),
    ],
    'kit-kaboodle': [],
    'pro-plan-veterinary-diets': [],
}
VET_CODES = {'EN': 'EN Gastroenteric', 'UR': 'UR Urinary St/Ox', 'DM': 'DM Dietetic Management', 'NF': 'NF Kidney Function', 'HA': 'HA Hydrolyzed'}

BRAND_PREFIXES = ['Pro Plan Veterinary Diets', 'Fancy Feast', 'Friskies', 'Purina ONE', 'Cat Chow', 'Kit & Kaboodle', 'Pro Plan']


def brand_of(title, slug):
    t = (title + ' ' + slug.replace('-', ' ')).lower()
    for key, name, words in BRANDS:
        if any(w in t for w in words):
            return key
    return None


def line_of(brand, title, slug):
    if brand == 'pro-plan-veterinary-diets':
        m = re.search(r'\b(EN|UR|DM|NF|HA)\b', title)
        return VET_CODES[m.group(1)] if m else None
    t = ' ' + (title + ' ' + slug.replace('-', ' ')).lower().replace('’', "'")
    t2 = t + ' ' + slug.lower()
    for name, words in LINES.get(brand, []):
        if any(w in t or w in t2 for w in words):
            return name
    return None


def short_name(title, brand, line):
    s = clean_title(title)
    s = re.sub(r'^Purina\s+', '', s)
    for p in BRAND_PREFIXES:
        if s.lower().startswith(p.lower() + ' '):
            s = s[len(p) + 1:]
            break
    if line and s.lower().startswith(line.lower() + ' '):
        s = s[len(line) + 1:]
    keep = s
    s = re.sub(r'\s*with Added Vitamins, Minerals and Nutrients\s*', ' ', s, flags=re.I)
    s = re.sub(r'\s+(Wet|Dry)\s+Cat\s+Food\b', '', s)
    s = re.sub(r'\bCat\s+Food\b', '', s) if len(s) > 30 else s
    s = re.sub(r'\s+', ' ', s).strip(' ,-–')
    # a name that shrinks to a bare word ("Formula") keeps the wet/dry wording so two products do not read the same
    return s if len(s) >= 15 else (re.sub(r'\s+', ' ', keep).strip(' ,-–') or clean_title(title))


# ---------------------------------------------------------------- tags
def tags_for(title, form, brand, flags):
    t = title.lower()
    out = []
    if '7+' in t or 'prime plus' in t:
        out.append('life_stage:adult-7plus')
    elif '11+' in t:
        out.append('life_stage:senior')
    elif 'kitten' in t or 'development' in t:
        out.append('life_stage:kitten')
    elif 'all life stages' in t:
        out.append('life_stage:all')
    elif 'senior' in t:
        out.append('life_stage:senior')
    elif 'adult' in t:
        out.append('life_stage:adult')
    if form == 'wet':
        if re.search(r'in a savory sauce|in sauce|\bsauce\b', t):
            out.append('medium:sauce')
        if 'gravy' in t:
            out.append('medium:gravy')
        if 'broth' in t:
            out.append('medium:broth')
        tex = []
        if re.search(r'pat[eé]', t):
            tex.append('pate')
        if 'mousse' in t:
            tex.append('mousse')
        if re.search(r'shred', t):
            tex.append('shreds')
        if re.search(r'chunky|chunks', t):
            tex.append('chunks')
        if 'flaked' in t:
            tex.append('flaked')
        if 'minced' in t:
            tex.append('minced')
        if 'morsels' in t:
            tex.append('morsels')
        if 'sliced' in t:
            tex.append('sliced')
        if not tex and brand == 'pro-plan' and 'classic' in t:
            tex.append('pate')
            flags.append('paté inferred from "Classic" (Pro Plan Classic is its paté texture)')
        out += ['texture:' + x for x in tex]
        if not any(x.startswith(('texture', 'medium')) for x in out) and 'variety' not in t:
            flags.append('no texture or medium tag')
    if brand == 'pro-plan-veterinary-diets':
        out.append('diet:clinical')
    if not any(x.startswith('life_stage') for x in out):
        flags.append('no life stage tag')
    return sorted(set(out))


# ---------------------------------------------------------------- barcodes
def check_ok(code):
    d = [int(c) for c in code]
    s = sum(x * (3 if i % 2 == 0 else 1) for i, x in enumerate(reversed(d[:-1])))
    return (10 - s % 10) % 10 == d[-1]


def pack_label(v):
    q, size, unit = int(float(v.get('item_quantity') or 1)), float(v.get('item_size') or 0), (v.get('item_description') or '').lower()
    if unit == 'count':
        return f'{size:g}-count pack'
    short = {'pound(s)': 'lb', 'ounce(s)': 'oz'}.get(unit, unit)
    shape = 'bag' if short == 'lb' else 'can'
    one = f'{size:g} {short} {shape}'
    return f'{q} × {one}' if q > 1 else one


# ---------------------------------------------------------------- build
products = collections.defaultdict(list)
audit = {'pages': len(glob.glob(str(SRC / 'purina-page*.json'))), 'listed': len(rows), 'unique_nid': len({r['nid'] for r in rows})}
code_owner = collections.defaultdict(list)
unplaced_brand = []
for r in rows:
    title, slug = clean_title(r['title']), r['url'].rstrip('/').split('/')[-1]
    brand = brand_of(title, slug)
    if not brand:
        unplaced_brand.append(title)
        continue
    line = line_of(brand, title, slug)
    flags = []
    variants = r.get('product_variations') or []
    units = {(v.get('item_description') or '').lower() for v in variants}
    tl = title.lower()
    if re.search(r'\bdry\b', tl):
        form = 'dry'
    elif re.search(r'\bwet\b', tl):
        form = 'wet'
    else:
        form = 'dry' if 'pound(s)' in units and 'ounce(s)' not in units else 'wet'
    bundle = r.get('type') == 'product_bundle'
    if bundle:
        flags.append('variety pack / bundle: decide how these are treated')
    if not line and brand not in ('kit-kaboodle',):
        flags.append('no line matched: placed on the brand')

    barcodes, bad = [], []
    for v in variants:
        raw = re.sub(r'\D', '', v.get('upc_code') or '')
        if not raw:
            continue
        if len(raw) == 12 and check_ok(raw):
            g = '0' + raw
            barcodes.append({'gtin': g, 'pack_label': pack_label(v)})
            code_owner[g].append(r['nid'])
        else:
            bad.append(raw)
    labels = ' '.join(b['pack_label'] for b in barcodes)
    if (form == 'dry' and ' can' in labels) or (form == 'wet' and ' bag' in labels):
        flags.append('pack sizes mix cans and bags (site data): check which sizes really belong')
    if bad:
        flags.append('invalid barcode(s) dropped: ' + ', '.join(bad))
    if not variants:
        flags.append('no pack sizes listed')

    status = [s['name'] for s in (r.get('product_status') or [])]
    if 'Discontinued' in status:
        flags.append('site marks this Discontinued')
    path = ['Purina', {'pro-plan-veterinary-diets': 'Pro Plan Veterinary Diets', 'pro-plan': 'Pro Plan', 'fancy-feast': 'Fancy Feast', 'friskies': 'Friskies',
                       'purina-one': 'Purina ONE', 'cat-chow': 'Cat Chow', 'kit-kaboodle': 'Kit & Kaboodle'}[brand]] + ([line] if line else [])
    row = {
        'path': path, 'name': short_name(title, brand, line), 'title_as_listed': title, 'import_key': 'purina|site|' + str(r['nid']),
        'species': 'cat', 'kind': 'food', 'form': form, 'tags': tags_for(title, form, brand, flags),
        'gtin': barcodes[0]['gtin'] if len(barcodes) == 1 else None, 'barcodes': barcodes,
        'description': text(r.get('description')) or None, 'ingredients': None, 'nutrition': None,
        'image_url': SITE + r['product_image'] if r.get('product_image') else None,
        'source': 'purina.com (site search export)', 'source_url': SITE + r['url'], 'audit_status': 'unreviewed',
        'meta': {'nid': r['nid'], 'bazaarvoice_id': r.get('bazaarvoice_id'), 'package_type': 'variety_pack' if bundle else 'single',
                 'rating': r.get('rating'), 'review_count': r.get('number_of_reviews'), 'site_status': status or None,
                 'upc_site': r.get('upc')},
        '_flags': flags,
    }
    row['meta'] = {k: v for k, v in row['meta'].items() if v not in (None, [], '')}
    products[brand].append(row)

# a code printed on two products stays on both in the file (the importer reports the clash and keeps the first)
dups = {g: n for g, n in code_owner.items() if len(set(n)) > 1}

# ---------------------------------------------------------------- ladder: current Purina ladder plus the rungs the products need
base = json.load(open(BASE_LADDER))
root = base['ladder']
kids = {c['name'].lower(): c for c in root.get('children', [])}
added = []
for key, name, _ in BRANDS:
    used = products.get(key)
    if not used:
        continue
    node = kids.get(name.lower())
    if not node:
        node = {'name': name, 'kind': 'brand'}
        if key == 'pro-plan-veterinary-diets':
            node['aliases'] = ['Purina Pro Plan Veterinary Diets']
            node['default_tags'] = ['diet:clinical']
        root['children'].append(node)
        kids[name.lower()] = node
        added.append(name)
    have = {c['name'].lower() for c in node.get('children', [])}
    for lname in sorted({r['path'][2] for r in used if len(r['path']) > 2}, key=lambda s: s.lower()):
        if lname.lower() not in have:
            node.setdefault('children', []).append({'name': lname, 'kind': 'line', 'species': ['cat']})
            added.append(f'{name} > {lname}')
            have.add(lname.lower())
classic = next((c for c in kids['fancy feast'].get('children', []) if c['name'] == 'Classic'), None)
if classic and 'Timeless Favorites' not in classic.get('aliases', []):
    classic['aliases'] = classic.get('aliases', []) + ['Timeless Favorites']

OUT.mkdir(parents=True, exist_ok=True)
(OUT / 'ladder-purina.json').write_text(json.dumps(base, indent=2, ensure_ascii=False) + '\n')
for key, name, _ in BRANDS:
    if products.get(key):
        (OUT / f'products-{key}.json').write_text(json.dumps({'region': 'US', 'reviewed': False, 'ladder': 'purina', 'brand': name, 'products': products[key]}, indent=2, ensure_ascii=False) + '\n')

allrows = [p for v in products.values() for p in v]
names = C((tuple(p['path']), p['name'].lower()) for p in allrows)
audit.update({
    'drafted': len(allrows), 'unplaced_brand': unplaced_brand,
    'by_brand': {k: len(v) for k, v in products.items()},
    'by_line': {k: dict(C(p['path'][2] if len(p['path']) > 2 else '(brand level)' for p in v)) for k, v in products.items()},
    'flags': dict(C(f.split(':')[0] for p in allrows for f in p['_flags'])),
    'form': dict(C(p['form'] for p in allrows)), 'variety_packs': sum(1 for p in allrows if p['meta'].get('package_type') == 'variety_pack'),
    'with_gtin': sum(1 for p in allrows if p['gtin']), 'with_any_barcode': sum(1 for p in allrows if p['barcodes']),
    'barcodes_total': sum(len(p['barcodes']) for p in allrows), 'barcodes_shared_between_products': {g: n for g, n in dups.items()},
    'with_description': sum(1 for p in allrows if p['description']), 'with_image': sum(1 for p in allrows if p['image_url']),
    'with_ingredients': 0, 'with_nutrition': 0,
    'life_stage_tagged': sum(1 for p in allrows if any(t.startswith('life_stage') for t in p['tags'])),
    'texture_or_medium_tagged_wet': sum(1 for p in allrows if p['form'] == 'wet' and any(t.startswith(('texture', 'medium')) for t in p['tags'])),
    'wet': sum(1 for p in allrows if p['form'] == 'wet'),
    'duplicate_name_groups_on_same_path': sum(1 for n in names.values() if n > 1),
    'rungs_added': added,
    'brand_level_products': [p['title_as_listed'] for p in allrows if len(p['path']) == 2],
})
(OUT / 'audit.json').write_text(json.dumps(audit, indent=2, ensure_ascii=False) + '\n')
print(json.dumps({k: v for k, v in audit.items() if k not in ('by_line', 'rungs_added', 'brand_level_products', 'barcodes_shared_between_products')}, indent=1, ensure_ascii=False))
