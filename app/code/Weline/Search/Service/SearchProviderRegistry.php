<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Search\Api\SearchProviderInterface;
use Weline\Search\Api\SearchScopeOptionsProviderInterface;

class SearchProviderRegistry
{
    /** @var array<string, SearchProviderInterface>|null */
    private ?array $providers = null;

    public function __construct(
        private readonly ObjectManager $objectManager
    ) {
    }

    /**
     * @return array<string, SearchProviderInterface>
     */
    public function all(bool $forceReload = false, ?string $area = null): array
    {
        if (!$forceReload && $this->providers !== null) {
            return $area === null
                ? $this->providers
                : $this->filterByArea($this->providers, $area);
        }

        $map = [];
        try {
            foreach (ExtendsData::getExtendedBy('Weline_Search', $forceReload) as $extensions) {
                foreach ($extensions as $extension) {
                    if ($this->extensionName($extension) !== 'Searcher') {
                        continue;
                    }
                    $class = $this->extensionClass($extension);
                    if ($class === '' || !class_exists($class)) {
                        continue;
                    }
                    $instance = $this->objectManager->getInstance($class);
                    if (!$instance instanceof SearchProviderInterface) {
                        continue;
                    }
                    $code = trim($instance->code());
                    if ($code === '' || $code === 'all') {
                        continue;
                    }
                    $map[$code] = $instance;
                }
            }
        } catch (\Throwable) {
            $map = [];
        }

        uasort(
            $map,
            static fn (SearchProviderInterface $a, SearchProviderInterface $b): int => $a->sortOrder() <=> $b->sortOrder()
        );
        // Cache the complete registry; area visibility is evaluated for each
        // consumer so a frontend lookup cannot replace the backend map.
        $this->providers = $map;

        return $area === null ? $map : $this->filterByArea($map, $area);
    }

    public function get(string $code, ?string $area = null): ?SearchProviderInterface
    {
        $code = trim($code);
        if ($code === '' || $code === 'all') {
            return null;
        }

        $provider = $this->all()[$code] ?? null;
        if ($provider === null) {
            return null;
        }
        if ($area === null || $area === '') {
            return $provider;
        }

        return $this->providerServesArea($provider, $area) ? $provider : null;
    }

    /**
     * @return list<array{code:string,label:string,children:list<array{code:string,label:string,params:array<string,int|string|float|bool>,children:list<array<string,mixed>>}>}>
     */
    public function listTypes(bool $withScopes = true, ?string $area = null): array
    {
        $logicalKey = 'search.provider_types.v2.'
            . ($withScopes ? 'scoped' : 'flat')
            . '.'
            . ($area !== null && trim($area) !== '' ? strtolower(trim($area)) : 'all');
        $builder = fn(): array => $this->buildTypes($withScopes, $area);

        // Search type metadata is storefront presentation data. Keep the
        // request memo in HeaderCommerceData, and reuse the same channel /
        // language variant across workers through the unified hot-cache.
        if (!Context::hasCurrent()) {
            return $builder();
        }
        try {
            /** @var StorefrontScopeHotCache $cache */
            $cache = ObjectManager::getInstance(StorefrontScopeHotCache::class);
            return $cache->rememberPolicy(self::typesPolicy(), $logicalKey, $builder);
        } catch (\Throwable) {
            return $builder();
        }
    }

    private static function typesPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'search.provider_types',
            pool: 'view',
            scope: 'channel',
            vary: ['lang', 'area'],
            dependencies: ['catalog', 'config', 'global/i18n'],
            freshTtlSeconds: 300,
            staleTtlSeconds: 1800,
        );
    }

    /** @return list<array<string,mixed>> */
    private function buildTypes(bool $withScopes, ?string $area): array
    {
        $out = [
            [
                'code' => 'all',
                'label' => (string)__('全部'),
                'children' => [],
            ],
        ];
        foreach ($this->all(area: $area) as $provider) {
            $item = [
                'code' => $provider->code(),
                'label' => $provider->label(),
                'children' => [],
            ];
            if ($withScopes && $provider instanceof SearchScopeOptionsProviderInterface) {
                $item['children'] = $this->normalizeScopeChildren($provider->listScopeOptions());
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param array<string, SearchProviderInterface> $map
     * @return array<string, SearchProviderInterface>
     */
    private function filterByArea(array $map, string $area): array
    {
        $area = strtolower(trim($area));
        if ($area === '') {
            return $map;
        }
        $filtered = [];
        foreach ($map as $code => $provider) {
            if ($this->providerServesArea($provider, $area)) {
                $filtered[$code] = $provider;
            }
        }

        return $filtered;
    }

    private function providerServesArea(SearchProviderInterface $provider, string $area): bool
    {
        $area = strtolower(trim($area));
        foreach ($provider->areas() as $item) {
            if (strtolower(trim((string)$item)) === $area) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> provider code => hit template path
     */
    public function hitTemplateMap(): array
    {
        $map = [];
        foreach ($this->all() as $provider) {
            $code = trim($provider->code());
            $template = trim($provider->hitTemplate());
            if ($code === '' || $code === 'all' || $template === '') {
                continue;
            }
            $map[$code] = $template;
        }

        return $map;
    }

    /**
     * @param list<array<string,mixed>>|null $types
     * @return array<string, string>
     */
    public function typeLabelMap(?array $types = null): array
    {
        $map = [];
        foreach ($types ?? $this->listTypes() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $map[$code] = (string)($row['label'] ?? $code);
        }

        return $map;
    }

    /**
     * @param list<array<string,mixed>>|null $types
     */
    public function resolveTypeLabel(string $code, ?array $types = null): string
    {
        $code = trim($code);
        if ($code === '') {
            return $code;
        }

        $label = $this->typeLabelMap($types)[$code] ?? $code;
        if ($label !== $code) {
            return $label;
        }

        return match ($code) {
            'all' => (string)__('全部'),
            'product' => (string)__('商品'),
            default => $code,
        };
    }

    /**
     * Breadcrumb for selected type + optional category scope.
     *
     * @param list<array<string,mixed>>|null $types
     * @return list<array{label:string,type:string,category_id:int}>
     */
    public function resolveScopeBreadcrumb(string $type, int $categoryId = 0, ?array $types = null): array
    {
        $type = trim($type);
        if ($type === '') {
            $type = 'all';
        }
        $types = $types ?? $this->listTypes();
        $crumbs = [];

        if ($type === 'all') {
            return [
                [
                    'label' => $this->resolveTypeLabel('all', $types),
                    'type' => 'all',
                    'category_id' => 0,
                ],
            ];
        }

        $typeRow = null;
        foreach ($types as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string)($row['code'] ?? '')) === $type) {
                $typeRow = $row;
                break;
            }
        }

        $crumbs[] = [
            'label' => $this->resolveTypeLabel($type, $types),
            'type' => $type,
            'category_id' => 0,
        ];

        if ($categoryId <= 0 || $typeRow === null) {
            return $crumbs;
        }

        $path = $this->findScopePath((array)($typeRow['children'] ?? []), $categoryId);
        foreach ($path as $node) {
            $params = is_array($node['params'] ?? null) ? $node['params'] : [];
            $crumbs[] = [
                'label' => (string)($node['label'] ?? ''),
                'type' => $type,
                'category_id' => (int)($params['category_id'] ?? 0),
            ];
        }

        return $crumbs;
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    private function findScopePath(array $nodes, int $categoryId): array
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $params = is_array($node['params'] ?? null) ? $node['params'] : [];
            $id = (int)($params['category_id'] ?? 0);
            if ($id === $categoryId) {
                return [$node];
            }
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            if ($children === []) {
                continue;
            }
            $nested = $this->findScopePath($children, $categoryId);
            if ($nested !== []) {
                return array_merge([$node], $nested);
            }
        }

        return [];
    }

    /**
     * @param list<array<string,mixed>> $raw
     * @return list<array{code:string,label:string,params:array<string,int|string|float|bool>,children:list<array{code:string,label:string,params:array<string,int|string|float|bool>,children:list<array<string,mixed>>}>}>
     */
    private function normalizeScopeChildren(array $raw, int $depth = 1, int $maxDepth = 3): array
    {
        if ($depth > $maxDepth) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row['code'] ?? ''));
            $label = trim((string)($row['label'] ?? ''));
            if ($code === '' || $label === '') {
                continue;
            }
            $params = $row['params'] ?? [];
            if (!is_array($params)) {
                $params = [];
            }
            $normalizedParams = [];
            foreach ($params as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                if (!is_scalar($value)) {
                    continue;
                }
                $normalizedParams[$key] = $value;
            }
            $childrenRaw = $row['children'] ?? [];
            $children = [];
            if ($depth < $maxDepth && is_array($childrenRaw) && $childrenRaw !== []) {
                $children = $this->normalizeScopeChildren($childrenRaw, $depth + 1, $maxDepth);
            }
            $out[] = [
                'code' => $code,
                'label' => $label,
                'params' => $normalizedParams,
                'children' => $children,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $extension
     */
    private function extensionName(array $extension): string
    {
        $extendName = trim((string)($extension['extend_name'] ?? ''));
        if ($extendName !== '') {
            return $extendName;
        }
        $filePath = str_replace('\\', '/', (string)($extension['file_path'] ?? ''));
        $segments = explode('/', $filePath);

        return trim((string)($segments[0] ?? ''));
    }

    /**
     * @param array<string, mixed> $extension
     */
    private function extensionClass(array $extension): string
    {
        foreach (['class', 'class_name'] as $key) {
            $class = trim((string)($extension[$key] ?? ''));
            if ($class !== '') {
                return $class;
            }
        }

        return $this->classFromFile((string)($extension['source_file'] ?? ''));
    }

    private function classFromFile(string $sourceFile): string
    {
        if ($sourceFile === '' || !is_file($sourceFile) || !is_readable($sourceFile)) {
            return '';
        }

        $content = file_get_contents($sourceFile, false, null, 0, 4096);
        if ($content === false) {
            return '';
        }

        $namespace = '';
        $className = '';
        if (preg_match('/^\s*namespace\s+([^;]+)\s*;/m', $content, $matches) === 1) {
            $namespace = trim($matches[1]);
        }
        if (preg_match('/^\s*(?:abstract\s+)?(?:final\s+)?class\s+(\w+)/m', $content, $matches) === 1) {
            $className = trim($matches[1]);
        }

        return $namespace !== '' && $className !== '' ? $namespace . '\\' . $className : '';
    }
}
