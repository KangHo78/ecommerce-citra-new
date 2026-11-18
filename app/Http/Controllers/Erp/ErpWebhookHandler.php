<?php

namespace App\Http\Controllers\Erp;

use App\Models\ProductVariant;
use App\Services\Erp\ErpCatalogSync;
use App\Services\Erp\ErpConnection;
use App\Services\Erp\ErpOrderEvents;

/** Isi webhook ERP → sinkron toko: katalog/stok di sini, event pesanan (sales_order.*, sales_delivery.*, sales_invoice.*) di ErpOrderEvents. */
class ErpWebhookHandler
{
    public function __construct(private ErpCatalogSync $sync) {}

    public function handle(ErpConnection $erp, string $event, array $data): void
    {
        match ($event) {
            'product.changed' => $this->sync->run($erp),
            'stock.changed' => $this->stock($erp, (array) ($data['skus'] ?? [])),
            default => app(ErpOrderEvents::class)->handle($erp, $event, $data),
        };
    }

    /** Stok varian yang disebut webhook: Σ tersedia gudang sumber. */
    private function stock(ErpConnection $erp, array $skus): void
    {
        foreach (array_slice($skus, 0, 200) as $sku) {
            $available = 0.0;
            foreach ($erp->warehouses ?: [null] as $code) {
                foreach ($erp->pages('/stock', array_filter(['sku' => $sku, 'warehouse_code' => $code])) as $row) {
                    $available += (float) $row['available'];
                }
            }
            ProductVariant::query()->where('sku', $sku)->whereHas('product', fn ($q) => $q->where('company_id', $erp->companyId))
                ->update(['stock' => max(0, (int) floor($available))]);
        }
    }
}
