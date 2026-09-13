# CJ Catalog & Listing Flow — Design

Date: 2026-09-13
Packages: `thayron/lunar-cjdropshipping` (importer), `thayron/cjdropshipping-php` (SDK)

## Goal

Replace "rule finds candidates → import with automatic markup" with a flow modelled on CJ's own "List" screen:
browse the CJ catalog inside the Lunar admin, add products to an import list, and confirm each product by hand
(name, ship-from warehouse, ship-to country, currency, shipping method, per-variant prices with a price
recommendation) before it is imported.

## Decisions (approved)

| Topic | Decision |
|---|---|
| Existing flow | New catalog + list; import rules keep running but only feed the list (no direct import). |
| Price recommendation | Markup % on (CJ variant cost + selected method's shipping), converted to the chosen currency, rounded. CJ RRP shown as reference. |
| Destination & currency | Chosen by hand, per product (not every product is good to ship from/to a given country). No global default. |
| Price sync | Confirmed prices are locked. Sync updates cost/stock, never price; flags `margin_at_risk`. |
| Architecture | Approach A: Filament pages, `cj_candidates` reused as the import list. |

## 1. SDK — `thayron/cjdropshipping-php` v0.2.0 (additive)

- `Data\Variant::$suggestedSellPrice` (`?string`, from `variantSugSellPrice`, USD).
- `Data\Product::$suggestedSellPrice` (`?string`, from `suggestSellPrice`, may be a range `"0.97-4.08"`).
- `Resources\LogisticsResource`, exposed as `CjClient::logistics()`:
  - `freightCalculate(Criteria\FreightQuery $query): list<Data\FreightOption>`
  - `FreightQuery`: `startCountryCode`, `endCountryCode`, `products` = list of `{vid, quantity}`; built via a fluent/named constructor matching existing Criteria classes.
  - `FreightOption`: `name` (`logisticName`), `priceUsd` (`logisticPrice`), `priceCny` (`logisticPriceCN`), `aging` (`logisticAging`), `raw()`.
  - Endpoint `POST logistic/freightCalculate`; costs 10 quota points. POST → no retry on transport errors (existing Transport rule); rate-limit backoff applies.
- Unit tests with fixtures; live test in group `live`. Tag `v0.2.0`. Importer requires `^0.2`.

## 2. Importer — data model

Migration (new, does not edit the original):

- `cj_candidates`
  - `import_rule_id` → nullable (catalog-listed items have no rule). Unique `(import_rule_id, cj_product_id)` replaced by unique `cj_product_id`; discovery upserts by `cj_product_id` and keeps an existing rule id.
  - `source` string: `catalog` | `rule`.
  - `listing` json nullable — the confirmed listing (see below).
  - `listed_at` timestamp nullable.
  - `CandidateStatus` reuses `approved` for "listing confirmed, import job dispatched" and gains `unavailable` (removed on CJ). Existing: `pending`, `approved`, `importing`, `imported`, `failed`, `ignored`.
- `cj_product_links`: `ship_from_country` (2), `ship_to_country` (2), `shipping_method` string nullable, `currency_code` (3) nullable, `price_locked` bool default false, `margin_at_risk` bool default false (indexed). Existing rows keep `price_locked = false` → current behaviour unchanged for the 13 imported products.
- `cj_variant_links`: `shipping_cost_usd` decimal(12,2) nullable, `price` decimal(12,2) nullable (confirmed price in link currency).

`listing` JSON shape:

```json
{
  "name": "Autumn Winter Plaid Dog Jacket",
  "ship_from_country": "CN",
  "ship_to_country": "GB",
  "currency_code": "GBP",
  "shipping_method": "CJPacket Ordinary",
  "markup_percent": "120.00",
  "rounding": "ends_99",
  "product_type_id": 1,
  "brand_id": null,
  "collection_id": null,
  "variants": [
    {"vid": "…", "selected": true, "cost_usd": "3.47", "shipping_cost_usd": "5.43", "price": "19.99"}
  ]
}
```

Config: `pricing.min_margin_percent` (default 20), `freight.cache_ttl` (default 21600 s).

## 3. Importer — admin (Filament, inside `CjDropshippingPlugin`)

### 3.1 CJ Catalog page (`Filament\Pages\CjCatalog`)
- Live grid from `products()->search()` (listV2): filters ship-from country, category, keyword, min/max price, min stock; sort; pagination.
- Card: image, name, CJ price (USD), stock, button **List**; badge "In list" / "Imported" (lookup by `cj_product_id` in candidates/links for the current page).
- **List** creates a candidate (`source=catalog`, `status=pending`, payload = summary). Idempotent: existing candidate → notification "already in list".
- CJ failure → inline error, filters kept.

### 3.2 Import list (`CandidateResource`, relabelled "Import list")
- Columns add `source`; rule column nullable. Filters by status/source/rule.
- Row actions: **Confirm** (→ confirmation page; only `pending`/`failed`), Ignore, Open on CJ. The old direct **Import** row/bulk action is removed.

### 3.3 Confirmation page (`CandidateResource\Pages\ConfirmListing`)
Loads product detail (`products()->find()`) and inventory (`inventoryByProduct()`), pre-fills from an existing `listing` if present.

- **Name** (text, English, max 255).
- **Ship from**: select limited to countries where the product has stock.
- **Ship to**: country select (CJ warehouse country list + ISO list), required.
- **Currency**: enabled Lunar currencies, required.
- **Product type / brand / collection**: selects (defaults from rule when the item came from a rule).
- **Quote shipping** action → `FreightQuoter` → method select showing `name — US$ price — aging`.
- **Variants table** (Livewire table/repeater): checkbox selected, image, SKU, options, CJ cost, shipping, total cost, CJ RRP (converted), **your price** (input), margin % (red below `min_margin_percent`, negative highlighted).
- Bulk: **Recommend price** (markup % + rounding), **Adjust by %**, **Adjust by amount**, **Set price** — applied to selected variants.
- **List it now**: validates, stores `listing`, `listed_at`, `status=approved`, dispatches `ImportProductJob`.

Validation:
- ≥ 1 selected variant; ship from/to, currency and method required.
- Every selected variant has price > 0 and a shipping quote for the chosen method (variants the method cannot ship are unselectable with that method).
- Any selected price below total cost requires the checkbox "I accept a negative margin".
- Candidate must be `pending` or `failed` (idempotency; job reads the saved `listing`, never screen state).

## 4. Importer — domain

- `Pricing\ListingPriceCalculator`: `recommend(costUsd, shippingUsd, markupPercent, rounding, Currency): string` — BCMath scale 12, reuses the USD rate resolution and rounding of `PriceCalculator`; `margin(priceInCurrency, costUsd, shippingUsd, Currency): string` (percent of price).
- `Logistics\FreightQuoter`: `quote(pid, from, to, list<vid>): array<vid, list<FreightOption>>`. Groups variants by `variantWeight`, one `freightCalculate` call per distinct weight (quantity 1), through `Throttle`; results cached `freight.cache_ttl` per (pid, from, to). Returns methods available per variant.
- `Actions\ImportProduct`: when the candidate has a `listing`, uses its name, imports only selected variants, writes prices from `listing` in `currency_code` only, stores link/variant-link listing fields and `price_locked = true`, adds to CJ My Products (existing). Without `listing` (legacy path) behaviour is unchanged.
- `Actions\DiscoverCandidates`: unchanged discovery, no import dispatch; upsert by `cj_product_id`, `source=rule`.
- `Actions\SyncProduct`: for `price_locked` links, updates cost/stock only; recomputes margin per variant with new cost + stored `shipping_cost_usd` against stored `price`; sets `margin_at_risk` when any variant margin < `min_margin_percent`, clears it otherwise. Unlocked links: unchanged behaviour.
- A CJ "product not found" while confirming marks the candidate `unavailable`.
- `ProductLinkResource`: filter and badge for `margin_at_risk`.

## 5. Error handling

- Freight quote failure: notification "Quote failed, try again"; typed prices kept (Livewire state).
- Quota exhausted (`QuotaDelay`) during confirm/quote: notification with reset time.
- Import job failure: candidate `failed` with error; can be confirmed again (listing pre-filled).

## 6. Testing

- SDK: Variant/Product suggested price mapping; `freightCalculate` request body and response mapping; error mapping.
- Catalog page: filters build the right search criteria; List creates a candidate; duplicate List is a no-op; badges.
- Confirmation page: pre-fill, quote populates methods, recommend/bulk adjust, margin display, validation rules (none selected, missing price, negative margin checkbox, wrong status), List it now stores listing + dispatches job.
- `ListingPriceCalculator`, `FreightQuoter` (grouping by weight, cache, unavailable methods).
- `ImportProduct` with listing: only selected variants, price in chosen currency, link fields, `price_locked`.
- `SyncProduct`: locked price untouched; `margin_at_risk` set and cleared; unlocked unchanged.
- `DiscoverCandidates`: no import dispatched; upsert keeps existing candidate.
- Migration: legacy links unlocked; existing candidates keep their rule.

## Out of scope

- Orders, tracking, CJ order placement (SDK Phase B remainder).
- Editing descriptions/images on the confirmation page (done in Lunar product edit).
- Assigning sites/channels at listing time (products stay draft on the default channel, as today).
