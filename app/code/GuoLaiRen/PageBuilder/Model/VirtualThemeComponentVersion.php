<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * AI 建站虚拟主题组件版本：存储部件的历史版本
 */

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'PageBuilder AI建站虚拟主题组件版本')]
#[Index(name: 'idx_component', columns: ['component_id'], comment: '组件')]
class VirtualThemeComponentVersion extends Model
{
    public const schema_table = 'guolairen_pb_virtual_theme_component_version';
    public const schema_primary_key = 'version_id';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '版本主键')]
    public const schema_fields_ID = 'version_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: '组件ID')]
    public const schema_fields_COMPONENT_ID = 'component_id';

    #[Col(type: 'int', nullable: false, default: 1, comment: '版本号')]
    public const schema_fields_VERSION_NO = 'version_no';

    #[Col(type: 'varchar', length: 16, nullable: false, default: 'draft', comment: '状态')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'longtext', nullable: true, comment: '模板内容')]
    public const schema_fields_TEMPLATE_CONTENT = 'template_content';

    #[Col(type: 'longtext', nullable: true, comment: '默认配置JSON')]
    public const schema_fields_DEFAULT_CONFIG = 'default_config';

    #[Col(type: 'longtext', nullable: true, comment: '元数据JSON')]
    public const schema_fields_META = 'meta';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';

    public function getId(mixed $default = 0): int
    {
        return (int) ($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getComponentId(): int
    {
        return (int) ($this->getData(self::schema_fields_COMPONENT_ID) ?: 0);
    }

    public function setComponentId(int $componentId): static
    {
        return $this->setData(self::schema_fields_COMPONENT_ID, $componentId);
    }

    public function getVersionNo(): int
    {
        return (int) ($this->getData(self::schema_fields_VERSION_NO) ?: 1);
    }

    public function setVersionNo(int $versionNo): static
    {
        return $this->setData(self::schema_fields_VERSION_NO, $versionNo);
    }

    public function getStatus(): string
    {
        return (string) ($this->getData(self::schema_fields_STATUS) ?: self::STATUS_DRAFT);
    }

    public function setStatus(string $status): static
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }

    public function getTemplateContent(): string
    {
        return (string) ($this->getData(self::schema_fields_TEMPLATE_CONTENT) ?: '');
    }

    public function setTemplateContent(string $content): static
    {
        return $this->setData(self::schema_fields_TEMPLATE_CONTENT, $content);
    }

    public function getDefaultConfig(): array
    {
        $config = $this->getData(self::schema_fields_DEFAULT_CONFIG);
        if (\is_array($config)) {
            return $config;
        }
        if (\is_string($config) && $config !== '') {
            $decoded = json_decode($config, true);
            return \is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public function setDefaultConfig(array $config): static
    {
        return $this->setData(self::schema_fields_DEFAULT_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }

    public function getMeta(): array
    {
        $meta = $this->getData(self::schema_fields_META);
        if (\is_array($meta)) {
            return $meta;
        }
        if (\is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            return \is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public function setMeta(array $meta): static
    {
        return $this->setData(self::schema_fields_META, json_encode($meta, JSON_UNESCAPED_UNICODE));
    }

    public function getCreateTime(): string
    {
        return (string) ($this->getData(self::schema_fields_CREATE_TIME) ?: '');
    }
}
