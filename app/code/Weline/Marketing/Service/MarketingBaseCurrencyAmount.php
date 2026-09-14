<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Currency\Service\CurrencyRateService;
use Weline\Framework\Manager\ObjectManager;

/**
 * Fixed monetary amounts in Marketing rules/coupons are authored in the site
 * base (benchmark) currency and converted to the checkout currency at apply time.
 * Missing FX fails closed (null) — never 1:1 across currencies.
 */
final class MarketingBaseCurrencyAmount
{
    public function __construct(
        private ?CurrencyRateService $rates = null,
    ) {
    }

    public static function forTesting(?CurrencyRateService $rates = null): self
    {
        return new self($rates);
    }

    public function baseCurrency(): string
    {
        $rates = $this->rates();
        if ($rates === null) {
            return 'CNY';
        }
        try {
            $base = strtoupper(trim($rates->getBaseCurrency()));

            return $base !== '' ? $base : 'CNY';
        } catch (\Throwable) {
            return 'CNY';
        }
    }

    /**
     * Checkout / quote currency from RuleEngine context (major-unit money already in this code).
     */
    public function checkoutCurrencyFromContext(array $context): string
    {
        $fromOrder = strtoupper(trim((string)($context['order']['currency'] ?? '')));
        if ($fromOrder !== '') {
            return $fromOrder;
        }
        $fromContext = strtoupper(trim((string)($context['currency'] ?? '')));
        if ($fromContext !== '') {
            return $fromContext;
        }

        return $this->baseCurrency();
    }

    /**
     * Convert a fixed amount stored in site base currency into checkout currency (major units).
     * Same currency → identity. Missing FX → null (caller must fail closed).
     */
    public function convertBaseMajorToCheckout(float $baseMajor, string $checkoutCurrency): ?float
    {
        $baseMajor = max(0.0, $baseMajor);
        $base = $this->baseCurrency();
        $to = strtoupper(trim($checkoutCurrency));
        if ($to === '') {
            $to = $base;
        }
        if ($baseMajor === 0.0 || $base === $to) {
            return round($baseMajor, 4);
        }

        $rates = $this->rates();
        if ($rates === null) {
            return null;
        }
        try {
            $converted = $rates->tryConvert($baseMajor, $base, $to);
            if ($converted === null) {
                return null;
            }

            return round(max(0.0, $converted), 4);
        } catch (\Throwable) {
            return null;
        }
    }

    private function rates(): ?CurrencyRateService
    {
        if ($this->rates instanceof CurrencyRateService) {
            return $this->rates;
        }
        try {
            $resolved = ObjectManager::getInstance(CurrencyRateService::class);

            return $resolved instanceof CurrencyRateService ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
