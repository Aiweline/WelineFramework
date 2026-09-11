<?php

declare(strict_types=1);

namespace Weline\Affiliate\Service;

use Weline\Framework\Database\ConnectionFactory;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;

/**
 * Storefront affiliate surface switches (PDP share panel, etc.).
 * Config saves go through SystemConfig → w_changed(storefront/config) so FPC/cache epochs bump.
 */
final class AffiliateStorefrontPolicy
{
    public const CONFIG_PRODUCT_SHARE_ENABLED = 'product_share_enabled';

    private const CONFIG_MODULE = 'Weline_Affiliate';
    private const CONFIG_AREA = ConfigReader::area_FRONTEND;

    /** @var array<string, bool>|null */
    private ?array $testingByScope = null;

    public function __construct(private ?ConfigStore $config = null)
    {
    }

    public static function forConnection(ConnectionFactory $connection): self
    {
        return new self(ConfigStore::forConnection($connection));
    }

    /**
     * @param array<string, bool> $byScope Keys: website{N}.store{M}.default | website{N}.default.default | global.default.default
     */
    public static function forTesting(array $byScope = []): self
    {
        $policy = new self();
        $normalized = [];
        foreach ($byScope as $scope => $value) {
            $normalized[self::normalizeTestingScopeKey((string)$scope)] = (bool)$value;
        }
        $policy->testingByScope = $normalized;

        return $policy;
    }

    public function isProductShareEnabled(int $websiteId = 0, int $storeId = 0): bool
    {
        if ($websiteId < 0 || $storeId < 0) {
            return false;
        }

        if ($this->testingByScope !== null) {
            return $this->testingEnabled($websiteId, $storeId);
        }

        try {
            $storeScope = self::scopeKey($websiteId, $storeId);
            $websiteScope = self::websiteScopeKey($websiteId);
            foreach ([$storeScope, $websiteScope, ConfigReader::SCOPE_GLOBAL] as $scope) {
                $value = $this->resolvedValue(self::CONFIG_PRODUCT_SHARE_ENABLED, $scope, null);
                if ($value !== null) {
                    return $this->toBool($value, true);
                }
            }
        } catch (\Throwable) {
            // Fail soft to enabled when config store is unavailable (unit seams / early boot).
        }

        return true;
    }

    public static function scopeKey(int $websiteId, int $storeId = 0): string
    {
        if ($websiteId < 0 || $storeId < 0) {
            throw new \InvalidArgumentException('affiliate_storefront_scope_invalid');
        }

        return 'website' . $websiteId . '.store' . $storeId . '.default';
    }

    public static function websiteScopeKey(int $websiteId): string
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('affiliate_storefront_website_invalid');
        }

        return 'website' . $websiteId . '.default.default';
    }

    private function testingEnabled(int $websiteId, int $storeId): bool
    {
        $candidates = [
            self::scopeKey($websiteId, $storeId),
            self::websiteScopeKey($websiteId),
            ConfigReader::SCOPE_GLOBAL,
            'global',
        ];
        foreach ($candidates as $scope) {
            if (array_key_exists($scope, $this->testingByScope ?? [])) {
                return (bool)$this->testingByScope[$scope];
            }
        }

        return true;
    }

    private function resolvedValue(string $key, string $scope, mixed $default): mixed
    {
        $resolved = $this->configStore()->resolveConfig(
            $key,
            self::CONFIG_MODULE,
            self::CONFIG_AREA,
            $scope,
            ConfigReader::LOCALE_DEFAULT,
            $default,
        );
        if (!is_array($resolved) || !array_key_exists('value', $resolved)) {
            return $default;
        }
        $value = $resolved['value'];
        if ($default === null && $value === null) {
            return null;
        }

        return $value;
    }

    private function configStore(): ConfigStore
    {
        return $this->config ??= new ConfigStore();
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return $default;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true)) {
                return false;
            }
        }

        return $default;
    }

    private static function normalizeTestingScopeKey(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if ($scope === '' || $scope === 'global' || $scope === ConfigReader::SCOPE_GLOBAL) {
            return ConfigReader::SCOPE_GLOBAL;
        }
        if (preg_match('/^website:(\d+)$/D', $scope, $match) === 1) {
            return self::websiteScopeKey((int)$match[1]);
        }
        if (preg_match('/^store:(\d+):(\d+)$/D', $scope, $match) === 1) {
            return self::scopeKey((int)$match[1], (int)$match[2]);
        }

        return $scope;
    }
}
