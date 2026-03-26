<?php

declare(strict_types=1);

namespace Weline\DataTable\Controller\Backend\Test\Concern;

trait HandlesBackendLayouts
{
    private function applyBackendLayout(bool $allowBlank = true, string $fallback = 'default'): string
    {
        $layoutKey = $this->resolveBackendLayoutKey($allowBlank, $fallback);
        $this->layoutType = $this->backendAdminPageService->resolveBackendLayoutType($layoutKey, $allowBlank, $fallback);

        return $layoutKey;
    }

    private function resolveBackendLayoutKey(bool $allowBlank = true, string $fallback = 'default'): string
    {
        return $this->backendAdminPageService->normalizeBackendLayoutKey(
            (string) $this->request->getParam('layout', ''),
            $allowBlank,
            $fallback
        );
    }

    /**
     * @param array<string,mixed> $query
     * @return array<int,array<string,mixed>>
     */
    private function buildBackendLayoutOptions(
        string $route,
        string $currentLayoutKey,
        bool $allowBlank = true,
        array $query = []
    ): array {
        $options = [];
        foreach ($this->backendAdminPageService->getBackendLayoutCatalog($allowBlank) as $key => $layout) {
            $params = $query;
            $params['layout'] = $key;
            $layout['url'] = $this->routeWithQuery($route, $params);
            $layout['active'] = $key === $currentLayoutKey;
            $options[] = $layout;
        }

        return $options;
    }

    /**
     * @param array<int|mixed,array<string,mixed>> $docs
     * @return array<int,array<string,mixed>>
     */
    private function decorateDocumentLinks(array $docs, string $route, string $layoutKey): array
    {
        $result = [];
        foreach ($docs as $doc) {
            $doc['url'] = $this->routeWithQuery($route, [
                'doc' => (string) ($doc['key'] ?? ''),
                'layout' => $layoutKey,
            ]);
            $result[] = $doc;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $query
     */
    private function routeWithQuery(string $route, array $query = []): string
    {
        $query = array_filter(
            $query,
            static fn (mixed $value): bool => !($value === null || $value === '')
        );

        if ($query === []) {
            return $route;
        }

        $separator = str_contains($route, '?') ? '&' : '?';

        return $route . $separator . http_build_query($query);
    }
}
