<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace WeShop\Cms\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'CMS pages table')]
#[Index(name: 'idx_weshop_cms_page_identifier', columns: ['identifier'], comment: 'Page identifier index')]
#[Index(name: 'idx_weshop_cms_page_status', columns: ['status'], comment: 'Page status index')]
#[Index(name: 'idx_weshop_cms_page_title', columns: ['title'], type: 'FULLTEXT', comment: 'Page title fulltext index')]
class Page extends Model
{
    public const STATUS_ENABLED = 1;
    public const STATUS_DISABLED = 0;

    public const schema_table = 'weshop_cms_page';
    public const schema_primary_key = 'page_id';
    public string $indexer = 'cms_page_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Page ID')]
    public const schema_fields_ID = 'page_id';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Page title')]
    public const schema_fields_TITLE = 'title';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Page identifier (URL key)')]
    public const schema_fields_IDENTIFIER = 'identifier';

    #[Col(type: 'longtext', nullable: true, comment: 'Page content (HTML)')]
    public const schema_fields_CONTENT = 'content';

    #[Col(type: 'text', nullable: true, comment: 'Content summary / excerpt')]
    public const schema_fields_CONTENT_HEADING = 'content_heading';

    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Meta title for SEO')]
    public const schema_fields_META_TITLE = 'meta_title';

    #[Col(type: 'text', nullable: true, comment: 'Meta description for SEO')]
    public const schema_fields_META_DESCRIPTION = 'meta_description';

    #[Col(type: 'text', nullable: true, comment: 'Meta keywords for SEO')]
    public const schema_fields_META_KEYWORDS = 'meta_keywords';

    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: 'Status: 1=enabled, 0=disabled')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'varchar', length: 100, nullable: true, comment: 'Page layout template')]
    public const schema_fields_PAGE_LAYOUT = 'page_layout';

    #[Col(type: 'int', nullable: true, default: 0, comment: 'Sort order')]
    public const schema_fields_SORT_ORDER = 'sort_order';

    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['page_id'];
    public array $_index_sort_keys = ['page_id', 'title', 'identifier', 'status', 'sort_order', 'created_at'];

    public function getTitle(): string
    {
        return (string) $this->getData(self::schema_fields_TITLE);
    }

    public function setTitle(string $title): static
    {
        $this->setData(self::schema_fields_TITLE, $title);
        return $this;
    }

    public function getIdentifier(): string
    {
        return (string) $this->getData(self::schema_fields_IDENTIFIER);
    }

    public function setIdentifier(string $identifier): static
    {
        $this->setData(self::schema_fields_IDENTIFIER, $identifier);
        return $this;
    }

    public function getContent(): string
    {
        return (string) $this->getData(self::schema_fields_CONTENT);
    }

    public function setContent(string $content): static
    {
        $this->setData(self::schema_fields_CONTENT, $content);
        return $this;
    }

    public function getContentHeading(): string
    {
        return (string) $this->getData(self::schema_fields_CONTENT_HEADING);
    }

    public function setContentHeading(string $contentHeading): static
    {
        $this->setData(self::schema_fields_CONTENT_HEADING, $contentHeading);
        return $this;
    }

    public function getMetaTitle(): string
    {
        return (string) $this->getData(self::schema_fields_META_TITLE);
    }

    public function setMetaTitle(string $metaTitle): static
    {
        $this->setData(self::schema_fields_META_TITLE, $metaTitle);
        return $this;
    }

    public function getMetaDescription(): string
    {
        return (string) $this->getData(self::schema_fields_META_DESCRIPTION);
    }

    public function setMetaDescription(string $metaDescription): static
    {
        $this->setData(self::schema_fields_META_DESCRIPTION, $metaDescription);
        return $this;
    }

    public function getMetaKeywords(): string
    {
        return (string) $this->getData(self::schema_fields_META_KEYWORDS);
    }

    public function setMetaKeywords(string $metaKeywords): static
    {
        $this->setData(self::schema_fields_META_KEYWORDS, $metaKeywords);
        return $this;
    }

    public function getStatus(): int
    {
        return (int) $this->getData(self::schema_fields_STATUS);
    }

    public function setStatus(int $status): static
    {
        $this->setData(self::schema_fields_STATUS, $status);
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->getStatus() === self::STATUS_ENABLED;
    }

    public function getPageLayout(): string
    {
        return (string) $this->getData(self::schema_fields_PAGE_LAYOUT);
    }

    public function setPageLayout(string $pageLayout): static
    {
        $this->setData(self::schema_fields_PAGE_LAYOUT, $pageLayout);
        return $this;
    }

    public function getSortOrder(): int
    {
        return (int) $this->getData(self::schema_fields_SORT_ORDER);
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->setData(self::schema_fields_SORT_ORDER, $sortOrder);
        return $this;
    }
}
