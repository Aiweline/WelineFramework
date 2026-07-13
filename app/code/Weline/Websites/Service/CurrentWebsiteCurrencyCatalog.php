<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Api\Localization\WebsiteCurrencyCatalogInterface;
use Weline\Websites\Data\WebsiteData;

final class CurrentWebsiteCurrencyCatalog implements WebsiteCurrencyCatalogInterface
{
    public function current(): array
    {
        $result = [];
        foreach (WebsiteData::getCurrencies() as $currency) {
            $code = strtoupper(trim((string)($currency['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $result[] = [
                'code' => $code,
                'name' => trim((string)($currency['name'] ?? '')),
            ];
        }

        return $result;
    }
}
