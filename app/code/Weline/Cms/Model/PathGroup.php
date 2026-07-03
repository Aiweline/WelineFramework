<?php
declare(strict_types=1);

namespace Weline\Cms\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'CMS 一级路径分组表')]
#[Index(name: 'uk_cms_path_group_site_path', columns: ['website_id', 'path_group'], type: 'UNIQUE', comment: '站点内一级路径唯一索引')]
#[Index(name: 'idx_cms_path_group_site', columns: ['website_id', 'deleted_at'], type: 'KEY', comment: '站点路径组查询索引')]
class PathGroup extends Model
{
    public const schema_table = 'weline_cms_path_group';
    public const schema_primary_key = 'group_id';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_WEBSITE_ID,
        self::schema_fields_PATH_GROUP,
    ];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '路径组ID')]
    public const schema_fields_ID = 'group_id';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '所属站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'varchar', length: 128, nullable: false, default: 'default', comment: '所属站点代码')]
    public const schema_fields_WEBSITE_CODE = 'website_code';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '一级路径')]
    public const schema_fields_PATH_GROUP = 'path_group';
    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '一级路径显示别名')]
    public const schema_fields_ALIAS = 'alias';
    #[Col(type: 'int', length: 11, nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    #[Col(type: 'datetime', nullable: true, comment: '软删除时间')]
    public const schema_fields_DELETED_AT = 'deleted_at';

    public function getGroupId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
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

    public function getAlias(): string
    {
        return (string)($this->getData(self::schema_fields_ALIAS) ?: '');
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
        $pathGroup = $this->getPathGroup();
        $alias = $this->getAlias();

        return [
            'group_id' => $this->getGroupId(),
            'website_id' => $this->getWebsiteId(),
            'website_code' => $this->getWebsiteCode(),
            'site' => $this->getWebsiteCode(),
            'path_group' => $pathGroup,
            'alias' => $alias,
            'path_group_alias' => $alias,
            'label' => ($alias !== '' ? $alias : $pathGroup) . ' / ' . $pathGroup,
            'sort_order' => (int)$this->getData(self::schema_fields_SORT_ORDER),
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
