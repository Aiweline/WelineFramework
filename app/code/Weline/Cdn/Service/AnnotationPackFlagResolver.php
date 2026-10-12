<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * 读 CDN SystemConfig `cdn/rules/pack_annotations`（global/website 继承链）。
 * 读失败时默认 true：Free 容量下以三面打包进边缘，避免注解永远推不上去。
 */
class AnnotationPackFlagResolver
{
    public const CONFIG_KEY = 'cdn/rules/pack_annotations';

    public function __construct(
        private readonly ?ConfigReader $configReader = null,
    ) {
    }

    public function isEnabled(?ScopeIdentity $identity = null): bool
    {
        try {
            $reader = $this->configReader;
            if ($reader === null) {
                $reader = ObjectManager::getInstance(ConfigReader::class);
            }
            if (!$reader instanceof ConfigReader) {
                return true;
            }

            $scope = $identity instanceof ScopeIdentity ? $identity : ScopeIdentity::global();
            $resolved = $reader->resolveTypedConfig(
                self::CONFIG_KEY,
                'Weline_Cdn',
                ConfigReader::area_BACKEND,
                $scope,
                null,
                true,
            );

            return self::truthy($resolved->value, true);
        } catch (\Throwable) {
            return true;
        }
    }

    private static function truthy(mixed $value, bool $default = true): bool
    {
        if ($value === null) {
            return $default;
        }
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }
        $normalized = \strtolower(\trim((string)$value));
        if ($normalized === '') {
            return $default;
        }
        if ($normalized === '0' || $normalized === 'false' || $normalized === 'no' || $normalized === 'off') {
            return false;
        }

        return $normalized === '1' || $normalized === 'true' || $normalized === 'yes' || $normalized === 'on';
    }
}
