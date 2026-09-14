<?php

declare(strict_types=1);

namespace Weline\Product\Model\Product;

use Weline\Framework\App\State;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Service\ProductCategoryAttributeService;

/**
 * Product basics translations for official &lt;local&gt; Taglib (name / short / detail / SEO).
 *
 * Drawer saves land here and sync into website-shard EAV (storefront source of truth).
 * Main-form {@see \Weline\Product\Service\ProductAdminCommandService} also upserts this table.
 */
#[Table(comment: '商品基础信息多语言')]
#[Index(name: 'uniq_product_local', columns: ['product_id', 'local_code'], type: 'UNIQUE')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'weline_product_local';
    public const indexer = 'product_local';

    public const LOCAL_FIELDS = [
        self::schema_fields_NAME,
        self::schema_fields_SHORT_DESCRIPTION,
        self::schema_fields_DESCRIPTION,
        self::schema_fields_META_NAME,
        self::schema_fields_META_DESCRIPTION,
        self::schema_fields_META_KEYWORDS,
    ];

    #[Col(type: 'int', nullable: false, primaryKey: true, comment: '商品 ID')]
    public const schema_fields_ID = Product::schema_fields_ID;

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = self::schema_fields_local_code;

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '商品名称')]
    public const schema_fields_NAME = 'name';

    #[Col(type: 'text', nullable: true, comment: '短描述')]
    public const schema_fields_SHORT_DESCRIPTION = 'short_description';

    #[Col(type: 'mediumtext', nullable: true, comment: '详情描述')]
    public const schema_fields_DESCRIPTION = 'description';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'SEO 标题')]
    public const schema_fields_META_NAME = 'meta_name';

    #[Col(type: 'text', nullable: true, comment: 'SEO 描述')]
    public const schema_fields_META_DESCRIPTION = 'meta_description';

    #[Col(type: 'text', nullable: true, comment: 'SEO 关键词')]
    public const schema_fields_META_KEYWORDS = 'meta_keywords';

    /** @var list<array<string, mixed>> */
    private static array $pendingEavSync = [];

    private static bool $syncing = false;

    public static function isSyncing(): bool
    {
        return self::$syncing;
    }

    /**
     * Upsert Local row without re-entering EAV sync (used by admin write path).
     *
     * @param array<string, string> $fields
     */
    public static function upsertQuiet(int $productId, string $locale, array $fields): void
    {
        $productId = max(0, $productId);
        if ($productId <= 0 || $fields === []) {
            return;
        }
        $locale = ProductCategoryAttributeService::normalizeLocaleKey(
            $locale !== '' ? $locale : (string)State::getLangLocal(),
        );
        if ($locale === '') {
            $locale = 'zh_Hans_CN';
        }

        $row = [
            self::schema_fields_ID => $productId,
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
        /** @var AttributeValueRepository $attributes */
        $attributes = ObjectManager::getInstance(AttributeValueRepository::class);
        self::$syncing = true;
        try {
            $writesByProduct = [];
            foreach ($rows as $row) {
                $productId = max(0, (int)($row[self::schema_fields_ID] ?? 0));
                $locale = ProductCategoryAttributeService::normalizeLocaleKey(
                    (string)($row[self::schema_fields_local_code] ?? ''),
                );
                if ($productId <= 0 || $locale === '') {
                    continue;
                }
                foreach (self::LOCAL_FIELDS as $field) {
                    if (!array_key_exists($field, $row)) {
                        continue;
                    }
                    $value = trim((string)$row[$field]);
                    if ($value === '') {
                        continue;
                    }
                    $writesByProduct[$productId][] = [
                        $field,
                        $locale,
                        $value,
                        $field === self::schema_fields_NAME,
                    ];
                }
            }

            // One committed change per product; repeated locales retain their input order.
            foreach ($writesByProduct as $productId => $writes) {
                $attributes->mutateProductAttributes(
                    $websiteId,
                    $productId,
                    0,
                    function () use ($attributes, $websiteId, $productId, $writes): void {
                        foreach ($writes as [$field, $locale, $value, $required]) {
                            $attributes->writeTyped(
                                $websiteId,
                                0,
                                'product',
                                $productId,
                                $field,
                                $locale,
                                'string',
                                $value,
                                $required,
                            );
                            // Preserve the empty-locale fallback immediately after its local value.
                            $attributes->writeTyped(
                                $websiteId,
                                0,
                                'product',
                                $productId,
                                $field,
                                '',
                                'string',
                                $value,
                                $required,
                            );
                        }
                    },
                );
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
