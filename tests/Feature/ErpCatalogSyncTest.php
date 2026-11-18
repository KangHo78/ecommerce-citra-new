<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Erp\ErpCatalogSync;
use App\Services\Erp\ErpConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Integrasi ERP DK tahap katalog: sinkron barang/varian/harga/foto/stok, webhook bertanda tangan, admin barang terkunci. */
class ErpCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->withoutVite();
        $this->company = Company::query()->where('slug', 'boq')->firstOrFail();
        ErpConnection::save($this->company->id, ['erp_enabled' => true, 'erp_base_url' => 'https://erp.test/api/v1', 'erp_api_key' => 'erpdk_kunci', 'erp_webhook_secret' => 'rahasia',
            'erp_price_level_id' => 2, 'erp_warehouse_codes' => '']);
        Http::fake([
            'erp.test/api/v1/products*' => Http::response(['data' => [[
                'id' => 12, 'code' => 'BRG-12', 'name' => 'Baut hex M10', 'category' => 'Baut > Baut hex', 'base_unit' => 'PCS', 'active' => true, 'weight' => '0.0120', 'description' => 'Galvanis',
                'images' => [['id' => 7, 'variant_id' => null, 'position' => 0, 'url' => 'https://erp.test/api/v1/product-images/7/large?v=111']],
                'variants' => [['id' => 31, 'sku' => 'BT-M10', 'name' => null, 'options' => [], 'base_price' => '1500.00', 'weight' => null, 'active' => true,
                    'prices' => [['price_level_id' => 2, 'unit' => 'PCS', 'price' => '1400.00']]]],
            ]], 'meta' => ['page' => 1, 'per_page' => 100, 'total' => 1, 'last_page' => 1]]),
            'erp.test/api/v1/stock*' => Http::response(['data' => [
                ['variant_id' => 31, 'sku' => 'BT-M10', 'available' => '120.0000'], ['variant_id' => 31, 'sku' => 'BT-M10', 'available' => '-5.0000'],
            ], 'meta' => ['page' => 1, 'per_page' => 100, 'total' => 2, 'last_page' => 1]]),
            'erp.test/api/v1/product-images/*' => Http::response('WEBPDATA', 200, ['Content-Type' => 'image/webp']),
        ]);
    }

    public function test_catalog_sync_copies_products_variants_prices_images_and_stock(): void
    {
        $stats = app(ErpCatalogSync::class)->run(ErpConnection::for($this->company->id), true);

        $this->assertSame(1, $stats['products']);
        $product = Product::query()->where('company_id', $this->company->id)->where('erp_product_id', 12)->firstOrFail();
        $this->assertSame('Baut hex M10', $product->name);
        $this->assertSame('active', $product->status);
        $this->assertSame('Baut', $product->mainCategory->name);
        $this->assertSame('Baut hex', $product->categoryDetail->name);
        $variant = ProductVariant::query()->where('erp_variant_id', 31)->firstOrFail();
        $this->assertSame('BT-M10', $variant->sku);
        $this->assertEquals(1400, (float) $variant->price);
        $this->assertSame(12, (int) $variant->weight_grams);
        $this->assertSame(115, (int) $variant->stock);
        Storage::disk('public')->assertExists($variant->image);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer erpdk_kunci'));

        // Sinkron kedua: foto versi sama tidak diunduh ulang, data tidak berlipat.
        app(ErpCatalogSync::class)->run(ErpConnection::for($this->company->id));
        $this->assertSame(1, Product::query()->where('erp_product_id', 12)->count());
        Http::assertSentCount(5);
    }

    public function test_signed_webhook_updates_stock_and_rejects_bad_signatures(): void
    {
        app(ErpCatalogSync::class)->run(ErpConnection::for($this->company->id), true);
        ProductVariant::query()->where('erp_variant_id', 31)->update(['stock' => 1]);
        $body = json_encode(['id' => 'u1', 'event' => 'stock.changed', 'data' => ['variant_ids' => [31], 'skus' => ['BT-M10']]]);
        $ts = (string) time();
        $send = fn (string $sig) => $this->call('POST', "/api/erp/webhook/{$this->company->slug}", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_ERP_TIMESTAMP' => $ts, 'HTTP_X_ERP_SIGNATURE' => $sig, 'HTTP_ACCEPT' => 'application/json'], $body);

        $send('sha256=salah')->assertStatus(401);
        $send('sha256='.hash_hmac('sha256', "{$ts}.{$body}", 'rahasia'))->assertOk();
        $this->assertSame(115, (int) ProductVariant::query()->where('erp_variant_id', 31)->value('stock'));
    }

    public function test_admin_cannot_edit_erp_managed_products_and_settings_hide_the_key(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('products.create'))->assertRedirect(route('products.index'));
        $this->get(route('pages.settings', ['tab' => 'erp']))->assertOk()->assertSee('Koneksi ERP')->assertDontSee('erpdk_kunci');
        $this->assertNotSame('erpdk_kunci', CompanySetting::values($this->company->id)['erp_api_key']);

        CompanySetting::setMany($this->company->id, ['erp_enabled' => '0']);
        $this->get(route('products.create'))->assertOk();
    }

    public function test_products_without_erp_price_sync_as_inactive_instead_of_failing(): void
    {
        Http::swap(new Factory);
        Http::fake(['erp.test/api/v1/products*' => Http::response(['data' => [[
            'id' => 40, 'code' => 'BRG-40', 'name' => 'Mur M8', 'category' => null, 'base_unit' => 'PCS', 'active' => true, 'weight' => null, 'description' => null, 'images' => [],
            'variants' => [['id' => 70, 'sku' => 'MR-M8', 'name' => null, 'options' => [], 'base_price' => null, 'weight' => null, 'active' => true,
                'prices' => [['price_level_id' => 2, 'unit' => 'PCS', 'price' => null]]]],
        ]], 'meta' => ['page' => 1, 'per_page' => 100, 'total' => 1, 'last_page' => 1]]),
            'erp.test/api/v1/stock*' => Http::response(['data' => [], 'meta' => ['page' => 1, 'per_page' => 100, 'total' => 0, 'last_page' => 1]])]);

        app(ErpCatalogSync::class)->run(ErpConnection::for($this->company->id), true);

        $product = Product::query()->where('company_id', $this->company->id)->where('erp_product_id', 40)->firstOrFail();
        $this->assertSame('inactive', $product->status);
        $this->assertSame(0.0, (float) ProductVariant::query()->where('erp_variant_id', 70)->value('price'));
    }
}
