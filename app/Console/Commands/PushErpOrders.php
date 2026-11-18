<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Transaction;
use App\Services\Erp\ErpConnection;
use App\Services\Erp\ErpException;
use App\Services\Erp\ErpOrderEvents;
use App\Services\Erp\ErpOrderSync;
use Illuminate\Console\Command;

/** Coba ulang pesanan toko yang belum/gagal terkirim ke ERP DK (SO, DP lunas, pembatalan). */
class PushErpOrders extends Command
{
    protected $signature = 'erp:push-orders {--company= : Id company} {--refresh : Ambil ulang status SO pesanan yang masih berjalan (cadangan bila webhook terlewat)}';

    protected $description = 'Kirim ulang pesanan toko yang belum tersinkron ke ERP DK';

    public function handle(ErpOrderSync $orders, ErpOrderEvents $events): int
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
            if ($this->option('refresh')) {
                $erp = ErpConnection::for((int) $companyId);
                $open = Transaction::query()->where('company_id', $companyId)->whereNotNull('erp_sales_order_id')
                    ->whereNotIn('status', ['selesai', 'dibatalkan'])->where('created_at', '>=', now()->subDays(60))
                    ->orderBy('id')->limit(200)->pluck('erp_sales_order_id');
                foreach ($open as $soId) {
                    try {
                        $events->refresh($erp, (int) $soId);
                        $done++;
                    } catch (ErpException $e) {
                        $this->warn("SO {$soId}: {$e->getMessage()}");
                    }
                }
            }
        }
        $this->info("{$done} pesanan diproses.");

        return self::SUCCESS;
    }
}
