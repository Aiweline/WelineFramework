<?php

declare(strict_types=1);

namespace Weline\Cms\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'CMS 页面多语言标题表')]
#[Index(name: 'uk_cms_page_locale_store_code', columns: ['page_id', 'store_id', 'locale_code'], type: 'UNIQUE', comment: '页面店铺语言唯一索引')]
#[Index(name: 'idx_cms_page_locale_store', columns: ['store_id', 'locale_code', 'variant_status', 'page_id'], type: 'KEY', comment: '店铺语言变体查询索引')]
#[Index(name: 'idx_cms_page_locale_page_status', columns: ['page_id', 'variant_status'], type: 'KEY', comment: '页面聚合状态查询索引')]
class PageLocale extends Model
{
    public const schema_table = 'weline_cms_page_locale';
    public const schema_primary_key = 'page_locale_id';

    public const ORIGIN_SOURCE = 'source';
    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_AI = 'ai';
    public const ORIGINS = [self::ORIGIN_SOURCE, self::ORIGIN_MANUAL, self::ORIGIN_AI];

    public const VARIANT_STATUS_DRAFT = 'draft';
    public const VARIANT_STATUS_PUBLISHED = 'published';
    public const VARIANT_STATUS_DISABLED = 'disabled';
    public const VARIANT_STATUSES = [
        self::VARIANT_STATUS_DRAFT,
        self::VARIANT_STATUS_PUBLISHED,
        self::VARIANT_STATUS_DISABLED,
    ];

    public const TRANSLATION_STATE_DRAFT = 'draft';
    public const TRANSLATION_STATE_REVIEWED = 'reviewed';
    public const TRANSLATION_STATES = [self::TRANSLATION_STATE_DRAFT, self::TRANSLATION_STATE_REVIEWED];

    public const VALIDATION_STATE_PENDING = 'pending';
    public const VALIDATION_STATE_VALID = 'valid';
    public const VALIDATION_STATE_LEGACY_UNVERIFIED = 'legacy_unverified';
    public const VALIDATION_STATES = [
        self::VALIDATION_STATE_PENDING,
        self::VALIDATION_STATE_VALID,
        self::VALIDATION_STATE_LEGACY_UNVERIFIED,
    ];

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_PAGE_ID,
        self::schema_fields_STORE_ID,
        self::schema_fields_LOCALE_CODE,
    ];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '页面语言ID')]
    public const schema_fields_ID = 'page_locale_id';
    #[Col(type: 'int', length: 11, nullable: false, comment: 'CMS 页面ID')]
    public const schema_fields_PAGE_ID = 'page_id';
    #[Col(type: 'int', length: 11, nullable: false, comment: '店铺ID')]
    public const schema_fields_STORE_ID = 'store_id';
    #[Col(type: 'varchar', length: 64, nullable: false, default: 'default', comment: '店铺代码快照')]
    public const schema_fields_STORE_CODE = 'store_code';
    #[Col(type: 'varchar', length: 16, nullable: false, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = 'locale_code';
    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '本地化标题')]
    public const schema_fields_TITLE = 'title';
    #[Col(type: 'varchar', length: 16, nullable: false, default: self::ORIGIN_MANUAL, comment: '内容来源')]
    public const schema_fields_ORIGIN = 'origin';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '翻译源摘要')]
    public const schema_fields_SOURCE_HASH = 'source_hash';
    #[Col(type: 'varchar', length: 16, nullable: false, default: self::VARIANT_STATUS_DRAFT, comment: '变体状态 draft|published|disabled')]
    public const schema_fields_VARIANT_STATUS = 'variant_status';
    #[Col(type: 'varchar', length: 16, nullable: false, default: self::TRANSLATION_STATE_DRAFT, comment: '翻译状态 draft|reviewed')]
    public const schema_fields_TRANSLATION_STATE = 'translation_state';
    #[Col(type: 'varchar', length: 32, nullable: false, default: self::VALIDATION_STATE_PENDING, comment: '校验状态 pending|valid|legacy_unverified')]
    public const schema_fields_VALIDATION_STATE = 'validation_state';
    #[Col(type: 'datetime', nullable: true, comment: '发布时间')]
    public const schema_fields_PUBLISHED_AT = 'published_at';
    #[Col(type: 'int', length: 11, nullable: false, default: 1, comment: '店铺语言变体修订号')]
    public const schema_fields_VARIANT_REVISION = 'variant_revision';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getPageLocaleId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function getPageId(): int
    {
        return (int)$this->getData(self::schema_fields_PAGE_ID);
    }

    public function getLocaleCode(): string
    {
        return (string)($this->getData(self::schema_fields_LOCALE_CODE) ?: '');
    }

    public function getStoreId(): int
    {
        return (int)$this->getData(self::schema_fields_STORE_ID);
    }

    public function getStoreCode(): string
    {
        return (string)($this->getData(self::schema_fields_STORE_CODE) ?: 'default');
    }

    public function getTitle(): string
    {
        return (string)($this->getData(self::schema_fields_TITLE) ?: '');
    }

    public function getOrigin(): string
    {
        return (string)($this->getData(self::schema_fields_ORIGIN) ?: self::ORIGIN_MANUAL);
    }

    public function getSourceHash(): string
    {
        return (string)($this->getData(self::schema_fields_SOURCE_HASH) ?: '');
    }

    public function getVariantStatus(): string
    {
        return (string)($this->getData(self::schema_fields_VARIANT_STATUS) ?: self::VARIANT_STATUS_DRAFT);
    }

    public function getTranslationState(): string
    {
        return (string)($this->getData(self::schema_fields_TRANSLATION_STATE) ?: self::TRANSLATION_STATE_DRAFT);
    }

    public function getValidationState(): string
    {
        return (string)($this->getData(self::schema_fields_VALIDATION_STATE) ?: self::VALIDATION_STATE_PENDING);
    }

    public function getPublishedAt(): ?string
    {
        $publishedAt = trim((string)$this->getData(self::schema_fields_PUBLISHED_AT));
        return $publishedAt !== '' ? $publishedAt : null;
    }

    public function getVariantRevision(): int
    {
        return max(1, (int)$this->getData(self::schema_fields_VARIANT_REVISION));
    }

    public function save_before(): void
    {
        parent::save_before();
        $isNew = $this->getPageLocaleId() <= 0;

        if ($this->getPageId() <= 0) {
            throw new \InvalidArgumentException((string)__('CMS 页面语言变体必须指定有效页面。'));
        }
        if ($this->getStoreId() <= 0) {
            throw new \InvalidArgumentException((string)__('CMS 页面语言变体必须指定有效店铺。'));
        }
        $storeCode = trim($this->getStoreCode());
        if ($storeCode === '' || strlen($storeCode) > 64
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $storeCode) !== 1
        ) {
            throw new \InvalidArgumentException((string)__('CMS 页面语言变体的店铺代码无效。'));
        }
        $localeCode = trim($this->getLocaleCode());
        $title = trim($this->getTitle());
        $origin = trim($this->getOrigin());
        $sourceHash = strtolower(trim($this->getSourceHash()));
        $titleLength = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
        if (
            preg_match('/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|[0-9]{3}))?$/', $localeCode) !== 1
            || strlen($localeCode) > 16
            || preg_match('//u', $title) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $title) === 1
            || $titleLength > 255
            || !in_array($origin, self::ORIGINS, true)
            || ($sourceHash !== '' && preg_match('/^[a-f0-9]{64}$/', $sourceHash) !== 1)
        ) {
            throw new \InvalidArgumentException((string)__('CMS 页面语言变体内容无效。'));
        }
        $variantStatus = $this->getVariantStatus();
        if (!in_array($variantStatus, self::VARIANT_STATUSES, true)) {
            throw new \InvalidArgumentException((string)__('CMS 页面变体状态无效：%{1}', [$variantStatus]));
        }
        $translationState = $this->getTranslationState();
        if (!in_array($translationState, self::TRANSLATION_STATES, true)) {
            throw new \InvalidArgumentException((string)__('CMS 页面翻译状态无效：%{1}', [$translationState]));
        }
        $validationState = $this->getValidationState();
        if (!in_array($validationState, self::VALIDATION_STATES, true)) {
            throw new \InvalidArgumentException((string)__('CMS 页面校验状态无效：%{1}', [$validationState]));
        }
        if ($variantStatus === self::VARIANT_STATUS_PUBLISHED) {
            if ($title === '') {
                throw new \InvalidArgumentException((string)__('已发布 CMS 页面变体的标题不能为空。'));
            }
            if ($translationState !== self::TRANSLATION_STATE_REVIEWED
                && $validationState !== self::VALIDATION_STATE_LEGACY_UNVERIFIED
            ) {
                throw new \InvalidArgumentException((string)__('未审核翻译不能发布 CMS 页面变体。'));
            }
            if (!in_array($validationState, [
                self::VALIDATION_STATE_VALID,
                self::VALIDATION_STATE_LEGACY_UNVERIFIED,
            ], true)) {
                throw new \InvalidArgumentException((string)__('未通过校验的 CMS 页面变体不能发布。'));
            }
            if (!$this->getData(self::schema_fields_PUBLISHED_AT)) {
                $this->setData(self::schema_fields_PUBLISHED_AT, date('Y-m-d H:i:s'));
            }
        }
        if ($variantStatus !== self::VARIANT_STATUS_PUBLISHED) {
            $this->setData(self::schema_fields_PUBLISHED_AT, null);
        }
        $this->setData(self::schema_fields_STORE_CODE, $storeCode);
        $this->setData(self::schema_fields_LOCALE_CODE, $localeCode);
        $this->setData(self::schema_fields_TITLE, $title);
        $this->setData(self::schema_fields_ORIGIN, $origin);
        $this->setData(self::schema_fields_SOURCE_HASH, $sourceHash);

        $now = date('Y-m-d H:i:s');
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        $this->setData(
            self::schema_fields_VARIANT_REVISION,
            $isNew ? 1 : $this->getVariantRevision() + 1,
        );
    }
}
