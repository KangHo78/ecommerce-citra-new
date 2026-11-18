<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Erp\ErpConnection;
use App\Services\Erp\ErpException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Penerima webhook ERP DK per company (`POST /api/erp/webhook/{slug}`): tanda tangan `X-Erp-Signature` =
 * sha256 HMAC atas `<X-Erp-Timestamp>.<isi>` dengan rahasia webhook company. `product.changed` = sinkron barang yang
 * berubah, `stock.changed` = stok varian itu; dikerjakan sesudah jawaban 200 dikirim supaya ERP tidak menunggu.
 */
class ErpWebhookController extends Controller
{
    public function __invoke(Request $request, string $company): JsonResponse
    {
        $model = Company::query()->where('slug', $company)->where('is_active', true)->first();
        $erp = $model ? ErpConnection::for($model->id) : null;
        $secret = $model ? ErpConnection::webhookSecret($model->id) : null;
        if (! $erp || ! $secret) {
            return response()->json(['message' => 'Koneksi ERP perusahaan ini tidak aktif.'], 404);
        }
        $timestamp = (string) $request->header('X-Erp-Timestamp');
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        if (! hash_equals($expected, (string) $request->header('X-Erp-Signature')) || abs(time() - (int) $timestamp) > 600) {
            return response()->json(['message' => 'Tanda tangan webhook tidak sah.'], 401);
        }
        $event = (string) $request->input('event');
        $data = (array) $request->input('data', []);

        dispatch(function () use ($erp, $event, $data) {
            try {
                app(ErpWebhookHandler::class)->handle($erp, $event, $data);
            } catch (ErpException $e) {
                Log::warning('Webhook ERP gagal diproses', ['company' => $erp->companyId, 'event' => $event, 'error' => $e->getMessage()]);
            }
        })->afterResponse();

        return response()->json(['received' => true]);
    }
}
