<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service\Preload;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\AbstractWorkerPreloadProvider;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * Publishes hot system-config rows into process memory at Worker start-up.
 *
 * Root cause this removes: `SystemConfig::resolveTypedConfig()` keeps its batch
 * rows in `self::$configs` (process cache) but reads it *after* the request cache
 * and the cache-envelope layer, so on every request the first resolver call
 * still walked cache ranking + envelope read before landing on the same rows —
 * measured 1.7–3.2 ms of the first call per request (the FPC gate asks for
 * `Weline_Cdn` dev-mode on every storefront request).
 *
 * Calling resolveTypedConfig() here for the scopes a storefront actually asks
 * for leaves `self::$configs` populated, so the request path is a plain array
 * hit. Module-owned: registered from etc/module.php, not hard-coded in runtime.
 */
final class SystemConfigHotKeysPreloadProvider extends AbstractWorkerPreloadProvider
{
    /**
     * Config keys read on every storefront request, with the module and area the
     * request path uses.
     *
     * Keep this list to keys that gate request flow; each entry costs a config
     * resolution at boot.
     */
    private const HOT_KEYS = [
        ['cdn/fpc/dev_mode', 'Weline_Cdn', SystemConfig::area_BACKEND],
    ];

    public function code(): string
    {
        return 'system_config_hot_keys';
    }

    public function priority(): int
    {
        return 62;
    }

    /**
     * Runs after the website directory so it can warm the default scope plus each
     * catalogue website.
     */
    public function dependsOn(): array
    {
        return ['website_directory'];
    }

    public function isEnabled(WorkerPreloadContext $context): bool
    {
        return $context->runtimeMode() === \Weline\Framework\Runtime\RuntimeInterface::MODE_WLS;
    }

    public function invalidationKeys(): array
    {
        return ['system_config'];
    }

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult
    {
        unset($context);
        $start = \microtime(true);
        $memoryStart = \memory_get_usage(true);

        /** @var SystemConfig $config */
        $config = ObjectManager::getInstance(SystemConfig::class);

        // The storefront resolves config against the frozen channel identity.
        // Warm the global scope plus the default website channel, which is what
        // an unmatched/default host resolves to.
        $identities = [
            ScopeIdentity::global(),
            ScopeIdentity::channel(0, 'default', 'default', 'default', ScopeIdentity::MODE_NORMAL),
        ];

        $warmed = 0;
        foreach (self::HOT_KEYS as [$key, $module, $area]) {
            foreach ($identities as $identity) {
                try {
                    $config->resolveTypedConfig($key, $module, $area, $identity, null, null);
                    $warmed++;
                } catch (\Throwable) {
                    // A single unresolvable key must not fail the whole warm-up.
                }
            }
        }

        return WorkerPreloadResult::warmed(
            $this->code(),
            $this->phase(),
            $warmed,
            \round((\microtime(true) - $start) * 1000, 2),
            \memory_get_usage(true) - $memoryStart,
        );
    }
}
