<?php

namespace App\Services\Erp;

/**
 * Event pesanan dari webhook ERP (sales_order.updated, sales_delivery.updated|received, sales_invoice.updated|paid):
 * memperbarui status transaksi toko yang tertaut. Diisi pada tahap pesanan (T2/T3); event lain diabaikan.
 */
class ErpOrderEvents
{
    public function handle(ErpConnection $erp, string $event, array $data): void
    {
        // Belum ada pesanan toko yang tertaut ke ERP pada tahap katalog.
    }
}
