<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\App\State;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\Category;
use Weline\Websites\Model\Website;

/**
 * Emits catalog_category Changed with storefront URLs (category + /categories + home).
 */
final class CatalogCategoryChangedPublisher
{
    public function __construct(
        private readonly ResourceRevisionService $revisions,
        private readonly ResourceChangeFactory $changes,
        private readonly NamespacePath $namespacePath,
    ) {
    }

    public function publish(int $websiteId, string $reason, int $categoryId = 0): void
    {
        $websiteId = \max(0, $websiteId);
        $website = clone ObjectManager::getInstance(Website::class);
        $website->clearData()->load($websiteId);
        $websiteCode = \trim((string)$website->getCode());
        if ($websiteCode === '') {
            $websiteCode = 'default';
        }

        $urls = $this->impactUrls($website, $categoryId);
        if ($urls === []) {
            return;
        }

        $resourceId = $websiteId . ':' . \max(0, $categoryId) . ':' . \md5($reason);
        $revision = $this->revisions->next('catalog_category', $resourceId);
        $catalogNs = $this->namespacePath->website($websiteCode, ['catalog']);
        $change = $this->changes->create(
            resourceType: 'catalog_category',
            resourceId: $resourceId,
            action: 'upsert',
            revision: $revision,
            websiteId: $websiteId,
            websiteCode: $websiteCode,
            before: [],
            after: [
                'reason' => $reason,
                'category_id' => \max(0, $categoryId),
            ],
            changedFields: ['reason', 'category_id'],
            impact: [
                'namespaces' => [$catalogNs],
                'urls' => $urls,
                'previous_urls' => [],
            ],
            origin: ['entry' => 'product.catalog_category.' . $reason],
            siteId: $websiteId,
        );
        \w_changed($change);
    }

    /**
     * @return list<string>
     */
    private function impactUrls(Website $website, int $categoryId): array
    {
        $urls = [
            '/' => '/',
            '/categories' => '/categories',
            '/products' => '/products',
        ];
        foreach ($this->localeRoots($website) as $root) {
            $urls[$root] = $root;
        }

        if ($categoryId > 0) {
            try {
                $category = clone ObjectManager::getInstance(Category::class);
                $row = $category->clearData()->reset()
                    ->where(Category::schema_fields_ID, $categoryId)
                    ->find()
                    ->fetchArray();
                $path = \trim(\str_replace('\\', '/', (string)($row[Category::schema_fields_PATH] ?? '')), '/');
                if ($path !== '') {
                    $cat = '/category/' . $path;
                    $urls[$cat] = $cat;
                    foreach ($this->localeRoots($website) as $root) {
                        $localized = \rtrim($root, '/') . $cat;
                        $urls[$localized] = $localized;
                    }
                }
            } catch (\Throwable) {
                // Fail-open: still purge listing + home.
            }
        }

        return \array_values($urls);
    }

    /**
     * @return list<string>
     */
    private function localeRoots(Website $website): array
    {
        $roots = [];
        try {
            $codes = $website->getLanguageCodes();
        } catch (\Throwable) {
            $codes = [];
        }
        if (!\is_array($codes) || $codes === []) {
            $codes = ['en_US', 'zh_Hans_CN', 'ar_SA'];
        }
        foreach ($codes as $code) {
            $code = \trim((string)$code);
            if ($code === '' || !State::isLanguageCodeShape($code)) {
                continue;
            }
            $root = '/' . $code . '/';
            $roots[$root] = $root;
        }

        return \array_values($roots);
    }
}
