<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'PageBuilder AI site session staged artifact')]
#[Index(name: 'idx_session_stage_key', columns: ['agent_session_id', 'stage_code', 'artifact_key'], type: 'UNIQUE', comment: 'One artifact per session stage key')]
#[Index(name: 'idx_session_stage', columns: ['agent_session_id', 'stage_code'], comment: 'Stage artifact lookup')]
class AiSiteAgentSessionArtifact extends Model
{
    public const schema_table = 'guolairen_page_builder_ai_site_agent_artifact';
    public const schema_primary_key = 'ai_site_agent_artifact_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Artifact ID')]
    public const schema_fields_ID = 'ai_site_agent_artifact_id';

    #[Col(type: 'int', nullable: false, comment: 'AI site session ID')]
    public const schema_fields_AGENT_SESSION_ID = 'agent_session_id';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Stage code')]
    public const schema_fields_STAGE_CODE = 'stage_code';

    #[Col(type: 'varchar', length: 96, nullable: false, default: '', comment: 'Artifact key')]
    public const schema_fields_ARTIFACT_KEY = 'artifact_key';

    #[Col(type: 'longtext', nullable: true, comment: 'Artifact payload JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Payload SHA1 hash')]
    public const schema_fields_PAYLOAD_HASH = 'payload_hash';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Payload JSON bytes')]
    public const schema_fields_PAYLOAD_BYTES = 'payload_bytes';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATE_TIME = 'create_time';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getStageCode(): string
    {
        return (string)($this->getData(self::schema_fields_STAGE_CODE) ?: '');
    }

    public function getArtifactKey(): string
    {
        return (string)($this->getData(self::schema_fields_ARTIFACT_KEY) ?: '');
    }

    public function getPayloadHash(): string
    {
        return (string)($this->getData(self::schema_fields_PAYLOAD_HASH) ?: '');
    }

    public function getPayloadBytes(): int
    {
        return (int)($this->getData(self::schema_fields_PAYLOAD_BYTES) ?: 0);
    }

    public function getPayloadValue(): mixed
    {
        $raw = $this->getData(self::schema_fields_PAYLOAD_JSON);
        if (!\is_string($raw) || \trim($raw) === '') {
            return [];
        }

        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded) || !\array_key_exists('value', $decoded)) {
            return [];
        }

        return $decoded['value'];
    }

    public function setPayloadValue(mixed $payload): static
    {
        $document = ['value' => $payload];
        try {
            $json = (string)\json_encode(
                $document,
                \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            $json = '{"value":[]}';
        }

        $this->setData(self::schema_fields_PAYLOAD_JSON, $json);
        $this->setData(self::schema_fields_PAYLOAD_HASH, \sha1($json));
        $this->setData(self::schema_fields_PAYLOAD_BYTES, \strlen($json));

        return $this;
    }
}
