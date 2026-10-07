# Tiki Pets (WooCommerce Store API): extraction and audit

Source: `raw/tikipets-page1..3.json`, the public `/wp-json/wc/store/v1/products?per_page=100` feed, 289 products
(232 Tiki Cat, 57 Tiki Dog). Regenerate with
`python3 scripts/tikipets_extract.py <dir with tikipets-page*.json> database/catalogue/drafts/tikipets`.
Drafts are `reviewed: false` and pass the validator (warnings only). They cover the same roots as the spreadsheet drafts
(`tiki-cat`), so they would replace `database/catalogue/us/ladders/tiki-cat.json` and its 31-product file, not add to them.

## What the feed gives us

| Need | Verdict |
|---|---|
| Brand / line | Good. The product URL is the site's own hierarchy (`/product/tiki-cat/tiki-cat-wet-food/shredded-cat/after-dark/...`). Cat lines: After Dark, Aloha Friends, Born Carnivore, Grill, Luau, Velvet Mousse, Silver (senior), Baby (kitten), Gelée, Solutions, Stix, Duets, Soft & Chewy, Meal Toppers, Mega Packs. Dog lines: Savory, Taste of the World, Petites Grain Free, Petites Whole Foods, Born Carnivore for Dogs, Puppy, Senior, Flavor Boosters, Protein Boosters, Tummy Toppers. |
| Sub-line | Only where the site has one: Solutions (Mousse, Dry, Toppers, Supplements) and Meal Toppers (Broths, Complements). |
| Name | Weak. Names are flavours ("Chicken & Duck", "Variety Pack"), often repeated; the line makes them meaningful. 42 products share their name with another product in the same line. |
| Species / kind / form | Good. Path and the Pet and Food Preference attributes agree. |
| Texture / medium | Good. Read from the name ("Mousse:", "Pâté", "in Broth", "Gelée") and the Texture attribute (Shredded Meat, Mousse, Pate, Cutlets). |
| Life stage | Partial. The Life Stage attribute exists on 87 products (All Life Stages 78, Senior 11, Kitten 5); Baby and Silver lines add kitten and senior. 152 food products have none. |
| Description | Marketing bullets only (all 289 have a short description; 9 have a long one). |
| Key ingredients | The "Ingredients" attribute is a protein/ingredient highlight (Chicken, Seafood, Pumpkin, Lamb...), not a list. Kept in `meta.key_ingredients`. |
| Ingredient list / guaranteed analysis / calories | Missing. No product carries them in the feed. |
| Claims | "Grain-Free" and "High Protein" are filed under the Texture attribute; moved to `meta.claims`, not tags. |
| Images | Good: every product has at least one, most 2 to 10, hosted on tikipets.com. Licence not established. |
| Price / stock | Retail price for an unstated pack; kept out of the draft. All 289 report in stock. |

## Hazards found

1. **Placeholder and duplicate listings.** 25 products are generic or numbered "Variety Pack" listings (slugs `variety-pack-11`,
   `-12`...), one slug ends in `-copy`, and 17 are tagged "new look" and may duplicate an older listing. 181 products have
   no SKU and 28 have no price (not purchasable), though several recipes without a SKU look real
   ("Chicken & Salmon Recipe in Gelée"), so SKU is not a reliable live/dead signal.
2. **Names repeat inside a line.** 18 groups, 42 products. The draft keeps every row with a unique `import_key` (the Woo slug)
   and flags the repeats; a person needs to merge or rename them.
3. **Variety packs and Mega Packs (41).** Multi-recipe boxes; same question as the other vendors.
4. **Seven products sit outside the category tree** (URL is just `/tiki-cat/{slug}/`: "Tuna", "Salmon", "Chicken Flavor"...) and
   one sits directly under Wet Food. They are placed on the brand with no line.
5. **Life-stage lines vs tags.** Baby and Silver are real Tiki Cat lines (they also carry the kitten and senior tags). Kept as lines.
6. **Cross-listing.** Products belong to several categories (e.g. After Dark appears under Velvet Mousse, Pâté and Shredded).
   The draft uses the URL's primary path only; the other categories are in `meta.categories`.
7. **Supplements are mixed in** (liquid meal replacer, mobility, digestion, skin and coat, senior comfort): food, treat or exclude?

## Decisions needed

1. Ladder shape: two roots (Tiki Cat, Tiki Dog) as drafted, or a Tiki Pets manufacturer above them.
2. Which listings to drop before import: generic "Variety Pack" rows, the `-copy` row, and "new look" duplicates.
3. Solutions: one line with Mousse, Dry, Toppers, Supplements sub-lines (as drafted), or separate lines.
4. Ingredient list and analysis: not in this feed. The product pages may carry them in a tab; saving one product page's HTML
   would show whether it is worth building a page-level fetch.
