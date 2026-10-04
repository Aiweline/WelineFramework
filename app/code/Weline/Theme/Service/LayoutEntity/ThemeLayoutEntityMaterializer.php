<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemePlaceableRegistry;
use Weline\Theme\Service\ThemeResourceCatalog;

/** Builds ordinary derived PHTML. The save coordinator publishes complete candidate groups. */
final class ThemeLayoutEntityMaterializer
{
    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ThemeLayoutSlotTreeBuilder $slotTree,
        private readonly ThemeLayoutEntityConfigStore $configStore,
    ) {}

    /** Discover source-owned containers before version-specific theme/locale configuration is frozen. */
    public function discoverPageNativeOwners(ThemeVersionIdentity $identity, string $layoutType, string $layoutOption, array $nodes): array
    {
        foreach ($nodes as $node) {
            if (is_array($node) && ($node['widget_code'] ?? '') === '__no_widget_placements__') { return $nodes; }
        }
        $origin = (new ThemeLayoutEntityDocumentShellBaker())->resolveLayoutAbsolutePath($layoutType, $identity->area, $layoutOption, $identity->themeId);
        return (new LayoutRelationCompiler(null, $this->loadTheme($identity)))->discoverNativeOwners($this->readSource($origin), $nodes);
    }

    /** @return array<string,string> Absolute PHTML path => complete source bytes, with no writes. */
    public function candidatePage(
        ThemeVersionIdentity $identity,
        string $layoutIdentityHash,
        string $structureKey,
        array $contentNodes,
        array $pageConfigByUid,
        string $pageType = '',
        string $layoutOption = 'default',
        string $targetType = 'global',
        ?int $targetId = null,
        array $localeOverrides = [],
        array $templateParams = [],
    ): array {
        $layoutType = $this->paths->layoutRoutePath(trim($pageType) !== '' ? $pageType : $layoutIdentityHash);
        $path = $this->paths->pageLayoutPhtml($identity, $layoutType, $layoutOption, $targetType, $targetId);
        $origin = (new ThemeLayoutEntityDocumentShellBaker())->resolveLayoutAbsolutePath($layoutType, $identity->area, $layoutOption, $identity->themeId);
        $source = $this->readSource($origin);
        $theme = $this->loadTheme($identity);
        $dependencyResolver = new ThemeLayoutTemplateDependencies();
        $dependencies = $dependencyResolver->moduleSources($source, $theme, $identity->area);
        $nodes = $this->selectNodes($contentNodes, false);
        $nodes = $this->resolvedNodes($nodes, $pageConfigByUid, $identity, $layoutOption, $targetType, $targetId);
        $source = (new LayoutRelationCompiler(null, $theme))->compile($source, $nodes, [], $localeOverrides, $identity);
        $localeMeta = [];
        foreach ($localeOverrides as $locale => $configs) {
            if (is_array($configs['layout'] ?? null)) { $localeMeta[$locale] = $configs['layout']; }
        }
        $source = $this->withTemplateParams($source, $templateParams, $localeMeta);
        $candidates = [$path => $this->metadata($source, [
            'origin' => $origin, 'identity' => $identity->toArray(),
            'layout_type' => $layoutType, 'layout_option' => $layoutOption,
            'target_type' => $targetType, 'target_id' => $targetId,
        ])];
        $visited = [];
        while ($dependencies !== []) {
            $logical = array_key_first($dependencies);
            $dependency = $dependencies[$logical];
            unset($dependencies[$logical]);
            if (isset($visited[$logical])) { continue; }
            $visited[$logical] = true;
            $original = $this->readSource($dependency['origin']);
            $dependencies += $dependencyResolver->moduleSources($original, $theme, $identity->area);
            $body = (new LayoutRelationCompiler(null, $theme))->compile($original, $nodes, [], $localeOverrides, $identity);
            $dependencyPath = $this->paths->pageSourcePhtml($identity, $layoutType, $layoutOption, $logical, $targetType, $targetId);
            $candidates[$dependencyPath] = $this->metadata($body, [
                'origin' => $dependency['origin'], 'identity' => $identity->toArray(),
                'resource_type' => 'page_dependency', 'logical_path' => $logical,
                'layout_type' => $layoutType, 'layout_option' => $layoutOption,
                'target_type' => $targetType, 'target_id' => $targetId,
            ]);
        }
        return $candidates;
    }

    public function materializePage(
        ThemeVersionIdentity $identity,
        string $layoutIdentityHash,
        string $structureKey,
        array $contentNodes,
        array $pageConfigByUid,
        string $pageType = '',
        string $layoutOption = 'default',
        string $targetType = 'global',
        ?int $targetId = null,
        array $localeOverrides = [],
        array $templateParams = [],
    ): string {
        $candidates = $this->candidatePage($identity, $layoutIdentityHash, $structureKey, $contentNodes, $pageConfigByUid,
            $pageType, $layoutOption, $targetType, $targetId, $localeOverrides, $templateParams);
        (new ThemeLayoutEntityBatchPublisher())->publish($identity, $candidates);
        return (string)array_key_first($candidates);
    }

    /** @param array<string,string> $partialOptions @param array<string,array> $partialParams @return array<string,string> */
    public function candidateChrome(ThemeScopeVersion $version, array $localeOverrides = [], array $partialOptions = [], array $partialParams = []): array
    {
        $identity = $version->toVersionIdentity();
        $theme = $this->loadTheme($identity);
        $resources = ObjectManager::getInstance(ThemeResourceCatalog::class)->getResources('partials', $identity->area, $theme);
        $partialOptions = $partialOptions !== [] ? $partialOptions : ['header' => 'default', 'footer' => 'default'];
        $nodes = $this->resolvedNodes($this->selectNodes($version->getChromePayload(), true), [], $identity);
        $candidates = [];
        $dependencies = [];
        foreach ($partialOptions as $type => $option) {
            $type = (string)$type;
            $option = trim((string)$option) !== '' ? (string)$option : 'default';
            $resource = $resources['partials/' . $type . '/' . $option]
                ?? $resources['partials/' . $type . '/default'] ?? null;
            $origin = is_array($resource) ? (string)($resource['file_path'] ?? '') : '';
            if ($origin === '' || !is_file($origin)) {
                throw new \RuntimeException('theme_partial_source_missing: ' . $type . '/' . $option);
            }
            $original = $this->readSource($origin);
            $dependencies += (new ThemeLayoutTemplateDependencies())->partialSources($original, $origin, $resources, $identity->area);
            $source = (new LayoutRelationCompiler(null, $theme))->compile($original, $nodes, [], $localeOverrides, $identity);
            $localeParams = [];
            foreach ($localeOverrides as $locale => $configs) {
                if (is_array($configs['partials.' . $type] ?? null)) { $localeParams[$locale] = $configs['partials.' . $type]; }
            }
            $source = $this->withTemplateParams($source, is_array($partialParams[$type] ?? null) ? $partialParams[$type] : [], $localeParams);
            $candidates[$this->paths->partialPhtml($identity, $type, $option)] = $this->metadata($source, [
                'origin' => $origin, 'identity' => $identity->toArray(), 'resource_type' => 'partial',
                'partial_type' => $type, 'partial_option' => $option,
            ]);
        }
        // Keep the original fetch and its PHP/Hook conditions in place. Only the
        // dependency's source is replaced by another ordinary derived PHTML.
        $visited = [];
        while ($dependencies !== []) {
            $key = array_key_first($dependencies);
            $resource = $dependencies[$key];
            unset($dependencies[$key]);
            if (isset($visited[$key])) { continue; }
            $visited[$key] = true;
            $relative = substr($key, strlen('partials/'));
            $split = strrpos($relative, '/');
            $type = substr($relative, 0, $split);
            $option = substr($relative, $split + 1);
            $path = $this->paths->partialPhtml($identity, $type, $option);
            if (isset($candidates[$path])) { continue; }
            $origin = (string)$resource['file_path'];
            $original = $this->readSource($origin);
            $dependencies += (new ThemeLayoutTemplateDependencies())->partialSources($original, $origin, $resources, $identity->area);
            $source = (new LayoutRelationCompiler(null, $theme))->compile($original, $nodes, [], $localeOverrides, $identity);
            $candidates[$path] = $this->metadata($source, [
                'origin' => $origin, 'identity' => $identity->toArray(), 'resource_type' => 'partial_dependency',
                'partial_type' => $type, 'partial_option' => $option,
            ]);
        }
        return $candidates;
    }

    public function materializeChrome(ThemeScopeVersion $version, array $localeOverrides = [], array $partialOptions = [], array $partialParams = []): string
    {
        $candidates = $this->candidateChrome($version, $localeOverrides, $partialOptions, $partialParams);
        (new ThemeLayoutEntityBatchPublisher())->publish($version->toVersionIdentity(), $candidates);
        return (string)array_key_first($candidates);
    }

    /** Compatibility entry: configuration changes now regenerate the actual PHTML. */
    public function bustChromeRenderedSnapshots(ThemeScopeVersion $version): void
    {
        $this->materializeChrome($version);
    }

    /** Keep descendants with their owning parent even when the child's slot name is not a chrome alias. */
    private function selectNodes(array $nodes, bool $chrome): array
    {
        $chromeNodes = $this->slotTree->filterChromeNodes($nodes);
        $indexed = [];
        foreach ($nodes as $key => $node) {
            if (is_array($node)) { $indexed[(string)($node['node_uid'] ?? $key)] = $node; }
        }
        $selected = [];
        foreach ($indexed as $uid => $node) {
            if (($node['widget_code'] ?? '') === '__no_widget_placements__') { $selected[$uid] = $node; continue; }
            $owner = $uid;
            $seen = [];
            while (!empty($indexed[$owner]['parent_uid']) && isset($indexed[(string)$indexed[$owner]['parent_uid']])) {
                if (isset($seen[$owner])) { throw new \RuntimeException('theme_layout_node_parent_cycle'); }
                $seen[$owner] = true;
                $owner = (string)$indexed[$owner]['parent_uid'];
            }
            if (isset($chromeNodes[$owner]) === $chrome) { $selected[$uid] = $node; }
        }
        return $selected;
    }

    /** Definitions supply code defaults once; all saved owner/locale parameters are supplied by the snapshot caller. */
    public function resolveNodeConfigurations(array $nodes, ThemeVersionIdentity $identity, string $option = 'default', string $targetType = 'global', ?int $targetId = null): array
    {
        $configs = [];
        foreach ($this->resolvedNodes($nodes, [], $identity, $option, $targetType, $targetId) as $node) {
            $configs[(string)$node['node_uid']] = $node['config'];
        }
        return $configs;
    }

    private function resolvedNodes(array $nodes, array $configs, ThemeVersionIdentity $identity, string $option = 'default', string $targetType = 'global', ?int $targetId = null): array
    {
        $registry = ObjectManager::getInstance(ThemePlaceableRegistry::class);
        $theme = $this->loadTheme($identity);
        foreach ($nodes as $uid => &$node) {
            $node['node_uid'] = (string)($node['node_uid'] ?? $uid);
            $stored = $configs[$node['node_uid']] ?? $node;
            $config = is_array($stored['config'] ?? null) ? $stored['config'] : (isset($configs[$node['node_uid']]) ? $stored : []);
            $definition = $registry->find((string)($node['widget_module'] ?? ''), (string)($node['widget_type'] ?? ''), (string)($node['widget_code'] ?? ''), $theme, $identity->area);
            $defaults = [];
            foreach ($definition?->params ?? [] as $key => $param) {
                $name = is_string($key) ? $key : (string)($param['param_name'] ?? $param['key'] ?? $param['name'] ?? '');
                if ($name !== '' && is_array($param) && array_key_exists('default', $param)) { $defaults[$name] = $param['default']; }
            }
            $node['_explicit_config'] = is_array($config) ? $config : [];
            $node['config'] = array_replace($defaults, $definition?->defaultConfig ?? [], $node['_explicit_config']);
            foreach ($definition?->params ?? [] as $key => $param) {
                $name = is_string($key) ? $key : (string)($param['param_name'] ?? $param['key'] ?? $param['name'] ?? '');
                $value = $node['config'][$name] ?? null;
                if (!is_array($param) || !is_string($value) || trim($value) === ''
                    || (strtolower((string)($param['type'] ?? '')) !== 'array' && !is_array($param['default'] ?? null))) { continue; }
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) { $node['config'][$name] = $decoded; }
            }
            $node['layout_option'] = $option;
            $node['scope'] = $identity->canonicalScope;
            $node['target_type'] = $targetType;
            $node['target_id'] = $targetId;
        }
        unset($node);
        return $nodes;
    }

    private function loadTheme(ThemeVersionIdentity $identity): ?WelineTheme
    {
        $theme = clone ObjectManager::getInstance(WelineTheme::class);
        $theme->clearData()->clearQuery()->load($identity->themeId);
        return (int)$theme->getId() === $identity->themeId ? $theme : null;
    }

    private function readSource(string $path): string
    {
        $source = file_get_contents($path);
        if (!is_string($source)) { throw new \RuntimeException('theme_layout_source_unreadable: ' . $path); }
        return $source;
    }

    private function withTemplateParams(string $source, array $params, array $localeParams = []): string
    {
        if ($params === [] && $localeParams === []) { return $source; }
        $expression = var_export($params, true);
        if ($localeParams !== []) {
            $cases = [];
            foreach ($localeParams as $locale => $values) {
                $cases[] = var_export(strtolower(str_replace('-', '_', (string)$locale)), true) . ' => ' . var_export($values, true);
            }
            $expression = 'match (strtolower(str_replace(\'-\', \'_\', \\Weline\\Theme\\Helper\\WidgetI18n::storefrontLocale()))) { '
                . implode(', ', $cases) . ', default => ' . $expression . ' }';
        }
        $php = '$this->setData(\'meta\', array_replace((array)$this->getData(\'meta\'), ' . $expression . '));';
        if (preg_match('/\A<\?php\b/', $source) === 1) {
            $rest = substr($source, 5);
            // Comments may precede declare; keep strict_types PHP's first statement.
            if (preg_match('~\A(?:\s+|/\*.*?\*/|//[^\n]*\n|\#[^\n]*\n)*(declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;)~s', $rest, $match) === 1) {
                $offset = 5 + strlen($match[0]);
                return substr($source, 0, $offset) . "\n" . $php . substr($source, $offset);
            }
            return '<?php ' . $php . $rest;
        }
        return '<?php ' . $php . ' ?>' . $source;
    }

    private function metadata(string $source, array $metadata): string
    {
        $comment = '/* weline-source:' . base64_encode(json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . ' */';
        if (preg_match('/\A<\?php\b/', $source) === 1) {
            return '<?php ' . $comment . substr($source, 5);
        }
        return '<?php ' . $comment . ' ?>' . $source;
    }
}
