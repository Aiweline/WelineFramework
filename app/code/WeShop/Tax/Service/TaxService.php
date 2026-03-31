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
        $breakdown = $this->calculateTaxBreakdown($subtotal, $country, $region, $context);

        return (float) ($breakdown['tax_amount'] ?? 0.0);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function calculateTaxBreakdown(
        float $subtotal,
        ?string $country = null,
        ?string $region = null,
        array $context = []
    ): array {
        $discount = max(0.0, (float) ($context['discount'] ?? 0.0));
        $shippingAmount = max(0.0, (float) ($context['shipping_amount'] ?? 0.0));
        $applyToShipping = $this->resolveApplyToShipping($context);
        $pricesIncludeTax = $this->resolvePricesIncludeTax($context);
        $shippingIncludesTax = $applyToShipping ? $this->resolveShippingIncludesTax($context, $pricesIncludeTax) : false;

        $taxableSubtotal = max(0.0, $subtotal - $discount);
        $taxableShippingAmount = $applyToShipping ? max(0.0, $shippingAmount) : 0.0;
        $taxableAmount = max(0.0, $taxableSubtotal + $taxableShippingAmount);
        $taxRate = $this->getTaxRate($country, $region, $context);

        $eventData = [
            'subtotal' => $subtotal,
            'country' => $country,
            'region' => $region,
            'discount' => $discount,
            'shipping_amount' => $shippingAmount,
            'taxable_amount' => &$taxableAmount,
            'taxable_subtotal' => &$taxableSubtotal,
            'taxable_shipping_amount' => &$taxableShippingAmount,
            'tax_rate' => &$taxRate,
            'apply_to_shipping' => &$applyToShipping,
            'prices_include_tax' => &$pricesIncludeTax,
            'price_includes_tax' => &$pricesIncludeTax,
            'shipping_includes_tax' => &$shippingIncludesTax,
            'context' => $context,
        ];
        $this->getEventsManager()->dispatch('WeShop_Tax::calculate_tax', $eventData);

        $taxRate = $this->normalizeRate($taxRate);
        $applyToShipping = (bool) $applyToShipping;
        $pricesIncludeTax = (bool) $pricesIncludeTax;
        $shippingIncludesTax = $applyToShipping && (bool) $shippingIncludesTax;

        $taxableSubtotal = max(0.0, (float) $taxableSubtotal);
        $taxableShippingAmount = $applyToShipping ? max(0.0, (float) $taxableShippingAmount) : 0.0;
        $combinedTaxableAmount = max(0.0, $taxableSubtotal + $taxableShippingAmount);
        if (abs($taxableAmount - $combinedTaxableAmount) > 0.0001) {
            if ($applyToShipping && $taxableShippingAmount > 0.0) {
                $taxableSubtotal = max(0.0, (float) $taxableAmount - $taxableShippingAmount);
            } else {
                $taxableSubtotal = max(0.0, (float) $taxableAmount);
                $taxableShippingAmount = 0.0;
            }
        }

        $taxableAmount = max(0.0, $taxableSubtotal + $taxableShippingAmount);
        $subtotalTax = $this->calculateTaxComponent($taxableSubtotal, $taxRate, $pricesIncludeTax);
        $shippingTax = $applyToShipping
            ? $this->calculateTaxComponent($taxableShippingAmount, $taxRate, $shippingIncludesTax)
            : 0.0;

        $includedTax = ($pricesIncludeTax ? $subtotalTax : 0.0)
            + ($shippingIncludesTax ? $shippingTax : 0.0);
        $chargeableTax = (!$pricesIncludeTax ? $subtotalTax : 0.0)
            + ((!$shippingIncludesTax && $applyToShipping) ? $shippingTax : 0.0);
        $taxAmount = $subtotalTax + $shippingTax;

        return [
            'tax_amount' => round(max(0.0, $taxAmount), 2),
            'chargeable_tax' => round(max(0.0, $chargeableTax), 2),
            'included_tax' => round(max(0.0, $includedTax), 2),
            'tax_rate' => $taxRate,
            'taxable_amount' => round($taxableAmount, 2),
            'taxable_subtotal' => round($taxableSubtotal, 2),
            'taxable_shipping_amount' => round($taxableShippingAmount, 2),
            'apply_to_shipping' => $applyToShipping,
            'prices_include_tax' => $pricesIncludeTax,
            'shipping_includes_tax' => $shippingIncludesTax,
        ];
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
            return $this->normalizeRate($context['default_rate']);
        }

        try {
            return $this->normalizeRate(Env::getInstance()->getConfig('tax.default_rate', null));
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function resolveApplyToShipping(array $context): bool
    {
        if (array_key_exists('apply_to_shipping', $context)) {
            return $this->normalizeBool($context['apply_to_shipping']);
        }

        try {
            return $this->normalizeBool(Env::getInstance()->getConfig('tax.apply_to_shipping', false));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function resolvePricesIncludeTax(array $context): bool
    {
        if (array_key_exists('prices_include_tax', $context)) {
            return $this->normalizeBool($context['prices_include_tax']);
        }

        if (array_key_exists('price_includes_tax', $context)) {
            return $this->normalizeBool($context['price_includes_tax']);
        }

        try {
            $configured = Env::getInstance()->getConfig('tax.prices_include_tax', null);
            if ($configured !== null && $configured !== '') {
                return $this->normalizeBool($configured);
            }
        } catch (\Throwable) {
        }

        try {
            return $this->normalizeBool(Env::getInstance()->getConfig('tax.price_includes_tax', false));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function resolveShippingIncludesTax(array $context, bool $fallback): bool
    {
        if (array_key_exists('shipping_includes_tax', $context)) {
            return $this->normalizeBool($context['shipping_includes_tax']);
        }

        try {
            return $this->normalizeBool(Env::getInstance()->getConfig('tax.shipping_includes_tax', $fallback), $fallback);
        } catch (\Throwable) {
            return $fallback;
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
            $rates[$code] = $this->normalizeRate($value);
        }

        return $rates;
    }

    protected function calculateTaxComponent(float $amount, float $taxRate, bool $includesTax): float
    {
        if ($amount <= 0.0 || $taxRate <= 0.0) {
            return 0.0;
        }

        if ($includesTax) {
            return $amount - ($amount / (1 + $taxRate));
        }

        return $amount * $taxRate;
    }

    protected function normalizeRate(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_string($value)) {
            $normalized = trim($value);
            $isPercent = str_ends_with($normalized, '%');
            if ($isPercent) {
                $normalized = substr($normalized, 0, -1);
            }

            if (!is_numeric($normalized)) {
                return 0.0;
            }

            $rate = (float) $normalized;
            if ($isPercent || ($rate > 1.0 && $rate <= 100.0)) {
                $rate /= 100;
            }

            return max(0.0, round($rate, 4));
        }

        if (!is_numeric($value)) {
            return 0.0;
        }

        $rate = (float) $value;
        if ($rate > 1.0 && $rate <= 100.0) {
            $rate /= 100;
        }

        return max(0.0, round($rate, 4));
    }

    protected function normalizeBool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (!is_string($value)) {
            return $default;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'y', 'on' => true,
            '0', 'false', 'no', 'n', 'off' => false,
            default => $default,
        };
    }

    protected function getEventsManager(): EventsManager
    {
        return ObjectManager::getInstance(EventsManager::class);
    }
}
