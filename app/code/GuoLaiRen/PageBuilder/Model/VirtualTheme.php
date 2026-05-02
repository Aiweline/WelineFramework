<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'PageBuilder AI建站虚拟主题')]
#[Index(name: 'idx_session', columns: ['session_id'], comment: '会话')]
#[Index(name: 'idx_website', columns: ['website_id'], comment: '站点')]
class VirtualTheme extends Model
{
    public const schema_table = 'guolairen_pb_virtual_theme';
    public const schema_primary_key = 'virtual_theme_id';

    public const SOURCE_PAGEBUILDER_AI = 'pagebuilder_ai_site_agent';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '虚拟主题主键')]
    public const schema_fields_ID = 'virtual_theme_id';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: '主题名称')]
    public const schema_fields_NAME = 'name';

    #[Col(type: 'int', nullable: false, default: 0, comment: '关联会话ID')]
    public const schema_fields_SESSION_ID = 'session_id';

    #[Col(type: 'int', nullable: false, default: 0, comment: '关联站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col(type: 'varchar', length: 128, nullable: true, comment: '主题路径')]
    public const schema_fields_PATH = 'path';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'pagebuilder_ai_site_agent', comment: '来源')]
    public const schema_fields_SOURCE = 'source';

    #[Col(type: 'longtext', nullable: true, comment: '主题配置JSON')]
    public const schema_fields_CONFIG = 'config';

    #[Col(type: 'tinyint', nullable: false, default: 0, comment: '是否激活')]
    public const schema_fields_IS_ACTIVE = 'is_active';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function getId(mixed $default = 0): int
    {
        return (int) ($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getName(): string
    {
        return (string) ($this->getData(self::schema_fields_NAME) ?: '');
    }

    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }

    public function getSessionId(): int
    {
        return (int) ($this->getData(self::schema_fields_SESSION_ID) ?: 0);
    }

    public function setSessionId(int $sessionId): static
    {
        return $this->setData(self::schema_fields_SESSION_ID, $sessionId);
    }

    public function getWebsiteId(): int
    {
        return (int) ($this->getData(self::schema_fields_WEBSITE_ID) ?: 0);
    }

    public function setWebsiteId(int $websiteId): static
    {
        return $this->setData(self::schema_fields_WEBSITE_ID, $websiteId);
    }

    public function getPath(): string
    {
        return (string) ($this->getData(self::schema_fields_PATH) ?: '');
    }

    public function setPath(string $path): static
    {
        return $this->setData(self::schema_fields_PATH, $path);
    }

    public function getSource(): string
    {
        return (string) ($this->getData(self::schema_fields_SOURCE) ?: self::SOURCE_PAGEBUILDER_AI);
    }

    public function setSource(string $source): static
    {
        return $this->setData(self::schema_fields_SOURCE, $source);
    }

    public function getConfig(): array
    {
        $config = $this->getData(self::schema_fields_CONFIG);
        if (\is_array($config)) {
            return $config;
        }
        if (\is_string($config) && $config !== '') {
            $decoded = json_decode($config, true);
            return \is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public function setConfig(array $config): static
    {
        $this->setData(self::schema_fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    public function isActive(): bool
    {
        return (bool) ($this->getData(self::schema_fields_IS_ACTIVE) ?: false);
    }

    public function getIsActive(): int
    {
        return (int) ($this->getData(self::schema_fields_IS_ACTIVE) ?: 0);
    }

    public function setIsActive(bool $isActive): static
    {
        return $this->setData(self::schema_fields_IS_ACTIVE, $isActive ? 1 : 0);
    }

    public function getCreateTime(): string
    {
        return (string) ($this->getData(self::schema_fields_CREATE_TIME) ?: '');
    }

    public function getUpdateTime(): string
    {
        return (string) ($this->getData(self::schema_fields_UPDATE_TIME) ?: '');
    }
}
