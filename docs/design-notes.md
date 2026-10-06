# Cat Füd: design decisions and open notes

Living notes. Newest decisions are at the top of each section. Anything under "Revisit" is deliberately not built yet.

## Catalogue structure (decided and built)

- The catalogue forks by **region**; only US exists. Brand tree, products and barcodes are separate per region.
- **Brand ladder**: manufacturer > brand > line > sub-line > sub-sub-line, any level optional, **capped at 5**. Products attach to the deepest node. `kind` is a hint, depth is what matters.
- **Facets are tags, not name parts**: texture, medium ("served in"), life stage, diet. Life stage (Kitten, Adult, Adult 7+, Senior, All life stages) is **not a rung**: makers print it inside line names, product names or nowhere. A line name that is only a life stage becomes a tag. A line that only ever holds one stage can inherit a tag (Prime Plus -> adult-7plus).
- **Clinical** (vet diet) is a deliberate workaround tag; revisit how owners of special-needs pets search and sort.
- **Aliases** resolve to the canonical node, so retailer spellings and renamed lines never create duplicates. A rename keeps the old name as an alias. Merge folds one node into another.
- A **product** is the stable thing an owner knows (barcode, ratings, pantry). Its name, ingredients and nutrition live in **versions** (`product_versions`); a correction edits the current version, a reformulation adds one.
- The curated ladder lives in `database/seeds/brand_suggestions_us.json` (`catalogue:seed-brands`).

## Contributed content (decided; roles, states, visibility, unplaced flow and audit log built; moderation actions and queue come with the admin endpoints)

- Brands, lines/series and sub-lines are **admin-owned**. Users never create tree nodes. A user contributes a **leaf**: a specific product (a barcode).
- A product must sit under an **existing** ladder path. If the ladder the user needs does not exist, the product is **unplaced**: no node, no "nearest match". It keeps the ladder the user typed verbatim (`requested_path`, never parsed into nodes) and stays private to them. They can use it in their app straight away.
- The admin "product request" queue is the unplaced products, grouped by typed ladder and by barcode, oldest first. Actions: map to an existing node; create the missing rung and map the whole group; decline with a reason. Show the age of the oldest unplaced item on the dashboard.
- Separate axes: who owns it (seed vs contributor), who can see it (owner vs everyone), and where it is in review (pending, needs changes, approved, rejected, merged). The product id never changes, so pantry, ratings and suggestions keep working through approval. A duplicate is merged by re-pointing the contributor's records; the old id keeps resolving (`merged_into_id`).
- An unplaced product cannot be approved for the public. Placement first, moderation second.
- Defaults (change any time): rejected products stay private to their contributor; only admins edit public products (suggested edits come later); barcode uniqueness applies to the public catalogue only, so two users can hold the same unknown barcode privately; the pilot has one admin role plus a moderator role for later.
- Visibility is enforced in one place (a global scope) and covered by leak tests: someone else's pending item must never show up in search, scan, browse, the brand picker or suggestions.

## Discontinuation (decided in principle, not built)

- Separate from recalls (attributed third-party notices) and from rebrands (rename/merge/move: identity continues). Discontinuation ends an identity.
- A status on **any node**: active, phasing out, discontinued, with announced/ended dates, source link, note, confidence, and an optional "replaced by" node. Everything below inherits; a product may override. Never deleted: barcodes still scan, pantry and ratings stay, a neutral badge says the maker discontinued it.
- Hidden from the picker for new products, "add to shopping" suggestions and the default catalogue view; pantry stock still counts for meal suggestions.
- Admin tool gets a guided "retire this node" action that previews affected products, pantry items and users. Detection and owner notifications come later.
- First real case: **Purina > Beyond** (reported by Tom while building the database; the maker's pages are still live, so record a source and confidence rather than assuming).

## Admin tool (decided)

- Lightweight static HTML + **Alpine.js** at `/admin`, same origin, built against the JSON API.
- API under `/api/v1`, admin routes under `/api/v1/admin` behind a role gate, documented with **Scramble** (OpenAPI). That means moving validation into form requests and responses into API resources, plus a test that fails when the spec drifts from the code.
- Scope for now: brands, series/lines, products and images (one tree view with a kind filter), data-quality dashboard, bulk actions, export, logo upload, rename/merge/move, retire, the request queue. Images need a real table (file, source, licence/attribution, primary flag, hash).
- Build order: 1) roles, moderation states, visibility, audit log (**done**); 2) Scramble + FormRequests/Resources + `/api/v1`; 3) admin endpoints; 4) static admin UI; 5) images.

## Revisit: product lookup and assignment in the Scan flow (client, not the admin tool)

The wording in the request flow is a UX problem for the client, not admin work. Notes for when we return to it:

1. **Pick, never guess.** The system shows candidates; the person chooses. No nearest-neighbour placement of something that does not match.
2. **Search-as-you-type over the whole tree**, not just the open rung: names and aliases at every level, results shown as full paths ("Purina > Pro Plan > Prime Plus"). Empty until typing, five results at most, "Can't find it?" at the **top** of the list (a bottom placement buried it last time). Respect the active pet's species. Show **all** life stages as chips beside the results; they are facets and do not affect the ladder.
3. **Layers, each only if the one above found nothing:** normalize (case, punctuation, accents, "&"/"and", "7+"; strip life-stage words) > exact name/alias > word-start match ("prime plu") > typo tolerance per word (a swapped pair counts as one edit, allowed edits scale with word length, compare against word prefixes so "priem pl" works) > word-order and spacing tolerance ("plus prime", "proplan") > context (already-chosen rung, species, product count) > barcode company prefix (first digits of the GTIN identify the maker; learn prefix to node from the catalogue and offer "other products with this prefix are Purina > Pro Plan" as a suggestion) > close **existing products** by name before a new one is created (could be another pack size).
4. **Before the request form:** say nothing matches, offer browse-by-first-letter on the tiles and a hint about where the line name sits on the package; only then "Can't find it? Tell us what's on the package".
5. **Learn from misses.** Log searches that found nothing and what the person eventually picked. When an admin maps a request, the user's typed text becomes an alias automatically. Frequent misses are promoted to aliases.
6. Score on the **server** in one place (the admin tool needs it too); the catalogue is small enough for no search engine. Cache the node index and invalidate it when nodes change.
7. Write a **fixed typo test list** before building (e.g. "priem pl", "prime plsu", "proplan", "royal canine", "hills science", "fancy fest", "wellnes", "purina beyound") and measure how often the right node is in the top five. Seed it with misspellings seen in the wild.
8. Exact name or alias matches resolve immediately; that is a true match, not a guess.

## Known data issues

- "Diamong Naturals" (spreadsheet typo for Diamond Naturals), fixable by a node rename or merge.
- A Fancy Feast row reads "Wild Alaskan Salmon (kitten) (Kitten)": the converter appended a variety that the name already contained.
- Pro Plan "High Protein" and "Savor" are marked dog-only but unverified.
- Brand logos came from a stock-photo collage; replace with official assets before any public release.
