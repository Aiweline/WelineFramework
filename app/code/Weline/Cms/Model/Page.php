<?php
declare(strict_types=1);

namespace Weline\Cms\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'CMS 页面表')]
#[Index(name: 'uk_cms_page_website_identifier', columns: ['website_id', 'identifier'], type: 'UNIQUE', comment: '站点内页面路径唯一索引')]
#[Index(name: 'idx_cms_page_site_group', columns: ['website_id', 'path_group', 'status'], type: 'KEY', comment: '站点路径组查询索引')]
#[Index(name: 'idx_cms_page_status_scope', columns: ['status', 'scope'], type: 'KEY', comment: '发布状态与范围查询索引')]
#[Index(name: 'idx_cms_page_deleted_at', columns: ['deleted_at'], type: 'KEY', comment: '软删除查询索引')]
class Page extends Model
{
    public const schema_table = 'weline_cms_page';
    public const schema_primary_key = 'page_id';

    public const TARGET_TYPE = 'cms_page';
    public const LAYOUT_TYPE = 'cms_page';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DISABLED = 'disabled';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_WEBSITE_ID,
        self::schema_fields_IDENTIFIER,
        self::schema_fields_SCOPE,
    ];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '页面ID')]
    public const schema_fields_ID = 'page_id';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '所属站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'varchar', length: 128, nullable: false, default: 'default', comment: '所属站点代码')]
    public const schema_fields_WEBSITE_CODE = 'website_code';
    #[Col(type: 'varchar', length: 100, nullable: false, default: '', comment: '一级路径分组')]
    public const schema_fields_PATH_GROUP = 'path_group';
    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '一级路径分组显示别名')]
    public const schema_fields_PATH_GROUP_ALIAS = 'path_group_alias';
    #[Col(type: 'varchar', length: 190, nullable: false, default: '', comment: '路径组内页面 slug')]
    public const schema_fields_SLUG = 'slug';
    #[Col(type: 'varchar', length: 190, nullable: false, comment: '页面路径标识')]
    public const schema_fields_IDENTIFIER = 'identifier';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '页面标题')]
    public const schema_fields_TITLE = 'title';
    #[Col(type: 'varchar', length: 32, nullable: false, default: self::STATUS_DRAFT, comment: '页面状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'varchar', length: 128, nullable: false, default: 'default', comment: '页面范围')]
    public const schema_fields_SCOPE = 'scope';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    #[Col(type: 'datetime', nullable: true, comment: '软删除时间')]
    public const schema_fields_DELETED_AT = 'deleted_at';

    public function getPageId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function getIdentifier(): string
    {
        return (string)($this->getData(self::schema_fields_IDENTIFIER) ?: '');
    }

    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::schema_fields_WEBSITE_ID);
    }

    public function getWebsiteCode(): string
    {
        return (string)($this->getData(self::schema_fields_WEBSITE_CODE) ?: 'default');
    }

    public function getPathGroup(): string
    {
        return (string)($this->getData(self::schema_fields_PATH_GROUP) ?: '');
    }

    public function getPathGroupAlias(): string
    {
        return (string)($this->getData(self::schema_fields_PATH_GROUP_ALIAS) ?: '');
    }

    public function getSlug(): string
    {
        return (string)($this->getData(self::schema_fields_SLUG) ?: '');
    }

    public function getTitle(): string
    {
        return (string)($this->getData(self::schema_fields_TITLE) ?: '');
    }

    public function getStatus(): string
    {
        return (string)($this->getData(self::schema_fields_STATUS) ?: self::STATUS_DRAFT);
    }

    public function getScope(): string
    {
        return (string)($this->getData(self::schema_fields_SCOPE) ?: 'default');
    }

    public function isPublished(): bool
    {
        return $this->getStatus() === self::STATUS_PUBLISHED;
    }

    public function isDeleted(): bool
    {
        return trim((string)$this->getData(self::schema_fields_DELETED_AT)) !== '';
    }

    /**
     * @return array<string,mixed>
     */
    public function toApiArray(): array
    {
        return [
            'page_id' => $this->getPageId(),
            'website_id' => $this->getWebsiteId(),
            'website_code' => $this->getWebsiteCode(),
            'site' => $this->getWebsiteCode(),
            'path_group' => $this->getPathGroup(),
            'path_group_alias' => $this->getPathGroupAlias(),
            'path_group_label' => $this->getPathGroupAlias() !== '' ? $this->getPathGroupAlias() : $this->getPathGroup(),
            'slug' => $this->getSlug(),
            'identifier' => $this->getIdentifier(),
            'path' => $this->getIdentifier(),
            'title' => $this->getTitle(),
            'status' => $this->getStatus(),
            'scope' => $this->getScope(),
            'created_at' => (string)($this->getData(self::schema_fields_CREATED_AT) ?: ''),
            'updated_at' => (string)($this->getData(self::schema_fields_UPDATED_AT) ?: ''),
            'deleted_at' => (string)($this->getData(self::schema_fields_DELETED_AT) ?: ''),
        ];
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
