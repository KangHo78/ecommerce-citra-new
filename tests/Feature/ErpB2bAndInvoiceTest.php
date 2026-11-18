<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Erp\ErpConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Integrasi ERP DK tahap B2B: dokumen B2B dibuat di ERP, invoice pesanan pembeli = PDF invoice ERP. */
class ErpB2bAndInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::query()->where('slug', 'boq')->firstOrFail();
        ErpConnection::save($this->company->id, ['erp_enabled' => true, 'erp_base_url' => 'https://erp.test/api/v1', 'erp_api_key' => 'erpdk_kunci',
            'erp_price_level_id' => null, 'erp_warehouse_codes' => '']);
    }

    public function test_b2b_writes_go_to_erp_while_lists_stay_readable(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->withSession(['admin_active_company_id' => $this->company->id]);

        $this->get(route('quotations.index'))->assertOk()->assertSee('dibuat dan diproses di ERP')->assertSee('https://erp.test');
        $this->get(route('quotations.create'))->assertRedirect(route('quotations.index'))->assertSessionHas('erp_notice');
        $this->post(route('quotations.store'), [])->assertRedirect(route('quotations.index'));
        $this->get(route('sales-orders.create'))->assertRedirect(route('sales-orders.index'));
        $this->postJson(route('quotations.store'), [])->assertStatus(423)->assertJsonPath('erp_url', 'https://erp.test');

        CompanySetting::setMany($this->company->id, ['erp_enabled' => '0']);
        $this->get(route('quotations.create'))->assertOk();
        $this->get(route('quotations.index'))->assertDontSee('dibuat dan diproses di ERP');
    }

    public function test_buyer_invoice_is_the_erp_pdf_with_shop_fallback(): void
    {
        $buyer = User::factory()->create();
        $tx = Transaction::withoutEvents(fn () => Transaction::create([
            'company_id' => $this->company->id, 'user_id' => $buyer->id, 'source' => Transaction::SOURCE_CHECKOUT, 'invoice_no' => 'INV-ERP-7',
            'order_id' => 'ORD-ERP-7', 'status' => 'selesai', 'payment_status' => 'paid', 'subtotal_amount' => 1000, 'shipping_cost' => 0,
            'discount_amount' => 0, 'grand_total' => 1000,
        ]));
        $tx->forceFill(['erp_sales_order_id' => 81, 'erp_invoice_id' => 30, 'erp_invoice_code' => 'FJ-2611-0004'])->saveQuietly();
        $down = false;
        Http::fake(['erp.test/*' => function () use (&$down) {
            return $down ? Http::response('down', 503) : Http::response('%PDF-1.4 erp', 200, ['Content-Type' => 'application/pdf']);
        }]);

        $this->actingAs($buyer)->get(route('invoice.show', $tx))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertSee('%PDF-1.4 erp', false);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer erpdk_kunci'));

        // Pembeli lain tidak boleh, dan ERP gagal = invoice toko.
        $this->actingAs(User::factory()->create())->get(route('invoice.show', $tx))->assertForbidden();
        $down = true;
        $this->actingAs($buyer)->get(route('invoice.show', $tx))->assertOk()->assertSee('INV-ERP-7');
    }
}
