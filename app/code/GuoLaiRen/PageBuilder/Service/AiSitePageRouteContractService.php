<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;

final class AiSitePageRouteContractService
{
    public const CONTRACT_VERSION = 'page_route_contract_v1';

    /** @var list<string> */
    private const HEADER_ROUTE_TYPES = [
        Page::TYPE_HOME,
        Page::TYPE_ABOUT,
        Page::TYPE_BLOG_LIST,
        Page::TYPE_CONTACT,
        Page::TYPE_CUSTOM,
    ];

    /** @var list<string> */
    private const FOOTER_FEATURED_ROUTE_TYPES = [
        Page::TYPE_HOME,
        Page::TYPE_ABOUT,
        Page::TYPE_CONTACT,
        Page::TYPE_BLOG_LIST,
        Page::TYPE_CUSTOM,
    ];

    /** @var list<string> */
    private const FOOTER_POLICY_ROUTE_TYPES = [
        Page::TYPE_PRIVACY_POLICY,
        Page::TYPE_TERMS_OF_SERVICE,
        Page::TYPE_REFUND_POLICY,
        Page::TYPE_SHIPPING_POLICY,
        Page::TYPE_COOKIE_POLICY,
    ];

    /**
     * @param array<int|string, mixed> $pageTypes
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function build(array $pageTypes, array $scope = [], string $locale = ''): array
    {
        $pageTypes = $this->normalizePageTypes($pageTypes);
        if ($pageTypes === [] && \is_array($scope['page_types'] ?? null)) {
            $pageTypes = $this->normalizePageTypes($scope['page_types']);
        }

        $routesByType = [];
        foreach ($pageTypes as $pageType) {
            $handle = $this->resolveHandle($pageType, $scope);
            $path = $pageType === Page::TYPE_HOME ? '/' : '/' . $handle;
            $routesByType[$pageType] = [
                'page_type' => $pageType,
                'handle' => $pageType === Page::TYPE_HOME ? '' : $handle,
                'path' => $this->normalizePath($path),
                'label' => $this->resolveLabel($pageType, $scope),
            ];
        }

        $contract = [
            'contract_version' => self::CONTRACT_VERSION,
            'routes_by_type' => $routesByType,
            'allowed_internal_paths' => $this->collectAllowedPaths($routesByType),
            'path_aliases' => $this->buildPathAliases($routesByType),
            'header_route_types' => $this->filterRouteTypes(self::HEADER_ROUTE_TYPES, $routesByType),
            'footer_featured_route_types' => $this->filterRouteTypes(self::FOOTER_FEATURED_ROUTE_TYPES, $routesByType),
            'footer_policy_route_types' => $this->filterRouteTypes(self::FOOTER_POLICY_ROUTE_TYPES, $routesByType),
        ];
        $contract['link_groups'] = $this->buildLinkGroups($contract, $routesByType);
        $contract['contract_hash'] = $this->stableHash($contract);

        return $contract;
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<int|string, mixed> $pageTypes
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function normalize(array $contract, array $pageTypes, array $scope = [], string $locale = ''): array
    {
        $base = $this->build($pageTypes, $scope, $locale);
        if ($contract === []) {
            return $base;
        }

        $targetTypes = $this->normalizePageTypes($pageTypes);
        if ($targetTypes === []) {
            $targetTypes = $this->normalizePageTypes(\array_keys(\is_array($contract['routes_by_type'] ?? null) ? $contract['routes_by_type'] : []));
        }

        $routesByType = [];
        $sourceRoutes = \is_array($contract['routes_by_type'] ?? null) ? $contract['routes_by_type'] : [];
        foreach ($targetTypes as $pageType) {
            $route = \is_array($sourceRoutes[$pageType] ?? null) ? $sourceRoutes[$pageType] : ($base['routes_by_type'][$pageType] ?? []);
            $handle = $pageType === Page::TYPE_HOME ? '' : $this->normalizeHandle((string)($route['handle'] ?? ''));
            if ($pageType !== Page::TYPE_HOME && $handle === '') {
                $handle = Page::getDefaultHandleForType($pageType);
            }
            $path = $pageType === Page::TYPE_HOME ? '/' : '/' . $handle;
            $routesByType[$pageType] = [
                'page_type' => $pageType,
                'handle' => $pageType === Page::TYPE_HOME ? '' : $handle,
                'path' => $this->normalizePath($path),
                'label' => \trim((string)($route['label'] ?? $this->resolveLabel($pageType, $scope))),
            ];
            if ($routesByType[$pageType]['path'] === '' || $routesByType[$pageType]['path'] === '#') {
                $routesByType[$pageType]['path'] = $pageType === Page::TYPE_HOME ? '/' : '/' . $handle;
            }
        }

        $normalized = [
            'contract_version' => (string)($contract['contract_version'] ?? self::CONTRACT_VERSION),
            'routes_by_type' => $routesByType,
            'allowed_internal_paths' => $this->collectAllowedPaths($routesByType),
            'path_aliases' => $this->buildPathAliases($routesByType),
            'header_route_types' => $this->filterRouteTypes(
                $this->normalizePageTypes(\is_array($contract['header_route_types'] ?? null) ? $contract['header_route_types'] : self::HEADER_ROUTE_TYPES),
                $routesByType
            ),
            'footer_featured_route_types' => $this->filterRouteTypes(
                $this->normalizePageTypes(\is_array($contract['footer_featured_route_types'] ?? null) ? $contract['footer_featured_route_types'] : self::FOOTER_FEATURED_ROUTE_TYPES),
                $routesByType
            ),
            'footer_policy_route_types' => $this->filterRouteTypes(
                $this->normalizePageTypes(\is_array($contract['footer_policy_route_types'] ?? null) ? $contract['footer_policy_route_types'] : self::FOOTER_POLICY_ROUTE_TYPES),
                $routesByType
            ),
        ];
        $normalized['link_groups'] = $this->buildLinkGroups($normalized, $routesByType);
        $normalized['contract_hash'] = $this->stableHash($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, array<string, string>>
     */
    public function routesByType(array $contract): array
    {
        $routes = [];
        foreach (\is_array($contract['routes_by_type'] ?? null) ? $contract['routes_by_type'] : [] as $pageType => $route) {
            if (!\is_string($pageType) || !\is_array($route)) {
                continue;
            }
            $path = $this->normalizePath((string)($route['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $routes[$pageType] = [
                'page_type' => $pageType,
                'handle' => \trim((string)($route['handle'] ?? '')),
                'path' => $path,
                'label' => \trim((string)($route['label'] ?? $pageType)),
            ];
        }

        return $routes;
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, string>
     */
    public function allowedPathMap(array $contract): array
    {
        $paths = [];
        foreach (\is_array($contract['allowed_internal_paths'] ?? null) ? $contract['allowed_internal_paths'] : [] as $path) {
            $path = $this->normalizePath((string)$path);
            if ($path !== '') {
                $paths[$path] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $contract
     */
    public function normalizeHrefToContractPath(array $contract, string $href): string
    {
        if (!$this->isContractHrefCandidate($href)) {
            return '';
        }
        $path = $this->normalizePath($href);
        if ($path === '') {
            return '';
        }
        $allowed = $this->allowedPathMap($contract);
        if (isset($allowed[$path])) {
            return $allowed[$path];
        }

        $aliases = [];
        foreach (\is_array($contract['path_aliases'] ?? null) ? $contract['path_aliases'] : [] as $alias => $target) {
            $aliasPath = $this->normalizePath((string)$alias);
            $targetPath = $this->normalizePath((string)$target);
            if ($aliasPath !== '' && $targetPath !== '') {
                $aliases[$aliasPath] = $targetPath;
            }
        }

        return $aliases[$path] ?? '';
    }

    public function normalizeHrefPath(string $href): string
    {
        return $this->normalizePath($href);
    }

    private function isContractHrefCandidate(string $href): bool
    {
        $href = \trim($href);
        if ($href === '' || $href === '#') {
            return false;
        }
        if (\preg_match('#^[a-z][a-z0-9+.-]*://#i', $href) === 1 || \str_starts_with($href, '//')) {
            return false;
        }
        if (\str_contains($href, '#') || \str_contains($href, '?')) {
            return false;
        }

        return \str_starts_with($href, '/');
    }

    /**
     * @param array<string, mixed> $contract
     */
    public function stableHash(array $contract): string
    {
        $payload = [
            'contract_version' => (string)($contract['contract_version'] ?? self::CONTRACT_VERSION),
            'routes_by_type' => \is_array($contract['routes_by_type'] ?? null) ? $contract['routes_by_type'] : [],
            'header_route_types' => \is_array($contract['header_route_types'] ?? null) ? $contract['header_route_types'] : [],
            'footer_featured_route_types' => \is_array($contract['footer_featured_route_types'] ?? null) ? $contract['footer_featured_route_types'] : [],
            'footer_policy_route_types' => \is_array($contract['footer_policy_route_types'] ?? null) ? $contract['footer_policy_route_types'] : [],
            'link_groups' => \is_array($contract['link_groups'] ?? null) ? $contract['link_groups'] : [],
        ];

        return \sha1((string)\json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param array<int|string, mixed> $pageTypes
     * @return list<string>
     */
    private function normalizePageTypes(array $pageTypes): array
    {
        $normalized = [];
        foreach ($pageTypes as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType !== '' && !\in_array($pageType, $normalized, true)) {
                $normalized[] = $pageType;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolveHandle(string $pageType, array $scope): string
    {
        if ($pageType === Page::TYPE_HOME) {
            return '';
        }

        foreach ([
            $scope['pagebuilder_pages_by_type'][$pageType]['handle'] ?? null,
            $scope['virtual_pages_by_type'][$pageType]['handle'] ?? null,
        ] as $candidate) {
            if (!\is_scalar($candidate)) {
                continue;
            }
            $handle = $this->normalizeHandle((string)$candidate);
            if ($handle !== '') {
                return $handle;
            }
        }

        return Page::getDefaultHandleForType($pageType);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolveLabel(string $pageType, array $scope): string
    {
        foreach ([
            $scope['pagebuilder_pages_by_type'][$pageType]['title'] ?? null,
            $scope['virtual_pages_by_type'][$pageType]['title'] ?? null,
            $scope['pagebuilder_pages_by_type'][$pageType]['name'] ?? null,
            $scope['virtual_pages_by_type'][$pageType]['name'] ?? null,
        ] as $candidate) {
            if (\is_scalar($candidate) && \trim((string)$candidate) !== '') {
                return \trim((string)$candidate);
            }
        }

        return \trim((string)(Page::getPageTypes()[$pageType] ?? $pageType));
    }

    private function normalizeHandle(string $handle): string
    {
        $path = $this->normalizePath($handle);
        if ($path === '/' || $path === '#') {
            return '';
        }

        return \trim($path, '/');
    }

    private function normalizePath(string $path): string
    {
        $path = \trim($path);
        if ($path === '') {
            return '';
        }
        if ($path === '#') {
            return '#';
        }

        $parsed = \parse_url($path);
        if (\is_array($parsed) && isset($parsed['path'])) {
            $path = (string)$parsed['path'];
        }
        $path = \trim($path);
        if ($path === '' || $path === '/') {
            return '/';
        }
        $path = '/' . \trim($path, '/');
        $path = \preg_replace('#/+#', '/', $path) ?? $path;

        return \strtolower($path);
    }

    /**
     * @param array<string, array<string, string>> $routesByType
     * @return list<string>
     */
    private function collectAllowedPaths(array $routesByType): array
    {
        $paths = [];
        foreach ($routesByType as $route) {
            $path = $this->normalizePath((string)($route['path'] ?? ''));
            if ($path !== '') {
                $paths[$path] = $path;
            }
        }

        return \array_values($paths);
    }

    /**
     * @param array<string, array<string, string>> $routesByType
     * @return array<string, string>
     */
    private function buildPathAliases(array $routesByType): array
    {
        $aliases = [];
        foreach ($routesByType as $pageType => $route) {
            $path = $this->normalizePath((string)($route['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $defaultHandle = $pageType === Page::TYPE_HOME ? '' : Page::getDefaultHandleForType((string)$pageType);
            $defaultPath = $pageType === Page::TYPE_HOME ? '/' : '/' . $defaultHandle;
            $defaultPath = $this->normalizePath($defaultPath);
            if ($defaultPath !== '' && $defaultPath !== $path) {
                $aliases[$defaultPath] = $path;
            }
            if ($pageType === Page::TYPE_COOKIE_POLICY) {
                $aliases['/cookie'] = $path;
            }
        }

        return $aliases;
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, array<string, string>> $routesByType
     * @return array<string, array<string, mixed>>
     */
    private function buildLinkGroups(array $contract, array $routesByType): array
    {
        return [
            'navigation_plan.header_items' => $this->buildLinkGroup(
                'navigation_plan.header_items',
                \is_array($contract['header_route_types'] ?? null) ? $contract['header_route_types'] : [],
                $routesByType
            ),
            'footer_plan.featured' => $this->buildLinkGroup(
                'footer_plan.featured',
                \is_array($contract['footer_featured_route_types'] ?? null) ? $contract['footer_featured_route_types'] : [],
                $routesByType
            ),
            'footer_plan.policies' => $this->buildLinkGroup(
                'footer_plan.policies',
                \is_array($contract['footer_policy_route_types'] ?? null) ? $contract['footer_policy_route_types'] : [],
                $routesByType
            ),
        ];
    }

    /**
     * @param list<string>|array<int|string, mixed> $routeTypes
     * @param array<string, array<string, string>> $routesByType
     * @return array<string, mixed>
     */
    private function buildLinkGroup(string $fieldPath, array $routeTypes, array $routesByType): array
    {
        $normalizedRouteTypes = $this->filterRouteTypes($this->normalizePageTypes($routeTypes), $routesByType);
        $allowedPaths = [];
        foreach ($normalizedRouteTypes as $pageType) {
            $path = $this->normalizePath((string)($routesByType[$pageType]['path'] ?? ''));
            if ($path !== '') {
                $allowedPaths[] = $path;
            }
        }

        return [
            'field_path' => $fieldPath,
            'route_types' => $normalizedRouteTypes,
            'allowed_paths' => \array_values(\array_unique($allowedPaths)),
        ];
    }

    /**
     * @param list<string> $types
     * @param array<string, mixed> $routesByType
     * @return list<string>
     */
    private function filterRouteTypes(array $types, array $routesByType): array
    {
        $filtered = [];
        foreach ($types as $type) {
            $type = \trim((string)$type);
            if ($type !== '' && isset($routesByType[$type]) && !\in_array($type, $filtered, true)) {
                $filtered[] = $type;
            }
        }

        return $filtered;
    }
}
