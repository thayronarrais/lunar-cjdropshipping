<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Illuminate\Support\Facades\DB;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\Product;
use Lunar\Models\TaxClass;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Data\Product as CjProduct;
use Thayron\CjDropshipping\Data\ProductInventory;
use Thayron\CjDropshipping\Data\Variant as CjVariant;
use Thayron\LunarCjDropshipping\Catalog\ImportResult;
use Thayron\LunarCjDropshipping\Catalog\OptionResolver;
use Thayron\LunarCjDropshipping\Catalog\VariantWriter;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\CjProductStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Exceptions\ImportException;
use Thayron\LunarCjDropshipping\Listing\Listing;
use Thayron\LunarCjDropshipping\Listing\ListingVariant;
use Thayron\LunarCjDropshipping\Mapping\VariantOptionParser;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Support\Throttle;

final class ImportProduct
{
    public function __construct(
        private readonly CjClient $cj,
        private readonly Throttle $throttle,
        private readonly VariantOptionParser $parser,
        private readonly OptionResolver $options,
        private readonly VariantWriter $variants,
    ) {}

    public function handle(Candidate $candidate): ImportResult
    {
        $existing = ProductLink::query()->where('cj_product_id', $candidate->cj_product_id)->first();

        if ($existing !== null && ! $existing->product()->exists()) {
            DB::transaction(function () use ($existing): void {
                $existing->variantLinks()->delete();
                $existing->delete();
            });
            $existing = null;
        }

        if ($existing !== null) {
            $candidate->forceFill(['status' => CandidateStatus::Imported, 'lunar_product_id' => $existing->lunar_product_id, 'error' => null])->save();

            return new ImportResult($existing, []);
        }

        $this->assertStoreIsReady();
        $candidate->forceFill(['status' => CandidateStatus::Importing, 'error' => null])->save();

        $this->throttle->wait();
        $cjProduct = $this->cj->products()->find($candidate->cj_product_id);
        $this->throttle->wait();
        $inventory = $this->cj->products()->inventoryByProduct($cjProduct->id);

        $listing = is_array($candidate->listing) ? Listing::fromArray($candidate->listing) : null;
        $link = DB::transaction(fn (): ProductLink => $this->createProduct($candidate, $listing, $cjProduct, $inventory));

        $candidate->forceFill(['status' => CandidateStatus::Imported, 'lunar_product_id' => $link->lunar_product_id, 'error' => null])->save();

        $imageUrls = $cjProduct->images !== [] ? $cjProduct->images : array_filter([$cjProduct->mainImage]);

        return new ImportResult($link, $imageUrls);
    }

    private function createProduct(Candidate $candidate, ?Listing $listing, CjProduct $cjProduct, ProductInventory $inventory): ProductLink
    {
        $settings = $this->settings($candidate, $listing, $cjProduct);

        /** @var array<string, ListingVariant> $selected */
        $selected = [];

        foreach ($listing?->selectedVariants() ?? [] as $listedVariant) {
            $selected[$listedVariant->vid] = $listedVariant;
        }

        $variants = $listing === null
            ? $cjProduct->variants
            : array_values(array_filter($cjProduct->variants, fn (CjVariant $variant): bool => isset($selected[$variant->id])));

        if ($listing !== null && count($variants) !== count($selected)) {
            throw new ImportException("Some selected variants of CJ product {$cjProduct->id} are no longer available.");
        }

        if ($variants === []) {
            throw new ImportException("CJ product {$cjProduct->id} has no variants.");
        }

        $product = Product::create([
            'product_type_id' => $settings['product_type_id'],
            'brand_id' => $settings['brand_id'],
            'status' => 'draft',
            'attribute_data' => collect([
                'name' => $this->translatedText($settings['name']),
                'description' => $this->translatedText($cjProduct->description ?? ''),
            ]),
        ]);

        $channels = $listing !== null && $listing->channelIds !== []
            ? Channel::query()->whereIn('id', $listing->channelIds)->get()
            : collect();

        $product->scheduleChannel($channels->isNotEmpty() ? $channels : Channel::getDefault());

        if ($settings['collection_id'] !== null) {
            $product->collections()->attach($settings['collection_id'], ['position' => 1]);
        }

        $link = ProductLink::create([
            'cj_product_id' => $cjProduct->id,
            'lunar_product_id' => $product->id,
            'import_rule_id' => $candidate->import_rule_id,
            'markup_percent' => $settings['markup_percent'],
            'rounding' => $settings['rounding'],
            'country_code' => $settings['country_code'],
            'ship_from_country' => $listing?->shipFromCountry,
            'ship_to_country' => $listing?->shipToCountry,
            'shipping_method' => $listing?->shippingMethod,
            'currency_code' => $listing?->currencyCode,
            'price_locked' => $listing !== null,
            'cj_status' => CjProductStatus::Active,
            'new_cj_variant_ids' => [],
            'skipped_cj_variant_ids' => $listing?->skippedVariantIds() ?? [],
            'last_synced_at' => now(),
        ]);

        $raw = $cjProduct->raw();
        $parsed = $this->parser->parse($raw['productKeyEn'] ?? null, $variants);
        $optionModels = array_map(fn (string $name) => $this->options->option($name), $parsed['options']);

        foreach ($optionModels as $position => $option) {
            $product->productOptions()->attach($option->id, ['position' => $position + 1]);
        }

        foreach ($variants as $cjVariant) {
            $this->variants->create($product, $link, $cjProduct, $cjVariant, $parsed['values'][$cjVariant->id] ?? [], $optionModels, $inventory, $selected[$cjVariant->id] ?? null);
        }

        return $link;
    }

    /**
     * @return array{name: string, product_type_id: int, brand_id: int|null, collection_id: int|null, markup_percent: string, rounding: PriceRounding, country_code: string|null}
     */
    private function settings(Candidate $candidate, ?Listing $listing, CjProduct $cjProduct): array
    {
        if ($listing !== null) {
            return [
                'name' => $listing->name,
                'product_type_id' => $listing->productTypeId,
                'brand_id' => $listing->brandId,
                'collection_id' => $listing->collectionId,
                'markup_percent' => $listing->markupPercent,
                'rounding' => $listing->rounding,
                'country_code' => $listing->shipFromCountry,
            ];
        }

        $rule = $candidate->importRule ?? throw new ImportException("Candidate {$candidate->cj_product_id} has no listing or import rule.");

        return [
            'name' => $cjProduct->name ?? $cjProduct->id,
            'product_type_id' => $rule->product_type_id,
            'brand_id' => $rule->brand_id,
            'collection_id' => $rule->collection_id,
            'markup_percent' => (string) $rule->markup_percent,
            'rounding' => $rule->rounding,
            'country_code' => $rule->country_code !== null ? strtoupper($rule->country_code) : null,
        ];
    }

    private function translatedText(string $value): TranslatedText
    {
        return new TranslatedText(collect($this->options->translated($value))->map(fn (string $text) => new Text($text)));
    }

    private function assertStoreIsReady(): void
    {
        if (Currency::getDefault() === null) {
            throw new ImportException('Lunar has no default currency.');
        }

        if (TaxClass::getDefault() === null) {
            throw new ImportException('Lunar has no default tax class.');
        }

        if (Channel::getDefault() === null) {
            throw new ImportException('Lunar has no default channel.');
        }
    }
}
