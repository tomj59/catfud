#!/usr/bin/env python3
"""
Turn Tiki Pets' WooCommerce Store API pages (tikipets-page*.json, from /wp-json/wc/store/v1/products?per_page=100&page=N) into draft
ladders + draft product files in the catalogue format, plus an audit.

    python3 scripts/tikipets_extract.py <dir with tikipets-page*.json> database/catalogue/drafts/tikipets

The ladder comes from the product URL path (/product/{brand}/{type}/{group}/{line}/{slug}/), which is the site's own primary
hierarchy, checked against the product categories. Writes ladder-tiki-cat.json, ladder-tiki-dog.json, products-tiki-cat.json,
products-tiki-dog.json, audit.json. Never touches database/catalogue/us/.
"""
import collections
import glob
import html
import json
import re
import sys
from pathlib import Path

SRC, OUT = Path(sys.argv[1]), Path(sys.argv[2])
OUT.mkdir(parents=True, exist_ok=True)
P = []
for f in sorted(glob.glob(str(SRC / 'tikipets-page*.json')), key=lambda f: int(re.search(r'(\d+)\.json$', f).group(1))):
    P += json.load(open(f))
H = html.unescape


def text(s):
    return re.sub(r'\s+', ' ', H(re.sub(r'<[^>]+>', ' ', s or ''))).strip()


# URL line slug -> (line, subline). The site's slugs are inconsistent with its own category names, so this is explicit data.
CAT = {
    'after-dark': ('After Dark', None), 'after-dark-pate': ('After Dark', None),
    'aloha-friends': ('Aloha Friends', None), 'friends-mousse': ('Aloha Friends', None),
    'grill': ('Grill', None), 'luau': ('Luau', None), 'velvet-mousse': ('Velvet Mousse', None),
    'kitten': ('Baby', None), 'senior-cat': ('Silver', None), 'lean-gelee': ('Gelée', None), 'mega-packs': ('Mega Packs', None),
    'born-carnivore': ('Born Carnivore', None),
    'solution-mousse': ('Solutions', 'Mousse'), 'solutions-dry': ('Solutions', 'Dry'), 'functional-toppers': ('Solutions', 'Toppers'),
    'solutions-digestion': ('Solutions', 'Supplements'), 'solutions-mobility': ('Solutions', 'Supplements'), 'solutions-skin-coat': ('Solutions', 'Supplements'),
    'comfort-liquid': ('Solutions', 'Supplements'), 'liquid-meal-replacer': ('Solutions', 'Supplements'),
    'stix': ('Stix', None), 'duets': ('Duets', None), 'soft-chewy': ('Soft & Chewy', None),
    'broth': ('Meal Toppers', 'Broths'), 'complements': ('Meal Toppers', 'Complements'), 'meal-toppers': ('Meal Toppers', None),
}
DOG = {
    'meaty': ('Savory', None), 'aloha-petites-wet-food': ('Petites Grain Free', None), 'aloha-petites-dry-dog-food': ('Born Carnivore for Dogs', None),
    'whole-foods': ('Petites Whole Foods', None), 'taste-of-the-world': ('Taste of the World', None), 'puppy': ('Puppy', None),
    'senior-dog': ('Senior', None), 'flavor-boosters': ('Flavor Boosters', None), 'protein-boosters': ('Protein Boosters', None),
    'tummy-toppers-for-dogs': ('Tummy Toppers', None),
}
STAGE_LINE = {'Baby': 'life_stage:kitten', 'Silver': 'life_stage:senior', 'Puppy': 'life_stage:kitten', 'Senior': 'life_stage:senior'}


def attr(p, name):
    return [t['name'] for a in p['attributes'] if a['name'] == name for t in a['terms']]


def tex(name, p):
    n = H(name).lower()
    tags = []
    for pat, key in ((r'\bmousse\b', 'texture:mousse'), (r'p[aâ]t[eé]', 'texture:pate'), (r'shred', 'texture:shreds'), (r'minced', 'texture:minced'),
                     (r'cutlet', 'texture:cuts'), (r'gel[eé]e', 'medium:jelly'), (r'in (?:\w+ )?broth|broths?\b', 'medium:broth'), (r'gravy', 'medium:gravy')):
        if re.search(pat, n):
            tags.append(key)
    for t in attr(p, 'Texture'):
        key = {'Shredded Meat': 'texture:shreds', 'Mousse': 'texture:mousse', 'Pate': 'texture:pate', 'Cutlets': 'texture:cuts'}.get(t)
        if key:
            tags.append(key)
    return tags


rows = {'tiki-cat': [], 'tiki-dog': []}
ladders = {'tiki-cat': collections.OrderedDict(), 'tiki-dog': collections.OrderedDict()}
for p in P:
    seg = p['permalink'].split('/product/')[1].strip('/').split('/')[:-1]
    brand_slug = seg[0] if seg else None
    root = brand_slug if brand_slug in rows else ('tiki-dog' if 'Dog' in attr(p, 'Pet') else 'tiki-cat')
    table = CAT if root == 'tiki-cat' else DOG
    lineslug = seg[-1] if len(seg) > 1 else None
    line, sub = table.get(lineslug, (None, None))
    flags = []
    if line is None:
        flags.append('no line: product sits outside the category tree' if len(seg) <= 1 else f'unmapped line slug {lineslug}')
    sku, purchasable = p['sku'], p['is_purchasable']
    if not sku:
        flags.append('no SKU on the store')
    if not purchasable or not p['prices'].get('price') or p['prices']['price'] == '0':
        flags.append('not purchasable / no price on the store')
    name = H(p['name']).strip()
    if re.fullmatch(r'(?i)variety pack', name) or re.search(r'-\d+$', p['slug']) and re.search(r'variety-pack', p['slug']):
        flags.append('generic or numbered variety pack listing')
    if 'variety' in name.lower() or lineslug == 'mega-packs':
        flags.append('variety pack / multi-recipe pack')
    if re.search(r'-copy$', p['slug']):
        flags.append('slug ends in -copy (duplicated listing)')
    if 'new look' in [t['name'].lower() for t in p['tags']]:
        flags.append('tagged "new look" (may duplicate an older listing)')
    pet = attr(p, 'Pet')
    species = 'dog' if (root == 'tiki-dog' or pet == ['Dog']) else 'cat'
    fp = attr(p, 'Food Preference')
    kind = 'treat' if ('Treat' in fp or (seg[1:2] == ['tiki-cat-treats'])) else 'food'
    form = 'dry' if ('Dry Food' in fp or 'dry-food' in p['permalink']) else ('wet' if ('Wet Food' in fp or 'wet-food' in p['permalink'] or 'Meal Topper' in fp or line in ('Meal Toppers',)) else None)
    ls = {'All Life Stages': 'life_stage:all', 'Senior': 'life_stage:senior', 'Kitten': 'life_stage:kitten'}
    stage = [ls[t] for t in attr(p, 'Life Stage') if t in ls] or ([STAGE_LINE[line]] if line in STAGE_LINE else [])
    if not stage and kind == 'food':
        flags.append('no life stage tag')
    tags = sorted(set(stage + tex(name, p)))
    if line:
        ent = ladders[root].setdefault(line, collections.OrderedDict())
        if sub:
            ent.setdefault(sub, 0)
    rows[root].append({
        'path': ['Tiki Cat' if root == 'tiki-cat' else 'Tiki Dog'] + ([line] if line else []) + ([sub] if sub else []),
        'name': name, 'import_key': 'tikipets|woo|' + p['slug'], 'species': species, 'kind': kind, 'form': form, 'tags': tags,
        'gtin': None, 'description': text(p['short_description']) or None, 'ingredients': None, 'nutrition': None,
        'image_url': p['images'][0]['src'] if p['images'] else None, 'source': 'tikipets.com (WooCommerce Store API)', 'source_url': p['permalink'],
        'audit_status': 'unreviewed',
        'meta': {'woo_id': p['id'], 'sku': sku or None, 'url_path': seg, 'categories': [H(c['name']) for c in p['categories']],
                 'key_ingredients': attr(p, 'Ingredients') or None, 'claims': [t for t in attr(p, 'Texture') if t in ('Grain-Free', 'High Protein')] or None,
                 'special_needs': attr(p, 'Special Needs') or None, 'images': len(p['images']), 'wc_tags': [t['name'] for t in p['tags']] or None},
        '_flags': flags,
    })

C = collections.Counter
for v in rows.values():
    dn = C((tuple(r['path']), r['name']) for r in v)
    for r in v:
        if dn[(tuple(r['path']), r['name'])] > 1:
            r['_flags'].append(f"name repeated {dn[(tuple(r['path']), r['name'])]}x in this line: tell apart by import_key/url")
audit = {'products': len(P), 'by_root': {k: len(v) for k, v in rows.items()}, 'lines': {r: {l: list(s) for l, s in ladders[r].items()} for r in ladders},
         'flags': dict(C(f for v in rows.values() for r in v for f in r['_flags'])),
         'line_counts': {r: dict(C(x['path'][1] if len(x['path']) > 1 else '(none)' for x in rows[r])) for r in rows},
         'with_sku': sum(1 for p in P if p['sku']), 'purchasable': sum(1 for p in P if p['is_purchasable']),
         'short_description': sum(1 for p in P if p['short_description'].strip()), 'long_description': sum(1 for p in P if p['description'].strip()),
         'with_images': sum(1 for p in P if p['images']), 'clean_candidates': sum(1 for v in rows.values() for r in v if not any(('not purchasable' in f or 'generic or numbered' in f or 'repeated' in f) for f in r['_flags']))}
for root in rows:
    name = 'Tiki Cat' if root == 'tiki-cat' else 'Tiki Dog'
    kids = []
    for line, subs in ladders[root].items():
        n = {'name': line, 'kind': 'line', 'species': ['cat' if root == 'tiki-cat' else 'dog']}
        if subs:
            n['children'] = [{'name': s, 'kind': 'subline'} for s in subs]
        kids.append(n)
    (OUT / f'ladder-{root}.json').write_text(json.dumps({'region': 'US', 'reviewed': False, 'ladder': {'name': name, 'kind': 'brand', 'children': kids}}, indent=2, ensure_ascii=False) + '\n')
    (OUT / f'products-{root}.json').write_text(json.dumps({'region': 'US', 'reviewed': False, 'ladder': root, 'brand': name, 'products': rows[root]}, indent=2, ensure_ascii=False) + '\n')
(OUT / 'audit.json').write_text(json.dumps(audit, indent=2, ensure_ascii=False) + '\n')
print(json.dumps(audit, indent=2, ensure_ascii=False))
