<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Integrasi ERP DK tahap status: invoice ERP pesanan toko (nomor + lunas) dari webhook. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('erp_invoice_id')->nullable();
            $table->string('erp_invoice_code', 40)->nullable();
            $table->boolean('erp_invoice_paid')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['erp_invoice_id', 'erp_invoice_code', 'erp_invoice_paid']);
        });
    }
};
