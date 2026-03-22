<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Websites AI建站工作台消息')]
#[Index(name: 'idx_ai_site_builder_message_session_id', columns: ['session_id', 'create_time'], comment: '按会话拉取消息')]
class AiSiteBuilderMessage extends Model
{
    public const schema_table = 'weline_websites_ai_site_builder_message';
    public const schema_primary_key = 'ai_site_builder_message_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '消息主键')]
    public const schema_fields_ID = 'ai_site_builder_message_id';

    #[Col(type: 'int', nullable: false, comment: '会话ID')]
    public const schema_fields_SESSION_ID = 'session_id';

    #[Col(type: 'varchar', length: 32, nullable: false, comment: '消息角色')]
    public const schema_fields_ROLE = 'role';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'message', comment: '消息类型')]
    public const schema_fields_MESSAGE_TYPE = 'message_type';

    #[Col(type: 'longtext', nullable: false, comment: '消息内容')]
    public const schema_fields_CONTENT = 'content';

    #[Col(type: 'longtext', nullable: true, comment: '工具载荷 JSON')]
    public const schema_fields_TOOL_PAYLOAD_JSON = 'tool_payload_json';

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

    public function getRole(): string
    {
        return (string)($this->getData(self::schema_fields_ROLE) ?: '');
    }

    public function getMessageType(): string
    {
        return (string)($this->getData(self::schema_fields_MESSAGE_TYPE) ?: 'message');
    }

    public function getContent(): string
    {
        return (string)($this->getData(self::schema_fields_CONTENT) ?: '');
    }

    /**
     * @return array<string, mixed>
     */
    public function getToolPayloadArray(): array
    {
        $raw = $this->getData(self::schema_fields_TOOL_PAYLOAD_JSON);
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
     * @param array<string, mixed> $toolPayload
     */
    public function setToolPayloadArray(array $toolPayload): static
    {
        $json = $toolPayload === []
            ? '{}'
            : \json_encode($toolPayload, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return $this->setData(self::schema_fields_TOOL_PAYLOAD_JSON, $json);
    }
}
