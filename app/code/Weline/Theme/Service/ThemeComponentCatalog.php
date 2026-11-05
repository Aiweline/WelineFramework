<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Dto\ThemeSlotDefinition;
use Weline\Theme\Model\WelineTheme;

class ThemeComponentCatalog
{
    /** 请求内 L0：同一请求重复取用不再走进程袋查找。 */
    private array $cache = [];
    /** @var array<string, array<string, ThemeComponentDefinition>> */
    private array $identityIndex = [];

    /**
     * 进程袋：组件定义只依赖（主题代次 + area + themeId），是全局事实。
     *
     * 旧实现把 memo 放在实例字段上，而 ObjectManager 对未实现
     * ProcessSharedInterface 的类按请求纤程建实例，memo 每请求必失 ——
     * 于是每请求重走 177 个组件的目录扫描，并重读
     * `w_theme_component`（每请求 2 次）+ `w_theme_component_version`（1 次），
     * 首次构建实测 88.88 ms。静态袋是 Worker 级的，preload 期构建结果请求期
     * 直接命中，加载就不必挪进请求里。
     *
     * 不放实例字段也不能改 ProcessSharedInterface：本类构造注入的
     * {@see ThemeContextService} 带请求上下文（预览主题 / 站点应用），
     * 变成进程单例会把 boot 期的上下文钉死在所有请求上。只共享不可变结果。
     *
     * @var array<string, array<int, ThemeComponentDefinition>>
     */
    private static array $processDefinitions = [];
    /** @var array<string, array<string, ThemeComponentDefinition>> */
    private static array $processIdentityIndex = [];
    /** 快照建立时的代次指纹；代次变化即整袋作废 */
    private static string $processGeneration = '';

    public function __construct(
        private readonly WelineTheme $welineTheme,
        private readonly ThemeDirectoryResolver $directoryResolver,
        private readonly ThemeFileComponentSource $fileSource,
        private readonly VirtualThemeComponentSource $virtualSource,
        private readonly WidgetRegistryComponentSource $widgetSource,
        private readonly ThemeContextService $themeContextService,
    ) {
    }

    /**
     * @return ThemeComponentDefinition[]
     */
    public function getDefinitions(string $area = 'frontend', ?WelineTheme $theme = null, bool $forceReload = false): array
    {
        $area = strtolower($area) === 'backend' ? 'backend' : 'frontend';
        $resolvedTheme = $this->resolveTheme($area, $theme);
        return $this->getDefinitionsForResolvedTheme($area, $resolvedTheme, $forceReload);
    }

    /**
     * @return ThemeComponentDefinition[]
     */
    private function getDefinitionsForResolvedTheme(string $area, ?WelineTheme $resolvedTheme, bool $forceReload = false): array
    {
        $themeId = $resolvedTheme?->getId() ?: 0;
        $cacheKey = "{$themeId}:{$area}";
        if (!$forceReload && isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // 代次指纹按请求冻结（同命名空间请求内只读一次），本方法每请求只做一次比较。
        $generation = self::syncProcessGeneration();
        if (!$forceReload && $generation !== '' && isset(self::$processDefinitions[$cacheKey])) {
            $this->cache[$cacheKey] = self::$processDefinitions[$cacheKey];

            return $this->cache[$cacheKey];
        }

        $definitions = [];
        $seen = [];
        $nativeThemeComponentCodes = [];

        foreach ($this->directoryResolver->getThemeChain($resolvedTheme) as $layerTheme) {
            foreach ($this->virtualSource->collect($area, $resolvedTheme, ['theme_id' => (int)$layerTheme->getId()]) as $definition) {
                $this->appendDefinition($definitions, $seen, $nativeThemeComponentCodes, $definition);
            }
            foreach ($this->fileSource->collect($area, $resolvedTheme, ['theme_id' => (int)$layerTheme->getId(), 'include_default' => false]) as $definition) {
                $this->appendDefinition($definitions, $seen, $nativeThemeComponentCodes, $definition);
            }
        }

        foreach ($this->fileSource->collect($area, $resolvedTheme, ['default_only' => true]) as $definition) {
            $this->appendDefinition($definitions, $seen, $nativeThemeComponentCodes, $definition);
        }

        foreach ($this->widgetSource->collect($area, $resolvedTheme, [
            'exclude_theme_component_codes' => array_keys($nativeThemeComponentCodes),
        ]) as $definition) {
            $this->appendDefinition($definitions, $seen, $nativeThemeComponentCodes, $definition);
        }
        $this->cache[$cacheKey] = array_values($definitions);
        $this->identityIndex[$cacheKey] = [];
        foreach ($this->cache[$cacheKey] as $definition) {
            $this->identityIndex[$cacheKey][$definition->getIdentity()] = $definition;
        }

        // 代次不可得（活跃事务 / 命名服务不可用）时不把快照钉进进程。
        if ($generation !== '') {
            self::$processDefinitions[$cacheKey] = $this->cache[$cacheKey];
            self::$processIdentityIndex[$cacheKey] = $this->identityIndex[$cacheKey];
        }

        return $this->cache[$cacheKey];
    }

    public function find(string $module, string $type, string $code, string $area = 'frontend', ?WelineTheme $theme = null): ?ThemeComponentDefinition
    {
        $identity = $this->buildIdentity($module, $type, $code);
        $area = strtolower($area) === 'backend' ? 'backend' : 'frontend';
        $resolvedTheme = $this->resolveTheme($area, $theme);
        $themeId = $resolvedTheme?->getId() ?: 0;
        $cacheKey = "{$themeId}:{$area}";
        $this->getDefinitionsForResolvedTheme($area, $resolvedTheme);

        $index = $this->identityIndex[$cacheKey] ?? self::$processIdentityIndex[$cacheKey] ?? [];

        return $index[$identity] ?? null;
    }

    /**
     * Resolve by module+code when published layout entities still carry a stale widget_type
     * (e.g. order-notice used to be form, registry is now content).
     */
    public function findByModuleCode(
        string $module,
        string $code,
        string $area = 'frontend',
        ?WelineTheme $theme = null,
    ): ?ThemeComponentDefinition {
        $module = trim($module);
        $code = trim($code);
        if ($module === '' || $code === '') {
            return null;
        }
        $area = strtolower($area) === 'backend' ? 'backend' : 'frontend';
        foreach ($this->getDefinitions($area, $theme) as $definition) {
            if ($definition->module === $module && $definition->code === $code) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Resolve slots owned by placeable container components, including external module widgets.
     *
     * @return array<string,mixed>|null
     */
    public function findSlot(
        string $slotId,
        string $area = 'frontend',
        ?WelineTheme $theme = null,
    ): ?array {
        $slotId = trim($slotId);
        if ($slotId === '') {
            return null;
        }

        $area = strtolower($area) === 'backend' ? 'backend' : 'frontend';
        foreach ($this->getDefinitions($area, $theme) as $definition) {
            foreach ($definition->slots as $key => $rawSlot) {
                $slot = is_array($rawSlot) ? $rawSlot : [];
                $candidateId = is_string($key) ? trim($key) : trim((string)($slot['id'] ?? ''));
                if ($candidateId !== $slotId) {
                    continue;
                }

                $accept = $slot['accept'] ?? ($slot['accepts'] ?? []);
                $accept = is_array($accept)
                    ? array_values(array_filter(array_map(
                        static fn(mixed $value): string => trim((string)$value),
                        $accept
                    )))
                    : [];
                $max = isset($slot['max']) && is_numeric($slot['max']) ? (int)$slot['max'] : null;
                $exclusive = (bool)($slot['exclusive'] ?? false) || $max === 1;

                return (new ThemeSlotDefinition(
                    id: $slotId,
                    name: trim((string)($slot['name'] ?? '')) ?: $slotId,
                    area: $area,
                    accept: $accept,
                    exclusive: $exclusive,
                    multiple: array_key_exists('multiple', $slot) ? (bool)$slot['multiple'] : !$exclusive,
                    append: (bool)($slot['append'] ?? false),
                    prepend: (bool)($slot['prepend'] ?? false),
                    meta: [
                        'position' => $slot['position'] ?? null,
                        'reject' => is_array($slot['reject'] ?? null) ? $slot['reject'] : [],
                        'max' => $max,
                        'min' => isset($slot['min']) && is_numeric($slot['min']) ? (int)$slot['min'] : null,
                        'required' => (bool)($slot['required'] ?? false),
                        'has_template_widgets' => false,
                        'template_widgets' => [],
                        'component_identity' => $definition->getIdentity(),
                    ],
                    sourcePath: $definition->templatePath,
                ))->toArray();
            }
        }

        return null;
    }

    public function clearCache(): void
    {
        $this->cache = [];
        $this->identityIndex = [];
        self::clearProcessSnapshot();
    }

    /**
     * 清空进程袋。
     *
     * 同 Worker 内由 {@see \Weline\Theme\Api\Runtime\ProcessCacheResetter} 与
     * {@see \Weline\Theme\Service\ThemeRuntimeCacheCleaner} 调用；跨 Worker 失效靠
     * {@see self::GENERATION_NAMESPACES} 的代次 bump，不依赖本方法广播。
     */
    public static function clearProcessSnapshot(): void
    {
        self::$processDefinitions = [];
        self::$processIdentityIndex = [];
        self::$processGeneration = '';
    }

    /**
     * 快照依赖的代次命名空间。
     *
     * - theme/catalogue：{@see WelineTheme::save_after()} / {@see \Weline\Theme\Model\ThemeComponent::save_after()}
     *   等账本写入 bump；
     * - theme：theme:update 等主题资源发布 bump；
     * - widget/registry：部件注册表变更 bump（WidgetRegistryComponentSource 的数据源）。
     *
     * @return list<string>
     */
    private static function generationNamespaces(): array
    {
        $namespaces = [
            WelineTheme::CATALOG_GENERATION_NAMESPACE,
            'global/storefront/theme',
        ];
        try {
            $namespaces[] = ObjectManager::getInstance(NamespacePath::class)->global('widget', ['registry']);
        } catch (\Throwable) {
            $namespaces[] = 'global/widget/registry';
        }

        return $namespaces;
    }

    /**
     * 代次变化即整袋作废；返回当前指纹（'' 表示不可得，不驻留进程）。
     */
    private static function syncProcessGeneration(): string
    {
        $generation = self::generationFingerprint();
        if ($generation === '' || $generation !== self::$processGeneration) {
            self::$processDefinitions = [];
            self::$processIdentityIndex = [];
            self::$processGeneration = $generation;
        }

        return $generation;
    }

    private static function generationFingerprint(): string
    {
        try {
            $fingerprint = ObjectManager::getInstance(NamespaceGenerationInterface::class)
                ->fingerprint(self::generationNamespaces());
        } catch (\Throwable) {
            return '';
        }

        return \is_string($fingerprint) ? $fingerprint : '';
    }

    private function appendDefinition(array &$definitions, array &$seen, array &$nativeThemeComponentCodes, ThemeComponentDefinition $definition): void
    {
        $identity = $definition->getIdentity();
        if (isset($seen[$identity])) {
            $existing = $definitions[$identity] ?? null;
            if ($existing instanceof ThemeComponentDefinition
                && ($definition->layerKey ?? '') === 'widget_registry'
                && ($existing->layerKey ?? '') !== 'widget_registry'
            ) {
                $definitions[$identity] = $definition;
            }
            return;
        }

        $definitions[$identity] = $definition;
        $seen[$identity] = true;

        if ($definition->module === 'Weline_Theme' && $definition->type === 'theme_component') {
            $nativeThemeComponentCodes[$definition->code] = true;
        }
    }

    private function resolveTheme(string $area, ?WelineTheme $theme = null): ?WelineTheme
    {
        if ($theme && $theme->getId()) {
            return $theme;
        }

        // Use ThemeContextService which properly handles preview themes
        return $this->themeContextService->resolveTheme($area, null, true);
    }

    private function buildIdentity(string $module, string $type, string $code): string
    {
        return "{$module}::{$type}::{$code}";
    }
}
