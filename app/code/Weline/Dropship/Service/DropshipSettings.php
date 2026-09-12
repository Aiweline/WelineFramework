<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * Scope-aware dropship settings (platforms + uplift).
 * Keys align with plan: dropship/platforms/enabled, dropship/pricing/uplift_percent.
 */
class DropshipSettings
{
    public const MODULE = 'Weline_Dropship';
    public const AREA = 'frontend';

    public const KEY_PLATFORMS_ENABLED = 'dropship/platforms/enabled';
    public const KEY_UPLIFT_PERCENT = 'dropship/pricing/uplift_percent';
    public const KEY_AUTO_PUSH = 'dropship/ops/auto_push';
    public const KEY_FOLLOW_ENABLED = 'dropship/ops/follow_enabled';
    public const DEFAULT_UPLIFT = 30;

    /**
     * @return list<string>
     */
    public function enabledPlatforms(string $storageScope = 'default.default.default'): array
    {
        $raw = $this->read(self::KEY_PLATFORMS_ENABLED, $storageScope, '');
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }
        $parts = array_filter(array_map('trim', explode(',', (string)$raw)));

        return array_values($parts);
    }

    public function isPlatformEnabled(string $providerCode, string $storageScope = 'default.default.default'): bool
    {
        $enabled = $this->enabledPlatforms($storageScope);

        return $enabled === [] || in_array($providerCode, $enabled, true);
    }

    public function upliftPercent(string $storageScope = 'default.default.default'): int
    {
        $v = (int)$this->read(self::KEY_UPLIFT_PERCENT, $storageScope, (string)self::DEFAULT_UPLIFT);

        return $v > 0 ? $v : self::DEFAULT_UPLIFT;
    }

    public function autoPushEnabled(string $storageScope = 'default.default.default'): bool
    {
        return (int)$this->read(self::KEY_AUTO_PUSH, $storageScope, '1') === 1;
    }

    public function followEnabled(string $storageScope = 'default.default.default'): bool
    {
        return (int)$this->read(self::KEY_FOLLOW_ENABLED, $storageScope, '1') === 1;
    }

    private function read(string $key, string $scope, string $default): mixed
    {
        try {
            /** @var SystemConfig $config */
            $config = ObjectManager::getInstance(SystemConfig::class);
            $value = $config->getConfig($key, self::MODULE, self::AREA, $default, $scope);
            if ($value !== null && $value !== '') {
                return $value;
            }
        } catch (\Throwable) {
            // fall through
        }

        return $default;
    }
}
