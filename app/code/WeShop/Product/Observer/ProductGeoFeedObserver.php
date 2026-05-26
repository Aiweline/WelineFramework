<?php

declare(strict_types=1);

namespace WeShop\Product\Observer;

use WeShop\Product\Model\Product;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

class ProductGeoFeedObserver implements ObserverInterface
{
    public function __construct(
        private readonly mixed $feed = null,
        private readonly mixed $dispatcher = null
    ) {
    }

    public function execute(Event &$event): void
    {
        $eventName = method_exists($event, 'getName') ? $event->getName() : '';
        $data = $event->getData();
        if (!is_array($data)) {
            $data = ['data' => $data];
        }

        try {
            if ($eventName === 'WeShop_Product::product_save_after') {
                $this->handleProductSave($data);
                return;
            }

            if ($eventName === 'WeShop_Product::product_delete_before'
                || $eventName === 'WeShop_Product::product_delete_after') {
                $this->handleProductDelete($data);
            }
        } catch (\Throwable $e) {
            if (function_exists('w_log_error')) {
                w_log_error('ProductGeoFeedObserver error: ' . $e->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function handleProductSave(array $data): void
    {
        $product = $data['product'] ?? null;
        $productId = $this->productId($product);
        if ($productId <= 0) {
            return;
        }

        $feedIds = $this->productFeedIds();
        if ($feedIds === []) {
            return;
        }

        $dispatcher = $this->dispatcherInstance();
        if (!$dispatcher || !method_exists($dispatcher, 'dispatchFeedItemUpdateToFeeds')) {
            return;
        }

        $dispatcher->dispatchFeedItemUpdateToFeeds(
            $feedIds,
            'product',
            $productId,
            $this->itemData($product, $productId)
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function handleProductDelete(array $data): void
    {
        $product = $data['product'] ?? null;
        $productId = $this->productId($product);
        if ($productId <= 0) {
            $productId = (int) ($data['product_id'] ?? 0);
        }
        if ($productId <= 0) {
            return;
        }

        $feedIds = $this->productFeedIds();
        if ($feedIds === []) {
            return;
        }

        $dispatcher = $this->dispatcherInstance();
        if ($dispatcher && method_exists($dispatcher, 'dispatchFeedItemDeleteFromFeeds')) {
            $dispatcher->dispatchFeedItemDeleteFromFeeds($feedIds, 'product', $productId);
        }
    }

    /**
     * @return int[]
     */
    private function productFeedIds(): array
    {
        if (!class_exists(\Weline\Geo\Model\Feed::class)) {
            return [];
        }

        try {
            $feed = $this->feed ?: ObjectManager::getInstance(\Weline\Geo\Model\Feed::class);
            $rows = $feed->reset()
                ->where(\Weline\Geo\Model\Feed::schema_fields_FEED_TYPE, \Weline\Geo\Model\Feed::TYPE_PRODUCT)
                ->where(\Weline\Geo\Model\Feed::schema_fields_IS_ENABLED, 1)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $ids = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row[\Weline\Geo\Model\Feed::schema_fields_ID] ?? $row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    private function dispatcherInstance(): mixed
    {
        if ($this->dispatcher) {
            return $this->dispatcher;
        }
        if (!class_exists(\Weline\Geo\Service\FeedEventDispatcher::class)) {
            return null;
        }
        try {
            return ObjectManager::getInstance(\Weline\Geo\Service\FeedEventDispatcher::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function productId(mixed $product): int
    {
        if (is_object($product) && method_exists($product, 'getId')) {
            return (int) $product->getId();
        }
        if (is_object($product) && method_exists($product, 'getData')) {
            return (int) ($product->getData(Product::schema_fields_ID) ?? $product->getData('id') ?? 0);
        }
        if (is_array($product)) {
            return (int) ($product[Product::schema_fields_ID] ?? $product['id'] ?? 0);
        }
        return 0;
    }

    private function itemData(mixed $product, int $productId): array
    {
        $name = $this->plain((string) $this->productData($product, Product::schema_fields_name));
        $shortDescription = $this->plain((string) $this->productData($product, Product::schema_fields_short_description));
        $description = $this->plain((string) $this->productData($product, Product::schema_fields_description));
        $image = $this->absoluteUrl((string) $this->productData($product, Product::schema_fields_image));
        $images = $this->imageList($this->productData($product, Product::schema_fields_images), $image);
        $stock = (int) $this->productData($product, Product::schema_fields_stock);
        $status = $this->productData($product, Product::schema_fields_status);
        $price = $this->productData($product, Product::schema_fields_price);
        $handle = trim((string) $this->productData($product, Product::schema_fields_HANDLE));

        $content = trim(implode("\n\n", array_filter([$shortDescription, $description])));
        $metadata = [
            'type' => 'product',
            'product_id' => $productId,
            'sku' => (string) $this->productData($product, Product::schema_fields_sku),
            'spu' => (string) $this->productData($product, Product::schema_fields_spu),
            'brand' => (string) $this->productData($product, 'brand'),
            'price' => is_numeric($price) ? (string) $price : '',
            'currency' => $this->currency(),
            'availability' => $stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'stock' => $stock,
            'image' => $image,
            'images' => $images,
        ];

        $itemData = [
            'title' => $name !== '' ? $name : 'Product #' . $productId,
            'content' => $content,
            'url' => $this->productUrl($productId, $handle),
            'metadata' => array_filter($metadata, static fn ($value): bool => $value !== '' && $value !== []),
            'is_published' => $status === 1 || $status === '1' || $status === 'enabled' ? 1 : 0,
            'published_at' => time(),
        ];

        return $this->normalizeGeoItemData($itemData);
    }

    private function productData(mixed $product, string $key): mixed
    {
        if (is_object($product) && method_exists($product, 'getData')) {
            try {
                return $product->getData($key);
            } catch (\Throwable) {
                return null;
            }
        }
        return is_array($product) ? ($product[$key] ?? null) : null;
    }

    private function productUrl(int $productId, string $handle): string
    {
        $baseUrl = rtrim($this->baseUrl(), '/');
        $path = $handle !== ''
            ? '/product/' . rawurlencode(trim($handle, '/'))
            : '/product/view?id=' . $productId;
        return $baseUrl !== '' ? $baseUrl . $path : $path;
    }

    private function baseUrl(): string
    {
        if (!function_exists('w_env')) {
            return '';
        }
        $url = (string) (w_env('website.url', '') ?: w_env('website_url', ''));
        return rtrim($url, '/');
    }

    private function currency(): string
    {
        if (!function_exists('w_env')) {
            return 'USD';
        }
        return strtoupper((string) w_env('user.currency', 'USD'));
    }

    /**
     * @return string[]
     */
    private function imageList(mixed $rawImages, string $mainImage): array
    {
        $images = [];
        if ($mainImage !== '') {
            $images[$mainImage] = true;
        }

        $decoded = is_string($rawImages) ? json_decode($rawImages, true) : $rawImages;
        $items = is_array($decoded) ? $decoded : [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $item = $item['url'] ?? $item['src'] ?? $item['image'] ?? '';
            }
            $url = $this->absoluteUrl((string) $item);
            if ($url !== '') {
                $images[$url] = true;
            }
        }

        return array_keys($images);
    }

    private function absoluteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//')) {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }
        $baseUrl = $this->baseUrl();
        return $baseUrl !== '' ? rtrim($baseUrl, '/') . '/' . ltrim($url, '/') : $url;
    }

    private function plain(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
        return trim($value);
    }

    /**
     * @param array<string, mixed> $itemData
     * @return array<string, mixed>
     */
    private function normalizeGeoItemData(array $itemData): array
    {
        if (!class_exists(\Weline\Geo\Service\SeoProfileGeoMetadataNormalizer::class)) {
            return $itemData;
        }

        try {
            $normalizer = ObjectManager::getInstance(\Weline\Geo\Service\SeoProfileGeoMetadataNormalizer::class);
        } catch (\Throwable) {
            $normalizer = new \Weline\Geo\Service\SeoProfileGeoMetadataNormalizer();
        }

        $normalized = $normalizer->toFeedItemData([
            'page_type' => 'product',
            'title' => $itemData['title'] ?? '',
            'description' => $itemData['content'] ?? '',
            'canonical_url' => $itemData['url'] ?? '',
            'url' => $itemData['url'] ?? '',
            'geo' => $itemData['metadata'] ?? [],
            'product' => $itemData['metadata'] ?? [],
        ]);

        return array_replace($itemData, [
            'metadata' => $normalized['metadata'] ?? ($itemData['metadata'] ?? []),
            'is_published' => $itemData['is_published'] ?? ($normalized['is_published'] ?? 1),
        ]);
    }
}
