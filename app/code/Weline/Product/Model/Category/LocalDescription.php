<?php

declare(strict_types=1);

namespace Weline\Product\Model\Category;

use Weline\Framework\App\State;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\Product\Model\Shard\Category;
use Weline\Product\Service\ProductCategoryAttributeService;

/**
 * Product category name translations for official &lt;local&gt; Taglib.
 *
 * Drawer saves land here and sync into website-shard EAV `name` (storefront source of truth).
 * Main-form {@see ProductCategoryAttributeService::writeName()} also upserts this table.
 */
#[Table(comment: '产品分类名称多语言')]
#[Index(name: 'uniq_product_category_local', columns: ['category_id', 'local_code'], type: 'UNIQUE')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'weline_product_category_local';
    public const indexer = 'product_category_local';

    #[Col(type: 'int', nullable: false, primaryKey: true, comment: '分类 ID')]
    public const schema_fields_ID = Category::schema_fields_ID;

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = self::schema_fields_local_code;

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '分类名称')]
    public const schema_fields_NAME = 'name';

    /** @var list<array<string, mixed>> */
    private static array $pendingEavSync = [];

    private static bool $syncing = false;

    public static function isSyncing(): bool
    {
        return self::$syncing;
    }

    /**
     * Upsert Local row without re-entering EAV sync (used by AttributeService::writeName).
     */
    public static function upsertQuiet(int $categoryId, string $locale, string $name): void
    {
        $categoryId = max(0, $categoryId);
        $name = trim($name);
        if ($categoryId <= 0 || $name === '') {
            return;
        }
        $locale = ProductCategoryAttributeService::normalizeLocaleKey(
            $locale !== '' ? $locale : (string)State::getLangLocal(),
        );
        if ($locale === '') {
            $locale = 'zh_Hans_CN';
        }

        self::$syncing = true;
        try {
            /** @var self $model */
            $model = ObjectManager::getInstance(self::class);
            $model->reset()->insert([
                [
                    self::schema_fields_ID => $categoryId,
                    self::schema_fields_local_code => $locale,
                    self::schema_fields_NAME => $name,
                ],
            ], self::schema_fields_ID . ',local_code', self::schema_fields_NAME)->fetch();
        } finally {
            self::$syncing = false;
        }
    }

    public function __call($method, $args)
    {
        if ($method === 'insert' && !self::$syncing && isset($args[0]) && is_array($args[0])) {
            foreach ($args[0] as $row) {
                if (is_array($row)) {
                    self::$pendingEavSync[] = $row;
                }
            }
        }

        return parent::__call($method, $args);
    }

    public function fetch_after(): void
    {
        parent::fetch_after();
        if (!$this->getIsInsert() || self::$syncing || self::$pendingEavSync === []) {
            return;
        }

        $rows = self::$pendingEavSync;
        self::$pendingEavSync = [];
        $websiteId = self::resolveWebsiteId();
        /** @var ProductCategoryAttributeService $attributes */
        $attributes = ObjectManager::getInstance(ProductCategoryAttributeService::class);
        self::$syncing = true;
        try {
            foreach ($rows as $row) {
                $categoryId = max(0, (int)($row[self::schema_fields_ID] ?? 0));
                $locale = ProductCategoryAttributeService::normalizeLocaleKey(
                    (string)($row[self::schema_fields_local_code] ?? ''),
                );
                $name = trim((string)($row[self::schema_fields_NAME] ?? ''));
                if ($categoryId <= 0 || $name === '') {
                    continue;
                }
                $attributes->writeName($websiteId, $categoryId, $name, $locale);
            }
        } finally {
            self::$syncing = false;
        }
    }

    private static function resolveWebsiteId(): int
    {
        $fromServer = max(0, (int)($_SERVER['WELINE_WEBSITE_ID'] ?? 0));
        if ($fromServer > 0 || isset($_SERVER['WELINE_WEBSITE_ID'])) {
            return $fromServer;
        }
        $fromCookie = Cookie::get('WELINE_WEBSITE_ID', null);
        if ($fromCookie !== null && $fromCookie !== '') {
            return max(0, (int)$fromCookie);
        }

        return 0;
    }
}
