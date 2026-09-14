# Listing profit, warnings and rule freight filter — Design

Date: 2026-09-14
Package: `thayron/lunar-cjdropshipping` (release v0.3.0)
Builds on: `docs/specs/2026-09-13-cj-catalog-listing-design.md`

## Goal

Stop listing products where shipping eats the margin:
1. The confirmation page shows the real profit per variant, net of VAT and card fees, and warns about risky rows.
2. Import rules can mark candidates whose shipping cost is too high, so they leave the default import list view.

Exchange rates are now kept current by the app command `currencies:update-rates`, which runs daily. This is already done in the app and is out of scope here.

## Decisions (approved)

| Topic | Decision |
|---|---|
| Profit | Computed per variant in the listing currency: price − VAT − total cost − card fee. |
| VAT | From Lunar tax zones for the ship-to country. A tax-inclusive zone takes VAT out of the price (price × rate ÷ (100 + rate)). A tax-exclusive zone or no zone means no VAT deduction. |
| Card fee | Config `pricing.card_fee_percent` (default 1.5) plus `pricing.card_fee_fixed` (default 0.20), both in listing-currency major units. |
| Margin % | Kept, but recomputed as profit ÷ price. `margin_at_risk` and the negative-margin rule use the same definition. |
| Warnings | Informational only; they never block listing. |
| Rule filter | Optional destination country, max shipping % of product cost, and max quotes per run (default 50). Runs as a separate job. |
| Filtered candidates | New status `ShippingTooHigh`, hidden by the default status filter and never deleted. |

## 1. Pricing — `Pricing\ListingPriceCalculator`

- `profit(string $price, string $costUsd, string $shippingUsd, Currency $currency, string $vatPercent, bool $vatInclusive): ?string`
  - Returns major units with the currency's decimal places, half-up.
  - Returns null when price ≤ 0.
  - Formula (BCMath, scale 12):
    - `vat = vatInclusive ? price × vat% ÷ (100 + vat%) : 0`
    - `fee = price × card_fee_percent ÷ 100 + card_fee_fixed`
    - `cost = (costUsd + shippingUsd) × usdToCurrencyRate(currency)`
    - `profit = price − vat − fee − cost`
- `margin(...)` gains the same VAT arguments and returns `profit ÷ price × 100` (2 decimals).
- The existing callers (the page's `marginFor`, the `ConfirmListing` negative-margin check, `SyncProduct::isMarginAtRisk`) pass VAT data:
  - The page and the action resolve VAT from `ship_to_country`.
  - Sync resolves VAT from `ProductLink::ship_to_country`. Links without a destination use no VAT, which is today's behaviour.
- Recommended price is unchanged: markup on (cost + shipping), converted and rounded.

### VAT resolver — `Pricing\VatResolver`
- `forCountry(?string $iso2): array{percent: string, inclusive: bool}`
- Looks up a Lunar `Country` by iso2, then a `TaxZone` linked to that country through `TaxZoneCountry`.
  - It prefers an active tax-inclusive zone, then any active zone.
  - The rate is the sum of `TaxRateAmount.percentage` for the default `TaxClass` across the zone's tax rates.
- Returns `['percent' => '0', 'inclusive' => false]` when nothing matches.
- Memoized per request.

## 2. Confirmation page warnings

Row flags, computed in the page and shown as a ⚠️ icon with a tooltip in a new narrow "Checks" column:
- `rrp_below_cost`: CJ RRP (converted) < total cost (converted).
- `shipping_over_product`: shipping USD > CJ cost USD.

A page-level warning banner appears above the table when the chosen currency differs from the currency of any ticked site.
- Site currency comes from `Support\SiteCurrencies::for(array $channelIds): array<int, string>` (channel id → currency code).
- The default reads the optional `storefront_channels` table when it exists: `Schema::hasTable`, cached per request, join `currency_id` → code.
- The default channel with no storefront row uses the Lunar default currency.
- It is bound in the container so apps can replace it.
- The importer takes no composer dependency on the themes package.

New "Profit" column next to "Margin", showing profit in the listing currency. Red when ≤ 0, amber when below `min_margin_percent`, green otherwise.

Translations go in both `lang/en` and `lang/pt_BR`: column labels, tooltips, banner.

## 3. Rule freight filter

### Schema (new migration)
- `cj_import_rules`:
  - `ship_to_country` string(2) nullable
  - `max_shipping_percent` decimal(8,2) nullable
  - `max_quotes_per_run` unsigned smallint, default 50
- `cj_candidates`:
  - `shipping_usd` decimal(12,2) nullable
  - `shipping_checked_at` timestamp nullable
- `CandidateStatus::ShippingTooHigh = 'shipping_too_high'` (colour warning; translations in both languages).

### Flow
- `DiscoverCandidatesJob` is unchanged. After discovery, when the rule has `ship_to_country` and `max_shipping_percent`, it dispatches `CheckCandidateShippingJob($rule)`.
- `Actions\CheckCandidateShipping::handle(ImportRule $rule): array{checked: int, too_high: int, skipped: int}`
  1. Selects candidates of that rule with status Pending and `shipping_checked_at` null, oldest first, limited to `max_quotes_per_run`.
  2. For each candidate:
     - Throttle, then `products()->find()` to get variants, weights and the product origin. Origin is the rule `country_code`, else the first warehouse country with stock from `inventoryByProduct`, else `CN`.
     - Quote shipping for the lightest variant with `FreightQuoter` (one call, 10 quota points).
     - Take the cheapest option price.
  3. Stores `shipping_usd` and `shipping_checked_at`.
  4. Status becomes `ShippingTooHigh` when `shipping_usd > cost_usd × max_shipping_percent ÷ 100`, or when no method is quoted at all.
  5. On `QuotaExceededException`, stops and releases the job until quota reset (`QuotaDelay`). On `NotFoundException`, marks the candidate `Unavailable`. Any other exception is logged; the candidate is marked checked with a null shipping, stays Pending, and the run continues.
- `cj:discover` also dispatches the check job for rules with the filter configured.
- `ImportRuleResource` form gains the three fields, with helper text explaining the quota cost (10 points per quote).
- The Import list filter by status shows `shipping_too_high`. The default view stays Pending, so filtered items are hidden.
- The bulk `reset` action accepts `ShippingTooHigh`.

## 4. Error handling

- Missing currency or VAT data on the page: profit and warnings show "—" and never block.
- Rule check job: `retryUntil` 48h, `maxExceptions` 3, unique per rule. Quota handling is the same as the other jobs.

## 5. Testing

- **Calculator:** `profit` and `margin` with inclusive VAT 20% (GBP), no VAT, and card fee. Known values:
  - £19.99, cost US$2.99 + shipping US$9.17, rate 0.74, VAT 20% inclusive, fee 1.5% + 0.20 → VAT 3.3317, fee 0.4999, cost 8.9984 → profit **7.16**, margin **35.82%**
  - **Compute exact expected values in the test by hand from these inputs, not with the implementation.**
- **VatResolver:** inclusive zone preferred; exclusive zone gives no deduction; unknown country gives zero.
- **Page:** profit column value; `rrp_below_cost` and `shipping_over_product` flags; currency mismatch banner with a storefront row; no banner without the table.
- **ConfirmListing:** the negative-margin rule uses the VAT-aware margin.
- **SyncProduct:** margin at risk accounts for VAT on links with `ship_to_country`.
- **CheckCandidateShipping:**
  - marks too high;
  - keeps Pending under the threshold;
  - respects `max_quotes_per_run`;
  - skips already-checked candidates;
  - releases on quota;
  - marks Unavailable on NotFound.
- **Rule form:** new fields save. **Import list:** reset accepts `ShippingTooHigh`.

## Out of scope
- Automatic currency conversion of already-listed prices.
- Per-site different VAT when several sites are ticked. The destination country decides VAT.
- Blocking listing on warnings.
