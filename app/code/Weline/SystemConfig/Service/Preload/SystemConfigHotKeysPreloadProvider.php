<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service\Preload;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\AbstractWorkerPreloadProvider;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * Publishes the config modules the storefront actually reads into process memory
 * before Worker READY.
 *
 * Why this exists
 * ---------------
 * `SystemConfig::resolveTypedConfig()` keeps its batch rows in `self::$configs`
 * (process cache, keyed by module + area + scope chain + locales). The cache is
 * correct, but its key embeds the **scope chain of the resolved identity**, so a
 * warm-up that invents an identity warms a bucket no real request reads.
 * Measured on a cold Worker: the first storefront request still paid 3.49 ms
 * (Weline_Cdn) + 1.83 ms (Weline_Theme) — exactly the two measured first-call
 * costs for those module/area pairs.
 *
 * What this does
 * --------------
 * 1. Loads the **whole** `w_system_config` table into a process-memory snapshot
 *    ({@see SystemConfig::warmTableSnapshot()}). Every (module, scope, locale)
 *    lookup afterwards is a memory slice — including the "this module has no
 *    rows" conclusion, which is what previously degenerated into one probe query
 *    per scope×locale (73 of the 79 `w_system_config` statements in a single
 *    request).
 * 2. Resolves ONE batch per hot (module, area) for every real storefront identity
 *    (global + each website's default store/channel + the synthetic unresolved
 *    chain), so the request path is a plain array hit. Those batches are served
 *    from the snapshot, so step 2 issues no SQL at all.
 *
 * `SystemConfig::configuredTargets()` is reported in the result so the coverage
 * gap is visible in the preload log instead of being guessed; once the snapshot
 * is loaded it is derived from the snapshot rather than re-querying the table.
 *
 * Invalidation (required)
 * -----------------------
 * The warmed rows live in the very same `self::$configs` the request reads, and
 * the existing write path already drops them:
 * admin save → `ConfigCacheInvalidationService` → `SystemConfig::clearProcessCache($area, $module)`.
 * Warming therefore never outlives a backend change.
 *
 * Module-owned: registered from etc/module.php; the framework runtime only
 * schedules providers and carries no config knowledge.
 */
final class SystemConfigHotKeysPreloadProvider extends AbstractWorkerPreloadProvider
{
    /**
     * (module, area) pairs measured on the storefront request path.
     *
     * These are pre-seeded into `self::$configs` per warmed identity so the very
     * first request is a plain process-bag hit. Rows come from the whole-table
     * snapshot, so this loop issues **zero** SQL.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const HOT_MODULES = [
        ['Weline_Cdn', SystemConfig::area_BACKEND],
        ['Weline_Theme', SystemConfig::area_FRONTEND],
    ];

    /** Bound: identities are site/store/channel combinations, never unbounded. */
    private const MAX_IDENTITIES = 12;

    /**
     * Default Website scope. Part of the framework default-scope contract; kept as
     * literals so this module does not import Weline_Websites constants (optional
     * dependency).
     */
    private const DEFAULT_WEBSITE_ID = 0;
    private const DEFAULT_WEBSITE_CODE = 'default';

    public function code(): string
    {
        return 'system_config_hot_keys';
    }

    public function priority(): int
    {
        return 62;
    }

    /**
     * Runs after the website directory so the real site/store/channel codes are
     * already in process memory.
     */
    public function dependsOn(): array
    {
        return ['website_directory'];
    }

    public function isEnabled(WorkerPreloadContext $context): bool
    {
        return $context->runtimeMode() === RuntimeInterface::MODE_WLS;
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

        // 第一步：把整张配置表一次读进进程内存。
        // 之后请求期任何 (area, module, scope, locale) 都是内存切片，包括"该模块没配置"
        // 这一结论，不再逐 key 探针查库（单个请求 79 条 w_system_config 的来源）。
        $snapshotTargets = SystemConfig::warmTableSnapshot();
        $snapshotReady = SystemConfig::hasTableSnapshot();

        $failures = [];
        if (!$snapshotReady) {
            // 读失败只如实记录，不冒成功：请求期会退回逐 key 库读（慢但正确）。
            $failures['system_config/snapshot'] = 'snapshot_unavailable';
        }

        /** @var SystemConfig $config */
        $config = ObjectManager::getInstance(SystemConfig::class);
        $identities = $this->storefrontIdentities();

        $warmed = 0;
        if ($snapshotReady) {
            foreach ($identities as $identity) {
                foreach (self::HOT_MODULES as [$module, $area]) {
                    if ($config->warmModuleRows($module, $area, $identity)) {
                        ++$warmed;
                        continue;
                    }
                    $failures[$module . '/' . $area] = 'warm_failed';
                }
            }
        }

        $durationMs = \round((\microtime(true) - $start) * 1000, 2);
        $memoryDelta = \memory_get_usage(true) - $memoryStart;

        return WorkerPreloadResult::warmed(
            $this->code(),
            $this->phase(),
            $warmed,
            $durationMs,
            $memoryDelta,
            [
                'identities' => \count($identities),
                // 整表快照覆盖的 (module, area) 对数：请求期能命中的范围。
                'snapshot_targets' => $snapshotTargets,
                'snapshot_ready' => $snapshotReady,
                // 覆盖率可见：库里真实存在的 (module, area) 对数（快照就位时由快照派生，不再查库）。
                'configured_targets' => \count(SystemConfig::configuredTargets()),
                'hot_modules' => \count(self::HOT_MODULES),
                'failures' => $failures,
            ],
        );
    }

    /**
     * Identities a storefront request can resolve to.
     *
     * `chainFromIdentity()` derives the config scope chain from the identity, so
     * warming a fabricated "default/default/default" bucket only helps the
     * unmatched-host case. Each real website contributes its default store and
     * channel; the synthetic chain stays as the last entry because that is what an
     * unresolved route uses.
     *
     * Weline_Websites is an optional dependency: when it is absent this degrades
     * to global + synthetic instead of failing the worker.
     *
     * @return list<ScopeIdentity>
     */
    private function storefrontIdentities(): array
    {
        $identities = [ScopeIdentity::global()];

        if (!\class_exists('Weline\\Websites\\Model\\Website')
            || !\class_exists('Weline\\Websites\\Service\\StoreCatalog')
        ) {
            return $this->withSyntheticIdentity($identities);
        }

        /** @var \Weline\Websites\Service\StoreCatalog $storeCatalog */
        $storeCatalog = ObjectManager::getInstance('Weline\\Websites\\Service\\StoreCatalog');
        $channelCatalog = \interface_exists('Weline\\Websites\\Api\\Catalog\\SalesChannelCatalogInterface')
            ? ObjectManager::getInstance('Weline\\Websites\\Api\\Catalog\\SalesChannelCatalogInterface')
            : null;

        /** @var array<int, array<string, mixed>> $websiteRows */
        $websiteRows = \Weline\Websites\Model\Website::fetchCatalogRows();
        foreach ($websiteRows as $row) {
            if (\count($identities) >= self::MAX_IDENTITIES) {
                break;
            }
            try {
                $identity = $this->identityForWebsite($row, $storeCatalog, $channelCatalog);
            } catch (\Throwable) {
                // One unresolvable site must not starve the others.
                continue;
            }
            if ($identity instanceof ScopeIdentity) {
                $identities[] = $identity;
            }
        }

        return $this->withSyntheticIdentity($identities);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function identityForWebsite(
        array $row,
        object $storeCatalog,
        ?object $channelCatalog,
    ): ?ScopeIdentity {
        $websiteId = (int)($row[\Weline\Websites\Model\Website::schema_fields_ID] ?? -1);
        $websiteCode = \trim((string)($row[\Weline\Websites\Model\Website::schema_fields_CODE] ?? ''));
        if ($websiteId < self::DEFAULT_WEBSITE_ID || $websiteCode === '') {
            return null;
        }

        $store = $this->pickDefault((array)$storeCatalog->byWebsite($websiteId));
        if ($store === null) {
            return null;
        }
        $storeCode = \trim((string)($store->code ?? ''));
        if ($storeCode === '') {
            return null;
        }

        $channelCode = $storeCode;
        if ($channelCatalog !== null) {
            $channel = $this->pickDefault((array)$channelCatalog->byStore((int)($store->id ?? 0)));
            $resolved = $channel !== null ? \trim((string)($channel->code ?? '')) : '';
            if ($resolved !== '') {
                $channelCode = $resolved;
            }
        }

        return ScopeIdentity::channel(
            $websiteId,
            $websiteCode,
            $storeCode,
            $channelCode,
            ScopeIdentity::MODE_NORMAL,
        );
    }

    /**
     * Prefer the declared default row, else the first usable one.
     *
     * @param array<int, mixed> $rows
     */
    private function pickDefault(array $rows): ?object
    {
        $fallback = null;
        foreach ($rows as $row) {
            if (!\is_object($row)) {
                continue;
            }
            if (($row->isDefault ?? false) === true) {
                return $row;
            }
            $fallback ??= $row;
        }

        return $fallback;
    }

    /**
     * @param list<ScopeIdentity> $identities
     * @return list<ScopeIdentity>
     */
    private function withSyntheticIdentity(array $identities): array
    {
        if (\count($identities) < self::MAX_IDENTITIES) {
            $identities[] = ScopeIdentity::channel(
                self::DEFAULT_WEBSITE_ID,
                self::DEFAULT_WEBSITE_CODE,
                'default',
                'default',
                ScopeIdentity::MODE_NORMAL,
            );
        }

        return $identities;
    }
}
