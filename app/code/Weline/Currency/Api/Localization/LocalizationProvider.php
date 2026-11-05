<?php

declare(strict_types=1);

namespace Weline\Currency\Api\Localization;

use Weline\Currency\Data\CurrencyData;
use Weline\Framework\App\Localization\LocalizationProviderInterface;

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

        return $codes;
    }

    public function defaultLanguage(): ?string
    {
        return null;
    }

    public function defaultCurrency(): ?string
    {
        return null;
    }

    public function supportsLanguage(string $code): ?bool
    {
        return null;
    }

    public function supportsCurrency(string $code): ?bool
    {
        return CurrencyData::getCurrency($code) !== null;
    }

    public function installedLanguageCodes(): ?array
    {
        return null;
    }
}
