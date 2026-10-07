# Weruva Shopify dump: extraction and audit

Source: `weruva-products.json` (Shopify `products.json`, 449 products, 722 variants). Regenerate everything here with
`python3 scripts/weruva_shopify_extract.py <dump> database/catalogue/drafts/weruva-shopify`. Nothing in this folder is
imported; `ladder.json` and `products.json` are `reviewed: false` drafts in the catalogue-file format.

## What the dump gives us

| Need | Verdict |
|---|---|
| Brand / line | Good. `vendor` + tags + handle prefixes place 436 of 438 products (2 variety packs left unplaced). |
| Sub-line | Partial. Real ranges exist for B.F.F. (OMG Gravy!, PLAY Paté!, Minced!, Kitten!, Toodzies), Cats in the Kitchen (Pantry Pours, Original, Paté, Kitten), Dogs in the Kitchen (Pantry Pours), Classic Cat (Paté, Stew!, Senior), Classic Dog (Puppy), Awesome Functions (Everything, Bouncy, Shiny, Belly), Cat Person (Shreds in Broth). Soulistic, TruLuxe, Wx, Paris Hilton, Meals 'n More and B.F.F. Fido have none we can derive. |
| Name, description, image | Good. Every product has a name, marketing copy and 2-14 images. Copy is marketing text, not label text. |
| Species | Good for 426; 12 not derivable (mostly variety packs and treats) and the draft defaults them to cat, flagged. |
| Kind / form | Good. Wet 373, treat 29, supplement 18, mixer 8, freeze-dried 6, dry 3, food 1. 11 non-food items excluded (toys, litter box, bowl, bed, can cover). |
| Texture / medium | Partial. 259 of 438 have a texture or medium tag from Shopify tags or the name. |
| Life stage | Partial. 240 of 438 tagged (Adult 217, Kitten, Senior). 169 food products flagged "no life stage tag". |
| Barcode (GTIN) | Present only inside image file names; there is no barcode field. 418 products yield a valid code, 215 of those a single unambiguous one. See hazards. |
| Ingredients | Missing. None of the 449 has an ingredient list. |
| Guaranteed analysis / calories | Missing. No product has any nutrition values (a few mention "moisture" or "phosphorus" in prose). |
| Price | DTC case prices only (12-pack, 24-pack). Kept in `meta`, not a unit price. |

## Hazards found

1. **Barcodes come from hand-typed file names.** Variants have no barcode, so codes were read from image file names
   and matched by the size named in the file, never by SKU, because SKUs are swapped between sizes in places
   (Senior Chicken & Tuna 3 oz is named with the 5.5 oz SKU). The same unit GTIN also appears on several recipes
   (12 conflicts, 25 products), e.g. `0810028244641` on both Feeling Farmy 5.5 oz and Paw Lickin' Chicken 13 oz;
   `0813778014816` on two different BFF PLAY pouches; two Paw Lickin' Chicken rows carry Polynesian BBQ codes.
   Treat every filename GTIN as a candidate, to be confirmed by a scan or a GS1 lookup.
2. **10 products hold codes that fail the check digit** (11-digit codes missing a leading digit, 12-13 digit typos).
3. **A "unit" barcode is per can size, not per pack count.** The same code serves the 12-pack and 24-pack, so the right
   shape is one product per recipe with several barcodes (`product_barcodes`), one per unit size. The importer's
   catalogue format currently takes a single `gtin` only.
4. **Variety packs are 99 of the 438 rows.** A variety pack is several recipes in a box; it is a poor fit for a
   per-recipe scorecard and meal suggester. Decide: skip, keep as a product flagged variety, or model as a bundle.
5. **18 "Supplement" rows are wet cans/pouches** (Wx "supplemental feeding" cans, pumpkin pouches). Kind is
   food or treat today; these need a decision.
6. **7 duplicate names inside a line** (e.g. Dogs in the Kitchen "Fowl Ball"): same name for the can and the pouch or
   a re-listing. `import_key` (Shopify handle) keeps them apart, but a person needs a way to tell them apart.
7. **Images are Weruva's own marketing photos.** The `source` is recorded, but licence/permission for reuse is not
   established. Fine for the draft, a question before any public release.
8. **Retired products are not visible.** This is a live store: discontinued items are simply absent, and 35 variants
   are marked unavailable. Nothing here tells us what was retired.

## Decisions needed before import

1. Ladder shape: all sub-brands as lines under `Weruva` (as drafted, matches the existing ladder), or promote
   Cat Person / Soulistic / TruLuxe / Awesome Functions / Wx to their own brand roots under a Weruva manufacturer.
2. Variety packs and Supplements: in, out, or flagged.
3. Barcode model: add `barcodes: [{gtin, pack_label}]` to the product file and the importer (small change), keep
   GTINs unconfirmed until scanned.
4. Where ingredients and nutrition come from, since this dump has neither (weruva.com product pages, label images,
   or user scans).
