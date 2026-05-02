<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'PageBuilder AI virtual theme component version')]
#[Index(name: 'idx_virtual_theme_component_version_component', columns: ['component_id'], comment: 'Component version lookup')]
#[Index(name: 'idx_virtual_theme_component_version_no', columns: ['component_id', 'version_no'], comment: 'Component version number lookup')]
class VirtualThemeComponentVersion extends Model
{
    public const schema_table = 'guolairen_pb_virtual_theme_component_version';
    public const schema_primary_key = 'version_id';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Version ID')]
    public const schema_fields_ID = 'version_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Component ID')]
    public const schema_fields_COMPONENT_ID = 'component_id';

    #[Col(type: 'int', nullable: false, default: 1, comment: 'Version number')]
    public const schema_fields_VERSION_NO = 'version_no';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::STATUS_PUBLISHED, comment: 'Version status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'mediumtext', nullable: true, comment: 'PHTML template content')]
    public const schema_fields_TEMPLATE_CONTENT = 'template_content';

    #[Col(type: 'longtext', nullable: true, comment: 'Default config JSON')]
    public const schema_fields_DEFAULT_CONFIG = 'default_config';

    #[Col(type: 'longtext', nullable: true, comment: 'Metadata JSON')]
    public const schema_fields_META_JSON = 'meta';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATE_TIME = 'create_time';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getComponentId(): int
    {
        return (int)($this->getData(self::schema_fields_COMPONENT_ID) ?: 0);
    }

    public function setComponentId(int $componentId): static
    {
        return $this->setData(self::schema_fields_COMPONENT_ID, $componentId);
    }

    public function getVersionNo(): int
    {
        return (int)($this->getData(self::schema_fields_VERSION_NO) ?: 1);
    }

    public function setVersionNo(int $versionNo): static
    {
        return $this->setData(self::schema_fields_VERSION_NO, \max(1, $versionNo));
    }

    public function getStatus(): string
    {
        return (string)($this->getData(self::schema_fields_STATUS) ?: self::STATUS_PUBLISHED);
    }

    public function setStatus(string $status): static
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }

    public function getTemplateContent(): string
    {
        return (string)($this->getData(self::schema_fields_TEMPLATE_CONTENT) ?: '');
    }

    public function setTemplateContent(string $content): static
    {
        return $this->setData(self::schema_fields_TEMPLATE_CONTENT, $content);
    }

    public function getDefaultConfig(): array
    {
        return $this->decodeArrayField(self::schema_fields_DEFAULT_CONFIG);
    }

    public function setDefaultConfig(array $config): static
    {
        return $this->setData(self::schema_fields_DEFAULT_CONFIG, self::encodeJson($config));
    }

    public function getMeta(): array
    {
        return $this->decodeArrayField(self::schema_fields_META_JSON);
    }

    public function setMeta(array $meta): static
    {
        return $this->setData(self::schema_fields_META_JSON, self::encodeJson($meta));
    }

    public function getCreateTime(): string
    {
        return (string)($this->getData(self::schema_fields_CREATE_TIME) ?: '');
    }

    public function getUpdateTime(): string
    {
        return (string)($this->getData(self::schema_fields_UPDATE_TIME) ?: '');
    }

    private function decodeArrayField(string $field): array
    {
        $raw = $this->getData($field);
        if (\is_array($raw)) {
            return $raw;
        }
        if (!\is_string($raw) || \trim($raw) === '') {
            return [];
        }

        $decoded = \json_decode($raw, true);
        return \is_array($decoded) ? $decoded : [];
    }

    private static function encodeJson(array $payload): string
    {
        $json = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
        return \is_string($json) ? $json : '{}';
    }
}
