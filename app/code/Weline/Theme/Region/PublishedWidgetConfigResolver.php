<?php

declare(strict_types=1);

namespace Weline\Theme\Region;

use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;

/**
 * 把「编辑器发布的部件配置」交给全局区片段。
 *
 * 编辑器保存的是 Scope 工作区里的节点（`/nodes/<uid>/config`），发布后落到
 * `theme_scope_release.effective_payload_json`。全局区片段不在布局产物里（产物里没有它们的
 * 节点），所以必须在这里按 widget code 反查已发布快照——否则就是「编辑器改了、前台不变」。
 *
 * 读取链与店面品牌解析一致：RequestContext 冻结的作用域身份 → 逐级回退（渠道→店铺→网站→全局）。
 */
final class PublishedWidgetConfigResolver
{
    /** @var array<string, array<string, array<string, mixed>>>|null */
    private static ?array $memo = null;

    /**
     * 已发布部件配置的合并结果（进程快照，按发布代次分桶）。
     *
     * 旧实现每请求 `select()` 一次 `theme_scope_release`（整行含 longtext payload）
     * 并在 PHP 里重新合并 60+ 份载荷，真机 6.52ms/请求；可这张表只在发布时写。
     * 键 =「当前作用域链的 Theme 命名空间代次指纹 + theme_id」，而所有发布路径
     * （ThemeRuntimeCacheCleaner 的 clearScopedCaches / clearLayoutEntityCaches /
     * invalidateAfterVersionPublish）本来就 bump 同一命名空间——所以别的 Worker
     * 也会重建，不是「重启才生效」。
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private static array $processSnapshot = [];

    /** 进程袋身份（显式命名，禁止匿名登记）。 */
    private const SNAPSHOT_BAG = 'theme.published_widget_config.snapshot';

    /** 一个 Worker 只服务少数站点，几十个作用域链已远超实际需要。 */
    private const SNAPSHOT_BAG_MAX = 24;

    /** 测试/请求边界用：清请求级 memo。进程快照由发布代次失效，不在此清。 */
    public static function reset(): void
    {
        self::$memo = null;
    }

    /**
     * @return array<string, mixed> 该部件在已发布快照里的配置（没有就空数组）
     */
    public static function forWidgetCode(string $widgetCode): array
    {
        $widgetCode = \trim($widgetCode);
        if ($widgetCode === '') {
            return [];
        }
        $map = self::all();

        return $map[$widgetCode] ?? [];
    }

    /**
     * @return array<string, array<string, mixed>> widget_code => config
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        self::$memo = self::mergedFromSnapshot();

        return self::$memo;
    }

    /**
     * 合并结果：先问发布代次化的进程快照，没命中才回数据库重算一次。
     *
     * @return array<string, array<string, mixed>>
     */
    private static function mergedFromSnapshot(): array
    {
        try {
            $themeId = self::currentThemeId();
            if ($themeId <= 0) {
                return [];
            }
            $bagKey = self::snapshotKey($themeId);
            if ($bagKey === null) {
                // 没读到代次（发布中/事务内）：直读权威，不写进程袋。
                return self::mergeReleasedPayloads($themeId);
            }
            $bag = &DictionaryCacheNamespace::processBag(
                self::SNAPSHOT_BAG,
                self::$processSnapshot,
                self::SNAPSHOT_BAG_MAX,
            );
            if (isset($bag[$bagKey])) {
                return $bag[$bagKey];
            }

            return $bag[$bagKey] = self::mergeReleasedPayloads($themeId);
        } catch (\Throwable) {
            // 读不到就按「没有编辑器配置」处理：片段走声明默认值，页面不能因此失败。
            return [];
        }
    }

    /**
     * 该主题下、当前作用域链内的所有已发布布局载荷，按发布时间（release_id）从旧到新，
     * 合并成 widget_code => config。
     *
     * @return array<string, array<string, mixed>>
     */
    private static function mergeReleasedPayloads(int $themeId): array
    {
        // 只认「已发布」的发布记录，并按发布时间（release_id）从旧到新合并：
        // 后发布的覆盖先发布的。这样「编辑器最后一次发布」就是最终结果，
        // 不受同身份重复 workspace 行、也不受作用域层级新旧不一致的影响。
        $merged = [];
        foreach (self::publishedPayloadsByAge($themeId) as $payload) {
            foreach (self::collect($payload) as $code => $config) {
                $merged[$code] = isset($merged[$code]) && \is_array($merged[$code])
                    ? \array_replace($merged[$code], $config)
                    : $config;
            }
        }

        return $merged;
    }

    /**
     * 该主题下、当前作用域链内的所有已发布布局载荷，按发布时间（release_id）从旧到新。
     *
     * @return list<array<string, mixed>>
     */
    private static function publishedPayloadsByAge(int $themeId): array
    {
        if ($themeId <= 0) {
            return [];
        }
        $scopes = [];
        foreach (self::scopeChain() as $scope) {
            $scopes[(string)$scope->storageScope] = true;
        }
        $out = [];
        foreach (self::loadReleaseRows($themeId) as $entry) {
            if (!isset($scopes[$entry['scope']])) {
                continue;
            }
            $out[] = $entry['payload'];
        }

        return $out;
    }

    /**
     * 读权威发布行（只在快照未命中时执行）。
     *
     * @return list<array{release_id: int, scope: string, payload: array<string, mixed>}>
     */
    private static function loadReleaseRows(int $themeId): array
    {
        try {
            $model = ObjectManager::getInstance(\Weline\Theme\Model\ThemeScopeRelease::class);
            // where 必须在 select() 之前链式调用才会生效（实测）；按主题+资源+区域先收窄，
            // 否则要把整表（数千行 payload）全取回内存再过滤，白花 2–3 ms/请求。
            $rows = $model
                ->where('theme_id', $themeId)
                ->where('resource_type', 'layout')
                ->where('area', 'frontend')
                ->select()
                ->fetchArray();
        } catch (\Throwable $__e) {
            return [];
        }
        $releases = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if ((int)($row['theme_id'] ?? 0) !== $themeId) {
                continue;
            }
            if ((string)($row['area'] ?? '') !== 'frontend' || (string)($row['resource_type'] ?? '') !== 'layout') {
                continue;
            }
            $releaseId = (int)($row['release_id'] ?? 0);
            if ($releaseId <= 0) {
                continue;
            }
            $payload = \json_decode((string)($row['effective_payload_json'] ?? ''), true);
            if (!\is_array($payload)) {
                continue;
            }
            $releases[$releaseId] = [
                'release_id' => $releaseId,
                'scope' => (string)($row['scope'] ?? ''),
                'payload' => $payload,
            ];
        }
        \ksort($releases);

        return \array_values($releases);
    }

    /**
     * 快照键：当前作用域链的 Theme 命名空间代次 + theme_id。
     *
     * 发布只 bump 被发布那个作用域的 Theme 命名空间，所以键必须覆盖整条回退链，
     * 否则「站点级发布、渠道级读者」会拿着旧快照。
     */
    private static function snapshotKey(int $themeId): ?string
    {
        try {
            $namespaces = [];
            $cleaner = ObjectManager::getInstance(ThemeRuntimeCacheCleaner::class);
            $paths = ObjectManager::getInstance(\Weline\Framework\Cache\Namespace\NamespacePath::class);
            foreach (self::scopeChain() as $scope) {
                try {
                    $namespaces[] = $cleaner->scopeThemeNamespace($paths, $scope->identity);
                } catch (\Throwable) {
                    continue;
                }
            }
            if ($namespaces === []) {
                $namespaces[] = $paths->global('storefront', ['theme']);
            }
            $fingerprint = ObjectManager::getInstance(NamespaceGenerationInterface::class)
                ->fingerprint($namespaces);
        } catch (\Throwable) {
            return null;
        }
        if (!\is_string($fingerprint) || $fingerprint === '') {
            return null;
        }

        return $fingerprint . '|' . $themeId;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, array<string, mixed>>
     */
    private static function collect(array $payload): array
    {
        $out = [];
        $nodes = $payload['nodes'] ?? [];
        if (!\is_array($nodes)) {
            return $out;
        }
        foreach ($nodes as $node) {
            if (!\is_array($node)) {
                continue;
            }
            $code = (string)($node['widget_code'] ?? '');
            $config = $node['config'] ?? [];
            if ($code === '' || !\is_array($config) || $config === []) {
                continue;
            }
            if (\array_key_exists('is_active', $node) && !$node['is_active']) {
                continue;
            }
            $out[$code] = $config;
        }

        return $out;
    }

    /** @return list<ScopeContext> 由近到远：当前 → 父级 → … → 全局 */
    private static function scopeChain(): array
    {
        $hierarchy = ObjectManager::getInstance(ScopeHierarchyInterface::class);
        $identity = null;
        try {
            $identity = RequestContext::scopeIdentity();
        } catch (\Throwable) {
            $identity = null;
        }
        if (!$identity instanceof ScopeIdentity) {
            $identity = ScopeIdentity::global();
        }
        $chain = [];
        $guard = 0;
        while ($identity instanceof ScopeIdentity && $guard++ < 6) {
            try {
                $context = $hierarchy->contextFromIdentity($identity);
            } catch (\Throwable) {
                break;
            }
            if (!$context instanceof ScopeContext) {
                break;
            }
            $chain[] = $context;
            if ($identity->isGlobal()) {
                break;
            }
            try {
                $identity = $hierarchy->parentIdentity($identity);
            } catch (\Throwable) {
                break;
            }
        }

        return $chain;
    }

    /** 诊断用：当前解析到的主题 id。 */
    public static function resolvedThemeId(): int
    {
        return self::currentThemeId();
    }

    /** 诊断用：当前作用域回退链（存储作用域字符串，由近到远）。 */
    public static function resolvedScopeChain(): array
    {
        return \array_map(static fn(ScopeContext $scope): string => (string)$scope->storageScope, self::scopeChain());
    }

    private static function currentThemeId(): int
    {
        try {
            $theme = \Weline\Theme\Helper\ThemeData::getCurrentTheme();
            $id = $theme !== null ? (int)$theme->getId() : 0;
            if ($id > 0) {
                return $id;
            }
        } catch (\Throwable) {
            // fallthrough：退到主题应用上下文
        }
        try {
            $application = \Weline\Framework\Runtime\ThemeApplicationContext::current('frontend');
            if ($application !== null) {
                return (int)$application->themeId;
            }
        } catch (\Throwable) {
            // 无主题上下文
        }

        return 0;
    }
}
