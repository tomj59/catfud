# Royal Canin retail feed: extraction and audit

Source: four hand-saved pages (`raw/rc-page1..4.json`) of the `products/facets` feed: US, cat, `sptretail`, 54 products
(`totalProducts: 54`, 14 per page). Regenerate with
`python3 scripts/royalcanin_extract.py <dir with rc-page*.json> database/catalogue/drafts/royalcanin-retail`.
`ladder.json` and `products.json` are `reviewed: false` drafts in the catalogue format and pass the validator (warnings only).

## What the feed gives us

| Need | Verdict |
|---|---|
| Brand / line | Good. Range codes map cleanly: FHN Feline Health Nutrition (23), FCN Feline Care Nutrition (22), FBN Feline Breed Nutrition (8), SUP Supplements (1). |
| Sub-line | Good. Named ranges in titles: Indoor, Kitten, Mother & Babycat, Instinctive, Fit & Active, Fussy, Sensitive Digestion, Spayed/Neutered, Aging 11+; Care: Dental, Urinary, Weight, Digestive, Hairball, Hair & Skin, Appetite Control; Breed: Persian, Siamese, Maine Coon, Ragdoll, American Shorthair, Bengal. |
| Barcode | Excellent. `sku` is the GTIN: 47 UPC-12 (units) and 7 GTIN-14 (cases). All 54 pass the check digit; none duplicated. |
| Life stage | Good. The feed has a lifestage list per product; "7+" and "Aging 11+" come from the titles (feed says mature / ageing). 22 products are "adult + mature" with no stage in the name, so adult is inferred and flagged. |
| Texture / medium | Good for wet (loaf, thin slices, morsels, mousse, chunks; sauce or gravy), read from the title. Dry has none. |
| Species | All cat (as requested). |
| Image | One pack shot per product on Royal Canin's CDN. Licence not established. |
| Name | Usable. Feed `title` for display; the internal `name` is a page-master label ("2022 - FHNW - Wet - Cat - ...") and is kept only in `meta`. |
| Description | Missing. |
| Ingredients | Missing. |
| Nutrition (GA, calories) | Missing. |
| Pack weight / can size | Missing per product. Only coarse facet buckets exist ("up to 6 oz", "1-5 lbs"). |
| Price | Retail price of an unstated pack (e.g. $52.21 for a case of cans, $28.49 for a dry bag). 5 rows are $0 or 0 (out of stock). Kept in `meta`, not a unit price. |

## Hazards found

1. **This is a listing, not a product detail feed.** Ingredients, guaranteed analysis, calories, description and pack
   sizes live on a per-product detail call we have not seen. That is the gap that matters for the scorecard and the meal
   planner.
2. **Only the retail pillar.** Royal Canin Veterinary Diets (urinary SO, renal, gastrointestinal and so on) are very
   likely under another `product_pillar`, so the clinical range is absent. Run the same request with that pillar
   (`--pillar` in `scripts/royalcanin_fetch.py`) to see.
3. **Several rows are one recipe in two packages.** 8 recipes appear twice: a STANDARD can and a HANDYCAN (small
   can) or a GTIN-14 case. Grouped into one product with several barcodes (54 rows become 46 products). The feed does
   not say which is which size; the internal name's packaging token is the only clue.
4. **Title conflicts.** "Aging 11+ Loaf in Sauce Wet Cat Food" is "ULTRA SOFT MOUSSE" in the internal title; the
   draft follows the internal title (mousse) and flags it. "Hairball Care This Slices In Gravy" is a feed typo.
   The internal title is spelled "WITHTOUT INOSITOL" for the breed range.
5. **Mother & Babycat** is marketed for pregnant/nursing queens and kittens; the draft tags it kitten only.
6. **Supplement.** The probiotic powder is not food; decide in/out/treat.
7. **Availability.** 8 rows out of stock. As with Weruva, discontinued items are invisible to us.

## Decisions needed

1. Ladder shape: `Royal Canin > {Feline Health | Care | Breed Nutrition, Supplements} > sub-line`, as drafted, or a flatter
   `Royal Canin > Indoor / Kitten / ...` with the range as a tag.
2. Vet diets: pull the other pillar, and if so give them the `diet:clinical` tag under their own line.
3. Detail endpoint: capture one product's detail call (it will hold ingredients, analysis, sizes) so the fetcher can
   loop over the 54 SKUs.
4. Barcodes: same list-of-barcodes gap as Weruva (the catalogue file takes one `gtin`).
