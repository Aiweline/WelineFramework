<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Model\Category;

/**
 * Ensures the default「新闻中心」blog category (slug=news) exists for storefront footer.
 */
final class BlogNewsCategoryBootstrap
{
    public const NEWS_SLUG = 'news';
    public const NEWS_NAME = '新闻中心';

    public function __construct(
        private readonly Category $categoryModel,
        private readonly BlogCategoryAttributeService $categoryAttributes,
    ) {
    }

    /**
     * @return array{created:bool,category_id:int,slug:string}
     */
    public function ensure(int $websiteId = 0): array
    {
        $websiteId = max(0, $websiteId);
        $model = clone $this->categoryModel;
        $row = $model->clearData()->reset()
            ->where(Category::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Category::schema_fields_SLUG, self::NEWS_SLUG)
            ->find()
            ->fetchArray();

        if (is_array($row) && (int)($row[Category::schema_fields_ID] ?? 0) > 0) {
            return [
                'created' => false,
                'category_id' => (int)$row[Category::schema_fields_ID],
                'slug' => self::NEWS_SLUG,
            ];
        }

        $now = date('Y-m-d H:i:s');
        $create = clone $this->categoryModel;
        $create->clearData()->reset();
        $create->setData(Category::schema_fields_WEBSITE_ID, $websiteId);
        $create->setData(Category::schema_fields_SLUG, self::NEWS_SLUG);
        $create->setData(Category::schema_fields_NAME, self::NEWS_NAME);
        $create->setData(Category::schema_fields_SORT_ORDER, 10);
        $create->setData(Category::schema_fields_CREATED_AT, $now);
        $create->setData(Category::schema_fields_UPDATED_AT, $now);
        $create->save();

        $categoryId = $create->getCategoryId();
        if ($categoryId > 0) {
            $this->categoryAttributes->writeName($websiteId, $categoryId, self::NEWS_NAME, '');
            $this->categoryAttributes->writeName($websiteId, $categoryId, self::NEWS_NAME, 'zh_Hans_CN');
            $this->categoryAttributes->writeName($websiteId, $categoryId, 'News Center', 'en_US');
            $this->categoryAttributes->writeCode($websiteId, $categoryId, self::NEWS_SLUG, '');
        }

        return [
            'created' => true,
            'category_id' => $categoryId,
            'slug' => self::NEWS_SLUG,
        ];
    }
}
