<?php

namespace App\Services;

use Illuminate\Support\Str;

class QrCodeService
{
    public static function generate(): string
    {
        return strtoupper(Str::uuid()->toString());
    }
}

