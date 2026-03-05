<?php
declare(strict_types=1);
namespace Weline\TwoFactorAuth\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** TOTP账户模型 - 存储用户第三方TOTP账户列表 */
#[Table(comment: 'TOTP账户表')]
#[Index(name: 'idx_user_id', columns: ['user_id'])]
class TotpAccount extends Model
{
    public const schema_table = 'totp_account';
    public const schema_primary_key = 'account_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '账户ID')]
    public const schema_fields_ID = 'account_id';
    #[Col('int', nullable: false, comment: '用户ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col('varchar', 255, nullable: false, comment: '账户名称')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 255, comment: '发行者名称')]
    public const schema_fields_ISSUER = 'issuer';
    #[Col('varchar', 255, nullable: false, comment: '密钥Base32')]
    public const schema_fields_SECRET = 'secret';
    #[Col('varchar', 20, nullable: false, default: 'SHA1', comment: '算法')]
    public const schema_fields_ALGORITHM = 'algorithm';
    #[Col('smallint', 1, nullable: false, default: 6, comment: '位数')]
    public const schema_fields_DIGITS = 'digits';
    #[Col('int', nullable: false, default: 30, comment: '周期秒')]
    public const schema_fields_PERIOD = 'period';
    #[Col('varchar', 255, comment: '图标URL')]
    public const schema_fields_ICON = 'icon';
    #[Col('varchar', 20, comment: '主题颜色')]
    public const schema_fields_COLOR = 'color';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
/**
     * 获取用户的所有账户
     * 
     * @param int $userId 用户ID
     * @return array
     */
    public function getUserAccounts(int $userId): array
    {
        return $this->where(self::schema_fields_USER_ID, $userId)
            ->order('created_at', 'DESC')
            ->select()
            ->fetchArray();
    }
    /**
     * 添加账户
     * 
     * @param int $userId 用户ID
     * @param string $name 账户名称
     * @param string $secret 密钥
     * @param string|null $issuer 发行者
     * @param string $algorithm 算法
     * @param int $digits 位数
     * @param int $period 周期
     * @return self
     */
    public function addAccount(
        int $userId,
        string $name,
        string $secret,
        ?string $issuer = null,
        string $algorithm = 'SHA1',
        int $digits = 6,
        int $period = 30
    ): self {
        $this->setData(self::schema_fields_USER_ID, $userId);
        $this->setData(self::schema_fields_NAME, $name);
        $this->setData(self::schema_fields_SECRET, $secret);
        $this->setData(self::schema_fields_ISSUER, $issuer);
        $this->setData(self::schema_fields_ALGORITHM, $algorithm);
        $this->setData(self::schema_fields_DIGITS, $digits);
        $this->setData(self::schema_fields_PERIOD, $period);
        $this->save();
        return $this;
    }
    /**
     * 删除账户
     * 
     * @param int $accountId 账户ID
     * @param int $userId 用户ID（用于安全验证）
     * @return bool
     */
    public function deleteAccount(int $accountId, int $userId): bool
    {
        $account = $this->where(self::schema_fields_ID, $accountId)
            ->where(self::schema_fields_USER_ID, $userId)
            ->find()
            ->fetch();
        
        if ($account) {
            return $account->delete();
        }
        
        return false;
    }
    /**
     * 更新账户信息
     * 
     * @param int $accountId 账户ID
     * @param int $userId 用户ID
     * @param array $data 要更新的数据
     * @return bool
     */
    public function updateAccount(int $accountId, int $userId, array $data): bool
    {
        $account = $this->where(self::schema_fields_ID, $accountId)
            ->where(self::schema_fields_USER_ID, $userId)
            ->find()
            ->fetch();
        
        if ($account) {
            foreach ($data as $key => $value) {
                if ($key !== self::schema_fields_ID && $key !== self::schema_fields_USER_ID) {
                    $account->setData($key, $value);
                }
            }
            return $account->save();
        }
        
        return false;
    }
}
