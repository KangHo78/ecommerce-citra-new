<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Services\Erp\ErpConnection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integrasi ERP DK: company yang tersambung membuat dan memproses dokumen B2B (penawaran, sales order, proforma,
 * surat jalan, invoice B2B, pembayaran dokumen) di ERP. Di admin toko dokumen lama tetap bisa dilihat dan dicetak;
 * rute tulisnya diarahkan ke daftar dengan pesan + tautan ERP.
 */
class ErpManagedB2b
{
    /** Nama rute tulis B2B → rute daftar tujuan saat ditolak. */
    private const BLOCKED = [
        'quotations.create' => 'quotations.index', 'quotations.store' => 'quotations.index', 'quotations.edit' => 'quotations.index',
        'quotations.update' => 'quotations.index', 'quotations.send' => 'quotations.index', 'quotations.update-status' => 'quotations.index',
        'quotations.close' => 'quotations.index', 'quotations.convert-form' => 'quotations.index', 'quotations.convert' => 'quotations.index',
        'sales-orders.create' => 'sales-orders.index', 'sales-orders.store' => 'sales-orders.index', 'sales-orders.cancel' => 'sales-orders.index',
        'proforma-invoices.create-form' => 'proforma-invoices.index', 'proforma-invoices.store' => 'proforma-invoices.index',
        'proforma-invoices.record-payment' => 'proforma-invoices.index', 'proforma-invoices.cancel' => 'proforma-invoices.index',
        'delivery-notes.create-form' => 'delivery-notes.index', 'delivery-notes.store' => 'delivery-notes.index', 'delivery-notes.ship' => 'delivery-notes.index',
        'delivery-notes.deliver' => 'delivery-notes.index', 'delivery-notes.cancel' => 'delivery-notes.index',
        'b2b-invoices.create-form' => 'b2b-invoices.index', 'b2b-invoices.store' => 'b2b-invoices.index', 'b2b-invoices.create-direct-form' => 'b2b-invoices.index',
        'b2b-invoices.store-direct' => 'b2b-invoices.index', 'b2b-invoices.record-payment' => 'b2b-invoices.index', 'b2b-invoices.cancel' => 'b2b-invoices.index',
        'document-payments.destroy' => 'b2b-invoices.index',
    ];

    public const MESSAGE = 'Dokumen B2B perusahaan ini dibuat dan diproses di ERP. Dokumen lama di toko tetap bisa dilihat dan dicetak.';

    /** Keterangan tetap di halaman B2B admin untuk company tersambung (null = bukan halaman B2B / tidak tersambung). */
    public static function notice(Request $request): ?string
    {
        if (! $request->routeIs('quotations.*', 'sales-orders.*', 'proforma-invoices.*', 'delivery-notes.*', 'b2b-invoices.*')) {
            return null;
        }
        $companyId = self::companyId();
        if (! ErpConnection::enabled($companyId)) {
            return null;
        }
        $erp = ErpConnection::webUrl($companyId);

        return self::MESSAGE.($erp ? " Buka ERP: {$erp}" : '');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $name = (string) $request->route()?->getName();
        if (! isset(self::BLOCKED[$name])) {
            return $next($request);
        }
        $companyId = self::companyId();
        if (! ErpConnection::enabled($companyId)) {
            return $next($request);
        }
        $erp = ErpConnection::webUrl($companyId);
        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE, 'erp_url' => $erp], 423);
        }

        return redirect()->route(self::BLOCKED[$name])->with('erp_notice', self::MESSAGE.($erp ? " Buka ERP: {$erp}" : ''));
    }

    private static function companyId(): int
    {
        return (int) (User::activeCompanyId() ?? Company::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->value('id') ?? 0);
    }
}
