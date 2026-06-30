<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Theme 虚拟布局版本表')]
#[Index(name: 'idx_theme_virtual_layout_version_asset', columns: ['virtual_layout_id', 'version_no'], type: 'KEY', comment: '虚拟布局版本索引')]
#[Index(name: 'idx_theme_virtual_layout_version_status', columns: ['virtual_layout_id', 'status'], type: 'KEY', comment: '虚拟布局版本状态索引')]
class ThemeVirtualLayoutVersion extends Model
{
    public const schema_table = 'theme_virtual_layout_version';
    public const schema_primary_key = 'version_id';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '版本ID')]
    public const schema_fields_ID = 'version_id';
    #[Col(type: 'int', nullable: false, comment: '虚拟布局ID')]
    public const schema_fields_VIRTUAL_LAYOUT_ID = 'virtual_layout_id';
    #[Col(type: 'int', nullable: false, default: 1, comment: '版本号')]
    public const schema_fields_VERSION_NO = 'version_no';
    #[Col(type: 'varchar', length: 32, nullable: false, default: self::STATUS_DRAFT, comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'mediumtext', nullable: false, comment: '布局源码')]
    public const schema_fields_SOURCE_CODE = 'source_code';
    #[Col(type: 'mediumtext', nullable: true, comment: '可视化结构JSON')]
    public const schema_fields_VISUAL_SCHEMA_JSON = 'visual_schema_json';
    #[Col(type: 'mediumtext', nullable: true, comment: 'AI Prompt')]
    public const schema_fields_AI_PROMPT = 'ai_prompt';
    #[Col(type: 'mediumtext', nullable: true, comment: '生成元数据JSON')]
    public const schema_fields_GENERATION_META_JSON = 'generation_meta_json';
    #[Col(type: 'mediumtext', nullable: true, comment: '校验结果JSON')]
    public const schema_fields_VALIDATION_JSON = 'validation_json';
    #[Col(type: 'int', nullable: true, comment: '父版本ID')]
    public const schema_fields_PARENT_VERSION_ID = 'parent_version_id';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: '操作者ID')]
    public const schema_fields_ACTOR_ID = 'actor_id';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: '操作者名称')]
    public const schema_fields_ACTOR_NAME = 'actor_name';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '变更原因')]
    public const schema_fields_REASON = 'reason';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getVirtualLayoutId(): int
    {
        return (int)($this->getData(self::schema_fields_VIRTUAL_LAYOUT_ID) ?: 0);
    }

    public function setVirtualLayoutId(int $virtualLayoutId): static
    {
        return $this->setData(self::schema_fields_VIRTUAL_LAYOUT_ID, $virtualLayoutId);
    }

    public function getVersionNo(): int
    {
        return (int)($this->getData(self::schema_fields_VERSION_NO) ?: 1);
    }

    public function setVersionNo(int $versionNo): static
    {
        return $this->setData(self::schema_fields_VERSION_NO, $versionNo);
    }

    public function getStatus(): string
    {
        return (string)($this->getData(self::schema_fields_STATUS) ?: self::STATUS_DRAFT);
    }

    public function setStatus(string $status): static
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }

    public function getSourceCode(): string
    {
        return (string)($this->getData(self::schema_fields_SOURCE_CODE) ?: '');
    }

    public function setSourceCode(string $sourceCode): static
    {
        return $this->setData(self::schema_fields_SOURCE_CODE, $sourceCode);
    }

    public function getVisualSchema(): array
    {
        return $this->decodeJsonField(self::schema_fields_VISUAL_SCHEMA_JSON);
    }

    public function setVisualSchema(array $visualSchema): static
    {
        return $this->setData(self::schema_fields_VISUAL_SCHEMA_JSON, json_encode($visualSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function getGenerationMeta(): array
    {
        return $this->decodeJsonField(self::schema_fields_GENERATION_META_JSON);
    }

    public function setGenerationMeta(array $generationMeta): static
    {
        return $this->setData(self::schema_fields_GENERATION_META_JSON, json_encode($generationMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function getValidation(): array
    {
        return $this->decodeJsonField(self::schema_fields_VALIDATION_JSON);
    }

    public function setValidation(array $validation): static
    {
        return $this->setData(self::schema_fields_VALIDATION_JSON, json_encode($validation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function setAiPrompt(string $aiPrompt): static
    {
        return $this->setData(self::schema_fields_AI_PROMPT, $aiPrompt);
    }

    public function setParentVersionId(?int $parentVersionId): static
    {
        return $this->setData(self::schema_fields_PARENT_VERSION_ID, $parentVersionId ?: null);
    }

    public function setActorId(string $actorId): static
    {
        return $this->setData(self::schema_fields_ACTOR_ID, $actorId);
    }

    public function setActorName(string $actorName): static
    {
        return $this->setData(self::schema_fields_ACTOR_NAME, $actorName);
    }

    public function setReason(string $reason): static
    {
        return $this->setData(self::schema_fields_REASON, $reason);
    }

    public function save_before(): void
    {
        parent::save_before();
        $now = date('Y-m-d H:i:s');
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATE_TIME, $now);
        }
        $this->setData(self::schema_fields_UPDATE_TIME, $now);
    }

    private function decodeJsonField(string $field): array
    {
        $value = $this->getData($field);
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
