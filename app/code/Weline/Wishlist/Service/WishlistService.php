<?php

declare(strict_types=1);

namespace Weline\Wishlist\Service;

final class WishlistService
{
    public function __construct(
        private readonly WishlistSessionStore $store,
        private readonly ProductCardSnapshotResolver $snapshots,
    ) {
    }

    /**
     * @return array{success:bool,wishlist_count:int,items:list<int>,message:string}
     */
    public function add(int $productId): array
    {
        if ($productId <= 0) {
            return $this->failure(__('无效的商品 ID'));
        }
        $ids = $this->store->listIds();
        if (!in_array($productId, $ids, true)) {
            $ids[] = $productId;
            $this->store->saveIds($ids);
        }

        return [
            'success' => true,
            'wishlist_count' => count($ids),
            'items' => $ids,
            'message' => (string)__('已加入收藏'),
        ];
    }

    /**
     * @return array{success:bool,wishlist_count:int,items:list<int>,message:string}
     */
    public function remove(int $productId): array
    {
        if ($productId <= 0) {
            return $this->failure(__('无效的商品 ID'));
        }
        $ids = array_values(array_filter(
            $this->store->listIds(),
            static fn (int $id): bool => $id !== $productId,
        ));
        $this->store->saveIds($ids);

        return [
            'success' => true,
            'wishlist_count' => count($ids),
            'items' => $ids,
            'message' => (string)__('已从收藏移除'),
        ];
    }

    /**
     * @return array{success:bool,wishlist_count:int,items:list<int>}
     */
    public function list(): array
    {
        $ids = $this->store->listIds();

        return [
            'success' => true,
            'wishlist_count' => count($ids),
            'items' => $ids,
        ];
    }

    /**
     * @return array{success:bool,wishlist_count:int,items:list<array<string,mixed>>}
     */
    public function listPage(): array
    {
        $ids = $this->store->listIds();
        $items = [];
        foreach ($ids as $productId) {
            $snapshot = $this->snapshots->resolve($productId);
            if ($snapshot !== null) {
                $items[] = $snapshot;
            }
        }

        return [
            'success' => true,
            'wishlist_count' => count($ids),
            'items' => $items,
        ];
    }

    /**
     * @return array{success:bool,wishlist_count:int}
     */
    public function count(): array
    {
        $ids = $this->store->listIds();

        return [
            'success' => true,
            'wishlist_count' => count($ids),
        ];
    }

    /**
     * @return array{success:bool,wishlist_count:int,items:list<int>,active:bool,message:string}
     */
    public function toggle(int $productId): array
    {
        $ids = $this->store->listIds();
        if (in_array($productId, $ids, true)) {
            $result = $this->remove($productId);
            $result['active'] = false;

            return $result;
        }
        $result = $this->add($productId);
        $result['active'] = true;

        return $result;
    }

    /**
     * @return array{success:bool,wishlist_count:int,items:list<int>,message:string}
     */
    private function failure(string $message): array
    {
        return [
            'success' => false,
            'wishlist_count' => count($this->store->listIds()),
            'items' => $this->store->listIds(),
            'message' => $message,
        ];
    }
}
