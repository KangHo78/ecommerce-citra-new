<?php

namespace App\Services\Erp;

use App\Models\CompanySetting;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Koneksi satu company toko ke ERP DK lewat API v1 (kunci API Bearer per PT). Pengaturan di company_settings:
 * erp_enabled, erp_base_url (…/api/v1), erp_api_key (terenkripsi), erp_price_level_id (harga toko dari level ini,
 * kosong = harga dasar), erp_warehouse_codes (gudang sumber stok, kosong = semua), erp_webhook_secret (E3).
 */
final class ErpConnection
{
    public function __construct(
        public readonly int $companyId,
        public readonly string $baseUrl,
        private readonly string $apiKey,
        public readonly ?int $priceLevelId = null,
        /** @var list<string> */
        public readonly array $warehouses = [],
    ) {}

    /** Koneksi aktif company ini, atau null bila belum dinyalakan/belum lengkap. */
    public static function for(int $companyId): ?self
    {
        $s = CompanySetting::values($companyId);
        if (! filter_var($s['erp_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        return self::fromSettings($companyId, $s);
    }

    /** Koneksi dari pengaturan tersimpan walau belum dinyalakan (untuk Tes koneksi). */
    public static function configured(int $companyId): ?self
    {
        return self::fromSettings($companyId, CompanySetting::values($companyId));
    }

    public static function enabled(int $companyId): bool
    {
        return self::for($companyId) !== null;
    }

    /** Ringkasan untuk layar pengaturan (kunci tidak pernah dikirim balik). */
    public static function settings(int $companyId): array
    {
        $s = CompanySetting::values($companyId);

        return [
            'enabled' => filter_var($s['erp_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'base_url' => (string) ($s['erp_base_url'] ?? ''),
            'has_key' => ! empty($s['erp_api_key']),
            'price_level_id' => $s['erp_price_level_id'] ?? null,
            'warehouse_codes' => implode(', ', (array) ($s['erp_warehouse_codes'] ?? [])),
            'has_webhook_secret' => ! empty($s['erp_webhook_secret']),
            'synced_at' => $s['erp_catalog_synced_at'] ?? null,
            'last_error' => $s['erp_last_error'] ?? null,
            'last_result' => $s['erp_last_result'] ?? null,
        ];
    }

    /** Simpan pengaturan; kunci/rahasia kosong = tetap yang lama. */
    public static function save(int $companyId, array $data): void
    {
        $values = [
            'erp_enabled' => ! empty($data['erp_enabled']) ? '1' : '0',
            'erp_base_url' => rtrim(trim((string) ($data['erp_base_url'] ?? '')), '/'),
            'erp_price_level_id' => isset($data['erp_price_level_id']) && $data['erp_price_level_id'] !== '' ? (int) $data['erp_price_level_id'] : null,
            'erp_warehouse_codes' => array_values(array_filter(array_map('trim', explode(',', (string) ($data['erp_warehouse_codes'] ?? ''))))),
        ];
        if (trim((string) ($data['erp_api_key'] ?? '')) !== '') {
            $values['erp_api_key'] = Crypt::encryptString(trim((string) $data['erp_api_key']));
        }
        if (trim((string) ($data['erp_webhook_secret'] ?? '')) !== '') {
            $values['erp_webhook_secret'] = Crypt::encryptString(trim((string) $data['erp_webhook_secret']));
        }
        CompanySetting::setMany($companyId, $values);
    }

    /** Rahasia webhook ERP untuk company ini (verifikasi tanda tangan X-Erp-Signature). */
    public static function webhookSecret(int $companyId): ?string
    {
        $secret = CompanySetting::values($companyId)['erp_webhook_secret'] ?? null;

        return $secret ? Crypt::decryptString($secret) : null;
    }

    public function get(string $path, array $query = []): array
    {
        return $this->send(fn (PendingRequest $r) => $r->get($this->url($path), $query));
    }

    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        return $this->send(fn (PendingRequest $r) => ($idempotencyKey ? $r->withHeaders(['Idempotency-Key' => $idempotencyKey]) : $r)->post($this->url($path), $body));
    }

    public function put(string $path, array $body = []): array
    {
        return $this->send(fn (PendingRequest $r) => $r->put($this->url($path), $body));
    }

    /** Semua baris daftar berhalaman API v1 (`{data, meta.last_page}`). */
    public function pages(string $path, array $query = []): Generator
    {
        $page = 1;
        do {
            $res = $this->get($path, [...$query, 'page' => $page, 'per_page' => 100]);
            foreach ($res['data'] ?? [] as $row) {
                yield $row;
            }
            $last = (int) ($res['meta']['last_page'] ?? 1);
            $page++;
        } while ($page <= $last);
    }

    /** Unduh file (mis. foto barang) dari alamat ERP dengan kunci yang sama. */
    public function download(string $url): string
    {
        try {
            $res = $this->request()->accept('*/*')->get($url);
        } catch (ConnectionException $e) {
            throw new ErpException('ERP tidak bisa dihubungi: '.$e->getMessage());
        }
        if (! $res->successful()) {
            throw new ErpException("Unduh file ERP gagal ({$res->status()}).", $res->status());
        }

        return $res->body();
    }

    private function send(callable $call): array
    {
        try {
            /** @var Response $res */
            $res = $call($this->request());
        } catch (ConnectionException $e) {
            throw new ErpException('ERP tidak bisa dihubungi: '.$e->getMessage());
        }
        if ($res->successful()) {
            return (array) $res->json();
        }
        $json = (array) $res->json();
        $message = match ($res->status()) {
            401 => 'Kunci API ERP ditolak. Periksa kunci di Pengaturan › ERP.',
            403 => 'Kunci API ERP tidak punya cakupan/izin: '.($json['message'] ?? 'ditolak').'.',
            429 => 'Terlalu banyak panggilan ke ERP; dicoba lagi nanti.',
            default => (string) ($json['message'] ?? "ERP menjawab {$res->status()}."),
        };

        throw new ErpException($message, $res->status(), (array) ($json['errors'] ?? []));
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->apiKey)->acceptJson()->timeout(30)->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    private function url(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : $this->baseUrl.'/'.ltrim($path, '/');
    }

    private static function fromSettings(int $companyId, array $s): ?self
    {
        $base = rtrim((string) ($s['erp_base_url'] ?? ''), '/');
        if ($base === '' || empty($s['erp_api_key'])) {
            return null;
        }

        return new self($companyId, $base, Crypt::decryptString($s['erp_api_key']),
            isset($s['erp_price_level_id']) && $s['erp_price_level_id'] !== null ? (int) $s['erp_price_level_id'] : null,
            array_values((array) ($s['erp_warehouse_codes'] ?? [])));
    }
}
