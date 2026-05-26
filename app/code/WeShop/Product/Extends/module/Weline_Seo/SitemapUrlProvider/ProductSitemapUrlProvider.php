<?php

declare(strict_types=1);

namespace WeShop\Product\Extends\Module\Weline_Seo\SitemapUrlProvider;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Provider\AbstractSitemapUrlProvider;
use WeShop\Product\Model\Product;

class ProductSitemapUrlProvider extends AbstractSitemapUrlProvider
{
    public function getScope(): string
    {
        return 'product';
    }

    public function getModule(): string
    {
        return 'WeShop_Product';
    }

    /**
     * @return int[]
     */
    public function getWebsiteIds(): array
    {
        $websites = w_query('websites', 'getWebsiteList', []);
        $ids = [];
        foreach ((array)$websites as $website) {
            $id = (int)($website['website_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    public function getUrlsForWebsite(int $websiteId): array
    {
        $website = w_query('websites', 'getWebsiteById', ['website_id' => $websiteId]);
        $baseUrl = rtrim((string)($website['url'] ?? w_env('website.url', '')), '/');
        if ($baseUrl === '') {
            return [];
        }

        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        try {
            $rows = $product->reset()
                ->where(Product::schema_fields_status, 1)
                ->where(Product::schema_fields_parent_id, 0)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $urls = [];
        foreach ($rows as $row) {
            $productId = (int)($row[Product::schema_fields_ID] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $handle = trim((string)($row[Product::schema_fields_HANDLE] ?? ''), '/');
            $loc = $handle !== ''
                ? $baseUrl . '/product/' . rawurlencode($handle)
                : $baseUrl . '/product/view?id=' . $productId;
            $urls[] = [
                'url_key' => 'product-' . $productId,
                'loc' => $loc,
                'lastmod' => date('Y-m-d'),
                'changefreq' => 'weekly',
                'priority' => '0.8',
                'images' => $this->imageMetadata($row, $baseUrl),
            ];
        }

        return $urls;
    }

    public function getDescription(): string
    {
        return __('WeShop 商品 sitemap URL 与图片扩展提供器');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, array<string, string>>
     */
    private function imageMetadata(array $row, string $baseUrl): array
    {
        $images = [];
        foreach ([$row[Product::schema_fields_image] ?? '', $row[Product::schema_fields_images] ?? ''] as $source) {
            foreach ($this->imageList($source) as $image) {
                $loc = $this->absoluteUrl($image, $baseUrl);
                if ($loc !== '') {
                    $images[$loc] = [
                        'loc' => $loc,
                        'title' => (string)($row[Product::schema_fields_name] ?? ''),
                    ];
                }
            }
        }
        return array_values($images);
    }

    /**
     * @return string[]
     */
    private function imageList(mixed $source): array
    {
        if (is_string($source) && trim($source) !== '') {
            $decoded = json_decode($source, true);
            if (is_array($decoded)) {
                $source = $decoded;
            } else {
                return [$source];
            }
        }
        if (!is_array($source)) {
            return [];
        }
        $images = [];
        foreach ($source as $image) {
            if (is_array($image)) {
                $image = $image['url'] ?? $image['src'] ?? $image['image'] ?? '';
            }
            $image = trim((string)$image);
            if ($image !== '') {
                $images[] = $image;
            }
        }
        return $images;
    }

    private function absoluteUrl(string $url, string $baseUrl): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//')) {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }
        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }
}
