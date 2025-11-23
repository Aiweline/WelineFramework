<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 用户双因素身份验证模型
 * 
 * @package Weline\TwoFactorAuth\Model
 */
class UserTwoFactor extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'user_2fa_id';
    public const fields_USER_ID = 'user_id';
    public const fields_SECRET = 'secret';
    public const fields_IS_ENABLED = 'is_enabled';
    public const fields_BACKUP_CODES = 'backup_codes';
    public const fields_LAST_USED_AT = 'last_used_at';
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
                    '用户2FA ID'
                )
                ->addColumn(
                    self::fields_USER_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '用户ID'
                )
                ->addColumn(
                    self::fields_SECRET,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '密钥（Base32编码）'
                )
                ->addColumn(
                    self::fields_IS_ENABLED,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'not null default 1',
                    '是否启用（1=启用，0=禁用）'
                )
                ->addColumn(
                    self::fields_BACKUP_CODES,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '备份码（JSON格式）'
                )
                ->addColumn(
                    self::fields_LAST_USED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    'null',
                    '最后使用时间'
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
                    TableInterface::index_type_UNIQUE,
                    'idx_user_id',
                    self::fields_USER_ID,
                    '用户ID唯一索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_is_enabled',
                    self::fields_IS_ENABLED,
                    '启用状态索引'
                )
                ->create();
        }
    }

    /**
     * 获取用户的2FA配置
     * 
     * @param int $userId 用户ID
     * @return self|null
     */
    public function getByUserId(int $userId): ?self
    {
        return $this->where(self::fields_USER_ID, $userId)->find()->fetch();
    }

    /**
     * 检查用户是否启用2FA
     * 
     * @param int $userId 用户ID
     * @return bool
     */
    public function isUserEnabled(int $userId): bool
    {
        $record = $this->getByUserId($userId);
        return $record && (bool)$record->getData(self::fields_IS_ENABLED);
    }

    /**
     * 启用2FA
     * 
     * @param int $userId 用户ID
     * @param string $secret 密钥
     * @param array $backupCodes 备份码
     * @return self
     */
    public function enableForUser(int $userId, string $secret, array $backupCodes = []): self
    {
        $existing = $this->getByUserId($userId);
        
        if ($existing) {
            $existing->setData(self::fields_SECRET, $secret);
            $existing->setData(self::fields_IS_ENABLED, 1);
            $existing->setData(self::fields_BACKUP_CODES, json_encode($backupCodes));
            $existing->save();
            return $existing;
        } else {
<<<<<<< HEAD
=======
            // 清除之前的数据，确保创建新记录时不会包含旧数据
            $this->clearData();
>>>>>>> dev-new
            $this->setData(self::fields_USER_ID, $userId);
            $this->setData(self::fields_SECRET, $secret);
            $this->setData(self::fields_IS_ENABLED, 1);
            $this->setData(self::fields_BACKUP_CODES, json_encode($backupCodes));
            $this->save();
            return $this;
        }
    }

    /**
     * 禁用2FA
     * 
     * @param int $userId 用户ID
     * @return bool
     */
    public function disableForUser(int $userId): bool
    {
        $record = $this->getByUserId($userId);
        if ($record) {
            $record->setData(self::fields_IS_ENABLED, 0);
            $record->save();
            return true;
        }
        return false;
    }

    /**
     * 更新最后使用时间
     * 
     * @param int $userId 用户ID
     * @return void
     */
    public function updateLastUsed(int $userId): void
    {
        $record = $this->getByUserId($userId);
        if ($record) {
            $record->setData(self::fields_LAST_USED_AT, date('Y-m-d H:i:s'));
            $record->save();
        }
    }

    /**
     * 获取备份码
     * 
     * @param int $userId 用户ID
     * @return array
     */
    public function getBackupCodes(int $userId): array
    {
        $record = $this->getByUserId($userId);
        if ($record) {
            $codes = $record->getData(self::fields_BACKUP_CODES);
            return $codes ? json_decode($codes, true) : [];
        }
        return [];
    }

    /**
     * 使用备份码
     * 
     * @param int $userId 用户ID
     * @param string $code 备份码
     * @return bool 是否成功
     */
    public function useBackupCode(int $userId, string $code): bool
    {
        $record = $this->getByUserId($userId);
        if (!$record) {
            return false;
        }

        $codes = $this->getBackupCodes($userId);
        $key = array_search($code, $codes);

        if ($key !== false) {
            // 移除已使用的备份码
            unset($codes[$key]);
            $record->setData(self::fields_BACKUP_CODES, json_encode(array_values($codes)));
            $record->save();
            $this->updateLastUsed($userId);
            return true;
        }

        return false;
    }

    /**
     * 重新生成备份码
     * 
     * @param int $userId 用户ID
     * @param array $newCodes 新的备份码
     * @return bool
     */
    public function regenerateBackupCodes(int $userId, array $newCodes): bool
    {
        $record = $this->getByUserId($userId);
        if ($record) {
            $record->setData(self::fields_BACKUP_CODES, json_encode($newCodes));
            $record->save();
            return true;
        }
        return false;
    }
}

