<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\Framework\Database\ConnectionFactory;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;

/**
 * Website/Store selling-mode switches with optional product-level overrides.
 * Session preference is advisory only — cart writes still go through SellingTypeResolver.
 */
final class SellingModePolicy
{
    public const MODE_TOC = 'toc';
    public const MODE_TOB = 'tob';

    public const CONFIG_TOC_ENABLED = 'selling_mode_toc_enabled';
    public const CONFIG_TOB_ENABLED = 'selling_mode_tob_enabled';

    public const PRODUCT_FLAG_TOC = 'selling_mode_toc';
    public const PRODUCT_FLAG_TOB = 'selling_mode_tob';

    public const DEFAULT_MOQ = 5;
    public const DEFAULT_QTY_STEP = 5;

    private const CONFIG_MODULE = 'Weline_B2B';
    private const CONFIG_AREA = ConfigReader::area_FRONTEND;

    /** @var array<string, array{toc?:mixed,tob?:mixed}>|null */
    private ?array $testingByScope = null;

    public function __construct(private ?ConfigStore $config = null)
    {
    }

    public static function forConnection(ConnectionFactory $connection): self
    {
        return new self(ConfigStore::forConnection($connection));
    }

    /**
     * @param array<string, array{toc?:mixed,tob?:mixed}|bool> $byScope
     *        Keys are normalized scopes (`website{N}.store{M}.default`) or shorthand
     *        `website:{id}` / `store:{website}:{store}`; values may be
     *        `['toc'=>bool,'tob'=>bool]` or a bool applied to both modes.
     */
    public static function forTesting(array $byScope = []): self
    {
        $policy = new self();
        $normalized = [];
        foreach ($byScope as $scope => $value) {
            $key = self::normalizeTestingScopeKey((string)$scope);
            if (is_bool($value)) {
                $normalized[$key] = ['toc' => $value, 'tob' => $value];
                continue;
            }
            if (!is_array($value)) {
                throw new \InvalidArgumentException('selling_mode_testing_scope_invalid');
            }
            $normalized[$key] = [
                'toc' => $value['toc'] ?? true,
                'tob' => $value['tob'] ?? true,
            ];
        }
        $policy->testingByScope = $normalized;
        return $policy;
    }

    public function isModeEnabled(
        string $mode,
        int $websiteId,
        int $storeId = 0,
        ?array $productFlags = null,
    ): bool {
        $mode = strtolower(trim($mode));
        if ($mode !== self::MODE_TOC && $mode !== self::MODE_TOB) {
            return false;
        }
        if ($websiteId < 0 || $storeId < 0) {
            return false;
        }

        if (!$this->configEnabled($mode, $websiteId, $storeId)) {
            return false;
        }

        return $this->productFlagAllows($mode, $productFlags);
    }

    public function defaultMoq(): int
    {
        return self::DEFAULT_MOQ;
    }

    public function defaultQtyStep(): int
    {
        return self::DEFAULT_QTY_STEP;
    }

    /**
     * Session preference helper: returns toc|tob only when that mode is enabled
     * for the given Website/Store; otherwise falls back to toc.
     * Does not write carts — callers must still use SellingTypeResolver.
     */
    public function preferredModeFromSession(
        ?string $sessionPref,
        int $websiteId = 0,
        int $storeId = 0,
        ?array $productFlags = null,
    ): string {
        $pref = strtolower(trim((string)$sessionPref));
        if (($pref === self::MODE_TOC || $pref === self::MODE_TOB)
            && $this->isModeEnabled($pref, $websiteId, $storeId, $productFlags)
        ) {
            return $pref;
        }
        return self::MODE_TOC;
    }

    public static function scopeKey(int $websiteId, int $storeId = 0): string
    {
        if ($websiteId < 0 || $storeId < 0) {
            throw new \InvalidArgumentException('selling_mode_scope_invalid');
        }
        return 'website' . $websiteId . '.store' . $storeId . '.default';
    }

    public static function websiteScopeKey(int $websiteId): string
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('selling_mode_website_invalid');
        }
        return 'website' . $websiteId . '.default.default';
    }

    private function configEnabled(string $mode, int $websiteId, int $storeId): bool
    {
        $key = $mode === self::MODE_TOC ? self::CONFIG_TOC_ENABLED : self::CONFIG_TOB_ENABLED;
        if ($this->testingByScope !== null) {
            return $this->testingConfigEnabled($mode, $websiteId, $storeId);
        }

        try {
            $storeScope = self::scopeKey($websiteId, $storeId);
            $websiteScope = self::websiteScopeKey($websiteId);
            $storeValue = $this->resolvedValue($key, $storeScope, null);
            if ($storeValue !== null) {
                return $this->toBool($storeValue, true);
            }
            $websiteValue = $this->resolvedValue($key, $websiteScope, null);
            if ($websiteValue !== null) {
                return $this->toBool($websiteValue, true);
            }
            $globalValue = $this->resolvedValue($key, ConfigReader::SCOPE_GLOBAL, null);
            if ($globalValue !== null) {
                return $this->toBool($globalValue, true);
            }
        } catch (\Throwable) {
            // Fail soft to defaults when config store is unavailable in unit seams.
        }

        return true;
    }

    private function testingConfigEnabled(string $mode, int $websiteId, int $storeId): bool
    {
        $candidates = [
            self::scopeKey($websiteId, $storeId),
            self::websiteScopeKey($websiteId),
            ConfigReader::SCOPE_GLOBAL,
            'global',
        ];
        foreach ($candidates as $scope) {
            if (!isset($this->testingByScope[$scope])) {
                continue;
            }
            $row = $this->testingByScope[$scope];
            return $this->toBool($row[$mode] ?? true, true);
        }
        return true;
    }

    /**
     * Product EAV override via request/array attributes. Unset => allow (fail-soft).
     * When Product EAV helpers exist, callers should hydrate flags into this array.
     *
     * @param array<string,mixed>|null $productFlags
     */
    private function productFlagAllows(string $mode, ?array $productFlags): bool
    {
        if ($productFlags === null) {
            return true;
        }
        $flagKey = $mode === self::MODE_TOC ? self::PRODUCT_FLAG_TOC : self::PRODUCT_FLAG_TOB;
        if (!array_key_exists($flagKey, $productFlags)) {
            return true;
        }
        return $this->toBool($productFlags[$flagKey], true);
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
        // Treat explicit null default sentinel as "unset" when the store echoes default.
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
