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
use Thayron\LunarCjDropshipping\Catalog\ImportResult;
use Thayron\LunarCjDropshipping\Catalog\OptionResolver;
use Thayron\LunarCjDropshipping\Catalog\VariantWriter;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\CjProductStatus;
use Thayron\LunarCjDropshipping\Exceptions\ImportException;
use Thayron\LunarCjDropshipping\Mapping\VariantOptionParser;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
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
    ) {
    }

    public function handle(Candidate $candidate): ImportResult
    {
        $existing = ProductLink::query()->where('cj_product_id', $candidate->cj_product_id)->first();

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

        $link = DB::transaction(fn (): ProductLink => $this->createProduct($candidate->importRule, $cjProduct, $inventory));

        $candidate->forceFill(['status' => CandidateStatus::Imported, 'lunar_product_id' => $link->lunar_product_id, 'error' => null])->save();

        $imageUrls = $cjProduct->images !== [] ? $cjProduct->images : array_filter([$cjProduct->mainImage]);

        return new ImportResult($link, array_values($imageUrls));
    }

    private function createProduct(ImportRule $rule, CjProduct $cjProduct, ProductInventory $inventory): ProductLink
    {
        if ($cjProduct->variants === []) {
            throw new ImportException("CJ product {$cjProduct->id} has no variants.");
        }

        $product = Product::create([
            'product_type_id' => $rule->product_type_id,
            'brand_id' => $rule->brand_id,
            'status' => 'draft',
            'attribute_data' => collect([
                'name' => $this->translatedText($cjProduct->name ?? $cjProduct->id),
                'description' => $this->translatedText($cjProduct->description ?? ''),
            ]),
        ]);

        $product->scheduleChannel(Channel::getDefault());

        if ($rule->collection_id !== null) {
            $product->collections()->attach($rule->collection_id, ['position' => 1]);
        }

        $link = ProductLink::create([
            'cj_product_id' => $cjProduct->id,
            'lunar_product_id' => $product->id,
            'import_rule_id' => $rule->id,
            'markup_percent' => $rule->markup_percent,
            'rounding' => $rule->rounding,
            'country_code' => $rule->country_code !== null ? strtoupper($rule->country_code) : null,
            'cj_status' => CjProductStatus::Active,
            'new_cj_variant_ids' => [],
            'last_synced_at' => now(),
        ]);

        $raw = $cjProduct->raw();
        $parsed = $this->parser->parse($raw['productKeyEn'] ?? null, $cjProduct->variants);
        $optionModels = array_map(fn (string $name) => $this->options->option($name), $parsed['options']);

        foreach ($optionModels as $position => $option) {
            $product->productOptions()->attach($option->id, ['position' => $position + 1]);
        }

        foreach ($cjProduct->variants as $cjVariant) {
            $this->variants->create($product, $link, $cjProduct, $cjVariant, $parsed['values'][$cjVariant->id] ?? [], $optionModels, $inventory);
        }

        return $link;
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
