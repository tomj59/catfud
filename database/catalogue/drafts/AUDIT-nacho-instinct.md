# Made By Nacho and Instinct (Shopify products.json): extraction and audit

Sources: `nacho.json` (56 products) and `instinct.json` (185). Regenerate with
`python3 scripts/shopify_nacho_instinct_extract.py <dir with the two files> database/catalogue/drafts`, which writes
`drafts/nacho/` and `drafts/instinct/` (ladder.json, products.json, audit.json). Both are `reviewed: false` and pass the
validator (warnings only). They cover the same roots as the spreadsheet drafts (`nacho`, `instinct`), so they would replace
those files, not add to them.

Neither store has a "line" concept. Lines below are **product families read from titles and tags**, by explicit rules in the script.

## Made By Nacho (52 drafted, 4 excluded: gift cards, starter kit, hydration kit, postcard)

| Need | Verdict |
|---|---|
| Lines | Inferred families: Flaked 9, Minced 9, Lickable Mousse Treats 8, Pâté 7, Kibble 5, Toppers 6 (Bone Broth, Functional), Cuts in Gravy 4, Freeze-Dried 3, Shreds 1. |
| Species | All cat. |
| Kind / form | Good: wet, dry, freeze-dried; 4 products marked "Treat or Topper" need a decision. |
| Texture / medium | 43 of 52, read from titles (the store's own `Texture:` tags are unreliable: kitten pâté is tagged "Minced & flaked"). |
| Life stage | Weak: only 22 of 52 (Lifestage tag plus "for Kittens" titles). 30 flagged. |
| Description | All 52, marketing copy ("Limited ingredients. No grains, corn...") with no list. |
| Ingredients, analysis, calories | Missing. |
| Extra | Proteins, diet claims (grain free, limited ingredient) and benefits are in `meta`, not tags. |
| Quality | 8 variety packs; 4 products have no product type; the old "Cans / Cups / Pouches" lines in the spreadsheet draft are replaced by families. |

## Instinct (141 drafted; 44 "combined listing parent" rows skipped)

Parent rows are empty containers (no description, several recipes as variants); each recipe is also its own listing, so skipping
them loses nothing. The list is in `drafts/instinct/audit.json`.

| Need | Verdict |
|---|---|
| Lines | Original 35, Raw Boost 36 (also spelled RawBoost+; Kibble, Mixers, Shakers), FreshRaw 15 (Medallions, Bites, Patties, Meals), FreshDried 15 (Raw Meals, Patés, Meal Blends), Healthy Cravings 11, Limited Ingredient Diet 8, Be Natural 4, Freshly Crafted Meals 4, Bone Broth 4, Duos 4, PeakBoost+ 3, Ultimate Protein 2. |
| Species | Good: 79 dog, 62 cat (tags, then title). |
| Form | Good from tags: dry, wet, freeze-dried, frozen. |
| Texture / medium | Only 33 of 141 (pâté, flaked, minced, broth tags). Dry and raw products have none. |
| Life stage | 97 of 141. The store tags most adult foods "adult" and "senior" together; both are kept. |
| Description | All 141, marketing bullets. |
| Ingredients, analysis, calories | Missing from the structured data. |
| Variants | Recipe and Size options per product (3 oz, 5.5 oz, 3.5 lb ...): sizes only, no weights as numbers. |

## Hazards

1. **Puppy.** 44 Instinct products are puppy foods; `puppy` is not in the life-stage vocabulary, so they carry no stage tag and
   are flagged. Decide: add a puppy stage, or reuse kitten as "young".
2. **Line is inferred.** 16 Instinct products (Flaked Entrées, Minced Cups, Split Cups, "Original Wet Food for Small Breed
   Dogs") are placed under Original because the titles name a format, not the line. Check them against Instinct's own
   site.
3. **Toppers and mixers (34).** Raw Boost Mixers and Shakers, Healthy Cravings and Bone Broth are toppers: food, treat or
   supplement?
4. **Variety packs.** 6 Instinct and 8 Nacho multi-recipe products.
5. **Two spellings.** "Raw Boost" and "RawBoost+" (and "Freeze-Dried Raw" vs "FreshDried") name the same lines; the draft
   uses one name per line, so aliases belong in the ladder file.

## Decisions needed

1. Puppy stage in the vocabulary, or not.
2. Whether Nacho's "families" are the right lines, or a flatter `Nacho > product` with form and texture as tags.
3. Instinct Original: confirm the format-named products belong there.
4. In/out for toppers, mixers and treats on both brands.
