<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Framework\Http\Fpc\CdnFpcDevModeFlagResolverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * 读 CDN SystemConfig `cdn/fpc/dev_mode`（按当前 ScopeIdentity 继承链）。
 */
final class CdnFpcDevModeFlagResolver implements CdnFpcDevModeFlagResolverInterface
{
    public const CONFIG_KEY = 'cdn/fpc/dev_mode';

    public function __construct(
        private readonly ?ConfigReader $configReader = null,
    ) {
    }

    public function isEnabledForCurrentRequest(): bool
    {
        try {
            $reader = $this->configReader;
            if ($reader === null) {
                $reader = ObjectManager::getInstance(ConfigReader::class);
            }
            if (!$reader instanceof ConfigReader) {
                return false;
            }

            $identity = RequestContext::scopeIdentity();
            if (!$identity instanceof ScopeIdentity) {
                $identity = ScopeIdentity::global();
            }

            $resolved = $reader->resolveTypedConfig(
                self::CONFIG_KEY,
                'Weline_Cdn',
                ConfigReader::area_BACKEND,
                $identity,
                null,
                false,
            );

            return self::truthy($resolved->value);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function truthy(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }
        $normalized = \strtolower(\trim((string)$value));

        return $normalized === '1' || $normalized === 'true' || $normalized === 'yes' || $normalized === 'on';
    }
}
