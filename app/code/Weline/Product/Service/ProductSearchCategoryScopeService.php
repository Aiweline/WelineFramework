<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * Product categories exposed to storefront search type scopes.
 *
 * Reads the website category tree via {@see ProductCatalogQueryConsumer} (catalog Query).
 * When empty, falls back to a Theme-aligned demo tree.
 */
final class ProductSearchCategoryScopeService
{
    private const MAX_DEPTH = 3;

    public function __construct(
        private readonly ProductCatalogQueryConsumer $catalog,
    ) {
    }

    /**
     * @return list<array{code:string,label:string,params:array<string,int|string|float|bool>,children:list<array<string,mixed>>}>
     */
    public function listForSearch(): array
    {
        $fromCatalog = $this->listFromCatalog();
        if ($fromCatalog !== []) {
            return $fromCatalog;
        }

        return $this->demoScopes();
    }

    /**
     * @return list<array{code:string,label:string,params:array<string,int|string|float|bool>,children:list<array<string,mixed>>}>
     */
    private function listFromCatalog(): array
    {
        $tree = $this->catalog->tree($this->resolveWebsiteId());
        if ($tree === []) {
            return [];
        }

        return $this->trimDepth($this->mapSearchNodes($tree), 1);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<array{code:string,label:string,params:array<string,int|string|float|bool>,children:list<array<string,mixed>>}>
     */
    private function mapSearchNodes(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if ((int)($node['is_active'] ?? 1) === 0
                || strtolower(trim((string)($node['status'] ?? 'active'))) === 'inactive') {
                continue;
            }
            $id = max(0, (int)($node['category_id'] ?? $node['id'] ?? 0));
            if ($id <= 0) {
                continue;
            }
            $children = is_array($node['nodes'] ?? null) ? $this->mapSearchNodes($node['nodes']) : [];
            $path = trim(str_replace('\\', '/', (string)($node['path'] ?? '')), '/');
            $out[] = [
                'code' => 'category_' . $id,
                'label' => trim((string)($node['name'] ?? '')) !== ''
                    ? (string)$node['name']
                    : $this->displayNameFromPath($path),
                'params' => ['category_id' => $id],
                'children' => $children,
            ];
        }

        return $out;
    }

    /**
     * Theme-aligned demo category tree (L1 → L2 → L3).
     *
     * @return list<array{code:string,label:string,params:array<string,int|string|float|bool>,children:list<array<string,mixed>>}>
     */
    private function demoScopes(): array
    {
        $nextId = 9000;
        $make = function (string $path, string $label, array $children = []) use (&$make, &$nextId): array {
            $nextId++;
            $node = [
                'code' => 'demo_' . str_replace('/', '_', $path),
                'label' => $label,
                'params' => [
                    'category_id' => $nextId,
                    'category_path' => $path,
                    'is_demo' => 1,
                ],
                'children' => [],
            ];
            foreach ($children as $child) {
                if (!is_array($child) || !isset($child['path'], $child['label'])) {
                    continue;
                }
                $grand = is_array($child['children'] ?? null) ? $child['children'] : [];
                $node['children'][] = $make((string)$child['path'], (string)$child['label'], $grand);
            }

            return $node;
        };

        $tree = [
            $make('electronics', (string)__('电子产品'), [
                [
                    'path' => 'electronics/phones',
                    'label' => (string)__('手机通讯'),
                    'children' => [
                        ['path' => 'electronics/phones/smartphones', 'label' => (string)__('智能手机')],
                        ['path' => 'electronics/phones/feature-phones', 'label' => (string)__('功能手机')],
                        ['path' => 'electronics/phones/5g-phones', 'label' => (string)__('5G手机')],
                    ],
                ],
                [
                    'path' => 'electronics/computers',
                    'label' => (string)__('电脑办公'),
                    'children' => [
                        ['path' => 'electronics/computers/laptops', 'label' => (string)__('笔记本')],
                        ['path' => 'electronics/computers/desktops', 'label' => (string)__('台式机')],
                        ['path' => 'electronics/computers/tablets', 'label' => (string)__('平板电脑')],
                    ],
                ],
                [
                    'path' => 'electronics/smart-devices',
                    'label' => (string)__('智能设备'),
                    'children' => [
                        ['path' => 'electronics/smart-devices/smart-watches', 'label' => (string)__('智能手表')],
                        ['path' => 'electronics/smart-devices/smart-speakers', 'label' => (string)__('智能音箱')],
                    ],
                ],
                [
                    'path' => 'electronics/audio-video',
                    'label' => (string)__('影音娱乐'),
                    'children' => [
                        ['path' => 'electronics/audio-video/headphones', 'label' => (string)__('耳机')],
                        ['path' => 'electronics/audio-video/speakers', 'label' => (string)__('音箱')],
                    ],
                ],
            ]),
            $make('clothing', (string)__('服装服饰'), [
                [
                    'path' => 'clothing/men',
                    'label' => (string)__('男装'),
                    'children' => [
                        ['path' => 'clothing/men/shirts', 'label' => (string)__('衬衫')],
                        ['path' => 'clothing/men/pants', 'label' => (string)__('裤装')],
                    ],
                ],
                [
                    'path' => 'clothing/women',
                    'label' => (string)__('女装'),
                    'children' => [
                        ['path' => 'clothing/women/dresses', 'label' => (string)__('连衣裙')],
                        ['path' => 'clothing/women/tops', 'label' => (string)__('上装')],
                    ],
                ],
                [
                    'path' => 'clothing/kids',
                    'label' => (string)__('童装'),
                    'children' => [],
                ],
            ]),
            $make('home', (string)__('家居用品'), [
                ['path' => 'home/furniture', 'label' => (string)__('家具'), 'children' => []],
                ['path' => 'home/kitchen', 'label' => (string)__('厨房用品'), 'children' => []],
            ]),
            $make('sports', (string)__('运动户外'), [
                ['path' => 'sports/outdoor', 'label' => (string)__('户外装备'), 'children' => []],
                ['path' => 'sports/fitness', 'label' => (string)__('健身器材'), 'children' => []],
            ]),
            $make('books', (string)__('图书音像'), []),
            $make('food', (string)__('食品饮料'), []),
        ];

        return $this->trimDepth($tree, 1);
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @return list<array{code:string,label:string,params:array<string,int|string|float|bool>,children:list<array<string,mixed>>}>
     */
    private function trimDepth(array $nodes, int $depth): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            if ($depth >= self::MAX_DEPTH) {
                $children = [];
            } elseif ($children !== []) {
                $children = $this->trimDepth($children, $depth + 1);
            }
            $out[] = [
                'code' => (string)($node['code'] ?? ''),
                'label' => (string)($node['label'] ?? ''),
                'params' => is_array($node['params'] ?? null) ? $node['params'] : [],
                'children' => $children,
            ];
        }

        return $out;
    }

    private function displayNameFromPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return (string)__('未命名分类');
        }
        $parts = explode('/', $path);
        $leaf = (string)end($parts);
        $leaf = str_replace(['-', '_'], ' ', $leaf);

        return $leaf !== '' ? $leaf : $path;
    }

    private function resolveWebsiteId(): int
    {
        try {
            if (class_exists(\Weline\Websites\Service\WebsiteAclGrantService::class)) {
                /** @var \Weline\Websites\Service\WebsiteAclGrantService $grants */
                $grants = ObjectManager::getInstance(\Weline\Websites\Service\WebsiteAclGrantService::class);
                $id = (int)$grants->currentWebsiteId();
                if ($id >= 0) {
                    return $id;
                }
            }
        } catch (\Throwable) {
        }

        return 0;
    }
}
