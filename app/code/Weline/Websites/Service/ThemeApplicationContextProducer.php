<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\DefaultThemeInterface;
use Weline\Theme\Api\Version\ThemeApplicationReferenceReaderInterface;
use Weline\Websites\Api\Theme\ThemeApplicationInterface;
use Weline\Websites\Api\Theme\ThemeApplicationReference;

/** 网站调用方校验范围与权限后，将网站应用选择转换成框架中性输入。 */
final class ThemeApplicationContextProducer
{
    /**
     * Process-level cache for runtime display contexts.
     *
     * The storefront builds the theme application context on every request
     * (InstallThemeApplicationContext observer, inside applyParsedUrl), and each
     * build resolves the application chain and reads the version resource
     * snapshot from the database — measured ~5 ms per request. For a fixed
     * (identity, storeMode, defaultLocale, displayName) the result is identical,
     * so it is a process-scoped cache keyed on exactly those inputs.
     *
     * Only `purpose: 'runtime'` with no caller-supplied resource references or
     * access claims is cached: those are the shapes where the answer is a pure
     * function of the key. Editing/asset flows always rebuild.
     *
     * Dropped by {@see self::clearProcessCache()}, which theme updates call.
     *
     * @var array<string, \Weline\Framework\Runtime\ThemeApplicationContext>
     */
    private static array $processContextCache = [];

    private const PROCESS_CACHE_MAX = 64;

    public function __construct(
        private readonly ThemeApplicationInterface $applications,
        private readonly ScopeHierarchyInterface $hierarchy,
        private readonly ThemeApplicationReferenceReaderInterface $referenceReader,
        private readonly DefaultThemeInterface $defaultTheme,
    ) {
    }

    /**
     * Drop cached runtime theme application contexts (theme version changed).
     */
    public static function clearProcessCache(): void
    {
        self::$processContextCache = [];
    }

    /**
     * Cached runtime context for a scope, without resolving anything.
     *
     * Callers that already know the scope inputs (the observer does, once it has
     * the frozen ScopeIdentity) use this to skip the whole build — including the
     * website row read they would otherwise do just to obtain the display name.
     * Returns null when the exact key is not cached, so the caller falls back to
     * build().
     *
     * The display name must be supplied because build() embeds it in the
     * context; passing a wrong name simply misses the cache.
     */
    public function cachedRuntimeContext(
        ScopeIdentity $identity,
        string $storeMode,
        string $defaultLocale,
        string $displayName = '',
    ): ?ThemeApplicationContext {
        $cacheKey = \implode("\n", [
            $identity->canonicalKey(),
            $storeMode,
            $defaultLocale,
            $displayName,
        ]);

        return self::$processContextCache[$cacheKey] ?? null;
    }

    /**
     * $identity 必须来自权威目录，不得直接采用浏览器声明。
     * 历史资源引用由 Theme 公开能力返回并由宿主校验后传入，不以最新祖先补齐。
     * @param array<string,array<string,mixed>> $resourceReferencesByScope 按准确存储范围给出资源引用。
     * @param array<string,mixed> $assetAccessClaims 已校验的文件访问声明，原样传递。
     * @param list<string> $invalidationNamespaces 使用方负责的精确失效范围。
     */
    public function build(
        ScopeIdentity $identity,
        string $storeMode,
        string $defaultLocale,
        string $displayName = '',
        array $resourceReferencesByScope = [],
        string $purpose = 'runtime',
        array $assetAccessClaims = [],
        array $invalidationNamespaces = [],
    ): ThemeApplicationContext {
        if ($identity->storeMode !== null && $identity->storeMode !== $storeMode) {
            throw new \InvalidArgumentException('website_theme_application_store_mode_mismatch');
        }

        // Cacheable shape: plain runtime build with no caller-supplied references.
        $cacheKey = null;
        if ($purpose === 'runtime'
            && $resourceReferencesByScope === []
            && $assetAccessClaims === []
            && $invalidationNamespaces === []
        ) {
            $cacheKey = \implode("\n", [
                $identity->canonicalKey(),
                $storeMode,
                $defaultLocale,
                $displayName,
            ]);
            if (isset(self::$processContextCache[$cacheKey])) {
                return self::$processContextCache[$cacheKey];
            }
        }

        $keys = [];
        $scopes = [];
        $cursor = $identity;
        do {
            $key = $this->hierarchy->toStorageScope($cursor);
            $keys[] = $cursor->canonicalKey();
            $scopes[] = [
                'provider' => 'websites',
                'scope_key' => $key,
                // Website/Global typed identity 没有模式；应用链沿用本次明确模式。
                'store_mode' => $storeMode,
                'display_name' => $cursor === $identity ? $displayName : '',
                'default_locale' => $defaultLocale,
                'resource_references' => $resourceReferencesByScope[$key] ?? [],
            ];
            $cursor = $this->hierarchy->parentIdentity($cursor);
        } while ($cursor !== null);
        $resolved = $this->applications->resolve($keys, $storeMode, 'frontend');
        $reference = $resolved->reference;
        if ($reference === null) {
            // 无网站/祖先应用引用时回落 Theme 模块全局默认（package_defaults；theme_id 可为 0）。
            $validated = $this->defaultTheme->defaultApplicationReference(
                'frontend',
                (string)$scopes[0]['scope_key'],
                $storeMode,
            );
            $reference = new ThemeApplicationReference(
                themeId: (int)$validated['theme_id'],
                themeVersionId: (int)$validated['theme_version_id'],
                contentRevision: (int)$validated['content_revision'],
                versionOwnerScope: (string)$validated['owner_scope'],
                versionOwnerStoreMode: (string)$validated['store_mode'],
                area: (string)$validated['area'],
            );
        }
        // 一次解析应用后，只取该准确版本的资源快照；不在渲染途中再解析应用或最新草稿。
        $resources=$this->referenceReader->resourceReferences($reference->toArray());
        foreach ($resources as $hash=>$resource) {
            $context=$resource['context']??[];
            $scope=$context['scope']??[];
            $storage=(string)($scope['storage_scope']??'');
            if (($scope['provider']??'')!=='websites' || ($scope['store_mode']??'')!==$storeMode
                || ($context['area']??'')!=='frontend') {
                throw new \RuntimeException('website_theme_application_resource_scope_mismatch');
            }
            $found=false;
            foreach ($scopes as &$contentScope) {
                if ($contentScope['scope_key']===$storage) {
                    // 正式请求以版本快照为准；编辑调用方可显式提供已经校验的编辑引用。
                    if ($purpose==='runtime' || !array_key_exists($hash,$contentScope['resource_references'])) {
                        $contentScope['resource_references'][$hash]=$resource;
                    }
                    $found=true;
                    break;
                }
            }
            unset($contentScope);
            if (!$found) {throw new \RuntimeException('website_theme_application_resource_owner_outside_chain');}
        }
        $context = new ThemeApplicationContext(
            provider: 'websites',
            scopeKey: $scopes[0]['scope_key'],
            storeMode: $storeMode,
            area: 'frontend',
            themeId: $reference->themeId,
            versionOwnerScope: $reference->versionOwnerScope,
            versionOwnerStoreMode: $reference->versionOwnerStoreMode,
            themeVersionId: $reference->themeVersionId,
            contentRevision: $reference->contentRevision,
            defaultLocale: $defaultLocale,
            displayName: $displayName,
            contentScopes: $scopes,
            purpose: $purpose,
            assetAccessClaims: $assetAccessClaims,
            invalidationNamespaces: $invalidationNamespaces,
        );

        if ($cacheKey !== null) {
            if (\count(self::$processContextCache) >= self::PROCESS_CACHE_MAX
                && !isset(self::$processContextCache[$cacheKey])
            ) {
                // Bounded, oldest-first eviction: worker processes are long-lived
                // and keyed by scope, so the population is small and stable.
                unset(self::$processContextCache[\array_key_first(self::$processContextCache)]);
            }
            self::$processContextCache[$cacheKey] = $context;
        }

        return $context;
    }
}
