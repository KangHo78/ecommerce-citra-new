<?php

namespace App\Services\Erp;

use RuntimeException;

/** Panggilan ke ERP DK gagal (jaringan, kunci, cakupan, validasi); pesan siap ditampilkan ke admin. */
class ErpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
