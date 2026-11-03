<?php

declare(strict_types=1);

namespace Weline\Theme\Region;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Scoped\ThemeContentScope;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\WelineTheme;

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
     * 已发布载荷按 release_id 的进程级缓存：发布记录一旦写入就不再变化，
     * 因此解码结果可以跨请求复用（每请求少解 60+ 份 JSON，约 2–3 ms）。
     *
     * @var array<int, array<string, array<string, mixed>>>
     */
    private static array $payloadCache = [];

    /** 测试/请求边界用。 */
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
        self::$memo = [];
        try {
            $themeId = self::currentThemeId();
            if ($themeId <= 0) {
                return self::$memo;
            }
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
            self::$memo = $merged;
        } catch (\Throwable) {
            // 读不到就按「没有编辑器配置」处理：片段走声明默认值，页面不能因此失败。
            self::$memo = [];
        }

        return self::$memo;
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
        $scopes = [];
        foreach (self::scopeChain() as $scope) {
            $scopes[(string)$scope->storageScope] = true;
        }
        $releases = [];
        foreach ($rows as $row) {
            if ((int)($row['theme_id'] ?? 0) !== $themeId) {
                continue;
            }
            if ((string)($row['area'] ?? '') !== 'frontend' || (string)($row['resource_type'] ?? '') !== 'layout') {
                continue;
            }
            if (!isset($scopes[(string)($row['scope'] ?? '')])) {
                continue;
            }
            $releaseId = (int)($row['release_id'] ?? 0);
            if ($releaseId <= 0) {
                continue;
            }
            $releases[$releaseId] = $row;
        }
        \ksort($releases);
        $out = [];
        foreach ($releases as $releaseId => $row) {
            if (!isset(self::$payloadCache[$releaseId])) {
                $decoded = \json_decode((string)($row['effective_payload_json'] ?? ''), true);
                if (!\is_array($decoded)) {
                    continue;
                }
                self::$payloadCache[$releaseId] = $decoded;
                if (\count(self::$payloadCache) > 256) {
                    // 有界缓存：只保留最近 256 份，避免常驻 worker 无限增长。
                    self::$payloadCache = \array_slice(self::$payloadCache, -256, null, true);
                }
            }
            $out[] = self::$payloadCache[$releaseId];
        }

        return $out;
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

    private static function storeMode(ScopeContext $scope): string
    {
        $mode = \trim((string)$scope->storeMode);

        return $mode === '' ? 'normal' : $mode;
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
