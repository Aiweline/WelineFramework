<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'AI site plan drafts before formal workbench session creation')]
#[Index(name: 'uk_ai_site_plan_draft_public_id', columns: ['public_id'], type: 'UNIQUE')]
#[Index(name: 'idx_ai_site_plan_draft_admin_user', columns: ['admin_user_id'])]
#[Index(name: 'idx_ai_site_plan_draft_status', columns: ['status'])]
#[Index(name: 'idx_ai_site_plan_draft_update_time', columns: ['update_time'])]
class AiSitePlanDraft extends Model
{
    public const schema_table = 'weline_websites_ai_site_plan_draft';
    public const schema_primary_key = 'ai_site_plan_draft_id';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_CANCELLED = 'cancelled';

    public const DOMAIN_SOURCE_NONE = '';
    public const DOMAIN_SOURCE_RECOMMENDED = 'recommended';
    public const DOMAIN_SOURCE_MANUAL = 'manual';
    public const DOMAIN_SOURCE_LOCAL_POOL = 'local_pool';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Primary key')]
    public const schema_fields_ID = 'ai_site_plan_draft_id';

    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Public draft token')]
    public const schema_fields_PUBLIC_ID = 'public_id';

    #[Col(type: 'int', nullable: false, comment: 'Admin user id')]
    public const schema_fields_ADMIN_USER_ID = 'admin_user_id';

    #[Col(type: 'varchar', length: 64, nullable: false, default: 'pagebuilder', comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::STATUS_DRAFT, comment: 'Draft status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Current confirmed version id')]
    public const schema_fields_CURRENT_VERSION_ID = 'current_version_id';

    #[Col(type: 'varchar', length: 1024, nullable: false, default: '', comment: 'Selected domain')]
    public const schema_fields_SELECTED_DOMAIN = 'selected_domain';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::DOMAIN_SOURCE_NONE, comment: 'Selected domain source')]
    public const schema_fields_SELECTED_DOMAIN_SOURCE = 'selected_domain_source';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Selected local pool id')]
    public const schema_fields_SELECTED_POOL_ID = 'selected_pool_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Preferred registrar account id')]
    public const schema_fields_REGISTRAR_ACCOUNT_ID = 'registrar_account_id';

    #[Col(type: 'varchar', length: 64, nullable: false, default: 'pagebuilder_style', comment: 'Selected build mode')]
    public const schema_fields_BUILD_MODE = 'build_mode';

    #[Col(type: 'longtext', nullable: true, comment: 'Draft payload JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATE_TIME = 'create_time';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function save_before(): void
    {
        parent::save_before();

        $now = \date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATE_TIME, $now);
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATE_TIME, $now);
        }

        $selectedDomain = \trim((string)$this->getData(self::schema_fields_SELECTED_DOMAIN));
        if ($selectedDomain !== '') {
            $this->setData(self::schema_fields_SELECTED_DOMAIN, \strtolower($selectedDomain));
        }
    }

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getPublicId(): string
    {
        return (string)($this->getData(self::schema_fields_PUBLIC_ID) ?: '');
    }

    public function getAdminUserId(): int
    {
        return (int)($this->getData(self::schema_fields_ADMIN_USER_ID) ?: 0);
    }

    public function getProviderCode(): string
    {
        return (string)($this->getData(self::schema_fields_PROVIDER_CODE) ?: 'pagebuilder');
    }

    public function getStatus(): string
    {
        return (string)($this->getData(self::schema_fields_STATUS) ?: self::STATUS_DRAFT);
    }

    public function getCurrentVersionId(): int
    {
        return (int)($this->getData(self::schema_fields_CURRENT_VERSION_ID) ?: 0);
    }

    public function getSelectedDomain(): string
    {
        return (string)($this->getData(self::schema_fields_SELECTED_DOMAIN) ?: '');
    }

    public function getSelectedDomainSource(): string
    {
        return (string)($this->getData(self::schema_fields_SELECTED_DOMAIN_SOURCE) ?: self::DOMAIN_SOURCE_NONE);
    }

    public function getSelectedPoolId(): int
    {
        return (int)($this->getData(self::schema_fields_SELECTED_POOL_ID) ?: 0);
    }

    public function getRegistrarAccountId(): int
    {
        return (int)($this->getData(self::schema_fields_REGISTRAR_ACCOUNT_ID) ?: 0);
    }

    public function getBuildMode(): string
    {
        return (string)($this->getData(self::schema_fields_BUILD_MODE) ?: 'pagebuilder_style');
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayloadArray(): array
    {
        return $this->decodeJsonField(self::schema_fields_PAYLOAD_JSON);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayloadArray(array $payload): static
    {
        return $this->setData(self::schema_fields_PAYLOAD_JSON, $this->encodeJsonField($payload));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonField(string $field): array
    {
        $raw = $this->getData($field);
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
    private function encodeJsonField(array $payload): string
    {
        return $payload === []
            ? '{}'
            : \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
