<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;

/**
 * Website/global switch: deposit paid must pass merchant review before balance pay.
 */
final class HangMerchantReviewPolicy
{
    public const CONFIG_KEY = 'b2b_hang_require_merchant_review_before_balance';

    private const CONFIG_MODULE = 'Weline_B2B';
    private const CONFIG_AREA = ConfigReader::area_FRONTEND;
    private const DEFAULT_REQUIRED = true;

    /** @var array<string, bool>|null website:{id}|global => required */
    private ?array $testingByScope = null;

    public function __construct(private ?ConfigStore $config = null)
    {
    }

    /**
     * @param array<string, bool> $byScope Keys: `website:{id}`, `global`, or website id string
     */
    public static function forTesting(array $byScope = []): self
    {
        $policy = new self();
        $normalized = [];
        foreach ($byScope as $scope => $value) {
            $key = self::normalizeTestingScopeKey((string)$scope);
            $normalized[$key] = (bool)$value;
        }
        $policy->testingByScope = $normalized;

        return $policy;
    }

    public function isRequired(int $websiteId): bool
    {
        if ($this->testingByScope !== null) {
            return $this->testingIsRequired($websiteId);
        }

        try {
            if ($websiteId >= 0) {
                $websiteValue = $this->resolvedValue(self::websiteScopeKey($websiteId), null);
                if ($websiteValue !== null) {
                    return $this->toBool($websiteValue, self::DEFAULT_REQUIRED);
                }
            }
            $globalValue = $this->resolvedValue(ConfigReader::SCOPE_GLOBAL, null);
            if ($globalValue !== null) {
                return $this->toBool($globalValue, self::DEFAULT_REQUIRED);
            }
        } catch (\Throwable) {
            // Fail soft to default (review required) when config store unavailable.
        }

        return self::DEFAULT_REQUIRED;
    }

    private function testingIsRequired(int $websiteId): bool
    {
        $candidates = [
            self::websiteScopeKey($websiteId),
            'website:' . $websiteId,
            (string)$websiteId,
            ConfigReader::SCOPE_GLOBAL,
            'global',
        ];
        foreach ($candidates as $scope) {
            if (array_key_exists($scope, $this->testingByScope ?? [])) {
                return (bool)$this->testingByScope[$scope];
            }
        }

        return self::DEFAULT_REQUIRED;
    }

    private function resolvedValue(string $scope, mixed $default): mixed
    {
        $resolved = $this->configStore()->resolveConfig(
            self::CONFIG_KEY,
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

    private static function websiteScopeKey(int $websiteId): string
    {
        // Align with SellingModePolicy / SystemConfig website scope identity.
        return 'website' . max(0, $websiteId) . '.default.default';
    }

    private static function normalizeTestingScopeKey(string $scope): string
    {
        $scope = trim($scope);
        if ($scope === '' || $scope === 'global' || $scope === ConfigReader::SCOPE_GLOBAL) {
            return ConfigReader::SCOPE_GLOBAL;
        }
        if (preg_match('/^website:(\d+)$/', $scope, $m) === 1) {
            return self::websiteScopeKey((int)$m[1]);
        }
        if (ctype_digit($scope)) {
            return self::websiteScopeKey((int)$scope);
        }

        return $scope;
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
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($v, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return $default;
    }
}
