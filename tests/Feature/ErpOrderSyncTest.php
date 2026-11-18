<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Transaction;
use App\Services\Erp\ErpConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Integrasi ERP DK tahap pesanan: checkout → pelanggan + SO dikonfirmasi, lunas → DP, batal → SO dibatalkan, gagal → dicoba ulang. */
class ErpOrderSyncTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::query()->where('slug', 'boq')->firstOrFail();
        ErpConnection::save($this->company->id, ['erp_enabled' => true, 'erp_base_url' => 'https://erp.test/api/v1', 'erp_api_key' => 'erpdk_kunci', 'erp_webhook_secret' => 'rahasia',
            'erp_price_level_id' => null, 'erp_warehouse_codes' => 'GD-01', 'erp_shipping_account_code' => '4105', 'erp_account_gateway' => '1104', 'erp_account_manual' => '1102']);
    }

    private bool $down = false;

    private array $creates = [];

    private function fakeErp(array $soResponses = []): void
    {
        $so = ['data' => ['id' => 81, 'code' => 'SO-2611-0001', 'status' => 'confirmed']];
        $this->creates = $soResponses ?: [Http::response($so, 201)];
        Http::fake(['erp.test/*' => function (Request $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($this->down) {
                return Http::response(['message' => 'down'], 503);
            }

            return match (true) {
                str_ends_with($path, '/customers') => $r->method() === 'GET' ? Http::response(['data' => []]) : Http::response(['data' => ['id' => 5]], 201),
                str_ends_with($path, '/payments') => Http::response(['data' => ['id' => 12, 'status' => 'posted']], 201),
                str_ends_with($path, '/cancel') => Http::response(['data' => ['id' => 81, 'status' => 'cancelled']]),
                str_ends_with($path, '/sales-orders') => array_shift($this->creates) ?? throw new \RuntimeException('SO dikirim lagi'),
            };
        }]);
    }

    private function order(array $overrides = []): Transaction
    {
        return \DB::transaction(function () use ($overrides) {
            $tx = Transaction::create(array_merge([
                'company_id' => $this->company->id, 'source' => Transaction::SOURCE_CHECKOUT,
                'invoice_no' => 'INV-ERP-1', 'order_id' => 'ORD-ERP-1', 'payment_type' => 'bank_transfer', 'payment_method' => 'Midtrans',
                'status' => 'pending', 'payment_status' => 'unpaid', 'subtotal_amount' => 30000, 'shipping_cost' => 10000, 'discount_amount' => 0, 'grand_total' => 40000,
                'shipping_recipient_name' => 'Budi', 'shipping_phone' => '0812', 'shipping_address_line' => 'Jl. Mawar 1', 'shipping_city' => 'Surabaya',
                'manual_customer_name' => 'Budi', 'manual_customer_email' => 'budi@example.test', 'midtrans_transaction_id' => 'MID-1',
            ], $overrides));
            $tx->details()->create(['product_name' => 'Baut', 'sku' => 'BT-M10', 'price' => 1500, 'quantity' => 20, 'subtotal' => 30000, 'discount_amount' => 0]);

            return $tx;
        });
    }

    public function test_checkout_creates_customer_and_confirmed_sales_order_then_payment_and_no_duplicates(): void
    {
        $this->fakeErp();
        $tx = $this->order()->fresh();

        $this->assertSame(81, (int) $tx->erp_sales_order_id);
        $this->assertSame('ordered', $tx->erp_state);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/customers') && str_starts_with((string) $r['external_ref'], 'guest-'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/sales-orders')
            && $r['external_ref'] === 'ORD-ERP-1' && $r['confirm'] === true && $r['warehouse_code'] === 'GD-01'
            && $r['lines'][0]['sku'] === 'BT-M10' && $r['lines'][0]['qty'] === 20 && $r['lines'][0]['price'] === '1500.00'
            && $r['services'][0]['account_code'] === '4105' && $r->header('Idempotency-Key')[0] === 'toko-so-ORD-ERP-1');

        $tx->update(['payment_status' => 'paid', 'status' => 'process', 'payment_paid_at' => now()]);
        $this->assertSame('paid', $tx->fresh()->erp_state);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sales-orders/81/payments')
            && $r['amount'] === '40000.00' && $r['account_code'] === '1104' && $r['reference'] === 'MID-1');

        // Perubahan status lain tidak mengirim SO/DP lagi.
        $tx->update(['status' => 'kirim']);
        Http::assertSentCount(4);
    }

    public function test_cancelled_before_payment_cancels_the_sales_order(): void
    {
        $this->fakeErp();
        $tx = $this->order();
        $tx->update(['status' => 'dibatalkan', 'payment_status' => 'cancelled', 'cancel_reason' => 'Kedaluwarsa']);

        $this->assertSame('cancelled', $tx->fresh()->erp_state);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sales-orders/81/cancel') && $r['reason'] === 'Kedaluwarsa');
    }

    public function test_stock_shortage_falls_back_to_backorder_and_errors_are_retried(): void
    {
        $this->fakeErp([
            Http::response(['message' => 'Stok kurang', 'errors' => ['stock' => ['Stok kurang']]], 422),
            Http::response(['data' => ['id' => 81, 'code' => 'SO-2611-0001']], 201),
        ]);
        $this->order();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sales-orders') && ($r['backorder'] ?? false) === true);

        // ERP mati: checkout tetap tersimpan, galat dicatat, lalu terkirim saat dicoba ulang.
        $this->down = true;
        $tx = $this->order(['invoice_no' => 'INV-ERP-2', 'order_id' => 'ORD-ERP-2', 'midtrans_transaction_id' => 'MID-2'])->fresh();
        $this->assertSame('pending', $tx->erp_state);
        $this->assertNotNull($tx->erp_error);

        $this->down = false;
        $this->creates[] = Http::response(['data' => ['id' => 82, 'code' => 'SO-2611-0002']], 201);
        Artisan::call('erp:push-orders');
        $tx->refresh();
        $this->assertSame(82, (int) $tx->erp_sales_order_id);
        $this->assertNull($tx->erp_error);
    }

    public function test_company_without_erp_connection_is_untouched(): void
    {
        Http::fake();
        ErpConnection::save($this->company->id, ['erp_enabled' => false]);
        $tx = $this->order()->fresh();

        $this->assertNull($tx->erp_state);
        Http::assertNothingSent();
    }
}
