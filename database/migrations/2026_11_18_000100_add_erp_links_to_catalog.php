<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrasi ERP DK: barang/varian toko untuk company yang tersambung ke ERP adalah salinan dari ERP (ERP = sumber data).
 * Kunci tautan = id barang/varian ERP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('erp_product_id')->nullable()->after('company_id');
            $table->timestamp('erp_synced_at')->nullable();
            $table->unique(['company_id', 'erp_product_id']);
        });
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedBigInteger('erp_variant_id')->nullable()->after('variant_id');
            $table->index('erp_variant_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropIndex(['erp_variant_id']);
            $table->dropColumn('erp_variant_id');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'erp_product_id']);
            $table->dropColumn(['erp_product_id', 'erp_synced_at']);
        });
    }
};
