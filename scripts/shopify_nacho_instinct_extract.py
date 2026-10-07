#!/usr/bin/env python3
"""
Turn Shopify products.json dumps for Made By Nacho (nacho.json) and Instinct (instinct.json) into draft ladders and draft
product files in the catalogue format, plus an audit.

    python3 scripts/shopify_nacho_instinct_extract.py <dir with nacho.json and instinct.json> database/catalogue/drafts

Writes drafts/nacho/{ladder,products,audit}.json and drafts/instinct/... . Never touches database/catalogue/us/.
Neither store has a "line" concept in its data: lines are product families read from titles and tags (explicit rules below).
"""
import collections
import html
import json
import re
import sys
from pathlib import Path

SRC, OUT = Path(sys.argv[1]), Path(sys.argv[2])
H = html.unescape
C = collections.Counter


def text(s):
    return re.sub(r'\s+', ' ', H(re.sub(r'<[^>]+>', ' ', s or ''))).strip()


def write(name, root, products, rows, ladder, audit):
    d = OUT / name
    d.mkdir(parents=True, exist_ok=True)
    kids = []
    for line, (species, subs) in ladder.items():
        n = {'name': line, 'kind': 'line', 'species': sorted(species)}
        if subs:
            n['children'] = [{'name': s, 'kind': 'subline'} for s in subs]
        kids.append(n)
    (d / 'ladder.json').write_text(json.dumps({'region': 'US', 'reviewed': False, 'ladder': {'name': root, 'kind': 'brand', 'children': kids}}, indent=2, ensure_ascii=False) + '\n')
    (d / 'products.json').write_text(json.dumps({'region': 'US', 'reviewed': False, 'ladder': name, 'brand': root, 'products': rows}, indent=2, ensure_ascii=False) + '\n')
    for r in rows:
        pass
    flags = C(f for r in rows for f in r['_flags'])
    audit.update({'drafted': len(rows), 'lines': {l: {'products': sum(1 for r in rows if len(r['path']) > 1 and r['path'][1] == l), 'sublines': list(s)} for l, (sp, s) in ladder.items()},
                  'flags': dict(flags), 'unplaced': [r['name'] for r in rows if len(r['path']) == 1],
                  'species': dict(C(r['species'] for r in rows)), 'with_description': sum(1 for r in rows if r['description']),
                  'with_image': sum(1 for r in rows if r['image_url']), 'tag_coverage': {
                      'texture_or_medium': sum(1 for r in rows if any(t.startswith(('texture', 'medium')) for t in r['tags'])),
                      'life_stage': sum(1 for r in rows if any(t.startswith('life_stage') for t in r['tags']))}})
    (d / 'audit.json').write_text(json.dumps(audit, indent=2, ensure_ascii=False) + '\n')
    return audit


def sizes(p):
    return [v['title'] for v in p['variants']]


# ---------------------------------------------------------------- Nacho
def nacho():
    P = json.load(open(SRC / 'nacho.json'))['products']
    skip, rows, ladder = [], [], collections.OrderedDict()
    for p in P:
        t = H(p['title'])
        if re.search(r'gift card|starter kit|hydration kit|postcard', t, re.I) or 'hidden-product' in p['tags']:
            skip.append(t)
            continue
        tl = t.lower()
        sub = None
        if 'lickable mousse' in tl:
            line = 'Lickable Mousse Treats'
        elif 'freeze-dried' in tl:
            line = 'Freeze-Dried'
        elif 'topper' in tl or 'bone broth topper' in tl:
            line = 'Toppers'
            sub = 'Bone Broth' if 'bone broth' in tl else 'Functional'
        elif 'kibble' in tl:
            line = 'Kibble'
        elif 'cuts in gravy' in tl:
            line = 'Cuts in Gravy'
        elif 'flaked' in tl and 'minced' not in tl:
            line = 'Flaked'
        elif re.search(r'minced|chef.s mince', tl):
            line = 'Minced'
        elif re.search(r'p[aâ]t[eé]', tl):
            line = 'Pâté'
        elif 'shreds' in tl:
            line = 'Shreds'
        else:
            line = None
        flags = []
        if line is None:
            flags.append('no line matched')
        tags = {x for x in p['tags']}
        out = set()
        stage = [k for k, v in (('Lifestage:Kitten', 'life_stage:kitten'), ('Lifestage:Senior', 'life_stage:senior'), ('Lifestage:Adult', 'life_stage:adult')) if k in tags]
        if 'kitten' in tl:
            stage.append('life_stage:kitten') if 'Lifestage:Kitten' not in tags else None
            stage = [s for s in stage if s != 'life_stage:adult']
        stage = [{'Lifestage:Kitten': 'life_stage:kitten', 'Lifestage:Senior': 'life_stage:senior', 'Lifestage:Adult': 'life_stage:adult'}.get(s, s) for s in stage]
        if not stage:
            flags.append('no life stage tag')
        if 'Lifestage:Senior' in tags and 'Lifestage:Adult' in tags:
            flags.append('adult and senior both tagged')
        # texture/medium come from the title (the Texture:* tags are inconsistent: kitten pâté tagged "Minced & flaked")
        for pat, key in ((r'p[aâ]t[eé]', 'texture:pate'), (r'flaked', 'texture:flaked'), (r'minced|mince\b', 'texture:minced'), (r'cuts in gravy', 'texture:cuts'),
                         (r'shreds', 'texture:shreds'), (r'mousse', 'texture:mousse'), (r'in gravy', 'medium:gravy'), (r'bone broth', 'medium:broth')):
            if re.search(pat, tl):
                out.add(key)
        kind = 'treat' if ('treat' in tl and 'topper' not in tl.replace('treats', '')) or 'product-type:Treats' in tags else 'food'
        form = 'dry' if 'kibble' in tl else ('freeze-dried' if 'freeze-dried' in tl else 'wet')
        variety = 'pack-type:Variety Pack' in tags or 'variety' in tl
        if variety:
            flags.append('variety pack: several recipes in one product')
        if 'Faire' in tags and not p['product_type']:
            flags.append('no product_type on the store')
        if 'Treat or Topper' in tags or (kind == 'food' and line == 'Freeze-Dried'):
            flags.append('treat vs topper: decide kind')
        if line:
            ent = ladder.setdefault(line, (set(), collections.OrderedDict()))
            ent[0].add('cat')
            if sub:
                ent[1].setdefault(sub, 0)
        rows.append({
            'path': ['Nacho'] + ([line] if line else []) + ([sub] if sub else []), 'name': t, 'import_key': 'nacho|shopify|' + p['handle'],
            'species': 'cat', 'kind': kind, 'form': form, 'tags': sorted(set(stage) | out), 'gtin': None,
            'description': text(p['body_html']) or None, 'ingredients': None, 'nutrition': None,
            'image_url': p['images'][0]['src'].split('?')[0] if p['images'] else None,
            'source': 'madebynacho.com (Shopify export)', 'source_url': 'https://madebynacho.com/products/' + p['handle'], 'audit_status': 'unreviewed',
            'meta': {'shopify_id': p['id'], 'product_type': p['product_type'] or None, 'variants': [{'sku': v['sku'] or None, 'title': v['title'], 'price': v['price'], 'available': v['available']} for v in p['variants']],
                     'shopify_tags': p['tags'], 'proteins': [x.split(':', 1)[1] for x in p['tags'] if x.startswith('Protein:')] or None,
                     'diet': [x.split(':', 1)[1] for x in p['tags'] if x.startswith('Diet:')] or None, 'benefits': [x.split(':', 1)[1] for x in p['tags'] if x.startswith('Benefit:')] or None},
            '_flags': flags,
        })
    return write('nacho', 'Nacho', P, rows, ladder, {'dump_products': len(P), 'excluded': skip})


# ---------------------------------------------------------------- Instinct
LINES = [
    (r'raw ?boost|rawboost', 'Raw Boost'), (r'peakboost', 'PeakBoost+'), (r'freshraw', 'FreshRaw'), (r'freshdried|freeze-dried raw', 'FreshDried'),
    (r'freshly crafted', 'Freshly Crafted Meals'), (r'limited ingredient', 'Limited Ingredient Diet'), (r'ultimate protein', 'Ultimate Protein'),
    (r'healthy cravings', 'Healthy Cravings'), (r'be ?natural', 'Be Natural'), (r'bone broth', 'Bone Broth'), (r'^duos\b', 'Duos'),
    (r'^original|flaked .*entr.e|minced cups|split cups', 'Original'),
]
SUBS = {'Raw Boost': [(r'mixers', 'Mixers'), (r'shakers', 'Shakers'), (r'kibble', 'Kibble')],
        'FreshDried': [(r'pat[eé]s', 'Patés'), (r'meal blends', 'Meal Blends'), (r'raw meals', 'Raw Meals')],
        'FreshRaw': [(r'medallions', 'Medallions'), (r'bites', 'Bites'), (r'patties', 'Patties'), (r'meals', 'Meals')]}


def instinct():
    P = json.load(open(SRC / 'instinct.json'))['products']
    rows, ladder, parents = [], collections.OrderedDict(), []
    for p in P:
        t = H(p['title'])
        tags = set(p['tags'])
        if 'combined listing parent' in tags:
            parents.append(t)
            continue
        tl, hl = t.lower(), p['handle'].lower()
        line = next((l for pat, l in LINES if re.search(pat, tl)), None)
        sub = next((s for pat, s in SUBS.get(line, []) if re.search(pat, tl)), None)
        flags = []
        if line is None:
            flags.append('no line matched')
        elif line == 'Original' and not tl.startswith('original'):
            flags.append('Original assumed (title names a format, not the line)')
        sp = 'cat' if ('cat' in tags and 'dog' not in tags) else 'dog' if ('dog' in tags and 'cat' not in tags) else None
        if sp is None:
            if re.search(r'kitten|\bcats?\b|for cats', tl + ' ' + hl):
                sp = 'cat'
            elif re.search(r'puppy|puppies|\bdogs?\b|small breed|for dogs', tl + ' ' + hl):
                sp = 'dog'
            else:
                flags.append('species not derivable')
        stage = []
        if 'kitten' in tags or 'kitten' in tl:
            stage.append('life_stage:kitten')
        elif 'puppy' in tags or 'puppies' in tl or 'puppy' in tl:
            flags.append('puppy stage: not in the life-stage vocabulary')
        elif 'adult' in tags and 'senior' in tags:
            stage += ['life_stage:adult', 'life_stage:senior']
        elif 'adult' in tags:
            stage.append('life_stage:adult')
        elif 'senior' in tags:
            stage.append('life_stage:senior')
        if not stage and not any('puppy' in f for f in flags):
            flags.append('no life stage tag')
        out = set()
        for tg, key in (('pate', 'texture:pate'), ('flaked', 'texture:flaked'), ('minced', 'texture:minced'), ('broth', 'medium:broth')):
            if tg in tags:
                out.add(key)
        form = 'freeze-dried' if 'freeze-dried' in tags else 'frozen' if 'frozen' in tags else 'dry' if 'dry' in tags else 'wet' if 'wet' in tags else None
        if form is None:
            flags.append('form not derivable')
        kind = 'treat' if ('treats' in tags and 'toppers' not in tags) else 'food'
        if 'toppers' in tags or 'mixers' in tags:
            flags.append('topper/mixer: decide kind (food vs treat vs supplement)')
        if 'variety' in tl or 'sampler' in tl:
            flags.append('variety pack: several recipes in one product')
        if line:
            ent = ladder.setdefault(line, (set(), collections.OrderedDict()))
            if sp:
                ent[0].add(sp)
            if sub:
                ent[1].setdefault(sub, 0)
        vs = [v['title'] for v in p['variants']]
        rows.append({
            'path': ['Instinct'] + ([line] if line else []) + ([sub] if sub else []), 'name': t, 'import_key': 'instinct|shopify|' + p['handle'],
            'species': sp or 'cat', 'kind': kind, 'form': form, 'tags': sorted(set(stage) | out), 'gtin': None,
            'description': text(p['body_html']) or None, 'ingredients': None, 'nutrition': None,
            'image_url': p['images'][0]['src'].split('?')[0] if p['images'] else None,
            'source': 'instinctpetfood.com (Shopify export)', 'source_url': 'https://instinctpetfood.com/products/' + p['handle'], 'audit_status': 'unreviewed',
            'meta': {'shopify_id': p['id'], 'vendor': p['vendor'], 'variants': [{'sku': v['sku'] or None, 'title': v['title'], 'price': v['price'], 'available': v['available']} for v in p['variants']],
                     'shopify_tags': p['tags'], 'grain_free': 'grain-free' in tags or None},
            '_flags': flags,
        })
    return write('instinct', 'Instinct', P, rows, ladder, {'dump_products': len(P), 'combined_listing_parents_skipped': len(parents), 'parents': parents})


for fn in (nacho, instinct):
    a = fn()
    print(json.dumps({k: v for k, v in a.items() if k not in ('parents', 'excluded')}, indent=1, ensure_ascii=False))
