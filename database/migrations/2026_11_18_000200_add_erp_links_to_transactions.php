<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrasi ERP DK tahap pesanan: transaksi toko company yang tersambung = SO di ERP (stok dipesan di ERP), pembayaran =
 * DP di ERP. erp_state: pending (belum terkirim) → ordered → paid / cancelled; erp_error = galat terakhir (dicoba ulang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('erp_sales_order_id')->nullable();
            $table->string('erp_sales_order_code', 40)->nullable();
            $table->string('erp_state', 20)->nullable();
            $table->string('erp_payment_reference', 100)->nullable();
            $table->text('erp_error')->nullable();
            $table->timestamp('erp_synced_at')->nullable();
            $table->index(['company_id', 'erp_state']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'erp_state']);
            $table->dropColumn(['erp_sales_order_id', 'erp_sales_order_code', 'erp_state', 'erp_payment_reference', 'erp_error', 'erp_synced_at']);
        });
    }
};
