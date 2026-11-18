<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Services\Erp\ErpConnection;
use App\Services\Erp\ErpOrderSync;

/**
 * Transaksi company yang tersambung ke ERP DK: dibuat → SO di ERP, lunas → DP, batal → SO dibatalkan. Berjalan setelah
 * commit (detail barang sudah tersimpan) dan tidak pernah menggagalkan checkout: galat ERP disimpan lalu dicoba ulang.
 */
class ErpTransactionObserver
{
    public bool $afterCommit = true;

    public function __construct(private ErpOrderSync $orders) {}

    public function created(Transaction $tx): void
    {
        $this->sync($tx);
    }

    public function updated(Transaction $tx): void
    {
        if ($tx->wasChanged(['payment_status', 'status'])) {
            $this->sync($tx);
        }
    }

    private function sync(Transaction $tx): void
    {
        if (ErpConnection::enabled((int) $tx->company_id)) {
            $this->orders->sync($tx->fresh() ?? $tx);
        }
    }
}
