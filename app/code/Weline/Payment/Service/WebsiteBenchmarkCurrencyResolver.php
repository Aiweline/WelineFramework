<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Currency\Service\CurrencyRateService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;

/**
 * Resolve the website default (benchmark) currency used for payment asset discount quotes (website benchmark).
 */
class WebsiteBenchmarkCurrencyResolver
{
    public function __construct(
        private ?CurrencyRateService $rates = null,
    ) {
    }

    public static function forTesting(?CurrencyRateService $rates = null): self
    {
        return new self($rates);
    }

    /** Uppercase ISO code; falls back to Currency base then CNY. */
    public function forWebsite(int $websiteId): string
    {
        $fromWebsite = $this->readWebsiteDefault($websiteId);
        if ($fromWebsite !== '') {
            return $fromWebsite;
        }

        try {
            $rates = $this->rates();
            if ($rates !== null) {
                $base = strtoupper(trim($rates->getBaseCurrency()));
                if ($base !== '') {
                    return $base;
                }
            }
        } catch (\Throwable) {
        }

        return 'CNY';
    }

    /**
     * Convert minor units of $sourceCurrency into website default currency minor units.
     * Same currency → identity. Missing FX → null (caller should skip/not fake).
     */
    public function convertMinorToWebsiteDefault(
        int $amountMinor,
        string $sourceCurrency,
        int $websiteId,
    ): ?int {
        $amountMinor = max(0, $amountMinor);
        $source = strtoupper(trim($sourceCurrency));
        $target = $this->forWebsite($websiteId);
        if ($source === '' || $source === $target) {
            return $amountMinor;
        }

        $rates = $this->rates();
        if ($rates === null) {
            return null;
        }

        $major = $amountMinor / 100;
        $converted = $rates->tryConvert($major, $source, $target);
        if ($converted === null) {
            return null;
        }

        return max(0, (int)round($converted * 100));
    }

    /**
     * Convert minor units of website default currency into $targetCurrency minor units.
     * Same currency → identity. Missing FX → null (caller should fail closed).
     */
    public function convertMinorFromWebsiteDefault(
        int $amountMinor,
        string $targetCurrency,
        int $websiteId,
    ): ?int {
        $amountMinor = max(0, $amountMinor);
        $target = strtoupper(trim($targetCurrency));
        $source = $this->forWebsite($websiteId);
        if ($target === '' || $source === $target) {
            return $amountMinor;
        }

        $rates = $this->rates();
        if ($rates === null) {
            return null;
        }

        $major = $amountMinor / 100;
        $converted = $rates->tryConvert($major, $source, $target);
        if ($converted === null) {
            return null;
        }

        return max(0, (int)round($converted * 100));
    }

    /**
     * Frozen FX snapshot for checkout display / reserve (same currency → rate 1).
     *
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

    private function readWebsiteDefault(int $websiteId): string
    {
        if ($websiteId < 0) {
            return '';
        }
        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            $website->clear()->load(Website::schema_fields_ID, $websiteId);
            if (!$website->getId() && $websiteId !== Website::ID_DEFAULT) {
                return '';
            }
            return strtoupper(trim((string)($website->getDefaultCurrency() ?? '')));
        } catch (\Throwable) {
            return '';
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
