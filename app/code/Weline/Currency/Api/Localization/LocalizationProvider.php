<?php

declare(strict_types=1);

namespace Weline\Currency\Api\Localization;

use Weline\Currency\Data\CurrencyData;
use Weline\Currency\Model\Currency;
use Weline\Framework\App\Localization\LocalizationProviderInterface;
use Weline\Framework\Manager\ObjectManager;

final class LocalizationProvider implements LocalizationProviderInterface
{
    public function priority(): int
    {
        return 10;
    }

    public function languageCodes(): array
    {
        return [];
    }

    public function currencyCodes(): array
    {
        $codes = [];
        foreach (CurrencyData::getCurrencies() as $row) {
            if (is_array($row) && trim((string)($row['code'] ?? '')) !== '') {
                $codes[] = (string)$row['code'];
            }
        }
        if ($codes !== []) {
            return $codes;
        }

        $rows = ObjectManager::getInstance(Currency::class)->clear()
            ->where(Currency::schema_fields_STATUS, true)
            ->select()
            ->fetchArray();
        foreach ((array)$rows as $row) {
            if (is_array($row) && trim((string)($row[Currency::schema_fields_CODE] ?? '')) !== '') {
                $codes[] = (string)$row[Currency::schema_fields_CODE];
            }
        }
        return $codes;
    }

    public function supportsLanguage(string $code): ?bool
    {
        return null;
    }

    public function supportsCurrency(string $code): ?bool
    {
        return CurrencyData::getCurrency($code) !== null;
    }
}
