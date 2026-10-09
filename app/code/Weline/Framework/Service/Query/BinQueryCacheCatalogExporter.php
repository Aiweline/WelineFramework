<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query;

/**
 * Exports a client-safe map of CDN-cacheable BinQuery operations for storefront workers.
 */
final class BinQueryCacheCatalogExporter
{
    public function __construct(
        private readonly QueryProviderRegistry $registry,
    ) {
    }

    /**
     * @return array<string, array{key_params: list<string>, vary: list<string>, ttl: string}>
     */
    public function exportFrontendCatalog(): array
    {
        $cachePolicy = new BinQueryCachePolicy();
        $catalog = [];
        foreach ($this->registry->getAllDescriptors() as $descriptor) {
            if (!\is_array($descriptor)) {
                continue;
            }
            $provider = (string)($descriptor['provider'] ?? $descriptor['name'] ?? '');
            if ($provider === '') {
                continue;
            }
            foreach (($descriptor['operations'] ?? []) as $operation) {
                if (!\is_array($operation) || !$cachePolicy->isCacheableOperation($operation)) {
                    continue;
                }
                if (($operation['frontend'] ?? false) !== true) {
                    continue;
                }
                $operationName = (string)($operation['name'] ?? '');
                if ($operationName === '') {
                    continue;
                }
                $cache = \is_array($operation['cache'] ?? null) ? $operation['cache'] : [];
                $key = $provider . '.' . $operationName;
                $catalog[$key] = [
                    'key_params' => $this->stringList($cache['key_params'] ?? []),
                    'vary' => $this->stringList($cache['vary'] ?? ['area', 'locale', 'currency']),
                    'ttl' => (string)($cache['ttl'] ?? ''),
                ];
            }
        }
        \ksort($catalog);

        return $catalog;
    }

    /**
     * @param mixed $list
     * @return list<string>
     */
    private function stringList(mixed $list): array
    {
        if (!\is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            $value = \trim((string)$item);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return \array_values(\array_unique($out));
    }
}
