# Cat Füd API (Phase 1 pilot)

Laravel API on SQLite for the Cat Füd pilot: a shared product catalogue, barcode lookup, and each user's private
pets, inventory, ratings and meal history. It follows the *Phase 1 Pilot: Decisions & Technical Plan* doc in the
Cat Füd project.

## Run it

Needs PHP 8.3 or newer (with `sqlite3`, `mbstring`, `xml`, `curl`, `fileinfo`) and Composer.

```sh
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed      # tables + 20 SAMPLE products (+ a local-only test login)
php artisan serve               # http://127.0.0.1:8000 (the web client is at /, the API at /api)
php artisan test                # 57 tests
```

Open `/` in a browser (iPhone Safari for the pilot). The seeded local test login is `pilot@example.test` / `password` (created only when `APP_ENV=local`).
SQLite is used so the pilot needs no database server. To move to MySQL later: change `DB_*` in `.env`, run
`php artisan migrate`, and copy the data across. The migrations use no database-specific features.

## Barcodes

Every barcode is stored as a canonical **GTIN-13** (`products.gtin`). A UPC-A is an EAN-13 with a leading 0, and
scanners disagree about which they report, so `036000291452` and `0036000291452` find the same product. The check
digit is validated; UPC-E (compressed) codes are not expanded and are rejected. See `app/Support/Gtin.php`.

## Product data

Products are global and shared. Each carries `source` and `last_verified_at` for provenance, and `nutrition` holds
label values exactly as printed (nothing is computed).

The 20 seed products in `database/seeds/products_sample.csv` are **placeholders**, named "SAMPLE ..." with
codes in the in-store `020` range so they can never collide with a real product. Nutrition is deliberately blank.
Replace them with real products via the importer:

```sh
php artisan products:import path/to/products.csv --dry-run   # validate and report only
php artisan products:import path/to/products.csv             # upsert by GTIN; safe to re-run
```

CSV columns: `gtin, brand, name, species, kind (food|treat), form, description, ingredients, image_url, source,
last_verified_at`, plus any `nutrition_<field>` columns (e.g. `nutrition_crude_protein_min_pct`). JSON files take a
list of the same keys, with `nutrition` as a nested object.

## Web client

`resources/app/index.html` is a single-page, mobile-first client served at `/` (same origin as the API, so no CORS).
It loads one library (ZXing, for scanning) from a CDN. Tabs:

- **Tonight**: suggests what to offer from the pantry, shows the evidence, with Offer / Shuffle / Not this one, then records the outcome and an optional scorecard rating.
- **Scan**: camera scan or typed barcode, then the product card (label values as printed, source, any public advisories), or an "add this product" form if the code is unknown.
- **Pantry**: the user's inventory with stocked / running low / out, a scorecard rating per pet, and a quick "log a meal".
- **Notices**: Public Advisories that may relate to the pantry, each attributed to its source, with confirm / dismiss and a fixed disclaimer.

To serve it over HTTPS for iPhone testing, run `php artisan serve --port=8080` and point your TLS proxy at it. The camera needs HTTPS.

## Meal suggester

`GET /api/pets/{id}/suggestions` (`app/Support/MealSuggester.php`). It uses only what the user recorded: the pet's last five
outcomes for each in-stock product, plus the user's own rating. Untried products are labelled as trials. The product
offered at the last meal is skipped when there is any alternative. `?shuffle=1` picks at random weighted by that history
(`&seed=` makes it repeatable), and `?exclude[]=<product id>` skips products the user passed on. There is no nutrition or
health input anywhere in it, and the wording never makes a health claim.

## Public Advisories

An advisory is what a named source said, and when. It cannot be saved without a source name and link, and the app never
writes advisory text. Add them by hand from a JSON file (see `database/seeds/advisories_sample.json`):

```sh
php artisan advisories:import path/to/advisories.json --dry-run
php artisan advisories:import path/to/advisories.json
```

Each entry can list `matches` by `gtin` (high confidence), `brand` (medium) or `free_text` (low), optionally with a
`lot_code`. Matches are only suggestions: each user confirms or dismisses them, and that decision is theirs alone and
never changes the advisory. The two seeded advisories are labelled SAMPLE and are not real.

## API

All routes are under `/api` and return JSON. Authenticated routes need `Authorization: Bearer <token>`
(Laravel Sanctum). Personal data is scoped to the signed-in user; other users' records return 404.

| Method and path | What it does |
| --- | --- |
| `POST /register`, `POST /login` | Returns `{user, token}` |
| `GET /me`, `POST /logout` | Current user; revoke the current token |
| `GET /products?q=&species=&kind=` | Search the shared catalogue (paginated) |
| `GET /products/lookup/{code}` | Scan lookup, with any attributed advisories. 200 product, 404 valid code but unknown (returns `gtin`), 422 invalid barcode |
| `GET /products/{id}` | One product, with advisories |
| `POST /products` | Add a product the lookup could not find (records the creator; 409 if it already exists) |
| `GET/POST /pets`, `PATCH/DELETE /pets/{id}` | The user's pets |
| `GET /pets/{id}/suggestions` | What to offer next, with evidence (see above) |
| `GET/POST /inventory`, `PATCH/DELETE /inventory/{id}` | The user's inventory; POST takes `product_id` or `gtin`, adds to the quantity if already there |
| `GET/POST /ratings`, `DELETE /ratings/{id}` | liked / neutral / refused per pet per product (POST again replaces) |
| `GET/POST /meal-offers`, `PATCH /meal-offers/{id}` | What was offered and the outcome (ate_all / ate_some / refused) |
| `GET /advisories` (`?all=1`) | Public Advisories for the user's pantry, or every one on file, plus the disclaimer |
| `PUT /advisory-matches/{id}/review` | The user's own confirm / dismiss / reset, with an optional note |

## Seeding from the research spreadsheet (and wiring up barcodes)

`database/seeds/catfood_seed.json` holds ~290 cat-food products converted from `catfood.xlsx` (Tiki Cat, Weruva, Purina,
Nacho, Instinct, Hill's, Fancy Feast, plus dry food). They have **no barcodes**: the spreadsheet has none.

- Load them: `php artisan migrate` (new audit columns), then `php artisan products:import database/seeds/catfood_seed.json`
  (a fresh `migrate:fresh --seed` in a `local` environment does it too). Add `--dry-run` first if you like.
- Regenerate the file after editing the spreadsheet: `python3 scripts/xlsx_to_seed.py path/to/catfood.xlsx` (needs `openpyxl`).
- Every row records its sheet and row number, the spreadsheet's own link, and a list of data flags (missing values,
  `#DIV/0!`, unreadable cells) in `meta`/`audit_notes`. Prices are a Dec 2024 snapshot, never current prices.
- Nutrition numbers are **the spreadsheet's values, not label values** (probably dry-matter) and are shown as such until audited.
- Re-importing is safe: a seeded product is identified by `import_key`, and once someone attaches a barcode, edits it, or
  marks it reviewed, later imports skip it (the command reports how many were skipped).

**Wire-up workflow (Scan tab):** scan a package whose barcode is unknown, search the products that still lack a barcode,
tap "This one" twice (second tap confirms). Or the other way round: Catalogue tab, pick a product, "Scan to attach barcode".
The Catalogue tab also shows progress (barcodes, reviewed, images) and the audit controls: *Looks right*, *Needs changes*, *Edit*.

Pilot caveat: any signed-in user can edit the shared catalogue. Roles come later.

## Brand ladder, regions, and facets

Real products carry up to five names (Purina > Pro Plan > Complete Essentials > ...), and depth varies by brand. The
catalogue stores that as a **brand tree** (`brand_nodes`, depth capped at 5, every level optional). A product points at
the deepest node it belongs to and keeps its own `name`. `brand`, `line`, `path_text` and `search_text` on the product are
caches derived from the tree; edit the ladder in the app (Catalogue > Details > Edit, "Brand ladder") rather than those columns.

- **Evidence:** `docs/brand-tree-us/` holds 390 real US examples from 60 brands that the depth cap and rules were vetted against.
- **Placement rules:** `database/seeds/brand_map_us.json` lists where the flat spreadsheet columns hide a ladder (manufacturer
  above brand, a sub-line in the variety column, a clinical diet). Anything not listed becomes brand > line.
- **Existing database:** `php artisan migrate`, then `php artisan catalogue:build-tree` (safe to repeat; never touches names,
  barcodes or audit status). A new import places products automatically. `--force` re-places products that already have a node,
  except ones whose path a person set by hand.
- **Facets, not names:** texture (pate, mousse, shreds, chunks, flaked, minced, ...), medium (gravy, broth, sauce, jelly, ...),
  life stage and diet are tag rows, filterable in or out (Catalogue > Texture and diet filters: tap once to include, twice to
  exclude). **Clinical** is a deliberate workaround tag for vet-diet variants until we learn how owners of special-needs
  cats prefer to find them; it labels how the product is sold and makes no health claim.
- **Region fork:** every catalogue table has a `region` (US only for now, not shown in the UI). A user's requests only see
  their region's brand tree, products and barcodes; the same barcode can exist in two regions as two different products.
- **Pack barcodes:** `products.gtin` stays the primary barcode; other packs (multipack, case) go in `product_barcodes`.
  Scanning any of them finds the product. In the Scan tab, tick "also show products that already have a barcode".
- **Advisories:** an advisory naming a manufacturer or brand matches every product beneath it in the ladder.

## Renames, aliases, life stage and recipe versions

The industry renames and reformulates constantly, so the catalogue is built to absorb it instead of fighting it.

- **Aliases.** Every brand-tree node can carry aliases ("Purina Pro Plan" for "Pro Plan", the printed "Adult 7+ | Prime Plus" for the line "Prime Plus"). Typing, importing or scanning an alias lands on the existing node instead of creating a duplicate, and aliases are searchable.
- **Curated ladder.** `database/seeds/brand_suggestions_us.json` is the hand-maintained list of brands, lines and sub-lines (with species, aliases and inherited tags); the instructions are at the top of the file. `php artisan catalogue:seed-brands` creates the nodes (safe to repeat); add `--prune-empty` to drop empty nodes the file no longer lists, `--dry-run` to preview.
- **Life stage is a facet, not a rung.** Kitten / Adult / Adult 7+ / Senior / All life stages ride with the product as tags, read from line, variety and product names. A line name that is only a life stage is turned into a tag instead of a tree level. A line that only ever holds one stage can carry it as an inherited tag (Prime Plus -> `life_stage:adult-7plus`).
- **Rename, move, merge** (API): `PATCH /api/brand-nodes/{id}` (`name`, `parent_id`, `kind`, `aliases`, `species`) keeps the old name as an alias and rewrites paths and depth for the subtree; a name that collides is refused. `POST /api/brand-nodes/{id}/merge {"into": id}` folds one node into another (products, children, names). Both respect the 5-level cap. Moving a product to a different line is the existing `PATCH /api/products/{id}` with `path`.
- **Recipe versions.** A product is the stable thing an owner knows (barcode, ratings, pantry); its name, ingredients and nutrition live in `product_versions`. Editing with `formula_change: "correction"` (default) fixes the current version; `"new_version"` keeps the old recipe as history, bumps `formula_version`, and the card says so neutrally. `GET /api/products/{id}/versions` lists them. A new barcode on a reformulated product, and flagging ratings made before a recipe change, are not built yet.
- There is no screen for renaming or merging nodes yet; use the API until the node review screen exists.

## Roles, moderation and visibility

- Accounts have a role: `user`, `moderator` or `admin`. Roles cannot be set through the API; use `php artisan user:role you@example.com admin`. The migration makes the first registered account an admin.
- **Users contribute products, never brand-tree nodes.** A product a user adds is `pending` and **private to them**: it works in their pantry, ratings and suggestions straight away, and nobody else can find it (search, scan, browse and the brand picker all go through one visibility scope, `VisibleScope`, with leak tests in `ModerationVisibilityTest`). Staff additions are approved immediately.
- The ladder a user picks must already exist (names and aliases match exactly). If it does not, the product stays **unplaced**: no node, no nearest match, the typed ladder kept verbatim in `requested_path` for a moderator.
- Users can edit only their own unapproved products. Only staff edit the public catalogue or attach barcodes to it; only admins rename, move or merge nodes.
- Moderation states: `approved`, `pending`, `needs_changes`, `rejected` (stays private to the contributor), `merged` (a duplicate; records are re-pointed). The same barcode can be held privately by different people; it is unique within the public catalogue.
- Every change to a product or brand node is written to `audit_logs` with who and what changed. The admin tool, `/api/v1`, Scramble docs and the moderation queue are the next steps; see `docs/design-notes.md`.

## Not built yet

- An admin screen to enter advisories and products (use the import commands for now).
- Automatic advisory feeds from official sources, and re-matching advisories when new products are added.
- Product image upload (only `image_url` is stored) and editing a product after it is created.
- Offline use, password reset, email verification, rate limits beyond login, and any non-iOS testing.
- The client has been tested only in headless Chromium at phone size, never on a real iPhone.

## Notes

- CORS uses Laravel's defaults. Restrict `config/cors.php` to the pilot web app's origin before real use.
- Registration is open. For a closed pilot, create accounts by hand or put a signup code in front of `/register`.
- `CLAUDE.md` and `AGENTS.md` come from the Laravel starter kit and can be deleted.

## API versioning and docs

All endpoints live under `/api/v1`. Interactive docs (Scramble, local env only) are at `/docs/api`; the machine-readable
contract is committed as `docs/openapi.json`. After changing any route, request, resource or `@response` docblock, regenerate it:

```
php artisan scramble:export --path=docs/openapi.json
```

`ApiContractTest` fails when the committed spec drifts from the code. Product lists use the standard Laravel envelope
(`data`, `links`, `meta.total`, `meta.current_page`, ...). Staff-only product fields (`audit_status`, `audit_notes`, `import_key`, `meta`) are omitted for everyone else.

## Admin API (`/api/v1/admin`, moderators and admins)

| Area | Endpoints |
| --- | --- |
| Dashboard | `GET /admin/dashboard` queue sizes, catalogue gaps, empty and near-duplicate nodes |
| Products | `GET /admin/products` (all contributors, filters: `moderation_status`, `placed`, `q`, `created_by`, `barcode`, `image`), `GET /admin/products/{id}`, `GET /admin/products/export` (CSV) |
| Decisions | `POST /admin/products/{id}/moderate` (approve / needs_changes / reject, note required to send back or decline), `/place`, `/merge`, `POST /admin/products/bulk` |
| Requests | `GET /admin/requests` unplaced products grouped by the ladder people typed; `POST /admin/requests/resolve` places (and optionally approves) a whole group; `POST /admin/requests/decline` |
| Ladder | `GET /admin/nodes`, `POST /admin/nodes`; rename/move/merge stay on `PATCH /brand-nodes/{id}` and `POST /brand-nodes/{id}/merge` (admin) |
| Retire (admin) | `POST /admin/nodes/{id}/retire` with `status` active / retiring / retired / disabled, `preview: true` for an impact report, `DELETE /admin/nodes/{id}` only when empty |
| History | `GET /admin/audit-log?subject_type=Product&subject_id=42` |

Rules enforced here: an unplaced product cannot be approved; a barcode already in the public catalogue means merge, not a second copy;
merging moves pantry stock, ratings, meal history, advisory matches and barcodes to the survivor. A retired node is inherited by
everything beneath it, stays visible to anyone who already owns the product, and is no longer offered in the picker to non-staff.

## Admin tool (`/admin`)

A static page (Alpine.js, vendored at `public/vendor/alpine.min.js`, no build step) on the same origin as the API. Moderators and admins sign
in with their normal account (the token is shared with the app via the browser). Tabs: Overview, Products (filter, review drawer, bulk
decisions, merge duplicates, CSV export), Ladder requests, Brand ladder (add, rename, merge, retire with impact preview, delete when empty), History.
Source: `resources/admin/index.html`. Product images and logo upload come next.

## Images and logos

Product pictures and brand logos are uploaded in the admin tool (staff only) and stored on the disk named by `CATFUD_IMAGE_DISK`
(default `public`, i.e. `storage/app/public`). Run `php artisan storage:link` once so `/storage/...` is served. `FILESYSTEM_PUBLIC_URL`
defaults to the relative `/storage`; set an absolute URL or CDN base for a native app. Each product has one current picture with its
source (manufacturer, retailer, own photo, other), optional source link, licence/permission note and credit line (`product_images`);
`products.image_url` always points at the current file, so the apps need no change. Replacing or removing a picture deletes the old file.
A node logo uploaded here overrides the built-in `public/images/brands/{slug}.*` file in the ladder picker.
Hash-based duplicate detection and several pictures per product (front, back, label) are not built yet.

## Catalogue files (source of truth for brands and products)

`database/catalogue/{region}/` holds `vocabulary.json` (tag groups), `ladders/{root}.json` (one brand ladder per file) and `products/{brand}.json` (every product with an explicit `path`, a list of rung names). Nothing is inferred at import time: the importer creates exactly the rungs the ladder files list, product paths never create rungs, and tags are exactly the listed ones.

Workflow, one brand at a time: edit the files, set `"reviewed": true`, then `php artisan catalogue:check <brand>` (errors block, `--warnings` shows flags), `php artisan catalogue:import --ladders`, `php artisan catalogue:import <brand>` (`--dry-run` to preview, `--overwrite` to replace values on existing rungs). Re-importing is safe and skips audited products. `catalogue:export` writes draft files from a database (never over reviewed files without `--force`).

### Rung status
Enum `App\Enums\NodeStatus`: `active`, `retiring` (still offered), `retired` (gone from the market) and `disabled` (switched off by us). Retired and disabled are hidden from non-staff pickers; a rung inherits a non-active status from its nearest ancestor. Every change records `previous_status` automatically, so a retired rung later disabled still reads "was retired"; the admin node list filters by current status (`status=`) and history (`ever=`). Detail: `status_on`, `status_confidence`, `status_source`, `status_note`, `successor_id`.
