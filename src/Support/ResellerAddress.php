<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Uri;

final class ResellerAddress
{
    public static function domain(): string
    {
        $host = (string) Uri::of(Config::string('app.url'))->host();

        return 'reseller.'.($host === '' ? 'localhost' : $host);
    }
}
