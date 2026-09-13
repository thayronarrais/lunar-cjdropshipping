<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lunar\Models\Brand;
use Lunar\Models\Collection;
use Lunar\Models\Product;
use Lunar\Models\ProductType;
use Lunar\Models\ProductVariant;

return new class extends Migration
{
    public function up(): void
    {
        $products = (new Product)->getTable();
        $variants = (new ProductVariant)->getTable();

        Schema::create('cj_import_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->json('category_ids')->nullable();
            $table->string('keyword')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->unsignedInteger('min_stock')->default(0);
            $table->decimal('min_cost', 12, 2)->nullable();
            $table->decimal('max_cost', 12, 2)->nullable();
            $table->decimal('markup_percent', 8, 2)->default(0);
            $table->string('rounding')->default('none');
            $table->foreignId('product_type_id')->constrained((new ProductType)->getTable());
            $table->foreignId('brand_id')->nullable()->constrained((new Brand)->getTable())->nullOnDelete();
            $table->foreignId('collection_id')->nullable()->constrained((new Collection)->getTable())->nullOnDelete();
            $table->unsignedSmallInteger('max_pages')->default(5);
            $table->timestamp('last_run_at')->nullable();
            $table->json('last_run_stats')->nullable();
            $table->timestamps();
        });

        Schema::create('cj_candidates', function (Blueprint $table) use ($products) {
            $table->id();
            $table->foreignId('import_rule_id')->constrained('cj_import_rules')->cascadeOnDelete();
            $table->string('cj_product_id');
            $table->string('cj_sku')->nullable();
            $table->string('name');
            $table->text('image_url')->nullable();
            $table->decimal('cost_usd', 12, 2)->nullable();
            $table->integer('warehouse_stock')->nullable();
            $table->string('cj_category_id')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('error')->nullable();
            $table->foreignId('lunar_product_id')->nullable()->constrained($products)->nullOnDelete();
            $table->json('payload');
            $table->timestamp('discovered_at');
            $table->timestamps();

            $table->unique(['import_rule_id', 'cj_product_id']);
        });

        Schema::create('cj_product_links', function (Blueprint $table) use ($products) {
            $table->id();
            $table->string('cj_product_id')->unique();
            $table->foreignId('lunar_product_id')->constrained($products)->cascadeOnDelete();
            $table->foreignId('import_rule_id')->nullable()->constrained('cj_import_rules')->nullOnDelete();
            $table->decimal('markup_percent', 8, 2)->default(0);
            $table->string('rounding')->default('none');
            $table->string('country_code', 2)->nullable();
            $table->string('cj_status')->default('active')->index();
            $table->unsignedTinyInteger('not_found_count')->default(0);
            $table->json('new_cj_variant_ids');
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->text('sync_error')->nullable();
            $table->timestamps();
        });

        Schema::create('cj_variant_links', function (Blueprint $table) use ($variants) {
            $table->id();
            $table->string('cj_variant_id')->unique();
            $table->foreignId('cj_product_link_id')->constrained('cj_product_links')->cascadeOnDelete();
            $table->foreignId('lunar_variant_id')->constrained($variants)->cascadeOnDelete();
            $table->string('cj_sku')->nullable();
            $table->decimal('cost_usd', 12, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cj_variant_links');
        Schema::dropIfExists('cj_product_links');
        Schema::dropIfExists('cj_candidates');
        Schema::dropIfExists('cj_import_rules');
    }
};
