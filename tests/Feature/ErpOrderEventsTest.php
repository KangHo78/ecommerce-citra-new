<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Erp\ErpConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Integrasi ERP DK tahap status: webhook pesanan ERP → status transaksi toko (kirim, selesai, batal, invoice). */
class ErpOrderEventsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    /** Isi GET /sales-orders/81 yang dijawab ERP tiruan. */
    private array $so = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::query()->where('slug', 'boq')->firstOrFail();
        ErpConnection::save($this->company->id, ['erp_enabled' => true, 'erp_base_url' => 'https://erp.test/api/v1', 'erp_api_key' => 'erpdk_kunci', 'erp_webhook_secret' => 'rahasia',
            'erp_price_level_id' => null, 'erp_warehouse_codes' => '', 'erp_account_gateway' => '1104']);
        $this->so = ['id' => 81, 'code' => 'SO-2611-0001', 'status' => 'confirmed', 'deliveries' => [], 'invoices' => []];
        Http::fake(['erp.test/*' => fn (Request $r) => str_contains($r->url(), '/sales-orders/81') && $r->method() === 'GET'
            ? Http::response(['data' => $this->so])
            : Http::response(['data' => ['id' => 1]], 201)]);
    }

    private function order(array $overrides = []): Transaction
    {
        $userId = User::factory()->create()->id;
        $tx = Transaction::withoutEvents(fn () => Transaction::create(array_merge([
            'company_id' => $this->company->id, 'user_id' => $userId, 'source' => Transaction::SOURCE_CHECKOUT,
            'invoice_no' => 'INV-ERP-9', 'order_id' => 'ORD-ERP-9', 'payment_type' => 'bank_transfer', 'status' => 'paid', 'payment_status' => 'paid',
            'subtotal_amount' => 30000, 'shipping_cost' => 0, 'discount_amount' => 0, 'grand_total' => 30000,
        ], $overrides)));
        // Sudah tertaut ke SO ERP (tahap pesanan), tanpa memicu kirim ulang.
        $tx->forceFill(['erp_sales_order_id' => 81, 'erp_sales_order_code' => 'SO-2611-0001', 'erp_state' => 'paid'])->saveQuietly();

        return $tx;
    }

    private function webhook(string $event, array $data)
    {
        $body = json_encode(['id' => uniqid(), 'event' => $event, 'data' => $data]);
        $ts = (string) time();

        return $this->call('POST', "/api/erp/webhook/{$this->company->slug}", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_ERP_TIMESTAMP' => $ts, 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ERP_SIGNATURE' => 'sha256='.hash_hmac('sha256', "{$ts}.{$body}", 'rahasia')], $body);
    }

    public function test_delivery_then_receipt_ships_and_completes_the_order(): void
    {
        $tx = $this->order();
        $this->so['status'] = 'done';
        $this->so['deliveries'] = [['id' => 44, 'code' => 'SJ-2611-0009', 'status' => 'posted', 'courier' => 'JNE', 'tracking_no' => 'JNE0012345', 'received_at' => null]];
        $this->webhook('sales_delivery.updated', ['id' => 44, 'sales_order_ids' => [81]])->assertOk();

        $tx->refresh();
        $this->assertSame('kirim', $tx->status);
        $this->assertSame('JNE0012345', $tx->tracking_number);
        $this->assertSame('JNE', $tx->shipping_label);
        $this->assertTrue(UserNotification::query()->where('user_id', $tx->user_id)->where('type', 'order_shipped')->exists());

        $this->so['deliveries'][0]['received_at'] = '2026-11-19T14:02:00+07:00';
        $this->so['invoices'] = [['id' => 30, 'code' => 'FJ-2611-0004', 'status' => 'posted', 'remaining' => '0.00']];
        $this->webhook('sales_delivery.received', ['id' => 44, 'sales_order_ids' => [81]])->assertOk();

        $tx->refresh();
        $this->assertSame('selesai', $tx->status);
        $this->assertSame('FJ-2611-0004', $tx->erp_invoice_code);
        $this->assertTrue((bool) $tx->erp_invoice_paid);
        $this->assertSame(2, $tx->statusHistories()->whereIn('type', ['order_shipped', 'order_completed'])->count());

        // Kabar yang sama lagi tidak mengubah apa pun dan tidak membatalkan/ mengirim apa pun ke ERP.
        $this->webhook('sales_order.updated', ['id' => 81])->assertOk();
        $this->assertSame('selesai', $tx->fresh()->status);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_sales_order_cancelled_in_erp_cancels_unpaid_order_once(): void
    {
        $tx = $this->order(['status' => 'pending', 'payment_status' => 'unpaid']);
        $tx->forceFill(['erp_state' => 'ordered'])->saveQuietly();
        $this->so['status'] = 'cancelled';
        $this->webhook('sales_order.updated', ['id' => 81])->assertOk();

        $tx->refresh();
        $this->assertSame('dibatalkan', $tx->status);
        $this->assertSame('cancelled', $tx->erp_state);
        // SO sudah batal di ERP: toko tidak memanggil cancel lagi.
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/cancel'));
    }

    public function test_paid_order_is_not_cancelled_by_erp_and_unlinked_orders_are_ignored(): void
    {
        $tx = $this->order();
        $this->so['status'] = 'cancelled';
        $this->webhook('sales_order.updated', ['id' => 81])->assertOk();
        $this->assertSame('paid', $tx->fresh()->status);

        $this->webhook('sales_order.updated', ['id' => 999])->assertOk();
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/sales-orders/999'));
    }

    public function test_hourly_refresh_catches_missed_webhooks(): void
    {
        $tx = $this->order();
        $this->so['deliveries'] = [['id' => 44, 'code' => 'SJ-2611-0009', 'status' => 'posted', 'courier' => null, 'tracking_no' => null, 'received_at' => null]];
        Artisan::call('erp:push-orders', ['--refresh' => true]);

        $tx->refresh();
        $this->assertSame('kirim', $tx->status);
        // Kurir sendiri tanpa resi: nomor surat jalan sebagai resi.
        $this->assertSame('SJ-2611-0009', $tx->tracking_number);
    }
}
