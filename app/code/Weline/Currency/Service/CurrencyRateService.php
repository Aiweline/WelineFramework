<?php

declare(strict_types=1);

namespace Weline\Currency\Service;

use Weline\Currency\Model\Config as CurrencyConfig;
use Weline\Currency\Model\Currency;
use Weline\Framework\App\State;

class CurrencyRateService
{
    /**
     * @var array<string, array<string, float|int|string>>
     */
    private const FALLBACK_DEFINITIONS = [
        'CNY' => [
            'code' => 'CNY',
            'symbol' => '¥',
            'icon' => '¥',
            'position' => 'left',
            'format' => '2,0',
            'thousand_separator' => ',',
            'decimal_separator' => '.',
            'rate' => 1.0,
            'base_currency' => 'CNY',
        ],
        'USD' => [
            'code' => 'USD',
            'symbol' => '$',
            'icon' => '$',
            'position' => 'left',
            'format' => '2,0',
            'thousand_separator' => ',',
            'decimal_separator' => '.',
            'rate' => 0.0,
            'base_currency' => 'CNY',
        ],
        'EUR' => [
            'code' => 'EUR',
            'symbol' => '€',
            'icon' => '€',
            'position' => 'left',
            'format' => '2,0',
            'thousand_separator' => ',',
            'decimal_separator' => '.',
            'rate' => 0.0,
            'base_currency' => 'CNY',
        ],
        'GBP' => [
            'code' => 'GBP',
            'symbol' => '£',
            'icon' => '£',
            'position' => 'left',
            'format' => '2,0',
            'thousand_separator' => ',',
            'decimal_separator' => '.',
            'rate' => 0.0,
            'base_currency' => 'CNY',
        ],
        'JPY' => [
            'code' => 'JPY',
            'symbol' => '¥',
            'icon' => '¥',
            'position' => 'left',
            'format' => '2,0',
            'thousand_separator' => ',',
            'decimal_separator' => '.',
            'rate' => 0.0,
            'base_currency' => 'CNY',
        ],
    ];

    public function __construct(
        private readonly CurrencyConfig $config,
        private readonly Currency $currencyModel
    ) {
    }

    public function getBaseCurrency(): string
    {
        $baseCurrency = $this->normalizeCurrencyCode($this->config->getBaseCurrency());
        return $baseCurrency !== '' ? $baseCurrency : 'CNY';
    }

    public function getCurrentCurrency(): string
    {
        $currentCurrency = $this->normalizeCurrencyCode(State::getCurrency());
        return $currentCurrency !== '' ? $currentCurrency : $this->getBaseCurrency();
    }

    public function convert(float $amount, ?string $sourceCurrency = null, ?string $targetCurrency = null): float
    {
        $sourceCurrency = $this->normalizeCurrencyCode($sourceCurrency ?: $this->getBaseCurrency());
        $targetCurrency = $this->normalizeCurrencyCode($targetCurrency ?: $this->getCurrentCurrency());

        if ($sourceCurrency === '' || $targetCurrency === '' || $sourceCurrency === $targetCurrency) {
            return round($amount, 4);
        }

        $baseCurrency = $this->getBaseCurrency();
        $amountInBase = $this->convertToBase($amount, $sourceCurrency, $baseCurrency);
        if ($amountInBase === null) {
            return round($amount, 4);
        }

        $convertedAmount = $this->convertFromBase($amountInBase, $targetCurrency, $baseCurrency);
        if ($convertedAmount === null) {
            return round($amount, 4);
        }

        return round($convertedAmount, 4);
    }

    public function format(float $amount, ?string $sourceCurrency = null, ?string $targetCurrency = null): string
    {
        $targetCurrency = $this->normalizeCurrencyCode($targetCurrency ?: $this->getCurrentCurrency());
        if ($targetCurrency === '') {
            $targetCurrency = $this->getBaseCurrency();
        }

        $convertedAmount = $this->convert($amount, $sourceCurrency, $targetCurrency);
        $definition = $this->loadCurrencyDefinition($targetCurrency);
        if ($definition === null) {
            return $this->fallbackFormat($convertedAmount, $targetCurrency);
        }

        return $this->formatWithDefinition($convertedAmount, $definition);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCurrencyDefinition(?string $currencyCode = null): array
    {
        $currencyCode = $this->normalizeCurrencyCode($currencyCode ?: $this->getCurrentCurrency());
        $definition = $this->loadCurrencyDefinition($currencyCode);
        if ($definition !== null) {
            return $definition;
        }

        return self::FALLBACK_DEFINITIONS[$this->getBaseCurrency()] ?? self::FALLBACK_DEFINITIONS['CNY'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadCurrencyDefinition(string $currencyCode): ?array
    {
        if ($currencyCode === '') {
            return null;
        }

        try {
            $currency = clone $this->currencyModel;
            $currency->clear()
                ->where(Currency::schema_fields_CODE, $currencyCode)
                ->where(Currency::schema_fields_STATUS, 1)
                ->find()
                ->fetch();

            if ($currency->getId()) {
                $data = $currency->getData();
                return is_array($data) ? $data : null;
            }
        } catch (\Throwable) {
        }

        return self::FALLBACK_DEFINITIONS[$currencyCode] ?? null;
    }

    private function convertToBase(float $amount, string $sourceCurrency, string $baseCurrency): ?float
    {
        if ($sourceCurrency === $baseCurrency) {
            return $amount;
        }

        $rate = $this->getCurrencyRateRelativeToBase($sourceCurrency, $baseCurrency);
        return $rate === null ? null : $amount * $rate;
    }

    private function convertFromBase(float $amount, string $targetCurrency, string $baseCurrency): ?float
    {
        if ($targetCurrency === $baseCurrency) {
            return $amount;
        }

        $rate = $this->getCurrencyRateRelativeToBase($targetCurrency, $baseCurrency);
        if ($rate === null || $rate <= 0.0) {
            return null;
        }

        return $amount / $rate;
    }

    private function getCurrencyRateRelativeToBase(string $currencyCode, string $baseCurrency): ?float
    {
        if ($currencyCode === $baseCurrency) {
            return 1.0;
        }

        $definition = $this->loadCurrencyDefinition($currencyCode);
        if ($definition === null) {
            return null;
        }

        $definitionBase = $this->normalizeCurrencyCode((string) ($definition['base_currency'] ?? $baseCurrency));
        if ($definitionBase !== '' && $definitionBase !== $baseCurrency) {
            return null;
        }

        $rate = (float) ($definition['rate'] ?? 0);
        return $rate > 0.0 ? $rate : null;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function formatWithDefinition(float $amount, array $definition): string
    {
        $formatParts = explode(',', (string) ($definition['format'] ?? '2,0'));
        $decimals = isset($formatParts[0]) && is_numeric($formatParts[0]) ? (int) $formatParts[0] : 2;
        $thousandSeparator = (string) ($definition['thousand_separator'] ?? ',');
        $decimalSeparator = (string) ($definition['decimal_separator'] ?? '.');
        $formattedAmount = number_format($amount, $decimals, $decimalSeparator, $thousandSeparator);

        $icon = trim((string) ($definition['icon'] ?? $definition['symbol'] ?? $definition['code'] ?? ''));
        if ($icon === '') {
            return $formattedAmount;
        }

        $position = strtolower(trim((string) ($definition['position'] ?? 'left')));
        return $position === 'right' ? $formattedAmount . $icon : $icon . $formattedAmount;
    }

    private function fallbackFormat(float $amount, string $currencyCode): string
    {
        return number_format($amount, 2) . ' ' . $currencyCode;
    }

    private function normalizeCurrencyCode(string $currencyCode): string
    {
        return strtoupper(trim($currencyCode));
    }
}
