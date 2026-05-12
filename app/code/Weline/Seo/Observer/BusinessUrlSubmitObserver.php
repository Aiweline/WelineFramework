<?php

declare(strict_types=1);

namespace Weline\Seo\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Service\UrlSubmitService;
use Weline\Websites\Model\Website;

class BusinessUrlSubmitObserver implements ObserverInterface
{
    public function __construct(
        private readonly UrlSubmitService $urlSubmitService,
        private readonly ObjectManager $objectManager
    ) {
    }

    public function execute(Event &$event): void
    {
        $eventName = method_exists($event, 'getName') ? $event->getName() : '';
        $data = $this->normalizeEventData($event->getData());

        if (str_contains($eventName, 'product_save_after')) {
            $this->submitProduct($data);
            return;
        }

        if (str_contains($eventName, 'category_save_after') || str_contains($eventName, 'Category_model_save_after')) {
            $this->submitCategory($data);
        }
    }

    /**
     * @param mixed $eventData
     * @return array<string, mixed>
     */
    private function normalizeEventData(mixed $eventData): array
    {
        if ($eventData instanceof DataObject) {
            return $eventData->getData();
        }
        if (is_array($eventData)) {
            return $eventData;
        }
        return ['data' => $eventData];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function submitProduct(array $data): void
    {
        $product = $data['product'] ?? $data['model'] ?? null;
        if (!is_object($product) || !method_exists($product, 'getData')) {
            return;
        }

        $productId = (int) ($this->read($product, ['product_id', 'id']) ?: $data['product_id'] ?? 0);
        if ($productId <= 0 || !$this->isEnabled($this->read($product, ['status']))) {
            return;
        }

        $websiteUrls = $this->websiteUrls();
        $urls = $this->productUrls($product, $productId, $websiteUrls);
        if ($urls === []) {
            return;
        }

        foreach ($urls as $urlData) {
            $this->urlSubmitService->requestSubmit(
                $urlData['url'],
                'product',
                [
                    'subject_type' => 'product',
                    'subject_id' => $productId,
                    'title' => (string) ($urlData['title'] ?: $this->read($product, ['meta_name', 'meta_title', 'name'])),
                    'description' => (string) ($urlData['description'] ?: $this->read($product, ['meta_description', 'short_description', 'description'])),
                    'content' => (string) $this->read($product, ['description', 'short_description']),
                    'tags' => $this->keywords($this->read($product, ['meta_keywords', 'sku', 'spu'])),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function submitCategory(array $data): void
    {
        $category = $data['category'] ?? $data['model'] ?? null;
        if (!is_object($category) || !method_exists($category, 'getData')) {
            return;
        }

        $categoryId = (int) ($this->read($category, ['category_id', 'id']) ?: $data['category_id'] ?? 0);
        if ($categoryId <= 0 || !$this->isEnabled($this->read($category, ['is_active', 'status']))) {
            return;
        }

        $handle = trim((string) $this->read($category, ['handle']), '/');
        if ($handle === '') {
            return;
        }

        foreach ($this->websiteUrls() as $websiteId => $baseUrl) {
            $this->urlSubmitService->requestSubmit(
                rtrim($baseUrl, '/') . '/catalog/category/' . $handle,
                'category',
                [
                    'subject_type' => 'category',
                    'subject_id' => $categoryId,
                    'title' => (string) $this->read($category, ['meta_title', 'name', 'title']),
                    'description' => (string) $this->read($category, ['meta_description', 'description']),
                    'content' => (string) $this->read($category, ['description']),
                    'tags' => $this->keywords($this->read($category, ['meta_keywords', 'name'])),
                    'website_id' => $websiteId,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]
            );
        }
    }

    /**
     * @param object $product
     * @param array<int, string> $websiteUrls
     * @return array<int, array{url:string,title:string,description:string}>
     */
    private function productUrls(object $product, int $productId, array $websiteUrls): array
    {
        $urls = [];

        if (class_exists(\WeShop\Product\Model\ProductWebsite::class)) {
            try {
                $productWebsite = $this->objectManager->getInstance(\WeShop\Product\Model\ProductWebsite::class);
                $rows = $productWebsite->reset()
                    ->where(\WeShop\Product\Model\ProductWebsite::schema_fields_PRODUCT_ID, $productId)
                    ->where(\WeShop\Product\Model\ProductWebsite::schema_fields_IS_ACTIVE, 1)
                    ->select()
                    ->fetchArray();

                foreach ($rows as $row) {
                    $websiteId = (int) ($row[\WeShop\Product\Model\ProductWebsite::schema_fields_WEBSITE_ID] ?? 0);
                    $handle = trim((string) ($row[\WeShop\Product\Model\ProductWebsite::schema_fields_HANDLE] ?? ''), '/');
                    $baseUrl = $websiteUrls[$websiteId] ?? reset($websiteUrls);
                    if ($handle === '' || !$baseUrl) {
                        continue;
                    }
                    $urls[] = [
                        'url' => rtrim((string) $baseUrl, '/') . '/product/' . $handle,
                        'title' => (string) ($row[\WeShop\Product\Model\ProductWebsite::schema_fields_META_TITLE] ?? ''),
                        'description' => (string) ($row[\WeShop\Product\Model\ProductWebsite::schema_fields_META_DESCRIPTION] ?? ''),
                    ];
                }
            } catch (\Throwable) {
            }
        }

        if ($urls !== []) {
            return $urls;
        }

        $handle = trim((string) $this->read($product, ['handle']), '/');
        if ($handle === '') {
            return [];
        }

        $baseUrl = reset($websiteUrls);
        return $baseUrl ? [[
            'url' => rtrim((string) $baseUrl, '/') . '/product/' . $handle,
            'title' => '',
            'description' => '',
        ]] : [];
    }

    /**
     * @return array<int, string>
     */
    private function websiteUrls(): array
    {
        $urls = [];
        try {
            /** @var Website $website */
            $website = $this->objectManager->getInstance(Website::class);
            foreach ($website->reset()->select()->fetchArray() as $row) {
                $websiteId = (int) ($row[Website::schema_fields_ID] ?? $row['website_id'] ?? 0);
                $url = rtrim((string) ($row[Website::schema_fields_URL] ?? $row['url'] ?? ''), '/');
                if ($websiteId > 0 && $url !== '') {
                    $urls[$websiteId] = $url;
                }
            }
        } catch (\Throwable) {
        }

        if ($urls === []) {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
            $scheme = (string) ($_SERVER['REQUEST_SCHEME'] ?? 'https');
            if ($host !== '') {
                $urls[0] = $scheme . '://' . $host;
            }
        }

        return $urls;
    }

    /**
     * @param object $source
     * @param string[] $keys
     */
    private function read(object $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (method_exists($source, 'getData')) {
                $value = $source->getData($key);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
        }
        return null;
    }

    private function isEnabled(mixed $status): bool
    {
        if ($status === null || $status === '') {
            return true;
        }
        return (int) $status === 1;
    }

    /**
     * @return string[]
     */
    private function keywords(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }
        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }
}
