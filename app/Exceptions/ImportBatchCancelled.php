<?php

namespace App\Exceptions;

/**
 * Dilempar worker saat flag cancel_requested terdeteksi di checkpoint.
 * BUKAN error — ditangkap khusus agar batch ditandai CANCELLED, bukan FAILED.
 */
class ImportBatchCancelled extends \RuntimeException {}
