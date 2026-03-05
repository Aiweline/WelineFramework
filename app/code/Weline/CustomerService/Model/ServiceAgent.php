<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '客服人员表')]
#[Index(name: 'idx_user_id', columns: ['user_id'])]
#[Index(name: 'idx_is_active', columns: ['is_active'])]
class ServiceAgent extends Model
{
    public const schema_table = 'service_agent';
    public const schema_primary_key = 'agent_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '客服ID')]
    public const schema_fields_ID = 'agent_id';
    #[Col(type: 'int', nullable: false, comment: '关联后台用户ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '客服名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '邮箱')]
    public const schema_fields_EMAIL = 'email';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'zh_Hans_CN', comment: '客服语言')]
    public const schema_fields_LOCALE = 'locale';
    #[Col(type: 'text', nullable: true, comment: '支持的语言列表(JSON)')]
    public const schema_fields_SUPPORTED_LOCALES = 'supported_locales';
    #[Col(type: 'int', length: 1, nullable: false, default: 1, comment: '是否激活')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'int', nullable: false, default: 10, comment: '最大并发会话数')]
    public const schema_fields_MAX_SESSIONS = 'max_sessions';
    #[Col(type: 'datetime', nullable: true, comment: '最后心跳时间')]
    public const schema_fields_LAST_HEARTBEAT = 'last_heartbeat';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    /** 心跳超时秒数：超过此时间未收到心跳视为离线 */
    public const HEARTBEAT_TIMEOUT = 60;

    public function _init(): void
    {
        $this->_primary_key = self::schema_fields_ID;
        $this->_table = self::schema_table;
    }

    public function getUserId(): int
    {
        return (int)$this->getData(self::schema_fields_USER_ID);
    }

    public function setUserId(int $userId): static
    {
        return $this->setData(self::schema_fields_USER_ID, $userId);
    }

    public function getName(): string
    {
        return (string)$this->getData(self::schema_fields_NAME);
    }

    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }

    public function getEmail(): string
    {
        return (string)$this->getData(self::schema_fields_EMAIL);
    }

    public function setEmail(string $email): static
    {
        return $this->setData(self::schema_fields_EMAIL, $email);
    }

    public function getLocale(): string
    {
        return (string)$this->getData(self::schema_fields_LOCALE);
    }

    public function setLocale(string $locale): static
    {
        return $this->setData(self::schema_fields_LOCALE, $locale);
    }

    public function getIsActive(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_ACTIVE);
    }

    public function setIsActive(bool $isActive): static
    {
        return $this->setData(self::schema_fields_IS_ACTIVE, $isActive ? 1 : 0);
    }

    public function getMaxSessions(): int
    {
        return (int)$this->getData(self::schema_fields_MAX_SESSIONS);
    }

    public function setMaxSessions(int $maxSessions): static
    {
        return $this->setData(self::schema_fields_MAX_SESSIONS, $maxSessions);
    }

    public function updateHeartbeat(): static
    {
        return $this->setData(self::schema_fields_LAST_HEARTBEAT, date('Y-m-d H:i:s'));
    }

    public function isOnline(): bool
    {
        $lastHeartbeat = $this->getData(self::schema_fields_LAST_HEARTBEAT);
        if (empty($lastHeartbeat)) {
            return false;
        }
        return (time() - strtotime($lastHeartbeat)) < self::HEARTBEAT_TIMEOUT;
    }

    public function getSupportedLocales(): array
    {
        $data = $this->getData(self::schema_fields_SUPPORTED_LOCALES);
        if (empty($data)) {
            return [$this->getLocale()];
        }
        $locales = json_decode($data, true);
        return is_array($locales) ? $locales : [$this->getLocale()];
    }

    public function setSupportedLocales(array $locales): static
    {
        return $this->setData(self::schema_fields_SUPPORTED_LOCALES, json_encode($locales));
    }

    public function supportsLocale(string $locale): bool
    {
        return in_array($locale, $this->getSupportedLocales(), true);
    }

    public static function getAvailableLocales(): array
    {
        return [
            'zh_Hans_CN' => '简体中文',
            'zh_Hant_TW' => '繁體中文',
            'en_US' => 'English',
            'ja_JP' => '日本語',
            'ko_KR' => '한국어',
            'fr_FR' => 'Français',
            'de_DE' => 'Deutsch',
            'es_ES' => 'Español',
            'pt_BR' => 'Português',
            'ru_RU' => 'Русский',
            'ar_SA' => 'العربية',
            'th_TH' => 'ไทย',
            'vi_VN' => 'Tiếng Việt',
        ];
    }
}
