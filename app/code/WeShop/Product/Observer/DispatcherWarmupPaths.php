<?php

declare(strict_types=1);

namespace WeShop\Product\Observer;

use WeShop\Product\Model\Product;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

class DispatcherWarmupPaths implements ObserverInterface
{
    private const LOCALE_CURRENCY = [
        'en_US' => 'USD',
        'zh_Hans_CN' => 'CNY',
    ];
    private const PREFERRED_PRODUCT_HANDLES = [
        'demo-category-81-sports',
        'demo-category-45-clothing',
    ];
    private const MAX_PRODUCT_HANDLES = 4;
    private const MAX_PRODUCT_IDS = 16;

    public function execute(Event &$event): void
    {
        $paths = $this->normalizePaths($event->getData('paths'));
        foreach ($this->resolveProductHandles() as $handle) {
            $this->appendWarmupPathVariants($paths, '/product/' . rawurlencode($handle));
        }
        foreach ($this->resolvePreferredProductIds() as $productId) {
            $this->appendProductIdWarmupPathVariants($paths, $productId);
        }
        foreach ($this->resolveProductIds() as $productId) {
            $this->appendProductIdWarmupPathVariants($paths, $productId);
        }

        $event->setData('paths', $this->normalizePaths($paths));
    }

    /**
     * @return list<string>
     */
    private function resolveProductHandles(): array
    {
        $handles = [];
        foreach (self::PREFERRED_PRODUCT_HANDLES as $handle) {
            if ($handle !== '') {
                $handles[$handle] = $handle;
            }
        }

        try {
            /** @var Product $product */
            $product = ObjectManager::getInstance(Product::class);
            $rows = $product->clear()
                ->fields('main_table.' . Product::schema_fields_HANDLE)
                ->where(Product::schema_fields_status, 1)
                ->where(Product::schema_fields_parent_id, 0)
                ->order(Product::schema_fields_ID, 'desc')
                ->limit(self::MAX_PRODUCT_HANDLES)
                ->select()
                ->fetchArray();
            foreach ((array)$rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $handle = \trim((string)($row[Product::schema_fields_HANDLE] ?? ''), '/');
                if ($handle !== '') {
                    $handles[$handle] = $handle;
                }
                if (\count($handles) >= self::MAX_PRODUCT_HANDLES) {
                    break;
                }
            }
        } catch (\Throwable) {
        }

        return \array_slice(\array_values($handles), 0, self::MAX_PRODUCT_HANDLES);
    }

    /**
     * @return list<int>
     */
    private function resolvePreferredProductIds(): array
    {
        $productIds = [];

        foreach (self::PREFERRED_PRODUCT_HANDLES as $handle) {
            $handle = \trim($handle);
            if ($handle === '') {
                continue;
            }

            try {
                /** @var Product $product */
                $product = ObjectManager::getInstance(Product::class);
                $rows = $product->clear()
                    ->fields('main_table.' . Product::schema_fields_ID)
                    ->where(Product::schema_fields_HANDLE, $handle)
                    ->where(Product::schema_fields_status, 1)
                    ->where(Product::schema_fields_parent_id, 0)
                    ->limit(1)
                    ->select()
                    ->fetchArray();
                foreach ((array)$rows as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    $productId = (int)($row[Product::schema_fields_ID] ?? 0);
                    if ($productId > 0) {
                        $productIds[$productId] = $productId;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return \array_values($productIds);
    }

    /**
     * @return list<int>
     */
    private function resolveProductIds(): array
    {
        $productIds = [];

        try {
            /** @var Product $product */
            $product = ObjectManager::getInstance(Product::class);
            $rows = $product->clear()
                ->fields('main_table.' . Product::schema_fields_ID)
                ->where(Product::schema_fields_status, 1)
                ->where(Product::schema_fields_parent_id, 0)
                ->order(Product::schema_fields_ID, 'desc')
                ->limit(self::MAX_PRODUCT_IDS)
                ->select()
                ->fetchArray();
            foreach ((array)$rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $productId = (int)($row[Product::schema_fields_ID] ?? 0);
                if ($productId > 0) {
                    $productIds[$productId] = $productId;
                }
                if (\count($productIds) >= self::MAX_PRODUCT_IDS) {
                    break;
                }
            }
        } catch (\Throwable) {
        }

        return \array_values($productIds);
    }

    /**
     * @return list<string>
     */
    private function normalizePaths(mixed $paths): array
    {
        if (\is_string($paths)) {
            $decoded = \json_decode($paths, true);
            $paths = \is_array($decoded) ? $decoded : (\preg_split('/[,\s]+/', $paths) ?: []);
        }
        if (!\is_array($paths)) {
            return [];
        }

        $normalized = [];
        foreach ($paths as $path) {
            if (!\is_scalar($path)) {
                continue;
            }
            $path = \str_replace(["\r", "\n", "\t"], '', \trim((string)$path));
            if ($path === '') {
                continue;
            }
            if ($path[0] !== '/') {
                $path = '/' . $path;
            }
            $normalized[$path] = $path;
        }

        return \array_values($normalized);
    }

    /**
     * @param list<string> $paths
     */
    private function appendWarmupPathVariants(array &$paths, string $path): void
    {
        $path = '/' . \ltrim($path, '/');
        $paths[] = $path;

        foreach (self::LOCALE_CURRENCY as $locale => $currency) {
            $paths[] = '/' . $locale . $path;
            $paths[] = '/' . $currency . '/' . $locale . $path;
        }
    }

    /**
     * Product-id URLs can be numerous, so keep the default set lean: current
     * storefront default plus en_US. Currency-specific handle URLs above cover
     * explicit multi-currency smoke paths without exploding build-ahead work.
     *
     * @param list<string> $paths
     */
    private function appendProductIdWarmupPathVariants(array &$paths, int $productId): void
    {
        if ($productId <= 0) {
            return;
        }

        $path = '/product/view?id=' . $productId;
        $paths[] = $path;
        $paths[] = '/en_US' . $path;
    }
}
