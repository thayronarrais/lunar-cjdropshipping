<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->removeDuplicateCandidates();

        Schema::table('cj_candidates', function (Blueprint $table): void {
            $table->dropForeign(['import_rule_id']);
        });

        Schema::table('cj_candidates', function (Blueprint $table): void {
            // Drop the unique index sharing the column before the index() below, then
            // re-add a plain index so MySQL always keeps one covering import_rule_id
            // (dropping the FK above removes the index it implied).
            $table->dropUnique(['import_rule_id', 'cj_product_id']);
            $table->unique('cj_product_id');
            $table->index('import_rule_id');
        });

        Schema::table('cj_candidates', function (Blueprint $table): void {
            $table->foreignId('import_rule_id')->nullable()->change();
            $table->foreign('import_rule_id')->references('id')->on('cj_import_rules')->nullOnDelete();
            $table->string('source')->default('rule')->after('import_rule_id');
            $table->json('listing')->nullable()->after('payload');
            $table->timestamp('listed_at')->nullable()->after('listing');
        });

        Schema::table('cj_product_links', function (Blueprint $table): void {
            $table->string('ship_from_country', 2)->nullable()->after('country_code');
            $table->string('ship_to_country', 2)->nullable()->after('ship_from_country');
            $table->string('shipping_method')->nullable()->after('ship_to_country');
            $table->string('currency_code', 3)->nullable()->after('shipping_method');
            $table->boolean('price_locked')->default(false)->after('currency_code');
            $table->boolean('margin_at_risk')->default(false)->index()->after('price_locked');
            $table->json('skipped_cj_variant_ids')->nullable()->after('new_cj_variant_ids');
        });

        Schema::table('cj_variant_links', function (Blueprint $table): void {
            $table->decimal('shipping_cost_usd', 12, 2)->nullable()->after('cost_usd');
            $table->decimal('price', 12, 2)->nullable()->after('shipping_cost_usd');
        });
    }

    public function down(): void
    {
        Schema::table('cj_variant_links', function (Blueprint $table): void {
            $table->dropColumn(['shipping_cost_usd', 'price']);
        });

        Schema::table('cj_product_links', function (Blueprint $table): void {
            $table->dropIndex(['margin_at_risk']);
            $table->dropColumn(['ship_from_country', 'ship_to_country', 'shipping_method', 'currency_code', 'price_locked', 'margin_at_risk', 'skipped_cj_variant_ids']);
        });

        DB::table('cj_candidates')->whereNull('import_rule_id')->delete();

        Schema::table('cj_candidates', function (Blueprint $table): void {
            $table->dropForeign(['import_rule_id']);
        });

        Schema::table('cj_candidates', function (Blueprint $table): void {
            $table->dropUnique(['cj_product_id']);
            $table->dropIndex(['import_rule_id']);
            $table->dropColumn(['source', 'listing', 'listed_at']);
            $table->foreignId('import_rule_id')->nullable(false)->change();
        });

        Schema::table('cj_candidates', function (Blueprint $table): void {
            $table->unique(['import_rule_id', 'cj_product_id']);
            $table->foreign('import_rule_id')->references('id')->on('cj_import_rules')->cascadeOnDelete();
        });
    }

    /**
     * The same CJ product found by several rules becomes one list item: rank by how far
     * along it is (imported first, then in-flight, then pending/failed, then anything
     * else), oldest first within a rank, and keep only that one row.
     */
    private function removeDuplicateCandidates(): void
    {
        $duplicates = DB::table('cj_candidates')
            ->select('cj_product_id')
            ->groupBy('cj_product_id')
            ->havingRaw('count(*) > 1')
            ->pluck('cj_product_id');

        foreach ($duplicates as $cjProductId) {
            $keep = DB::table('cj_candidates')
                ->where('cj_product_id', $cjProductId)
                ->orderByRaw("case
                    when status = 'imported' then 0
                    when status in ('importing', 'approved') then 1
                    when status in ('pending', 'failed') then 2
                    else 3
                end")
                ->orderBy('id')
                ->value('id');

            DB::table('cj_candidates')->where('cj_product_id', $cjProductId)->where('id', '!=', $keep)->delete();
        }
    }
};
