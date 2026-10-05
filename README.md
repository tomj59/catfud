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
# catfud
