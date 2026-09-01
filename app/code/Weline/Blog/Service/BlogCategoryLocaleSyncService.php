<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Model\BlogCategoryAttributeEntity;
use Weline\Blog\Model\Category;

/**
 * One-time sync: DB category.name → EAV zh_Hans_CN + known en_US labels.
 */
final class BlogCategoryLocaleSyncService
{
    /** @var array<string, string> */
    private const EN_LABELS = [
        '技术分享' => 'Tech Sharing',
        '产品动态' => 'Product Updates',
        '公司新闻' => 'Company News',
    ];

    public function __construct(
        private readonly Category $categoryModel,
        private readonly BlogCategoryAttributeService $categoryAttributes,
    ) {
    }

    public function syncExistingCategories(): int
    {
        $model = clone $this->categoryModel;
        $rows = $model->clearData()->reset()
            ->order(Category::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
        if (!is_array($rows)) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $categoryId = (int)($row[Category::schema_fields_ID] ?? 0);
            $websiteId = (int)($row[Category::schema_fields_WEBSITE_ID] ?? 0);
            $name = trim((string)($row[Category::schema_fields_NAME] ?? ''));
            $slug = trim((string)($row[Category::schema_fields_SLUG] ?? ''));
            if ($categoryId <= 0 || $name === '') {
                continue;
            }

            if ($this->categoryAttributes->readName($websiteId, $categoryId, 'zh_Hans_CN') === '') {
                $this->categoryAttributes->writeName($websiteId, $categoryId, $name, 'zh_Hans_CN');
                ++$count;
            }
            if ($slug !== '' && $this->categoryAttributes->readName($websiteId, $categoryId, 'en_US') === '') {
                $en = self::EN_LABELS[$name] ?? '';
                if ($en !== '') {
                    $this->categoryAttributes->writeName($websiteId, $categoryId, $en, 'en_US');
                    ++$count;
                }
            }
            if ($slug !== '') {
                $this->categoryAttributes->writeCode($websiteId, $categoryId, $slug, 'zh_Hans_CN');
            }
        }

        return $count;
    }
}
