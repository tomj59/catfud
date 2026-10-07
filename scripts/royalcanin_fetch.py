#!/usr/bin/env python3
"""
Pull Royal Canin's storefront catalogue (the products/facets feed their site uses) into raw JSON files, one per page.

    RC_AUTH='Bearer eyJ...' python3 scripts/royalcanin_fetch.py out/royalcanin [--species cat] [--limit 14] [--delay 2]

The endpoint wants the Authorization header the website itself sends (it answers 401 "Bad JWT Token" without it). Copy that
header value from your browser's network tab; it expires, so grab a fresh one if a run stops on 401.
Polite by design: one request at a time, a pause between pages, backs off on 429/5xx, stops on 401/403, and resumes
(pages already on disk are skipped). Writes page-0001.json ... plus pages-index.json. Never touches database/.
"""
import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

URL = 'https://rc-api.royalcanin.com/internal/product-digital-shelf/products/facets'
ap = argparse.ArgumentParser()
ap.add_argument('out')
ap.add_argument('--species', default='cat')
ap.add_argument('--limit', type=int, default=14)
ap.add_argument('--delay', type=float, default=2.0)
ap.add_argument('--max-pages', type=int, default=500)
ap.add_argument('--pillar', default='sptretail', help="product_pillar; 'all' to omit it (to see vet diets etc. if they use another pillar)")
a = ap.parse_args()
auth = os.environ.get('RC_AUTH', '').strip()
if not auth:
    sys.exit('Set RC_AUTH to the Authorization header value the website sends (e.g. "Bearer eyJ...").')

out = Path(a.out)
out.mkdir(parents=True, exist_ok=True)


def fetch(page):
    body = {'locale': 'en_US', 'page': page, 'limit': a.limit, 'species': [a.species], 'commercetools_enabled': True,
            'ordering': 'best_seller_in_stock', 'brand': ['royal_canin']}
    if a.pillar != 'all':
        body['product_pillar'] = [a.pillar]
    req = urllib.request.Request(URL, data=json.dumps(body).encode(), method='POST', headers={
        'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': auth,
        'User-Agent': 'CatFud-catalogue-research/0.1'})
    for attempt in range(5):
        try:
            with urllib.request.urlopen(req, timeout=40) as r:
                return json.loads(r.read())
        except urllib.error.HTTPError as e:
            if e.code in (401, 403):
                sys.exit(f'HTTP {e.code}: {e.read()[:200]!r}. The token is missing, wrong or expired; stopping.')
            if e.code == 429 or e.code >= 500:
                wait = 10 * (attempt + 1)
                print(f'  page {page}: HTTP {e.code}, waiting {wait}s', flush=True)
                time.sleep(wait)
                continue
            sys.exit(f'HTTP {e.code}: {e.read()[:300]!r}')
    sys.exit(f'page {page}: gave up after retries')


def items_of(d):
    """The products list: the first list of objects in the response (the shape is not documented)."""
    if isinstance(d, list):
        return d
    for k in ('products', 'items', 'results', 'data', 'hits'):
        if isinstance(d.get(k), list):
            return d[k]
    for v in d.values():
        if isinstance(v, list) and v and isinstance(v[0], dict):
            return v
        if isinstance(v, dict):
            r = items_of(v)
            if r:
                return r
    return []


seen, index = set(), []
for page in range(1, a.max_pages + 1):
    f = out / f'page-{page:04d}.json'
    if f.exists():
        d = json.loads(f.read_text())
    else:
        d = fetch(page)
        f.write_text(json.dumps(d, indent=1, ensure_ascii=False))
        time.sleep(a.delay)
    items = items_of(d)
    ids = [json.dumps(i, sort_keys=True)[:200] if not isinstance(i, dict) else str(i.get('id') or i.get('sku') or i.get('slug') or json.dumps(i, sort_keys=True)[:200]) for i in items]
    new = [i for i in ids if i not in seen]
    seen.update(ids)
    index.append({'page': page, 'items': len(items), 'new': len(new)})
    print(f'page {page}: {len(items)} items, {len(new)} new, {len(seen)} total', flush=True)
    if not items or not new:
        break
(out / 'pages-index.json').write_text(json.dumps(index, indent=1))
print(f'done: {len(seen)} distinct items in {len(index)} pages -> {out}')
