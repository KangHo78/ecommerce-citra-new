<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToActiveCompany;
use App\Models\Transaction;
use App\Services\Erp\ErpConnection;
use App\Services\Erp\ErpException;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    use ScopesToActiveCompany;

    public function show(Request $request, Transaction $transaction)
    {
        $user = $request->user();
        abort_unless($user, 403);

        if ($user->canAccessAdminPanel()) {
            abort_unless($user->hasAdminPermission('transactions.show'), 403);
            $this->guardCompanyOwnership($transaction->company_id);
        } else {
            abort_unless((int) $transaction->user_id === (int) $user->id, 403);
        }

        // Pesanan yang sudah ditagih di ERP DK: invoice resmi = PDF invoice ERP (gagal diambil = invoice toko).
        if ($transaction->erp_invoice_id && ! $request->boolean('shop') && ($erp = ErpConnection::for((int) $transaction->company_id))) {
            try {
                $pdf = $erp->download("/sales-invoices/{$transaction->erp_invoice_id}/pdf");

                return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
                    'Content-Disposition' => 'inline; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $transaction->erp_invoice_code).'.pdf"']);
            } catch (ErpException) {
                // Lanjut ke invoice toko.
            }
        }

        $transaction->load(['user', 'details']);

        return view('invoices.print', compact('transaction'));
    }
}
