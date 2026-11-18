<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Services\Erp\ErpCatalogSync;
use App\Services\Erp\ErpConnection;
use App\Services\Erp\ErpException;
use Illuminate\Console\Command;

/** Sinkron katalog + stok dari ERP DK untuk semua company yang tersambung (atau satu company). */
class SyncErpCatalog extends Command
{
    protected $signature = 'erp:sync {--company= : Id company} {--full : Sinkron penuh (barang yang hilang di ERP dinonaktifkan)} {--stock : Stok saja}';

    protected $description = 'Sinkron barang, harga, foto, dan stok dari ERP DK';

    public function handle(ErpCatalogSync $sync): int
    {
        $companies = Company::query()->where('is_active', true)->when($this->option('company'), fn ($q, $id) => $q->whereKey((int) $id))->pluck('id');
        $failed = false;
        foreach ($companies as $companyId) {
            $erp = ErpConnection::for((int) $companyId);
            if (! $erp) {
                continue;
            }
            try {
                $result = $this->option('stock') ? ['stock' => $sync->stock($erp)] : $sync->run($erp, (bool) $this->option('full'));
                $this->info("Company {$companyId}: ".json_encode($result));
            } catch (ErpException $e) {
                $failed = true;
                CompanySetting::setMany((int) $companyId, ['erp_last_error' => ['message' => $e->getMessage(), 'at' => now()->toIso8601String()]]);
                $this->error("Company {$companyId}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
