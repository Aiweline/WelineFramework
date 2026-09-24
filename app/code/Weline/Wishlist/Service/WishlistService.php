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
        if ($this->snapshots->resolve($productId) === null) {
            return $this->failure(__('无效的商品 ID'));
        }
        $ids = $this->pruneInvalidIds();
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
        $ids = $this->pruneInvalidIds();

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
        $entries = $this->resolveValidEntries();
        $ids = [];
        $items = [];
        foreach ($entries as $entry) {
            $ids[] = $entry['product_id'];
            $items[] = $entry['snapshot'];
        }
        $this->persistIfChanged($ids);

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
        $ids = $this->pruneInvalidIds();

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
     * Drop product IDs that no longer resolve to a published storefront offer.
     *
     * @return list<int>
     */
    private function pruneInvalidIds(): array
    {
        $ids = [];
        foreach ($this->resolveValidEntries() as $entry) {
            $ids[] = $entry['product_id'];
        }
        $this->persistIfChanged($ids);

        return $ids;
    }

    /**
     * @return list<array{product_id:int,snapshot:array<string,mixed>}>
     */
    private function resolveValidEntries(): array
    {
        $entries = [];
        $ids = $this->store->listIds();
        $snapshots = $this->snapshots->resolveMany($ids);
        foreach ($ids as $productId) {
            $snapshot = $snapshots[$productId] ?? null;
            if ($snapshot !== null) {
                $entries[] = [
                    'product_id' => $productId,
                    'snapshot' => $snapshot,
                ];
            }
        }

        return $entries;
    }

    /**
     * @param list<int> $validIds
     */
    private function persistIfChanged(array $validIds): void
    {
        if ($validIds === $this->store->listIds()) {
            return;
        }
        $this->store->saveIds($validIds);
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
