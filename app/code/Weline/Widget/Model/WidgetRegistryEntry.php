<?php

declare(strict_types=1);

namespace Weline\Widget\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '普通文件 Widget 注册账本')]
#[Index(name: 'uk_widget_registry_entry_identity', columns: ['widget_area', 'widget_module', 'widget_type', 'widget_code'], type: 'UNIQUE')]
#[Index(name: 'idx_widget_registry_entry_module', columns: ['widget_module'])]
#[Index(name: 'idx_widget_registry_entry_default', columns: ['has_default_injections'])]
class WidgetRegistryEntry extends Model
{
    public const schema_table = 'widget_registry_entry';
    public const schema_primary_key = 'registry_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '注册ID')]
    public const schema_fields_ID = 'registry_id';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'frontend', comment: 'Widget 区域')]
    public const schema_fields_WIDGET_AREA = 'widget_area';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: 'Widget 模块')]
    public const schema_fields_WIDGET_MODULE = 'widget_module';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Widget 类型')]
    public const schema_fields_WIDGET_TYPE = 'widget_type';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Widget 代码')]
    public const schema_fields_WIDGET_CODE = 'widget_code';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Widget 名称')]
    public const schema_fields_WIDGET_NAME = 'widget_name';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: 'Widget 描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '模板路径')]
    public const schema_fields_TEMPLATE = 'template';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '来源文件')]
    public const schema_fields_WIDGET_FILE = 'widget_file';
    #[Col(type: 'varchar', length: 50, nullable: true, comment: 'Widget 版本')]
    public const schema_fields_VERSION = 'version';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '注册内容 Hash')]
    public const schema_fields_CONFIG_HASH = 'config_hash';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否声明默认注入')]
    public const schema_fields_HAS_DEFAULT_INJECTIONS = 'has_default_injections';
    #[Col(type: 'mediumtext', nullable: true, comment: '默认注入 JSON')]
    public const schema_fields_DEFAULT_INJECTIONS_JSON = 'default_injections_json';
    #[Col(type: 'mediumtext', nullable: true, comment: '完整注册 JSON')]
    public const schema_fields_REGISTRY_JSON = 'registry_json';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: '最近收集来源')]
    public const schema_fields_COLLECTION_SOURCE = 'collection_source';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '是否有效')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '首次收集时间')]
    public const schema_fields_FIRST_COLLECTED_AT = 'first_collected_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '最近收集时间')]
    public const schema_fields_LAST_COLLECTED_AT = 'last_collected_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];

    public function save_before(): void
    {
        parent::save_before();
        $now = date('Y-m-d H:i:s');
        if (!$this->getData(self::schema_fields_FIRST_COLLECTED_AT)) {
            $this->setData(self::schema_fields_FIRST_COLLECTED_AT, $now);
        }
        if (!$this->getData(self::schema_fields_LAST_COLLECTED_AT)) {
            $this->setData(self::schema_fields_LAST_COLLECTED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }

    public function getRegistryId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }
}
