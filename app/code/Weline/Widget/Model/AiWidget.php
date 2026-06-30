<?php

declare(strict_types=1);

namespace Weline\Widget\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'AI 生成 Widget 表')]
#[Index(name: 'idx_widget_ai_code', columns: ['widget_code'], type: 'UNIQUE')]
#[Index(name: 'idx_widget_ai_active', columns: ['is_active'])]
#[Index(name: 'idx_widget_ai_type_slot', columns: ['type', 'slot'])]
class AiWidget extends Model
{
    public const schema_table = 'widget_ai_widget';
    public const schema_primary_key = 'ai_widget_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键ID')]
    public const schema_fields_ID = 'ai_widget_id';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Widget 代码')]
    public const schema_fields_WIDGET_CODE = 'widget_code';
    #[Col(type: 'varchar', length: 64, nullable: false, default: 'content', comment: 'Widget 类型')]
    public const schema_fields_TYPE = 'type';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'mediumtext', nullable: false, comment: '模板内容')]
    public const schema_fields_TEMPLATE_CONTENT = 'template_content';
    #[Col(type: 'mediumtext', nullable: true, comment: '参数定义JSON')]
    public const schema_fields_PARAMS_JSON = 'params_json';
    #[Col(type: 'mediumtext', nullable: true, comment: '默认配置JSON')]
    public const schema_fields_DEFAULT_CONFIG_JSON = 'default_config_json';
    #[Col(type: 'mediumtext', nullable: true, comment: '元信息JSON')]
    public const schema_fields_META_JSON = 'meta_json';
    #[Col(type: 'text', nullable: true, comment: '位置JSON')]
    public const schema_fields_POSITION_JSON = 'position_json';
    #[Col(type: 'text', nullable: true, comment: '页面布局JSON')]
    public const schema_fields_PAGE_LAYOUTS_JSON = 'page_layouts_json';
    #[Col(type: 'text', nullable: true, comment: 'Slot 协议JSON')]
    public const schema_fields_SUPPORTS_JSON = 'supports_json';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: '主要 Slot')]
    public const schema_fields_SLOT = 'slot';
    #[Col(type: 'mediumtext', nullable: true, comment: '内部 Slots JSON')]
    public const schema_fields_SLOTS_JSON = 'slots_json';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否独占')]
    public const schema_fields_EXCLUSIVE = 'exclusive';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '是否兼容')]
    public const schema_fields_COMPATIBLE = 'compatible';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否容器部件')]
    public const schema_fields_IS_CONTAINER = 'is_container';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'mediumtext', nullable: true, comment: '生成提示词')]
    public const schema_fields_PROMPT = 'prompt';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: '智能体代码')]
    public const schema_fields_AGENT_CODE = 'agent_code';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: '模型代码')]
    public const schema_fields_MODEL_CODE = 'model_code';
    #[Col(type: 'mediumtext', nullable: true, comment: '校验结果JSON')]
    public const schema_fields_VALIDATION_JSON = 'validation_json';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public array $_unit_primary_keys = [self::schema_fields_ID];

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getWidgetCode(): string
    {
        return (string)($this->getData(self::schema_fields_WIDGET_CODE) ?: '');
    }

    public function getParams(): array
    {
        return $this->decodeJsonField(self::schema_fields_PARAMS_JSON);
    }

    public function getDefaultConfig(): array
    {
        return $this->decodeJsonField(self::schema_fields_DEFAULT_CONFIG_JSON);
    }

    public function getMeta(): array
    {
        return $this->decodeJsonField(self::schema_fields_META_JSON);
    }

    public function getPosition(): array
    {
        return $this->decodeJsonField(self::schema_fields_POSITION_JSON);
    }

    public function getPageLayouts(): array
    {
        return $this->decodeJsonField(self::schema_fields_PAGE_LAYOUTS_JSON);
    }

    public function getSupports(): array
    {
        return $this->decodeJsonField(self::schema_fields_SUPPORTS_JSON);
    }

    public function getSlots(): array
    {
        return $this->decodeJsonField(self::schema_fields_SLOTS_JSON);
    }

    private function decodeJsonField(string $field): array
    {
        $raw = $this->getData($field);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
