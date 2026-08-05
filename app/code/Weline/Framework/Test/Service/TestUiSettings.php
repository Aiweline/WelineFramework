<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Service;

use Weline\SystemConfig\Model\SystemConfig;

/**
 * 测试管理「UI 测试」开关：读写 SystemConfig（全局 backend）。
 */
final class TestUiSettings
{
    public const MODULE = 'Weline_Framework';
    public const KEY_UI_ENABLED = 'test.ui_enabled';

    public function __construct(
        private readonly SystemConfig $systemConfig,
    ) {
    }

    public function isUiEnabled(): bool
    {
        $value = $this->systemConfig->getConfig(
            self::KEY_UI_ENABLED,
            self::MODULE,
            SystemConfig::area_BACKEND,
            false
        );

        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    public function setUiEnabled(bool $enabled): bool
    {
        return $this->systemConfig->setScopedConfig(
            key: self::KEY_UI_ENABLED,
            value: $enabled,
            module: self::MODULE,
            area: SystemConfig::area_BACKEND,
            scope: SystemConfig::SCOPE_GLOBAL,
            options: [
                'value_types' => [
                    self::KEY_UI_ENABLED => SystemConfig::VALUE_TYPE_BOOL,
                ],
                'metadata' => [
                    'source' => 'framework_test_ui_switch',
                ],
            ],
        );
    }
}
