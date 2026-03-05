<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 用户双因素身份验证模型 */
#[Table(comment: '用户2FA表')]
#[Index(name: 'idx_user_id', columns: ['user_id'], type: 'UNIQUE')]
#[Index(name: 'idx_is_enabled', columns: ['is_enabled'])]
class UserTwoFactor extends Model
{
    public const schema_table = 'user_two_factor';
    public const schema_primary_key = 'user_2fa_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '用户2FA ID')]
    public const schema_fields_ID = 'user_2fa_id';
    #[Col('int', nullable: false, comment: '用户ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col('varchar', 255, nullable: false, comment: '密钥Base32')]
    public const schema_fields_SECRET = 'secret';
    #[Col('smallint', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ENABLED = 'is_enabled';
    #[Col('text', comment: '备份码JSON')]
    public const schema_fields_BACKUP_CODES = 'backup_codes';
    #[Col('datetime', comment: '最后使用时间')]
    public const schema_fields_LAST_USED_AT = 'last_used_at';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
/**
     * 获取用户的2FA配置
     * 
     * @param int $userId 用户ID
     * @return self|null
     */
    public function getByUserId(int $userId): ?self
    {
        return $this->where(self::schema_fields_USER_ID, $userId)->find()->fetch();
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
        return $record && (bool)$record->getData(self::schema_fields_IS_ENABLED);
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
            $existing->setData(self::schema_fields_SECRET, $secret);
            $existing->setData(self::schema_fields_IS_ENABLED, 1);
            $existing->setData(self::schema_fields_BACKUP_CODES, json_encode($backupCodes));
            $existing->save();
            return $existing;
        } else {
            // 清除之前的数据，确保创建新记录时不会包含旧数据
            $this->clearData();
            $this->setData(self::schema_fields_USER_ID, $userId);
            $this->setData(self::schema_fields_SECRET, $secret);
            $this->setData(self::schema_fields_IS_ENABLED, 1);
            $this->setData(self::schema_fields_BACKUP_CODES, json_encode($backupCodes));
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
            $record->setData(self::schema_fields_IS_ENABLED, 0);
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
            $record->setData(self::schema_fields_LAST_USED_AT, date('Y-m-d H:i:s'));
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
            $codes = $record->getData(self::schema_fields_BACKUP_CODES);
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
            $record->setData(self::schema_fields_BACKUP_CODES, json_encode(array_values($codes)));
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
            $record->setData(self::schema_fields_BACKUP_CODES, json_encode($newCodes));
            $record->save();
            return true;
        }
        return false;
    }
}

