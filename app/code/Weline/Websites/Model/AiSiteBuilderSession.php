<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Websites AI建站工作台会话')]
#[Index(name: 'idx_ai_site_builder_public_id', columns: ['public_id'], comment: '对外会话令牌')]
#[Index(name: 'idx_ai_site_builder_admin_user', columns: ['admin_user_id'], comment: '后台用户')]
#[Index(name: 'idx_ai_site_builder_provider', columns: ['provider_code'], comment: '流程提供者')]
#[Index(name: 'idx_ai_site_builder_update_time', columns: ['update_time'], comment: '最近更新时间')]
class AiSiteBuilderSession extends Model
{
    public const schema_table = 'weline_websites_ai_site_builder_session';
    public const schema_primary_key = 'ai_site_builder_session_id';

    public const STAGE_BRIEF = 'brief';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '会话主键')]
    public const schema_fields_ID = 'ai_site_builder_session_id';

    #[Col(type: 'varchar', length: 32, nullable: false, unique: true, comment: '对外会话令牌')]
    public const schema_fields_PUBLIC_ID = 'public_id';

    #[Col(type: 'int', nullable: false, comment: '后台用户ID')]
    public const schema_fields_ADMIN_USER_ID = 'admin_user_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: '流程提供者编码')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';

    #[Col(type: 'varchar', length: 64, nullable: false, default: self::STAGE_BRIEF, comment: '当前阶段')]
    public const schema_fields_CURRENT_STAGE = 'current_stage';

    #[Col(type: 'int', nullable: false, default: 0, comment: '关联网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '已选域名')]
    public const schema_fields_SELECTED_DOMAIN = 'selected_domain';

    #[Col(type: 'int', nullable: false, default: 0, comment: '域名注册账户ID')]
    public const schema_fields_REGISTRAR_ACCOUNT_ID = 'registrar_account_id';

    #[Col(type: 'varchar', length: 1024, nullable: false, default: '', comment: '预览地址')]
    public const schema_fields_PREVIEW_URL = 'preview_url';

    #[Col(type: 'longtext', nullable: true, comment: '会话 scope JSON')]
    public const schema_fields_SCOPE_JSON = 'scope_json';

    #[Col(type: 'longtext', nullable: true, comment: 'provider_state JSON')]
    public const schema_fields_PROVIDER_STATE_JSON = 'provider_state_json';

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
        return (string)($this->getData(self::schema_fields_PROVIDER_CODE) ?: '');
    }

    public function getCurrentStage(): string
    {
        return (string)($this->getData(self::schema_fields_CURRENT_STAGE) ?: self::STAGE_BRIEF);
    }

    public function getWebsiteId(): int
    {
        return (int)($this->getData(self::schema_fields_WEBSITE_ID) ?: 0);
    }

    public function getSelectedDomain(): string
    {
        return (string)($this->getData(self::schema_fields_SELECTED_DOMAIN) ?: '');
    }

    public function getRegistrarAccountId(): int
    {
        return (int)($this->getData(self::schema_fields_REGISTRAR_ACCOUNT_ID) ?: 0);
    }

    public function getPreviewUrl(): string
    {
        return (string)($this->getData(self::schema_fields_PREVIEW_URL) ?: '');
    }

    /**
     * @return array<string, mixed>
     */
    public function getScopeArray(): array
    {
        return $this->decodeJsonField(self::schema_fields_SCOPE_JSON);
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function setScopeArray(array $scope): static
    {
        return $this->setData(self::schema_fields_SCOPE_JSON, $this->encodeJsonField($scope));
    }

    /**
     * @return array<string, mixed>
     */
    public function getProviderStateArray(): array
    {
        return $this->decodeJsonField(self::schema_fields_PROVIDER_STATE_JSON);
    }

    /**
     * @param array<string, mixed> $providerState
     */
    public function setProviderStateArray(array $providerState): static
    {
        return $this->setData(self::schema_fields_PROVIDER_STATE_JSON, $this->encodeJsonField($providerState));
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
