<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Websites AI建站工作台事件流')]
#[Index(name: 'idx_ai_site_builder_event_session_id', columns: ['session_id', 'create_time'], comment: '按会话拉取事件')]
class AiSiteBuilderEvent extends Model
{
    public const schema_table = 'weline_websites_ai_site_builder_event';
    public const schema_primary_key = 'ai_site_builder_event_id';

    public const LEVEL_INFO = 'info';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '事件主键')]
    public const schema_fields_ID = 'ai_site_builder_event_id';

    #[Col(type: 'int', nullable: false, comment: '会话ID')]
    public const schema_fields_SESSION_ID = 'session_id';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: '阶段编码')]
    public const schema_fields_STAGE_CODE = 'stage_code';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: '事件类型')]
    public const schema_fields_EVENT_TYPE = 'event_type';

    #[Col(type: 'varchar', length: 16, nullable: false, default: self::LEVEL_INFO, comment: '级别')]
    public const schema_fields_LEVEL = 'level';

    #[Col(type: 'longtext', nullable: true, comment: '事件载荷 JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getSessionId(): int
    {
        return (int)($this->getData(self::schema_fields_SESSION_ID) ?: 0);
    }

    public function getStageCode(): string
    {
        return (string)($this->getData(self::schema_fields_STAGE_CODE) ?: '');
    }

    public function getEventType(): string
    {
        return (string)($this->getData(self::schema_fields_EVENT_TYPE) ?: '');
    }

    public function getLevel(): string
    {
        return (string)($this->getData(self::schema_fields_LEVEL) ?: self::LEVEL_INFO);
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
