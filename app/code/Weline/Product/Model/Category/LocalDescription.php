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
 * Product category name/description translations for official &lt;local&gt; Taglib.
 *
 * Drawer saves land here and sync into website-shard EAV (storefront source of truth).
 * Main-form {@see ProductCategoryAttributeService::writeName()} /
 * {@see ProductCategoryAttributeService::writeDescription()} also upsert this table.
 */
#[Table(comment: '产品分类多语言')]
#[Index(name: 'uniq_product_category_local', columns: ['category_id', 'local_code'], type: 'UNIQUE')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'weline_product_category_local';
    public const indexer = 'product_category_local';

    public const LOCAL_FIELDS = [
        self::schema_fields_NAME,
        self::schema_fields_DESCRIPTION,
    ];

    #[Col(type: 'int', nullable: false, primaryKey: true, comment: '分类 ID')]
    public const schema_fields_ID = Category::schema_fields_ID;

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = self::schema_fields_local_code;

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '分类名称')]
    public const schema_fields_NAME = 'name';

    #[Col(type: 'text', nullable: true, comment: '分类描述')]
    public const schema_fields_DESCRIPTION = 'description';

    /** @var list<array<string, mixed>> */
    private static array $pendingEavSync = [];

    private static bool $syncing = false;

    public static function isSyncing(): bool
    {
        return self::$syncing;
    }

    /**
     * Upsert Local row without re-entering EAV sync.
     *
     * BC: third argument may be the name string (legacy writeName callers) or a field map.
     *
     * @param string|array<string, string> $nameOrFields
     */
    public static function upsertQuiet(
        int $categoryId,
        string $locale,
        string|array $nameOrFields = '',
        string $description = '',
    ): void {
        $categoryId = max(0, $categoryId);
        if ($categoryId <= 0) {
            return;
        }
        $locale = ProductCategoryAttributeService::normalizeLocaleKey(
            $locale !== '' ? $locale : (string)State::getLangLocal(),
        );
        if ($locale === '') {
            $locale = 'zh_Hans_CN';
        }

        if (is_array($nameOrFields)) {
            $fields = $nameOrFields;
        } else {
            $fields = [];
            $name = trim($nameOrFields);
            if ($name !== '') {
                $fields[self::schema_fields_NAME] = $name;
            }
            $description = trim($description);
            if ($description !== '') {
                $fields[self::schema_fields_DESCRIPTION] = $description;
            }
        }

        $row = [
            self::schema_fields_ID => $categoryId,
            self::schema_fields_local_code => $locale,
        ];
        $updateCols = [];
        foreach (self::LOCAL_FIELDS as $field) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $value = trim((string)$fields[$field]);
            $row[$field] = $value;
            $updateCols[] = $field;
        }
        if ($updateCols === []) {
            return;
        }

        self::$syncing = true;
        try {
            /** @var self $model */
            $model = ObjectManager::getInstance(self::class);
            $model->reset()->insert(
                [$row],
                self::schema_fields_ID . ',local_code',
                implode(',', $updateCols),
            )->fetch();
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
                if ($categoryId <= 0) {
                    continue;
                }
                if (array_key_exists(self::schema_fields_NAME, $row)) {
                    $name = trim((string)$row[self::schema_fields_NAME]);
                    if ($name !== '') {
                        $attributes->writeName($websiteId, $categoryId, $name, $locale);
                    }
                }
                if (array_key_exists(self::schema_fields_DESCRIPTION, $row)) {
                    $description = trim((string)$row[self::schema_fields_DESCRIPTION]);
                    $attributes->writeDescription($websiteId, $categoryId, $description, $locale);
                }
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
