#!/usr/bin/env python3
"""
Turn a Shopify products.json dump (Weruva's store) into a draft ladder + draft product file in the catalogue format,
and an audit of what the dump can and cannot give us.

    python3 scripts/weruva_shopify_extract.py weruva-products.json database/catalogue/drafts/weruva-shopify

Writes ladder.json, products.json, excluded.json and audit.json into the output directory. It never touches
database/catalogue/us/. The output is a DRAFT (reviewed:false): read it, fix the rules or the data, then move it in.
"""
import collections
import html
import json
import re
import sys
from pathlib import Path

SRC = Path(sys.argv[1])
OUT = Path(sys.argv[2])
OUT.mkdir(parents=True, exist_ok=True)
products = json.loads(SRC.read_text())['products']

ROOT = 'Weruva'
NONFOOD = {'Cat Toy', 'Cat Litter', 'Food Storage', 'Bowl', 'Pet Bed'}


def has(p, *tags):
    return any(t in p['tags'] for t in tags)


def species_of(p):
    h = p['handle']
    v = p['vendor']
    if v in ('Dogs in the Kitchen', 'BFF Best Fido Friend', 'Awesome Functions'):
        return 'dog'
    if v in ('Cat Person', 'TruLuxe', 'Cats in the Kitchen', 'BFF Best Feline Friend'):
        return 'cat'
    c, d = has(p, 'Cat', 'Weruva Cat', 'Low Phos Cat'), has(p, 'Dog', 'Low Phos Dog', 'Weruva Classic Dog')
    if re.search(r'(^|-)cat(-|s-|$)', h) and not re.search(r'(^|-)dog(s)?(-|$)', h):
        return 'cat'
    if re.search(r'(^|-)dog(s)?(-|$)', h) and not re.search(r'(^|-)cat(-|$)', h):
        return 'dog'
    if c and not d:
        return 'cat'
    if d and not c:
        return 'dog'
    return None


def line_of(p, sp):
    """(line, subline|None, why). The line is the sub-brand a shopper would recognise; the subline is a named range inside it."""
    v, h, t = p['vendor'], p['handle'], p['tags']
    if v == 'BFF Best Feline Friend':
        for tag, name in (('BFF OMG Gravy', 'OMG Gravy!'), ('BFF PLAY Paté', 'PLAY Paté!'), ('BFF Minced', 'Minced!'),
                          ('BFF Kitten!', 'Kitten!'), ('BFF Toodzies', 'Toodzies')):
            if tag in t:
                return 'B.F.F.', name, f'tag:{tag}'
        return 'B.F.F.', None, 'vendor only'
    if v == 'BFF Best Fido Friend':
        return 'B.F.F. Fido', None, 'vendor only'
    if v == 'Cats in the Kitchen':
        for tag, name in (('Cats in the Kitchen Pantry Pours', 'Pantry Pours'), ('Cats in the Kitchen Kitten', 'Kitten'),
                          ('CITK Original', 'Original'), ('Cats in the Kitchen Paté', 'Paté')):
            if tag in t:
                return 'Cats in the Kitchen', name, f'tag:{tag}'
        return 'Cats in the Kitchen', None, 'vendor only'
    if v == 'Dogs in the Kitchen':
        for tag, name in (('Dogs in the Kitchen Pantry Pours', 'Pantry Pours'),):
            if tag in t:
                return 'Dogs in the Kitchen', name, f'tag:{tag}'
        return 'Dogs in the Kitchen', None, 'vendor only'
    if v == 'Soulistic':
        return 'Soulistic', None, 'vendor only'
    if v == 'TruLuxe':
        return 'TruLuxe', None, 'vendor only'
    if v == 'Cat Person':
        sub = 'Shreds in Broth' if 'Shreds in Broth' in t else None
        return 'Cat Person', sub, 'tag' if sub else 'vendor only'
    if v == 'Awesome Functions':
        m = re.match(r'Awesome (Everything|Bouncy|Shiny|Belly)\b', p['title'])
        return 'Awesome Functions', (m.group(1) if m else None), 'title prefix' if m else 'vendor only'
    if v == 'Weruva Wx':
        return 'Wx', None, 'vendor only'
    # vendor == Weruva
    if has(p, 'Paris Hilton'):
        return 'Paris Hilton', None, 'tag:Paris Hilton'
    if has(p, "Weruva Meals 'n More", "Weruva Dog Meals 'n More"):
        return "Meals 'n More", None, "tag:Meals 'n More"
    if has(p, 'Weruva Classic Cat', 'Weruva Cat Paté'):
        return 'Classic Cat', 'Paté' if has(p, 'Weruva Cat Paté') else None, 'tag'
    if has(p, 'Weruva Cat Stew!'):
        return 'Classic Cat', 'Stew!', 'tag'
    if has(p, 'Weruva Classic Dog'):
        return 'Classic Dog', None, 'tag'
    if has(p, 'Wx') or 'Wx' in p['title']:
        return 'Wx', None, 'title'
    if h.startswith('senior-cat-'):
        return 'Classic Cat', 'Senior', 'handle prefix'
    if h.startswith('weruva-puppy-'):
        return 'Classic Dog', 'Puppy', 'handle prefix'
    return None, None, 'unplaced'


def life_stage(p, h=None):
    h = p['handle']
    if has(p, 'Kitten') or 'kitten' in h:
        return 'life_stage:kitten'
    if has(p, 'Puppy') or 'puppy' in h:
        return None  # puppy is not in the vocabulary
    if has(p, 'Senior') or h.startswith('senior-'):
        return 'life_stage:senior'
    if has(p, 'Adult'):
        return 'life_stage:adult'
    return None


def texture_tags(p):
    t, h, title = p['tags'], p['handle'], p['title'].lower()
    out = []
    for tag, key in (('Paté', 'texture:pate'), ('Shredded', 'texture:shreds'), ('Flaked', 'texture:flaked'), ('Minced', 'texture:minced'),
                     ('Gravy', 'medium:gravy'), ('Soup', 'medium:broth'), ('Shreds in Broth', 'medium:broth'), ('Stew', 'medium:stew'),
                     ('Gelée', 'medium:jelly')):
        if tag in t:
            out.append(key)
    if 'purée' in title or 'puree' in h:
        out.append('texture:pate')
    return sorted(set(out))


def valid_gtin(g):
    g = g.zfill(14)
    return (10 - sum(int(c) * (3 if i % 2 == 0 else 1) for i, c in enumerate(g[:13])) % 10) % 10 == int(g[13])


def canonical13(g):
    return g.zfill(13) if len(g) <= 13 else g[1:] if g.startswith('0') else g


def size_token(s):
    m = re.search(r'(\d+(?:[.\-_]\d+)?)\s*-?\s*oz', s.replace('_20', ' '), re.I)
    if not m:
        return None
    n = float(m.group(1).replace('-', '.').replace('_', '.'))
    return (('%g' % n) + 'oz')


def gtins_for(p):
    """Candidate unit barcodes read from image file names / alt text. The export has no barcode field, and the file names are
    hand-typed (SKUs swapped between sizes, a code pasted onto the wrong product), so these are CANDIDATES keyed by the size
    named in the file name, never by SKU. Returns [{'gtin', 'size', 'file'}], one per distinct code."""
    out = {}
    srcs = [im['src'].split('/')[-1].split('?')[0] for im in p['images']]
    for v in p['variants']:
        fi = v.get('featured_image') or {}
        srcs += [fi.get('src', '').split('/')[-1].split('?')[0], fi.get('alt') or '']
    for s in srcs:
        m = re.search(r'(?<!\d)(\d{11,14})(?!\d)', s)
        if m and m.group(1) not in out:
            out[m.group(1)] = {'gtin': m.group(1), 'size': size_token(s), 'file': s[:90]}
    return list(out.values())


def text(p):
    return re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', p['body_html'] or ''))).strip()


def unit_size(vt):
    m = re.search(r'(\d+(?:\.\d+)?)\s*oz\s*(Can|Pouch|Cup|Tube|Bag|Tub)?', vt, re.I)
    return (m.group(0).strip() if m else None)


def pack_count(vt):
    m = re.search(r'(\d+)\s*pk', vt, re.I)
    return int(m.group(1)) if m else None


def form_of(p):
    s = ' '.join(v['title'] for v in p['variants']).lower() + ' ' + p['handle']
    for k, n in (('pouch', 'pouch'), ('cup', 'cup'), ('tube', 'tube'), ('bag', 'bag'), ('can', 'can')):
        if k in s:
            return n
    return None


ladder = collections.OrderedDict()   # line -> {'sub': set, 'species': set}
rows, excluded, audit_rows = [], [], []
stats = collections.Counter()

for p in products:
    if p['product_type'] in NONFOOD:
        excluded.append({'title': p['title'], 'handle': p['handle'], 'why': f"product_type {p['product_type']}"})
        continue
    sp = species_of(p)
    line, sub, why = line_of(p, sp)
    if line is None and p['vendor'] == 'Weruva' and sp:
        line, why = ('Classic Cat' if sp == 'cat' else 'Classic Dog'), 'fallback: Weruva vendor + species (no sub-brand tag)'
    variety = has(p, 'Variety Pack') or 'variety' in p['handle'] or 'sampler' in p['handle']
    flags = []
    if line is None:
        flags.append('no line could be derived')
        stats['unplaced'] += 1
    if sp is None:
        flags.append('species not derivable')
    if variety:
        flags.append('variety pack: several recipes in one product')
    if p['product_type'] == 'Supplement':
        flags.append('product_type Supplement (supplemental-feeding / topper): decide food vs treat vs excluded')
    kind = 'treat' if p['product_type'] == 'Treat' else 'food'
    ptype = p['product_type']
    form = {'Wet Food': 'wet', 'Dry Food': 'dry', 'Freeze Dried': 'freeze-dried', 'Treat': 'treat', 'Food Mixer': 'wet', 'Supplement': 'wet', 'Food': 'wet'}.get(ptype)
    cands = gtins_for(p)
    wanted = {size_token(unit_size(v['title']) or '') for v in p['variants']} - {None}
    for c in cands:   # file names often drop the decimal point: 247oz is 2.47oz, 30oz is 3.0oz, 105oz is 1.05oz
        if c['size'] and c['size'] not in wanted:
            n = float(c['size'][:-2])
            for div in (10, 100):
                alt = ('%g' % (n / div)) + 'oz'
                if alt in wanted:
                    c['size'] = alt
                    break
    uniq, bad = [], []
    for c in cands:
        if len(c['gtin']) >= 12 and valid_gtin(c['gtin']):
            uniq.append({'gtin': canonical13(c['gtin']), 'pack_label': (c['size'] + ' unit') if c['size'] else None, 'basis': 'image file name', 'file': c['file']})
        else:
            bad.append(c)
    if bad:
        flags.append('image name holds a code that is not a valid GTIN')
    if not uniq:
        flags.append('no barcode derivable')
    else:
        want = {unit_size(v['title']) and size_token(unit_size(v['title'])) for v in p['variants']} - {None}
        have = {size_token(b['pack_label'] or '') for b in uniq} - {None}
        if want - have:
            flags.append('barcode missing for some sizes')
        if any(b['pack_label'] is None for b in uniq) and len(uniq) > 1:
            flags.append('barcode size not readable from file name')
    stage = life_stage(p)
    tags = ([stage] if stage else []) + texture_tags(p)
    if not stage and kind == 'food':
        flags.append('no life stage tag')
    if line:
        ent = ladder.setdefault(line, {'sub': collections.OrderedDict(), 'species': set()})
        if sub:
            ent['sub'].setdefault(sub, set())
        if sp:
            ent['species'].add(sp)
    path = [ROOT] + ([line] if line else []) + ([sub] if sub else [])
    key = 'shopify|weruva|' + p['handle']
    rows.append({
        'path': path, 'name': p['title'], 'import_key': key, 'species': sp or 'cat', 'kind': kind, 'form': form,
        'texture': None, 'tags': tags,
        'gtin': uniq[0]['gtin'] if len(uniq) == 1 else None,
        'barcodes': uniq,
        'description': text(p) or None, 'ingredients': None, 'nutrition': None,
        'image_url': p['images'][0]['src'].split('?')[0] if p['images'] else None,
        'source': 'weruva.com (Shopify export)', 'source_url': f"https://www.weruva.com/products/{p['handle']}",
        'audit_status': 'unreviewed',
        'meta': {'shopify_id': p['id'], 'vendor': p['vendor'], 'product_type': ptype, 'form_factor': form_of(p),
                 'variants': [{'sku': v['sku'], 'title': v['title'], 'unit': unit_size(v['title']), 'packs': pack_count(v['title']), 'price': v['price'], 'available': v['available']} for v in p['variants']],
                 'shopify_tags': p['tags'], 'line_basis': why, 'bad_codes': [b['gtin'] for b in bad] or None},
        '_flags': flags,
    })
    stats['placed' if line else 'unplaced_total'] += 0


def node(name, kind, **kw):
    return {'name': name, 'kind': kind, **kw}


children = []
for line, ent in ladder.items():
    n = node(line, 'line', species=sorted(ent['species']) or None)
    if ent['sub']:
        n['children'] = [node(s, 'subline') for s in ent['sub']]
    children.append({k: v for k, v in n.items() if v is not None})
(OUT / 'ladder.json').write_text(json.dumps({'region': 'US', 'reviewed': False, 'ladder': {'name': ROOT, 'kind': 'brand', 'children': children}}, indent=2, ensure_ascii=False) + '\n')
(OUT / 'products.json').write_text(json.dumps({'region': 'US', 'reviewed': False, 'ladder': 'weruva', 'brand': ROOT, 'products': rows}, indent=2, ensure_ascii=False) + '\n')
(OUT / 'excluded.json').write_text(json.dumps(excluded, indent=2, ensure_ascii=False) + '\n')

C = collections.Counter
flagc = C(f for r in rows for f in r['_flags'])
summary = {
    'dump_products': len(products), 'excluded_nonfood': len(excluded), 'drafted': len(rows),
    'lines': {l: {'products': sum(1 for r in rows if len(r['path']) > 1 and r['path'][1] == l), 'sublines': list(e['sub'])} for l, e in ladder.items()},
    'unplaced': [r['name'] for r in rows if len(r['path']) == 1],
    'with_any_barcode': sum(1 for r in rows if r['barcodes']), 'with_single_gtin': sum(1 for r in rows if r['gtin']),
    'multi_barcode': sum(1 for r in rows if len(r['barcodes']) > 1),
    'species': dict(C(r['species'] for r in rows)),
    'flags': dict(flagc),
    'description_empty': sum(1 for r in rows if not r['description']),
    'ingredients': 0, 'nutrition': 0,
    'dup_names_in_same_path': sum(1 for k, c in C((tuple(r['path']), r['name']) for r in rows).items() if c > 1),
    'duplicate_gtins': [g for g, c in C(b['gtin'] for r in rows for b in r['barcodes']).items() if c > 1],
}
(OUT / 'audit.json').write_text(json.dumps(summary, indent=2, ensure_ascii=False) + '\n')
print(json.dumps(summary, indent=2, ensure_ascii=False))
