<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Search\Service\SearchProviderRegistry;

/**
 * 页头商务数据：优先 Query 真实数据；仅在无数据/不可用时回落主题演示默认值。
 */
final class HeaderCommerceData
{
    /**
     * @return list<string>
     */
    public static function defaultHotWords(): array
    {
        return ['iPhone', '耳机', '运动鞋', '连衣裙', '笔记本'];
    }

    /**
     * @return array{
     *   words:list<string>,
     *   source:string,
     *   is_demo:bool
     * }
     */
    public static function resolveHotWords(int $limit = 8): array
    {
        $limit = max(1, min(20, $limit));
        try {
            if (\function_exists('w_query')) {
                $result = \w_query('search', 'hotWords', ['limit' => $limit], 'frontend');
                if (\is_array($result) && ($result['success'] ?? false)) {
                    $words = $result['words'] ?? ($result['data']['words'] ?? []);
                    $normalized = self::normalizeHotWords($words, $limit);
                    if ($normalized !== []) {
                        return [
                            'words' => $normalized,
                            'source' => (string)($result['source'] ?? $result['data']['source'] ?? 'search'),
                            'is_demo' => false,
                        ];
                    }
                }
            }
        } catch (\Throwable) {
            // fall through to demo defaults
        }

        return [
            'words' => \array_slice(self::defaultHotWords(), 0, $limit),
            'source' => 'theme_demo',
            'is_demo' => true,
        ];
    }

    /**
     * @return array{
     *   available:bool,
     *   is_demo:bool,
     *   is_empty:bool,
     *   cart_count:int,
     *   subtotal:float,
     *   subtotal_formatted:string,
     *   currency:string,
     *   items:list<array<string,mixed>>
     * }
     */
    public static function resolveCartSummary(bool $allowDemoFallback = true, int $itemLimit = 5): array
    {
        $itemLimit = max(1, min(20, $itemLimit));
        $queried = false;
        try {
            if (\function_exists('w_query')) {
                $result = \w_query('cart', 'summary', [], 'frontend');
                if (\is_array($result) && ($result['success'] ?? false)) {
                    $queried = true;
                    $data = \is_array($result['data'] ?? null) ? $result['data'] : $result;
                    $items = \is_array($data['items'] ?? null) ? $data['items'] : [];
                    $items = \array_values(\array_filter($items, static fn ($item): bool => \is_array($item)));
                    $items = \array_slice($items, 0, $itemLimit);
                    $count = (int)($data['cart_count'] ?? $data['item_count'] ?? 0);
                    $subtotal = (float)($data['subtotal'] ?? $data['grand_total'] ?? 0);
                    $currency = (string)($data['currency'] ?? 'CNY');
                    $isEmpty = $count <= 0 || ($data['is_empty'] ?? false) || $items === [];

                    // 主题预览且真实购物车为空：回落演示数据，便于观察完整 chrome
                    if ($isEmpty && $allowDemoFallback) {
                        return self::demoCartSummary();
                    }

                    return [
                        'available' => true,
                        'is_demo' => false,
                        'is_empty' => $isEmpty,
                        'cart_count' => $count,
                        'subtotal' => $subtotal,
                        'subtotal_formatted' => self::formatMoney($subtotal, $currency),
                        'currency' => $currency,
                        'items' => $items,
                    ];
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        if ($allowDemoFallback) {
            return self::demoCartSummary();
        }

        return [
            'available' => $queried,
            'is_demo' => false,
            'is_empty' => true,
            'cart_count' => 0,
            'subtotal' => 0.0,
            'subtotal_formatted' => self::formatMoney(0, 'CNY'),
            'currency' => 'CNY',
            'items' => [],
        ];
    }

    /**
     * @return array{
     *   available:bool,
     *   is_demo:bool,
     *   is_empty:bool,
     *   cart_count:int,
     *   subtotal:float,
     *   subtotal_formatted:string,
     *   currency:string,
     *   items:list<array<string,mixed>>
     * }
     */
    public static function demoCartSummary(): array
    {
        $placeholder = '';
        try {
            // Preview-only sample line; live empty carts must not use this path.
            $products = ThemeDemoCatalog::products(1, 3);
            $product = $products[0] ?? null;
            if (\is_array($product)) {
                $placeholder = (string)($product['image'] ?? '');
                return [
                    'available' => true,
                    'is_demo' => true,
                    'is_empty' => false,
                    'cart_count' => 3,
                    'subtotal' => 299.0,
                    'subtotal_formatted' => ThemeDemoCatalog::formatPrice(299.0),
                    'currency' => 'CNY',
                    'items' => [[
                        'name' => (string)($product['name'] ?? __('示例商品名称')),
                        'image' => $placeholder,
                        'price' => 99.0,
                        'qty' => 1,
                        'quantity' => 1,
                        'row_total' => 99.0,
                        'url' => (string)($product['url'] ?? '/cart'),
                    ]],
                ];
            }
        } catch (\Throwable) {
            // ignore
        }

        return [
            'available' => true,
            'is_demo' => true,
            'is_empty' => false,
            'cart_count' => 3,
            'subtotal' => 299.0,
            'subtotal_formatted' => ThemeDemoCatalog::formatPrice(299.0),
            'currency' => 'CNY',
            'items' => [[
                'name' => (string)__('示例商品名称'),
                'image' => '',
                'price' => 99.0,
                'qty' => 1,
                'quantity' => 1,
                'row_total' => 99.0,
                'url' => '/cart',
            ]],
        ];
    }

    public static function formatMoney(float $amount, string $currency = 'CNY'): string
    {
        $currency = strtoupper(trim($currency));
        $symbol = match ($currency) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            default => '¥',
        };

        return $symbol . number_format($amount, 2);
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private static function normalizeHotWords(mixed $raw, int $limit): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            $word = \is_string($item)
                ? trim($item)
                : trim((string)((\is_array($item) ? ($item['word'] ?? $item['title'] ?? $item['q'] ?? '') : '')));
            if ($word === '') {
                continue;
            }
            $key = \mb_strtolower($word);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $word;
            if (\count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array{code:string,label:string,children:list<array{code:string,label:string,params:array<string,int|string|float|bool>}>}>
     */
    public static function resolveSearchTypes(): array
    {
        try {
            /** @var SearchProviderRegistry $registry */
            $registry = ObjectManager::getInstance(SearchProviderRegistry::class);
            $types = $registry->listTypes();
            if ($types !== []) {
                return $types;
            }
        } catch (\Throwable) {
            // fall through
        }

        return [
            [
                'code' => 'all',
                'label' => (string)__('全部'),
                'children' => [],
            ],
        ];
    }
}
