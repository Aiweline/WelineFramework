<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'AI site plan draft versions')]
#[Index(name: 'idx_ai_site_plan_version_draft', columns: ['draft_id', 'version_no'])]
#[Index(name: 'idx_ai_site_plan_version_create_time', columns: ['create_time'])]
class AiSitePlanVersion extends Model
{
    public const schema_table = 'weline_websites_ai_site_plan_version';
    public const schema_primary_key = 'ai_site_plan_version_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Primary key')]
    public const schema_fields_ID = 'ai_site_plan_version_id';

    #[Col(type: 'int', nullable: false, comment: 'Draft id')]
    public const schema_fields_DRAFT_ID = 'draft_id';

    #[Col(type: 'int', nullable: false, default: 1, comment: 'Version number')]
    public const schema_fields_VERSION_NO = 'version_no';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'generate', comment: 'Source type')]
    public const schema_fields_SOURCE_TYPE = 'source_type';

    #[Col(type: 'longtext', nullable: true, comment: 'Prompt or user instruction text')]
    public const schema_fields_SOURCE_MESSAGE = 'source_message';

    #[Col(type: 'longtext', nullable: true, comment: 'Version payload JSON')]
    public const schema_fields_PLAN_JSON = 'plan_json';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATE_TIME = 'create_time';

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getDraftId(): int
    {
        return (int)($this->getData(self::schema_fields_DRAFT_ID) ?: 0);
    }

    public function getVersionNo(): int
    {
        return (int)($this->getData(self::schema_fields_VERSION_NO) ?: 1);
    }

    public function getSourceType(): string
    {
        return (string)($this->getData(self::schema_fields_SOURCE_TYPE) ?: 'generate');
    }

    public function getSourceMessage(): string
    {
        return (string)($this->getData(self::schema_fields_SOURCE_MESSAGE) ?: '');
    }

    /**
     * @return array<string, mixed>
     */
    public function getPlanArray(): array
    {
        $raw = $this->getData(self::schema_fields_PLAN_JSON);
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
    public function setPlanArray(array $payload): static
    {
        $json = $payload === []
            ? '{}'
            : \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return $this->setData(self::schema_fields_PLAN_JSON, $json);
    }
}
