<?php

declare(strict_types=1);

namespace Weline\Points\Service;

use Weline\Currency\Service\CurrencyRateService;
use Weline\Framework\Manager\ObjectManager;

/**
 * Website default (benchmark) currency for points earning and discount.
 *
 * Earning must be computed in the base currency, never by treating the
 * checkout-currency number as if it were the base currency.
 */
class PointsBaseCurrencyResolver
{
    public function __construct(
        private ?CurrencyRateService $rates = null,
    ) {
    }

    public static function forTesting(?CurrencyRateService $rates = null): self
    {
        return new self($rates);
    }

    public function forWebsite(int $websiteId): string
    {
        if ($websiteId >= 0) {
            try {
                $fromWebsite = strtoupper(trim((string) (
                    \Weline\Websites\Data\WebsiteData::defaultCurrencyForWebsite($websiteId) ?? ''
                )));
                if ($fromWebsite !== '') {
                    return $fromWebsite;
                }
            } catch (\Throwable) {
            }
        }

        try {
            $rates = $this->rates();
            if ($rates !== null) {
                $base = strtoupper(trim((string) $rates->getBaseCurrency()));
                if ($base !== '') {
                    return $base;
                }
            }
        } catch (\Throwable) {
        }

        return 'CNY';
    }

    /**
     * Convert checkout-currency minor units into website base-currency minor
     * units. Missing FX returns null so callers can fail closed instead of
     * assuming 1:1.
     *
     * @return array{
     *   amount_minor:int,
     *   currency:string,
     *   precision:int,
     *   fx:?array{from:string,to:string,rate:string,label:string}
     * }|null
     */
    public function convertMinorToBase(
        int $amountMinor,
        string $sourceCurrency,
        int $websiteId,
        int $sourcePrecision = 2,
    ): ?array {
        $amountMinor = max(0, $amountMinor);
        $source = strtoupper(trim($sourceCurrency)) ?: $this->forWebsite($websiteId);
        $target = $this->forWebsite($websiteId);
        $sourcePrecision = max(0, min(6, $sourcePrecision));
        $targetPrecision = OrderPointsContext::precisionForCurrency($target);

        if ($source === $target) {
            return [
                'amount_minor' => $amountMinor,
                'currency' => $target,
                'precision' => $targetPrecision,
                'fx' => [
                    'from' => $source,
                    'to' => $target,
                    'rate' => '1',
                    'label' => $source . '=' . $target,
                ],
            ];
        }

        $rates = $this->rates();
        if ($rates === null) {
            return null;
        }

        $major = $amountMinor / (10 ** $sourcePrecision);
        $converted = $rates->tryConvert($major, $source, $target);
        if ($converted === null) {
            return null;
        }

        return [
            'amount_minor' => max(0, (int) round($converted * (10 ** $targetPrecision))),
            'currency' => $target,
            'precision' => $targetPrecision,
            'fx' => $this->rateSnapshot($source, $target, $websiteId),
        ];
    }

    /**
     * Convert minor units between two currencies with explicit source precision.
     *
     * @return array{amount_minor:int,currency:string,precision:int,fx:?array}|null
     */
    public function convertMinor(
        int $amountMinor,
        string $fromCurrency,
        string $toCurrency,
        int $websiteId,
        int $fromPrecision = 2,
    ): ?array {
        $from = strtoupper(trim($fromCurrency)) ?: $this->forWebsite($websiteId);
        $to = strtoupper(trim($toCurrency)) ?: $from;
        $fromPrecision = max(0, min(6, $fromPrecision));
        $toPrecision = OrderPointsContext::precisionForCurrency($to);
        if ($from === $to) {
            return [
                'amount_minor' => max(0, $amountMinor),
                'currency' => $to,
                'precision' => $toPrecision,
                'fx' => [
                    'from' => $from,
                    'to' => $to,
                    'rate' => '1',
                    'label' => $from . '=' . $to,
                ],
            ];
        }

        $rates = $this->rates();
        if ($rates === null) {
            return null;
        }
        $major = max(0, $amountMinor) / (10 ** $fromPrecision);
        $converted = $rates->tryConvert($major, $from, $to);
        if ($converted === null) {
            return null;
        }

        return [
            'amount_minor' => max(0, (int) round($converted * (10 ** $toPrecision))),
            'currency' => $to,
            'precision' => $toPrecision,
            'fx' => $this->rateSnapshot($from, $to, $websiteId),
        ];
    }

    /**
     * @return array{from:string,to:string,rate:string,label:string}|null
     */
    public function rateSnapshot(string $fromCurrency, string $toCurrency, int $websiteId): ?array
    {
        $from = strtoupper(trim($fromCurrency)) ?: $this->forWebsite($websiteId);
        $to = strtoupper(trim($toCurrency)) ?: $from;
        if ($from === $to) {
            return [
                'from' => $from,
                'to' => $to,
                'rate' => '1',
                'label' => $from . '=' . $to,
            ];
        }

        $rates = $this->rates();
        if ($rates === null) {
            return null;
        }
        $one = $rates->tryConvert(1.0, $from, $to);
        if ($one === null || $one <= 0) {
            return null;
        }
        $rate = rtrim(rtrim(number_format($one, 8, '.', ''), '0'), '.') ?: '0';

        return [
            'from' => $from,
            'to' => $to,
            'rate' => $rate,
            'label' => '1 ' . $from . ' = ' . $rate . ' ' . $to,
        ];
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
