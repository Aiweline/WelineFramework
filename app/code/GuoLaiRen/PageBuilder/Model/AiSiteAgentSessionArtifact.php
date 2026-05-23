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
    public const EXTERNAL_PAYLOAD_FILE_KEY = '_external_artifact_file';

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

        $value = $decoded['value'];
        if (\is_array($value) && isset($value[self::EXTERNAL_PAYLOAD_FILE_KEY])) {
            return $this->readExternalPayloadValue((string)$value[self::EXTERNAL_PAYLOAD_FILE_KEY]);
        }

        return $value;
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

        return $this->setPayloadDocumentJson($json);
    }

    public function setPayloadDocumentJson(string $json, ?string $hash = null, ?int $bytes = null): static
    {
        if (\trim($json) === '') {
            $json = '{"value":[]}';
        }

        $this->setData(self::schema_fields_PAYLOAD_JSON, $json);
        $this->setData(self::schema_fields_PAYLOAD_HASH, $hash !== null && $hash !== '' ? $hash : \sha1($json));
        $this->setData(self::schema_fields_PAYLOAD_BYTES, $bytes ?? \strlen($json));

        return $this;
    }

    private function readExternalPayloadValue(string $relativePath): mixed
    {
        $relativePath = \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, \ltrim($relativePath, '/\\'));
        if ($relativePath === '' || \str_contains($relativePath, '..') || !\defined('BP')) {
            return [];
        }

        $base = BP . 'var' . \DIRECTORY_SEPARATOR . 'pagebuilder' . \DIRECTORY_SEPARATOR . 'session-artifacts';
        $path = BP . $relativePath;
        $baseReal = \realpath($base);
        $pathReal = \realpath($path);
        if (!\is_string($baseReal) || !\is_string($pathReal)) {
            return [];
        }

        $basePrefix = \rtrim($baseReal, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
        if (!\str_starts_with($pathReal, $basePrefix) || !\is_file($pathReal)) {
            return [];
        }

        $raw = \file_get_contents($pathReal);
        if (!\is_string($raw) || \trim($raw) === '') {
            return [];
        }

        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) && \array_key_exists('value', $decoded) ? $decoded['value'] : [];
    }
}
