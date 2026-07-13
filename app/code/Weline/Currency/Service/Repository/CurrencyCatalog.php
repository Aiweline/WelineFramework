<?php

declare(strict_types=1);

namespace Weline\Currency\Service\Repository;

use Weline\Currency\Api\CurrencyCatalogInterface;
use Weline\Currency\Api\Data\CurrencyRecord;
use Weline\Currency\Model\Currency;

final class CurrencyCatalog implements CurrencyCatalogInterface
{
    public function __construct(
        private readonly Currency $currency,
    ) {
    }

    public function active(): array
    {
        $records = [];
        $rows = $this->currency->reset()
            ->where(Currency::schema_fields_STATUS, 1)
            ->order(Currency::schema_fields_CODE, 'ASC')
            ->select()
            ->fetchArray();
        foreach ($rows as $currency) {
            if (!is_array($currency)) {
                continue;
            }
            $code = trim((string)($currency[Currency::schema_fields_CODE] ?? ''));
            if ($code === '') {
                continue;
            }
            $records[] = new CurrencyRecord(
                code: $code,
                name: (string)($currency[Currency::schema_fields_NAME] ?? $code),
                symbol: (string)($currency[Currency::schema_fields_SYMBOL] ?? ''),
                active: true,
                format: (string)($currency[Currency::schema_fields_FORMAT] ?? '1,0'),
                position: (string)($currency[Currency::schema_fields_POSITION] ?? 'left'),
                rate: (float)($currency[Currency::schema_fields_RATE] ?? 0.0),
            );
        }

        return $records;
    }
}
