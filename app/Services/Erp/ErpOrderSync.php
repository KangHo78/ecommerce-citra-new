<?php

namespace App\Services\Erp;

use App\Models\CompanySetting;
use App\Models\Transaction;

/**
 * Pesanan toko → ERP DK (company tersambung): transaksi = pelanggan + SO dikonfirmasi (stok dipesan di ERP; stok ERP
 * kurang = tetap dikonfirmasi sebagai backorder), lunas = DP atas SO itu (reference = id pembayaran, tanpa dobel),
 * batal/kedaluwarsa sebelum lunas = SO dibatalkan. Gagal = disimpan di erp_error dan dicoba ulang `erp:push-orders`.
 */
class ErpOrderSync
{
    public const PENDING = 'pending';

    public const ORDERED = 'ordered';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    /** Langkah yang masih kurang untuk transaksi ini (dipanggil observer dan coba ulang). */
    public function sync(Transaction $tx): void
    {
        $erp = ErpConnection::for((int) $tx->company_id);
        if (! $erp) {
            return;
        }
        try {
            $cancelled = strtolower((string) $tx->status) === 'dibatalkan';
            $paid = strtolower((string) $tx->payment_status) === 'paid';
            if ($cancelled && ! $paid) {
                $this->cancel($erp, $tx);

                return;
            }
            if (! $tx->erp_sales_order_id) {
                $this->push($erp, $tx);
            }
            if ($paid && $tx->erp_state !== self::PAID) {
                $this->pay($erp, $tx);
            }
            $tx->forceFill(['erp_error' => null, 'erp_synced_at' => now()])->saveQuietly();
        } catch (ErpException $e) {
            $tx->forceFill(['erp_state' => $tx->erp_state ?: self::PENDING, 'erp_error' => mb_substr($e->getMessage(), 0, 1000)])->saveQuietly();
        }
    }

    /** Pelanggan (cocok external_ref) + SO dikonfirmasi; external_ref SO = order_id toko. */
    public function push(ErpConnection $erp, Transaction $tx): void
    {
        $tx->loadMissing(['details', 'user']);
        $settings = CompanySetting::values((int) $tx->company_id);
        $customerRef = $this->customer($erp, $tx);
        $services = [];
        if ((float) $tx->shipping_cost > 0) {
            $account = trim((string) ($settings['erp_shipping_account_code'] ?? ''));
            if ($account === '') {
                throw new ErpException('Isi kode akun pendapatan ongkir di Setting › Koneksi ERP supaya ongkir ikut ke SO.');
            }
            $services[] = ['account_code' => $account, 'description' => trim('Ongkos kirim '.($tx->shipping_courier_name ?? '').' '.($tx->shipping_service ?? '')),
                'qty' => 1, 'price' => self::money($tx->shipping_cost), 'vat_exempt' => true];
        }
        $body = [
            'customer_external_ref' => $customerRef,
            'external_ref' => (string) $tx->order_id,
            'date' => optional($tx->created_at)->toDateString(),
            'tax_mode' => (float) $tx->tax_amount > 0 ? 'exclusive' : 'none',
            'warehouse_code' => $erp->warehouses[0] ?? null,
            'recipient_name' => $tx->shipping_recipient_name ?: $tx->customerDisplayName(),
            'recipient_phone' => $tx->shipping_phone ?: $tx->manual_customer_phone,
            'ship_address' => trim(implode(', ', array_filter([$tx->shipping_address_line, $tx->shipping_district, $tx->shipping_city, $tx->shipping_province, $tx->shipping_postal_code]))) ?: null,
            'discount_amount' => self::money($tx->discount_amount),
            'lines' => $tx->details->map(fn ($d) => [
                'sku' => (string) $d->sku,
                'qty' => (int) $d->quantity,
                // Harga bersih per satuan setelah diskon baris toko (flash sale, dsb.).
                'price' => self::money(((float) $d->subtotal) / max(1, (int) $d->quantity)),
            ])->values()->all(),
            'services' => $services,
            'confirm' => true,
        ];
        $body = array_filter($body, fn ($v) => $v !== null);
        try {
            $so = $erp->post('/sales-orders', $body, 'toko-so-'.$tx->order_id)['data'];
        } catch (ErpException $e) {
            if ($e->status === 409) {
                // SO pesanan ini sudah ada di ERP (jawaban sebelumnya hilang): pakai SO itu.
                $so = $erp->get('/sales-orders', ['external_ref' => (string) $tx->order_id, 'status' => 'draft,pending,confirmed,partial,done', 'per_page' => 1])['data'][0] ?? throw $e;
            } elseif ($e->status === 422 && isset($e->errors['stock'])) {
                // Stok ERP kurang: tetap dikonfirmasi sebagai backorder (pembeli sudah checkout di toko), ditandai di ERP.
                $so = $erp->post('/sales-orders', [...$body, 'backorder' => true], 'toko-so-bo-'.$tx->order_id)['data'];
            } else {
                throw $e;
            }
        }
        $tx->forceFill(['erp_sales_order_id' => (int) $so['id'], 'erp_sales_order_code' => $so['code'] ?? null, 'erp_state' => self::ORDERED])->saveQuietly();
    }

    /** Uang diterima = DP atas SO (akun penampung per cara bayar), reference = id pembayaran gateway atau nomor pesanan. */
    public function pay(ErpConnection $erp, Transaction $tx): void
    {
        $settings = CompanySetting::values((int) $tx->company_id);
        $manual = $tx->payment_proof_path !== null || in_array(strtolower((string) $tx->payment_type), ['manual', 'manual_transfer', 'transfer'], true);
        $account = trim((string) ($settings[$manual ? 'erp_account_manual' : 'erp_account_gateway'] ?? ''));
        if ($account === '') {
            throw new ErpException('Isi kode akun kas/bank penerima pembayaran di Setting › Koneksi ERP.');
        }
        $reference = (string) ($tx->midtrans_transaction_id ?: $tx->order_id);
        $erp->post("/sales-orders/{$tx->erp_sales_order_id}/payments", [
            'amount' => self::money($tx->payment_amount ?: $tx->grand_total),
            'account_code' => $account,
            'reference' => $reference,
            'date' => optional($tx->payment_paid_at ?? $tx->payment_verified_at ?? now())->toDateString(),
            'notes' => "Pembayaran toko {$tx->invoice_no}",
        ], 'toko-pay-'.$tx->order_id);
        $tx->forceFill(['erp_state' => self::PAID, 'erp_payment_reference' => $reference])->saveQuietly();
    }

    public function cancel(ErpConnection $erp, Transaction $tx): void
    {
        if ($tx->erp_sales_order_id && $tx->erp_state !== self::CANCELLED) {
            $erp->post("/sales-orders/{$tx->erp_sales_order_id}/cancel", ['reason' => mb_substr((string) ($tx->cancel_reason ?: 'Dibatalkan di toko online'), 0, 255)]);
        }
        $tx->forceFill(['erp_state' => self::CANCELLED, 'erp_error' => null, 'erp_synced_at' => now()])->saveQuietly();
    }

    /** Angka uang untuk API ERP: dua desimal bertitik, sama di MySQL dan SQLite. */
    private static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** Pelanggan ERP untuk pembeli ini (akun toko = user-<id>, tamu = guest-<sidik email/telepon>); dibuat bila belum ada. */
    private function customer(ErpConnection $erp, Transaction $tx): string
    {
        $user = $tx->user;
        $ref = $user ? 'user-'.$user->id : 'guest-'.substr(sha1(strtolower(trim((string) ($tx->manual_customer_email ?: $tx->manual_customer_phone ?: $tx->order_id)))), 0, 24);
        $data = array_filter([
            'name' => mb_substr($user?->name ?: $tx->manual_customer_name ?: $tx->shipping_recipient_name ?: 'Pembeli toko', 0, 150),
            'phone' => $user?->phone ?? $tx->manual_customer_phone ?? $tx->shipping_phone,
            'email' => $user?->email ?: $tx->manual_customer_email,
            'address' => $tx->shipping_address_line,
            'external_ref' => $ref,
            'channel' => 'ecommerce',
        ], fn ($v) => $v !== null && $v !== '');
        $found = $erp->get('/customers', ['external_ref' => $ref, 'per_page' => 1])['data'][0] ?? null;
        if (! $found) {
            $erp->post('/customers', $data, 'toko-cust-'.$ref);
        }

        return $ref;
    }
}
