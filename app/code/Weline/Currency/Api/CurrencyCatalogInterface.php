<?php

declare(strict_types=1);

namespace Weline\Currency\Api;

use Weline\Currency\Api\Data\CurrencyRecord;

interface CurrencyCatalogInterface
{
    /** @return list<CurrencyRecord> */
    public function active(): array;
}
