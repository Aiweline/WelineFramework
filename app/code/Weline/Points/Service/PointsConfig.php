<?php

declare(strict_types=1);

namespace Weline\Points\Service;

use Weline\SystemConfig\Api\ConfigReader;

/**
 * Points rule switches and ratios.
 *
 * Earning is closed by default. Discount is closed by the existing Payment
 * points gate (enabled + positive exchange ratio + CustomerAsset mode), while
 * its own cap defaults to 30% of the payable.
 */
class PointsConfig
{
    public const MODULE = 'Weline_Points';
    public const PAYMENT_MODULE = 'Weline_Payment';
    public const AREA = 'backend';

    public const KEY_EARN_ENABLED = 'points/earn/enabled';
    public const KEY_EARN_RATE = 'points/earn/rate_per_currency_unit';
    public const KEY_GROWTH_RATE = 'points/growth/rate_per_currency_unit';
    public const KEY_VIP_ENABLED = 'points/vip/enabled';
    public const KEY_VIP_MULTIPLIER = 'points/vip/multiplier_enabled';
    public const KEY_VIP_BASIS = 'points/vip/basis';
    public const KEY_DISCOUNT_ENABLED = 'points/discount/enabled';
    public const KEY_DISCOUNT_MAX_RATIO = 'points/discount/max_ratio';
    public const KEY_PRODUCT_DEFAULT_RATIO = 'points/product/default_ratio';
    public const KEY_PRODUCT_DEFAULT_FIXED = 'points/product/default_fixed_points';

    public const VIP_BASIS_LIFETIME = 'lifetime_points';
    public const VIP_BASIS_GROWTH = 'growth';

    public const DEFAULT_DISCOUNT_MAX_RATIO = 0.30;

    public function __construct(
        private readonly ?ConfigReader $reader = null,
    ) {
    }

    public function earnEnabled(): bool
    {
        return $this->readBool(self::KEY_EARN_ENABLED, false);
    }

    public function earnRate(): float
    {
        return max(0.0, $this->readFloat(self::KEY_EARN_RATE, 1.0));
    }

    public function growthRate(): float
    {
        return max(0.0, $this->readFloat(self::KEY_GROWTH_RATE, 1.0));
    }

    public function vipEnabled(): bool
    {
        return $this->readBool(self::KEY_VIP_ENABLED, false);
    }

    public function vipMultiplierEnabled(): bool
    {
        return $this->readBool(self::KEY_VIP_MULTIPLIER, true);
    }

    public function vipBasis(): string
    {
        $value = strtolower(trim((string) $this->read(self::KEY_VIP_BASIS, self::VIP_BASIS_LIFETIME)));

        return $value === self::VIP_BASIS_GROWTH ? self::VIP_BASIS_GROWTH : self::VIP_BASIS_LIFETIME;
    }

    public function discountEnabled(): bool
    {
        return $this->readBool(self::KEY_DISCOUNT_ENABLED, true);
    }

    public function discountMaxRatio(): float
    {
        return max(0.0, min(1.0, $this->readFloat(self::KEY_DISCOUNT_MAX_RATIO, self::DEFAULT_DISCOUNT_MAX_RATIO)));
    }

    public function productDefaultRatio(): float
    {
        return max(0.0, $this->readFloat(self::KEY_PRODUCT_DEFAULT_RATIO, 1.0));
    }

    public function productDefaultFixedPoints(): int
    {
        return max(0, (int) $this->readFloat(self::KEY_PRODUCT_DEFAULT_FIXED, 0.0));
    }

    public function paymentPointsEnabled(): bool
    {
        return $this->readBoolExternal('payment/asset/points/enabled', self::PAYMENT_MODULE, false);
    }

    public function paymentExchangeRatio(): float
    {
        return max(0.0, $this->readFloatExternal('payment/asset/points/exchange_ratio', self::PAYMENT_MODULE, 0.0));
    }

    private function readBool(string $key, bool $default): bool
    {
        return $this->toBool($this->read($key, $default ? 1 : 0));
    }

    private function readFloat(string $key, float $default): float
    {
        $value = $this->read($key, $default);

        return \is_numeric($value) ? (float) $value : $default;
    }

    private function readBoolExternal(string $key, string $module, bool $default): bool
    {
        return $this->toBool($this->readExternal($key, $module, $default ? 1 : 0));
    }

    private function readFloatExternal(string $key, string $module, float $default): float
    {
        $value = $this->readExternal($key, $module, $default);

        return \is_numeric($value) ? (float) $value : $default;
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return \in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    private function read(string $key, mixed $default): mixed
    {
        try {
            $reader = $this->reader ?? new ConfigReader();

            return $reader->get($key, self::MODULE, self::AREA, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    private function readExternal(string $key, string $module, mixed $default): mixed
    {
        try {
            $reader = $this->reader ?? new ConfigReader();

            return $reader->get($key, $module, self::AREA, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
