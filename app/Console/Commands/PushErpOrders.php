<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Transaction;
use App\Services\Erp\ErpConnection;
use App\Services\Erp\ErpOrderSync;
use Illuminate\Console\Command;

/** Coba ulang pesanan toko yang belum/gagal terkirim ke ERP DK (SO, DP lunas, pembatalan). */
class PushErpOrders extends Command
{
    protected $signature = 'erp:push-orders {--company= : Id company}';

    protected $description = 'Kirim ulang pesanan toko yang belum tersinkron ke ERP DK';

    public function handle(ErpOrderSync $orders): int
    {
        $companies = Company::query()->where('is_active', true)->when($this->option('company'), fn ($q, $id) => $q->whereKey((int) $id))->pluck('id');
        $done = 0;
        foreach ($companies as $companyId) {
            if (! ErpConnection::enabled((int) $companyId)) {
                continue;
            }
            $pending = Transaction::query()->where('company_id', $companyId)
                ->where(fn ($q) => $q->whereNotNull('erp_error')->orWhere('erp_state', ErpOrderSync::PENDING)
                    ->orWhere(fn ($w) => $w->where('erp_state', ErpOrderSync::ORDERED)->where(fn ($p) => $p->where('payment_status', 'paid')->orWhere('status', 'dibatalkan'))))
                ->orderBy('id')->limit(100)->get();
            foreach ($pending as $tx) {
                $orders->sync($tx);
                $done++;
            }
        }
        $this->info("{$done} pesanan diproses.");

        return self::SUCCESS;
    }
}
