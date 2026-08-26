<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Product-side consumer bridge for the universal catalog hub (chapter 5).
 */
final class ProductCatalogQueryConsumer
{
    /**
     * @return list<array<string, mixed>>
     */
    public function tree(int $websiteId, string $locale = ''): array
    {
        try {
            $rows = w_query('catalog', 'tree', [
                'space' => 'product',
                'scope_level' => 'website',
                'website_id' => max(0, $websiteId),
                'locale' => trim($locale),
            ]);
        } catch (\Throwable) {
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
