<?php

declare(strict_types=1);

namespace Weline\Currency\Service;

use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Currency\Model\Config as CurrencyConfig;
use Weline\Currency\Model\Currency;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;
use Weline\Server\Service\MemoryStateFacade;

class CurrencyRateService
{
    private const CACHE_NAMESPACE = 'weline_site_runtime';

    /** @var array<string, array<string, mixed>|null> */
    private static array $definitionCache = [];
    private static ?string $baseCurrencyCache = null;
    private static ?MemoryStateFacade $runtimeCache = null;
    private static bool $runtimeCacheResolved = false;

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
        if (self::$baseCurrencyCache !== null) {
            return self::$baseCurrencyCache;
        }

        $cached = $this->runtimeCacheGet('currency.base');
        if (is_string($cached) && $cached !== '') {
            return self::$baseCurrencyCache = $cached;
        }

        $baseCurrency = $this->normalizeCurrencyCode($this->config->getBaseCurrency());
        $baseCurrency = $baseCurrency !== '' ? $baseCurrency : 'CNY';
        self::$baseCurrencyCache = $baseCurrency;
        $this->runtimeCacheSet('currency.base', $baseCurrency);

        return $baseCurrency;
    }

    public function getCurrentCurrency(): string
    {
        $currentCurrency = $this->normalizeCurrencyCode(State::getCurrency());
        return $currentCurrency !== '' ? $currentCurrency : $this->getBaseCurrency();
    }

    public function getCurrentRate(): float
    {
        $baseCurrency = $this->getBaseCurrency();
        $currentCurrency = $this->getCurrentCurrency();
        if ($currentCurrency === '' || $currentCurrency === $baseCurrency) {
            return 1.0;
        }

        $rate = $this->getCurrencyRateRelativeToBase($currentCurrency, $baseCurrency);
        if ($rate === null || $rate <= 0.0) {
            return 1.0;
        }

        return 1.0 / $rate;
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
        if (array_key_exists($currencyCode, self::$definitionCache)) {
            return self::$definitionCache[$currencyCode];
        }

        $cacheKey = 'currency.definition.' . $currencyCode;
        $cached = $this->runtimeCacheGet($cacheKey);
        if (is_array($cached)) {
            return self::$definitionCache[$currencyCode] = $cached;
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
                $definition = is_array($data) ? $data : null;
                self::$definitionCache[$currencyCode] = $definition;
                if ($definition !== null) {
                    $this->runtimeCacheSet($cacheKey, $definition);
                }
                return $definition;
            }
        } catch (\Throwable) {
        }

        $definition = self::FALLBACK_DEFINITIONS[$currencyCode] ?? null;
        self::$definitionCache[$currencyCode] = $definition;
        if ($definition !== null) {
            $this->runtimeCacheSet($cacheKey, $definition);
        }
        return $definition;
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

    private function runtimeCacheGet(string $key): mixed
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return null;
        }

        try {
            return $cache->get(self::CACHE_NAMESPACE, $key);
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
            return null;
        }
    }

    private function runtimeCacheSet(string $key, mixed $value): void
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return;
        }

        try {
            $cache->set(self::CACHE_NAMESPACE, $key, $value, $this->cacheTtl());
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
        }
    }

    private static function runtimeCache(): ?MemoryStateFacade
    {
        if (self::$runtimeCacheResolved) {
            return self::$runtimeCache;
        }
        self::$runtimeCacheResolved = true;

        if (!class_exists(Runtime::class, false) || !Runtime::isPersistent() || !class_exists(MemoryStateFacade::class)) {
            return null;
        }

        try {
            /** @var RuntimeCachePolicy $policy */
            $policy = ObjectManager::getInstance(RuntimeCachePolicy::class);
            self::$runtimeCache = new MemoryStateFacade($policy->memoryOptions([
                'consumer_code' => self::CACHE_NAMESPACE,
                'prefer_direct_connect' => true,
                'persistent' => true,
                'lazy_connect' => true,
            ]));
        } catch (\Throwable) {
            self::$runtimeCache = null;
        }

        return self::$runtimeCache;
    }

    private function cacheTtl(): int
    {
        try {
            /** @var RuntimeCachePolicy $policy */
            $policy = ObjectManager::getInstance(RuntimeCachePolicy::class);
            return $policy->ttl('site.currency_ttl', 300);
        } catch (\Throwable) {
            return 300;
        }
    }
}
