<?php
declare(strict_types=1);

namespace Weline\Smtp\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Smtp 中继/提交账户（与邮局 MailAccount 分离）')]
#[Index(name: 'idx_weline_smtp_account_email', columns: ['email'], type: 'UNIQUE')]
#[Index(name: 'idx_weline_smtp_account_service', columns: ['smtp_service_id'])]
#[Index(name: 'idx_weline_smtp_account_domain', columns: ['domain_name'])]
#[Index(name: 'idx_weline_smtp_account_status', columns: ['status'])]
class SmtpAccount extends Model
{
    public const schema_table = 'weline_smtp_account';
    public const schema_primary_key = 'smtp_account_id';

    public const ROLE_SUBMIT = 'submit';
    public const ROLE_RELAY = 'relay';

    /** 本机启用时固定创建的提交账号 local_part（不可删、不可改密）。 */
    public const SYSTEM_SUBMIT_LOCAL = 'smtp';

    /** 开启中继服务时固定创建的中继账号 local_part（不可删、不可改密）。 */
    public const SYSTEM_RELAY_LOCAL = 'relay';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Smtp 账户ID')]
    public const schema_fields_ID = 'smtp_account_id';

    #[Col('int', default: 0, comment: '所属 Smtp 服务ID')]
    public const schema_fields_SERVICE_ID = 'smtp_service_id';

    #[Col('varchar', 255, nullable: false, comment: 'Smtp 服务域名')]
    public const schema_fields_DOMAIN_NAME = 'domain_name';

    #[Col('varchar', 255, nullable: false, comment: '完整邮箱地址')]
    public const schema_fields_EMAIL = 'email';

    #[Col('varchar', 120, nullable: true, comment: '显示名称')]
    public const schema_fields_DISPLAY_NAME = 'display_name';

    #[Col('varchar', 16, default: 'submit', comment: 'submit|relay')]
    public const schema_fields_ROLE = 'account_role';

    #[Col('varchar', 32, default: 'active', comment: '状态')]
    public const schema_fields_STATUS = 'status';

    #[Col('tinyint', default: 0, comment: '系统固定账号：禁止删除与改密')]
    public const schema_fields_IS_SYSTEM = 'is_system';

    #[Col('text', nullable: true, comment: '密码密封')]
    public const schema_fields_PASSWORD_SEALED = 'password_sealed';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    /**
     * 本机 Smtp 固定系统账号 local_part → 角色。
     *
     * @return array<string, string>
     */
    public static function systemLocalParts(bool $relayEnabled): array
    {
        $parts = [self::SYSTEM_SUBMIT_LOCAL => self::ROLE_SUBMIT];
        if ($relayEnabled) {
            $parts[self::SYSTEM_RELAY_LOCAL] = self::ROLE_RELAY;
        }

        return $parts;
    }

    public static function isReservedSystemLocalPart(string $localPart): bool
    {
        $local = strtolower(trim($localPart));

        return $local === self::SYSTEM_SUBMIT_LOCAL || $local === self::SYSTEM_RELAY_LOCAL;
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

    public function isSystemAccount(): bool
    {
        if ((int)$this->getData(self::schema_fields_IS_SYSTEM) === 1) {
            return true;
        }

        return self::isReservedSystemLocalPart(self::emailLocalPart((string)$this->getData(self::schema_fields_EMAIL)));
    }
}
