<?php

namespace App\Services\Erp;

use App\Models\Transaction;
use App\Models\TransactionStatusHistory;
use App\Models\UserNotification;
use App\Services\CommerceReservationService;
use App\Services\LoyaltyPointService;
use Illuminate\Support\Facades\DB;

/**
 * Status pesanan dari ERP DK (webhook sales_order.updated, sales_delivery.updated|received, sales_invoice.updated|paid):
 * toko mengambil ulang SO-nya lalu menyesuaikan transaksi yang tertaut (erp_sales_order_id):
 * surat jalan diposting = Kirim + resi, semua surat jalan diterima dan SO selesai = Selesai (poin loyalti seperti
 * pembeli menandai diterima), SO dibatalkan di ERP sebelum lunas = Dibatalkan, invoice = nomor + lunas.
 */
class ErpOrderEvents
{
    private const ORDER_EVENTS = ['sales_order.updated', 'sales_delivery.updated', 'sales_delivery.received', 'sales_invoice.updated', 'sales_invoice.paid'];

    private const SHIPPABLE = ['paid', 'settlement', 'capture', 'process', 'processing'];

    private const SHIPPED = ['kirim', 'shipping', 'shipped'];

    public function __construct(private CommerceReservationService $reservations, private LoyaltyPointService $loyalty) {}

    public function handle(ErpConnection $erp, string $event, array $data): void
    {
        if (! in_array($event, self::ORDER_EVENTS, true)) {
            return;
        }
        $ids = $event === 'sales_order.updated' ? [$data['id'] ?? null] : (array) ($data['sales_order_ids'] ?? []);
        foreach (array_unique(array_filter(array_map('intval', $ids))) as $soId) {
            $this->refresh($erp, $soId);
        }
    }

    /** Satu SO ERP → transaksi toko-nya. Dipanggil webhook dan `erp:push-orders` (cadangan bila webhook terlewat). */
    public function refresh(ErpConnection $erp, int $soId): void
    {
        $exists = Transaction::query()->where('company_id', $erp->companyId)->where('erp_sales_order_id', $soId)->exists();
        if (! $exists) {
            return;
        }
        $so = $erp->get("/sales-orders/{$soId}")['data'] ?? null;
        if (! $so) {
            return;
        }
        $notice = DB::transaction(function () use ($erp, $soId, $so) {
            $tx = Transaction::query()->where('company_id', $erp->companyId)->where('erp_sales_order_id', $soId)->lockForUpdate()->first();

            return $tx ? $this->apply($tx, $so) : null;
        });
        if ($notice) {
            UserNotification::create($notice);
        }
    }

    /** @return array<string, mixed>|null pemberitahuan pembeli (dikirim sesudah commit) */
    private function apply(Transaction $tx, array $so): ?array
    {
        $this->invoice($tx, $so);
        $status = strtolower((string) $tx->status);
        $paid = strtolower((string) $tx->payment_status) === 'paid';

        if (($so['status'] ?? null) === 'cancelled') {
            if ($paid || $status === 'dibatalkan') {
                return null;
            }
            // Ditandai sebelum disimpan supaya observer tidak membatalkan SO di ERP lagi.
            $tx->forceFill(['erp_state' => ErpOrderSync::CANCELLED, 'status' => 'dibatalkan', 'payment_status' => 'cancelled',
                'cancelled_at' => now(), 'cancel_reason' => 'Dibatalkan di ERP ('.($so['code'] ?? '').').'])->save();
            $this->reservations->release($tx);
            $this->loyalty->releaseRedeemReservation($tx);
            $this->history($tx, $status, 'dibatalkan', 'erp_cancelled', 'SO '.($so['code'] ?? '').' dibatalkan di ERP.');

            return $this->notice($tx, 'order_cancelled', 'Pesanan Dibatalkan', 'Pesanan '.$tx->invoice_no.' dibatalkan oleh toko.');
        }

        $deliveries = collect($so['deliveries'] ?? [])->where('status', '!=', 'cancelled')->values();
        if ($deliveries->isEmpty()) {
            return null;
        }
        $last = $deliveries->last();
        $tracking = (string) ($last['tracking_no'] ?: $last['code']);
        $courier = (string) ($last['courier'] ?? '');

        if (in_array($status, self::SHIPPABLE, true) || (in_array($status, self::SHIPPED, true) && $tx->tracking_number !== $tracking)) {
            $first = ! in_array($status, self::SHIPPED, true);
            $tx->forceFill(['status' => 'kirim', 'tracking_number' => $tracking, 'shipping_label' => $courier ?: $tx->shipping_label,
                'shipped_at' => $tx->shipped_at ?: now()])->save();
            $this->history($tx, $status, 'kirim', 'order_shipped', 'Dikirim dari ERP ('.$last['code'].'). Resi: '.$tracking);
            $status = 'kirim';
            if ($first && ! $this->delivered($so, $deliveries)) {
                return $this->notice($tx, 'order_shipped', 'Pesanan Dalam Perjalanan',
                    'Pesanan '.$tx->invoice_no.' sudah dikirim via '.($courier ?: 'kurir').'. No. Resi: '.$tracking);
            }
        }

        if (in_array($status, self::SHIPPED, true) && $this->delivered($so, $deliveries)) {
            $tx->forceFill(['status' => 'selesai'])->save();
            $this->history($tx, $status, 'selesai', 'order_completed', 'Barang diterima (bukti terima ERP).');
            $points = $this->loyalty->awardCompletedTransactionPoints($tx);

            return $this->notice($tx, 'order_completed', 'Pesanan Selesai', 'Pesanan '.$tx->invoice_no.' sudah diterima. Terima kasih sudah berbelanja.'
                .($points > 0 ? ' Anda mendapat '.number_format($points, 0, ',', '.').' point.' : ''));
        }

        return null;
    }

    /** Selesai = SO terkirim semua dan setiap surat jalannya punya bukti terima. */
    private function delivered(array $so, $deliveries): bool
    {
        return ($so['status'] ?? null) === 'done' && $deliveries->every(fn ($d) => ! empty($d['received_at']));
    }

    private function invoice(Transaction $tx, array $so): void
    {
        $invoice = collect($so['invoices'] ?? [])->where('status', 'posted')->last();
        $fill = $invoice
            ? ['erp_invoice_id' => (int) $invoice['id'], 'erp_invoice_code' => $invoice['code'], 'erp_invoice_paid' => $invoice['remaining'] !== null && (float) $invoice['remaining'] <= 0]
            : ['erp_invoice_id' => null, 'erp_invoice_code' => null, 'erp_invoice_paid' => false];
        $tx->forceFill([...$fill, 'erp_synced_at' => now()])->saveQuietly();
    }

    private function history(Transaction $tx, string $from, string $to, string $type, string $note): void
    {
        TransactionStatusHistory::create(['transaction_id' => $tx->id, 'user_id' => null, 'from_status' => $from, 'to_status' => $to, 'type' => $type, 'note' => $note]);
    }

    private function notice(Transaction $tx, string $type, string $title, string $body): ?array
    {
        return $tx->user_id ? ['user_id' => $tx->user_id, 'type' => $type, 'title' => $title, 'body' => $body, 'url' => route('frontend.profil').'?tab=pesanan'] : null;
    }
}
