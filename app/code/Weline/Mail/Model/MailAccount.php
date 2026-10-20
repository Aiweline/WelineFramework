<?php
declare(strict_types=1);

namespace Weline\Mail\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '企业邮箱账号表')]
#[Index(name: 'idx_weline_mail_account_email', columns: ['email'], type: 'UNIQUE')]
#[Index(name: 'idx_weline_mail_account_domain', columns: ['domain_id'])]
#[Index(name: 'idx_weline_mail_account_customer', columns: ['customer_id'])]
#[Index(name: 'idx_weline_mail_account_status', columns: ['status'])]
class MailAccount extends Model
{
    public const schema_table = 'weline_mail_account';
    public const schema_primary_key = 'account_id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '账号ID')]
    public const schema_fields_ID = 'account_id';

    #[Col('int', nullable: false, comment: '域名ID')]
    public const schema_fields_DOMAIN_ID = 'domain_id';

    #[Col('int', default: 0, comment: '前台客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';

    #[Col('varchar', 255, nullable: false, comment: '邮箱地址')]
    public const schema_fields_EMAIL = 'email';

    #[Col('varchar', 120, nullable: true, comment: '显示名称')]
    public const schema_fields_DISPLAY_NAME = 'display_name';

    #[Col('int', default: 1024, comment: '容量MB')]
    public const schema_fields_QUOTA_MB = 'quota_mb';

    #[Col('varchar', 32, default: 'pending', comment: '账号状态')]
    public const schema_fields_STATUS = 'status';

    /** contact@ 人收、smtp@ 提交代理：禁止删改身份与改密。social-{站code}@ 由融媒体开通：禁止删除与改身份，仍可重置密码。 */
    public const SYSTEM_LOCAL_CONTACT = 'contact';
    public const SYSTEM_LOCAL_SMTP = 'smtp';
    public const SYSTEM_LOCAL_SOCIAL_PREFIX = 'social-';

    #[Col('tinyint', default: 0, comment: '系统固定账号：禁止删除；contact/smtp 另禁止改密')]
    public const schema_fields_IS_SYSTEM = 'is_system';

    #[Col('tinyint', default: 0, comment: '该邮箱是否走外投 smarthost')]
    public const schema_fields_OUTBOUND_RELAY_ENABLED = 'outbound_relay_enabled';

    #[Col('varchar', 255, nullable: true, comment: '外投 smarthost 主机')]
    public const schema_fields_OUTBOUND_RELAY_HOST = 'outbound_relay_host';

    #[Col('int', nullable: true, comment: '外投 smarthost 端口')]
    public const schema_fields_OUTBOUND_RELAY_PORT = 'outbound_relay_port';

    #[Col('varchar', 255, nullable: true, comment: '外投 smarthost 用户名')]
    public const schema_fields_OUTBOUND_RELAY_USERNAME = 'outbound_relay_username';

    #[Col('text', nullable: true, comment: '外投 smarthost 密码密封')]
    public const schema_fields_OUTBOUND_RELAY_PASSWORD_SEALED = 'outbound_relay_password_sealed';

    #[Col('varchar', 16, default: 'starttls', comment: '外投 TLS：starttls|smtps|none')]
    public const schema_fields_OUTBOUND_RELAY_TLS = 'outbound_relay_tls';

    #[Col('varchar', 255, nullable: true, comment: '本地登录密码哈希')]
    public const schema_fields_PASSWORD_HASH = 'password_hash';

    #[Col('text', nullable: true, comment: '登录密码密封（项目密钥，可跟库迁机）')]
    public const schema_fields_PASSWORD_SEALED = 'password_sealed';

    #[Col('datetime', nullable: true, comment: '最后同步时间')]
    public const schema_fields_LAST_SYNCED_AT = 'last_synced_at';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    /**
     * 邮局系统固定账号 local_part（与 Ensure / Smtp 提交代理对齐）。
     *
     * @return list<string>
     */
    public static function reservedSystemLocalParts(): array
    {
        return [self::SYSTEM_LOCAL_CONTACT, self::SYSTEM_LOCAL_SMTP];
    }

    public static function isReservedSystemLocalPart(string $localPart): bool
    {
        $local = strtolower(trim($localPart));

        return $local !== '' && in_array($local, self::reservedSystemLocalParts(), true);
    }

    /**
     * 融媒体开通的社媒专用邮箱 local_part：social-{网站code}。
     */
    public static function isSocialMailboxLocalPart(string $localPart): bool
    {
        $local = strtolower(trim($localPart));
        $prefix = self::SYSTEM_LOCAL_SOCIAL_PREFIX;

        return $local !== ''
            && str_starts_with($local, $prefix)
            && strlen($local) > strlen($prefix);
    }

    public static function isGeneratedSystemLocalPart(string $localPart): bool
    {
        return self::isReservedSystemLocalPart($localPart)
            || self::isSocialMailboxLocalPart($localPart);
    }

    public static function emailLocalPart(string $email): string
    {
        $email = strtolower(trim($email));
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return '';
        }

        return substr($email, 0, $at);
    }

    /**
     * 系统角色短标签（列表徽章旁说明）。
     */
    public static function systemRoleLabelForLocal(string $localPart): string
    {
        $local = strtolower(trim($localPart));
        if (self::isSocialMailboxLocalPart($local)) {
            return (string)__('社媒注册（系统）');
        }

        return match ($local) {
            self::SYSTEM_LOCAL_CONTACT => (string)__('站点联系（人收）'),
            self::SYSTEM_LOCAL_SMTP => (string)__('SMTP 提交/代理'),
            default => (string)__('系统固定账号'),
        };
    }

    public function isSystemAccount(): bool
    {
        if ((int)$this->getData(self::schema_fields_IS_SYSTEM) === 1) {
            return true;
        }

        return self::isGeneratedSystemLocalPart(
            self::emailLocalPart((string)$this->getData(self::schema_fields_EMAIL))
        );
    }

    public function systemRoleLabel(): string
    {
        return self::systemRoleLabelForLocal(
            self::emailLocalPart((string)$this->getData(self::schema_fields_EMAIL))
        );
    }
}
