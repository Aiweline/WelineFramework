<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * TOTP账户模型
 * 用于存储用户的第三方TOTP账户（类似Google Authenticator的账户列表）
 * 
 * @package Weline\TwoFactorAuth\Model
 */
class TotpAccount extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'account_id';
    public const fields_USER_ID = 'user_id';
    public const fields_NAME = 'name';
    public const fields_ISSUER = 'issuer';
    public const fields_SECRET = 'secret';
    public const fields_ALGORITHM = 'algorithm';
    public const fields_DIGITS = 'digits';
    public const fields_PERIOD = 'period';
    public const fields_ICON = 'icon';
    public const fields_COLOR = 'color';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '账户ID'
                )
                ->addColumn(
                    self::fields_USER_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '用户ID'
                )
                ->addColumn(
                    self::fields_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '账户名称'
                )
                ->addColumn(
                    self::fields_ISSUER,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'null',
                    '发行者名称'
                )
                ->addColumn(
                    self::fields_SECRET,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '密钥（Base32编码）'
                )
                ->addColumn(
                    self::fields_ALGORITHM,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'not null default \'SHA1\'',
                    '算法（SHA1/SHA256/SHA512）'
                )
                ->addColumn(
                    self::fields_DIGITS,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'not null default 6',
                    '位数（6/8）'
                )
                ->addColumn(
                    self::fields_PERIOD,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null default 30',
                    '周期（秒）'
                )
                ->addColumn(
                    self::fields_ICON,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'null',
                    '图标URL或标识'
                )
                ->addColumn(
                    self::fields_COLOR,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'null',
                    '主题颜色'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    'not null default CURRENT_TIMESTAMP',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP',
                    '更新时间'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_user_id',
                    self::fields_USER_ID,
                    '用户ID索引'
                )
                ->create();
        }
    }

    /**
     * 获取用户的所有账户
     * 
     * @param int $userId 用户ID
     * @return array
     */
    public function getUserAccounts(int $userId): array
    {
        return $this->where(self::fields_USER_ID, $userId)
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
        $this->setData(self::fields_USER_ID, $userId);
        $this->setData(self::fields_NAME, $name);
        $this->setData(self::fields_SECRET, $secret);
        $this->setData(self::fields_ISSUER, $issuer);
        $this->setData(self::fields_ALGORITHM, $algorithm);
        $this->setData(self::fields_DIGITS, $digits);
        $this->setData(self::fields_PERIOD, $period);
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
        $account = $this->where(self::fields_ID, $accountId)
            ->where(self::fields_USER_ID, $userId)
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
        $account = $this->where(self::fields_ID, $accountId)
            ->where(self::fields_USER_ID, $userId)
            ->find()
            ->fetch();
        
        if ($account) {
            foreach ($data as $key => $value) {
                if ($key !== self::fields_ID && $key !== self::fields_USER_ID) {
                    $account->setData($key, $value);
                }
            }
            return $account->save();
        }
        
        return false;
    }
}

