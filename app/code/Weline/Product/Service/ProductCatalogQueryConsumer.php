<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Product-side consumer bridge for the universal catalog hub (chapter 5).
 */
final class ProductCatalogQueryConsumer
{
    private ?string $lastError = null;

    /**
     * Last catalog.tree failure message for same-request diagnosis; null when last tree() succeeded.
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tree(int $websiteId, string $locale = ''): array
    {
        $this->lastError = null;
        $websiteId = max(0, $websiteId);
        $locale = trim($locale);
        try {
            $rows = w_query('catalog', 'tree', [
                'space' => 'product',
                'scope_level' => 'website',
                'website_id' => $websiteId,
                'locale' => $locale,
            ]);
        } catch (\Throwable $e) {
            $this->lastError = $e::class . ': ' . $e->getMessage();
            if (\function_exists('w_log_error')) {
                w_log_error(
                    'ProductCatalogQueryConsumer catalog.tree failed: ' . $e->getMessage(),
                    [
                        'website_id' => $websiteId,
                        'locale' => $locale,
                        'exception' => $e::class,
                    ],
                    'product'
                );
            }

            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function flatRows(int $websiteId, string $locale = ''): array
    {
        $flat = [];
        $this->flattenTree($this->tree($websiteId, $locale), $flat);

        return $flat;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $out
     */
    private function flattenTree(array $nodes, array &$out): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $children = is_array($node['nodes'] ?? null) ? $node['nodes'] : [];
            $row = $node;
            unset($row['nodes']);
            $out[] = $row;
            if ($children !== []) {
                $this->flattenTree($children, $out);
            }
        }
    }
}
