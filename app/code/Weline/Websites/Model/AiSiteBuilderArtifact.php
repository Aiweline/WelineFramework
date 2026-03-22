<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Websites AI建站工作台物料')]
#[Index(name: 'uk_ai_site_builder_artifact', columns: ['session_id', 'artifact_type', 'artifact_code'], type: 'UNIQUE')]
#[Index(name: 'idx_ai_site_builder_artifact_session_type', columns: ['session_id', 'artifact_type'], comment: '按会话与类型查询')]
class AiSiteBuilderArtifact extends Model
{
    public const schema_table = 'weline_websites_ai_site_builder_artifact';
    public const schema_primary_key = 'ai_site_builder_artifact_id';

    public const STATUS_READY = 'ready';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '物料主键')]
    public const schema_fields_ID = 'ai_site_builder_artifact_id';

    #[Col(type: 'int', nullable: false, comment: '会话ID')]
    public const schema_fields_SESSION_ID = 'session_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: '物料类型')]
    public const schema_fields_ARTIFACT_TYPE = 'artifact_type';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: '物料编码')]
    public const schema_fields_ARTIFACT_CODE = 'artifact_code';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '物料标题')]
    public const schema_fields_TITLE = 'title';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::STATUS_READY, comment: '物料状态')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'longtext', nullable: true, comment: '物料载荷 JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function save_before(): void
    {
        parent::save_before();

        $now = \date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATE_TIME, $now);
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATE_TIME, $now);
        }
    }

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getSessionId(): int
    {
        return (int)($this->getData(self::schema_fields_SESSION_ID) ?: 0);
    }

    public function getArtifactType(): string
    {
        return (string)($this->getData(self::schema_fields_ARTIFACT_TYPE) ?: '');
    }

    public function getArtifactCode(): string
    {
        return (string)($this->getData(self::schema_fields_ARTIFACT_CODE) ?: '');
    }

    public function getTitle(): string
    {
        return (string)($this->getData(self::schema_fields_TITLE) ?: '');
    }

    public function getStatus(): string
    {
        return (string)($this->getData(self::schema_fields_STATUS) ?: self::STATUS_READY);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayloadArray(): array
    {
        $raw = $this->getData(self::schema_fields_PAYLOAD_JSON);
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!\is_string($raw)) {
            return [];
        }
        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayloadArray(array $payload): static
    {
        $json = $payload === []
            ? '{}'
            : \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return $this->setData(self::schema_fields_PAYLOAD_JSON, $json);
    }
}
