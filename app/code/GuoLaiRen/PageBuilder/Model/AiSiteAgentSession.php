<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * AI 建站工作台会话：持久化 scope、阶段、站点/虚拟主题关联与发布状态
 */

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'PageBuilder AI建站工作台会话')]
#[Index(name: 'idx_public_id', columns: ['public_id'], comment: '对外令牌')]
#[Index(name: 'idx_admin_user', columns: ['admin_user_id'], comment: '后台用户')]
#[Index(name: 'idx_website', columns: ['website_id'], comment: '站点')]
#[Index(name: 'idx_virtual_theme', columns: ['virtual_theme_id'], comment: 'PageBuilder 虚拟主题')]
class AiSiteAgentSession extends Model
{
    public const schema_table = 'guolairen_page_builder_ai_site_agent_session';
    public const schema_primary_key = 'ai_site_agent_session_id';

    public const STAGE_BRIEF = 'brief';
    public const STAGE_DOMAIN = 'domain';
    public const STAGE_DOMAIN_WAIT = 'domain_wait';
    public const STAGE_PLAN = 'plan';
    public const STAGE_PAGE_TYPES = 'page_types';
    public const STAGE_CONTENT = 'content';
    public const STAGE_VISUAL_EDIT = 'visual_edit';
    public const STAGE_PUBLISH = 'publish';

    public const PUBLISH_STATUS_DRAFT = 'draft';
    public const PUBLISH_STATUS_PUBLISHING = 'publishing';
    public const PUBLISH_STATUS_PUBLISHED = 'published';
    public const PUBLISH_STATUS_FAILED = 'failed';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '会话主键')]
    public const schema_fields_ID = 'ai_site_agent_session_id';

    #[Col(type: 'varchar', length: 32, nullable: false, unique: true, comment: '对外会话令牌(前端/API)')]
    public const schema_fields_PUBLIC_ID = 'public_id';

    #[Col(type: 'int', nullable: false, comment: '后台用户ID')]
    public const schema_fields_ADMIN_USER_ID = 'admin_user_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: '关联站点ID，0 表示未绑定')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'PageBuilder 虚拟主题 ID，0 表示未创建')]
    public const schema_fields_VIRTUAL_THEME_ID = 'virtual_theme_id';

    #[Col(type: 'varchar', length: 64, nullable: false, default: self::STAGE_BRIEF, comment: '当前流程阶段')]
    public const schema_fields_STAGE = 'stage';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::PUBLISH_STATUS_DRAFT, comment: '发布状态')]
    public const schema_fields_PUBLISH_STATUS = 'publish_status';

    #[Col(type: 'longtext', nullable: true, comment: 'Scope JSON（站点简报、域名、页面类型、多语言片段等）')]
    public const schema_fields_SCOPE_JSON = 'scope_json';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function getId(mixed $default = 0): int
    {
        return (int) ($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getPublicId(): string
    {
        return (string) ($this->getData(self::schema_fields_PUBLIC_ID) ?: '');
    }

    public function getAdminUserId(): int
    {
        return (int) ($this->getData(self::schema_fields_ADMIN_USER_ID) ?: 0);
    }

    public function getWebsiteId(): int
    {
        return (int) ($this->getData(self::schema_fields_WEBSITE_ID) ?: 0);
    }

    public function getVirtualThemeId(): int
    {
        return (int) ($this->getData(self::schema_fields_VIRTUAL_THEME_ID) ?: 0);
    }

    public function setVirtualThemeId(int $themeId): static
    {
        return $this->setData(self::schema_fields_VIRTUAL_THEME_ID, $themeId);
    }

    public function getStage(): string
    {
        return (string) ($this->getData(self::schema_fields_STAGE) ?: self::STAGE_BRIEF);
    }

    public function getPublishStatus(): string
    {
        return (string) ($this->getData(self::schema_fields_PUBLISH_STATUS) ?: self::PUBLISH_STATUS_DRAFT);
    }

    /**
     * @return array<string, mixed>
     */
    public function getScopeArray(): array
    {
        $raw = $this->getData(self::schema_fields_SCOPE_JSON);
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
     * @param array<string, mixed> $scope
     */
    public function setScopeArray(array $scope): static
    {
        $json = $scope === [] ? '{}' : \json_encode($scope, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        return $this->setData(self::schema_fields_SCOPE_JSON, $json);
    }
}
