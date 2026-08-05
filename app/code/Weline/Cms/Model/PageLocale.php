<?php

declare(strict_types=1);

namespace Weline\Cms\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'CMS 页面多语言标题表')]
#[Index(name: 'uk_cms_page_locale_page_code', columns: ['page_id', 'locale_code'], type: 'UNIQUE', comment: '页面语言唯一索引')]
#[Index(name: 'idx_cms_page_locale_code', columns: ['locale_code', 'page_id'], type: 'KEY', comment: '语言页面查询索引')]
class PageLocale extends Model
{
    public const schema_table = 'weline_cms_page_locale';
    public const schema_primary_key = 'page_locale_id';

    public const ORIGIN_SOURCE = 'source';
    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_AI = 'ai';
    public const ORIGINS = [self::ORIGIN_SOURCE, self::ORIGIN_MANUAL, self::ORIGIN_AI];

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_PAGE_ID,
        self::schema_fields_LOCALE_CODE,
    ];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '页面语言ID')]
    public const schema_fields_ID = 'page_locale_id';
    #[Col(type: 'int', length: 11, nullable: false, comment: 'CMS 页面ID')]
    public const schema_fields_PAGE_ID = 'page_id';
    #[Col(type: 'varchar', length: 16, nullable: false, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = 'locale_code';
    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '本地化标题')]
    public const schema_fields_TITLE = 'title';
    #[Col(type: 'varchar', length: 16, nullable: false, default: self::ORIGIN_MANUAL, comment: '内容来源')]
    public const schema_fields_ORIGIN = 'origin';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '翻译源摘要')]
    public const schema_fields_SOURCE_HASH = 'source_hash';
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

    public function save_before(): void
    {
        parent::save_before();

        $now = date('Y-m-d H:i:s');
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}
