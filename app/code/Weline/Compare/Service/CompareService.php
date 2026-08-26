<?php

declare(strict_types=1);

namespace Weline\Compare\Service;

final class CompareService
{
    public function __construct(
        private readonly CompareSessionStore $store,
        private readonly ProductCardSnapshotResolver $snapshots,
    ) {
    }

    /**
     * @return array{success:bool,compare_count:int,max:int,items:list<array<string,mixed>>,message?:string}
     */
    public function list(): array
    {
        $items = [];
        foreach ($this->store->listIds() as $productId) {
            $snapshot = $this->snapshots->resolve($productId);
            if ($snapshot !== null) {
                $items[] = $snapshot;
            }
        }

        return [
            'success' => true,
            'compare_count' => count($items),
            'max' => CompareSessionStore::MAX_ITEMS,
            'items' => $items,
        ];
    }

    /**
     * @return array{success:bool,compare_count:int,max:int,message:string}
     */
    public function add(int $productId): array
    {
        if ($productId <= 0) {
            return $this->failure((string)__('无效的商品 ID'));
        }
        $ids = $this->store->listIds();
        if (in_array($productId, $ids, true)) {
            return [
                'success' => true,
                'compare_count' => count($ids),
                'max' => CompareSessionStore::MAX_ITEMS,
                'message' => (string)__('该商品已在对比栏'),
            ];
        }
        if (count($ids) >= CompareSessionStore::MAX_ITEMS) {
            return [
                'success' => false,
                'compare_count' => count($ids),
                'max' => CompareSessionStore::MAX_ITEMS,
                'message' => (string)__('对比栏已满（最多 %{1} 件）', [CompareSessionStore::MAX_ITEMS]),
            ];
        }
        $ids[] = $productId;
        $this->store->saveIds($ids);

        return [
            'success' => true,
            'compare_count' => count($ids),
            'max' => CompareSessionStore::MAX_ITEMS,
            'message' => (string)__('已加入对比'),
        ];
    }

    /**
     * @return array{success:bool,compare_count:int,max:int,message:string}
     */
    public function remove(int $productId): array
    {
        $ids = array_values(array_filter(
            $this->store->listIds(),
            static fn (int $id): bool => $id !== $productId,
        ));
        $this->store->saveIds($ids);

        return [
            'success' => true,
            'compare_count' => count($ids),
            'max' => CompareSessionStore::MAX_ITEMS,
            'message' => (string)__('已从对比栏移除'),
        ];
    }

    /**
     * @return array{success:bool,compare_count:int,max:int,message:string}
     */
    public function clear(): array
    {
        $this->store->saveIds([]);

        return [
            'success' => true,
            'compare_count' => 0,
            'max' => CompareSessionStore::MAX_ITEMS,
            'message' => (string)__('对比栏已清空'),
        ];
    }

    /**
     * @return array{success:bool,product?:array<string,mixed>,message?:string}
     */
    public function quickView(int $productId, ?array $fallback = null): array
    {
        $snapshot = $this->snapshots->resolve($productId);
        if ($snapshot === null && is_array($fallback)) {
            $snapshot = $this->normalizeFallback($productId, $fallback);
        }
        if ($snapshot === null) {
            return [
                'success' => false,
                'message' => (string)__('无法加载商品预览'),
            ];
        }

        return [
            'success' => true,
            'product' => $snapshot,
        ];
    }

    /**
     * @param array<string, mixed> $fallback
     * @return array<string, mixed>
     */
    private function normalizeFallback(int $productId, array $fallback): array
    {
        $price = (float)($fallback['price'] ?? 0);

        return [
            'product_id' => $productId,
            'name' => (string)($fallback['name'] ?? ''),
            'sku' => (string)($fallback['sku'] ?? ''),
            'image' => (string)($fallback['image'] ?? ''),
            'price' => $price,
            'currency' => (string)($fallback['currency'] ?? 'CNY'),
            'formatted_price' => (string)($fallback['formatted_price'] ?? ('¥' . number_format($price, 2))),
            'short_description' => (string)($fallback['short_description'] ?? ''),
            'url' => (string)($fallback['url'] ?? ('/product/' . $productId)),
            'rating' => (float)($fallback['rating'] ?? 0),
            'review_count' => (int)($fallback['review_count'] ?? 0),
            'attribute_set_label' => '',
            'specifications' => [],
            'attributes' => [],
        ];
    }

    /**
     * @return array{success:bool,compare_count:int,max:int,message:string}
     */
    private function failure(string $message): array
    {
        return [
            'success' => false,
            'compare_count' => count($this->store->listIds()),
            'max' => CompareSessionStore::MAX_ITEMS,
            'message' => $message,
        ];
    }
}
