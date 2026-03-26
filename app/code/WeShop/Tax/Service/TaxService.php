<?php

declare(strict_types=1);

namespace WeShop\Tax\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class TaxService
{
    /**
     * @param array<string, mixed> $context
     */
    public function calculateTax(float $subtotal, ?string $country = null, ?string $region = null, array $context = []): float
    {
        $discount = max(0.0, (float) ($context['discount'] ?? 0.0));
        $shippingAmount = max(0.0, (float) ($context['shipping_amount'] ?? 0.0));
        $applyToShipping = $this->resolveApplyToShipping($context);

        $taxableAmount = max(
            0.0,
            $subtotal - $discount + ($applyToShipping ? $shippingAmount : 0.0)
        );
        $taxRate = $this->getTaxRate($country, $region, $context);

        $eventData = [
            'subtotal' => $subtotal,
            'country' => $country,
            'region' => $region,
            'discount' => $discount,
            'shipping_amount' => $shippingAmount,
            'taxable_amount' => &$taxableAmount,
            'tax_rate' => &$taxRate,
            'context' => $context,
        ];
        $this->getEventsManager()->dispatch('WeShop_Tax::calculate_tax', $eventData);

        return round(max(0.0, $taxableAmount) * max(0.0, $taxRate), 2);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function getTaxRate(?string $country = null, ?string $region = null, array $context = []): float
    {
        $country = strtoupper(trim((string) $country));
        $region = strtoupper(trim((string) $region));

        $taxRate = $this->readDefaultRate($context);

        $countryRates = $this->readRates($context['country_rates'] ?? null, 'tax.country_rates');
        if ($country !== '' && array_key_exists($country, $countryRates)) {
            $taxRate = $countryRates[$country];
        }

        $regionRates = $this->readRates($context['region_rates'] ?? null, 'tax.region_rates');
        $combinedRegionKey = $country !== '' && $region !== '' ? $country . '-' . $region : '';
        if ($combinedRegionKey !== '' && array_key_exists($combinedRegionKey, $regionRates)) {
            $taxRate = $regionRates[$combinedRegionKey];
        } elseif ($region !== '' && array_key_exists($region, $regionRates)) {
            $taxRate = $regionRates[$region];
        }

        return max(0.0, round($taxRate, 4));
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function readDefaultRate(array $context): float
    {
        if (array_key_exists('default_rate', $context)) {
            return (float) $context['default_rate'];
        }

        try {
            return (float) Env::getInstance()->getConfig('tax.default_rate', 0.1);
        } catch (\Throwable) {
            return 0.1;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function resolveApplyToShipping(array $context): bool
    {
        if (array_key_exists('apply_to_shipping', $context)) {
            return (bool) $context['apply_to_shipping'];
        }

        try {
            return (bool) Env::getInstance()->getConfig('tax.apply_to_shipping', false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, float>
     */
    protected function readRates(mixed $contextValue, string $configKey): array
    {
        $source = $contextValue;
        if ($source === null) {
            try {
                $source = Env::getInstance()->getConfig($configKey, []);
            } catch (\Throwable) {
                $source = [];
            }
        }

        if (is_string($source)) {
            $decoded = json_decode($source, true);
            $source = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($source)) {
            return [];
        }

        $rates = [];
        foreach ($source as $key => $value) {
            $code = strtoupper(trim((string) $key));
            if ($code === '') {
                continue;
            }
            $rates[$code] = max(0.0, (float) $value);
        }

        return $rates;
    }

    protected function getEventsManager(): EventsManager
    {
        return ObjectManager::getInstance(EventsManager::class);
    }
}
