<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Service;

use Weline\TwoFactorAuth\Helper\TwoFactorAuthHelper;
use Weline\TwoFactorAuth\Model\UserTwoFactor;

/**
 * 双因素身份验证服务
 * 
 * @package Weline\TwoFactorAuth\Service
 */
class TwoFactorAuthService
{
    private UserTwoFactor $userTwoFactor;

    public function __construct(
        UserTwoFactor $userTwoFactor
    ) {
        $this->userTwoFactor = $userTwoFactor;
    }

    /**
     * 为用户初始化2FA
     * 生成密钥和备份码，但不立即启用
     * 
     * @param int $userId 用户ID
     * @return array ['secret' => string, 'backup_codes' => array]
     */
    public function initialize(int $userId): array
    {
        $secret = TwoFactorAuthHelper::generateSecret();
        $backupCodes = TwoFactorAuthHelper::generateBackupCodes(10);

        return [
            'secret' => $secret,
            'backup_codes' => $backupCodes,
        ];
    }

    /**
     * 启用2FA（需要先验证一次验证码）
     * 
     * @param int $userId 用户ID
     * @param string $secret 密钥
     * @param string $code 验证码
     * @param array $backupCodes 备份码
     * @return bool
     */
    public function enable(int $userId, string $secret, string $code, array $backupCodes = []): bool
    {
        // 验证码必须正确才能启用
        if (!TwoFactorAuthHelper::verifyCode($secret, $code)) {
            return false;
        }

        $this->userTwoFactor->enableForUser($userId, $secret, $backupCodes);
        return true;
    }

    /**
     * 禁用2FA
     * 
     * @param int $userId 用户ID
     * @param string $code 验证码（必须提供正确的验证码才能禁用）
     * @return bool
     */
    public function disable(int $userId, string $code): bool
    {
        $record = $this->userTwoFactor->getByUserId($userId);
        if (!$record) {
            return false;
        }

        $secret = $record->getData(UserTwoFactor::fields_SECRET);
        
        // 验证码必须正确才能禁用
        if (!TwoFactorAuthHelper::verifyCode($secret, $code)) {
            return false;
        }

        return $this->userTwoFactor->disableForUser($userId);
    }

    /**
     * 验证用户的2FA验证码
     * 
     * @param int $userId 用户ID
     * @param string $code 验证码
     * @return bool
     */
    public function verify(int $userId, string $code): bool
    {
        $record = $this->userTwoFactor->getByUserId($userId);
        if (!$record || !$record->getData(UserTwoFactor::fields_IS_ENABLED)) {
            return false;
        }

        $secret = $record->getData(UserTwoFactor::fields_SECRET);
        
        if (TwoFactorAuthHelper::verifyCode($secret, $code)) {
            $this->userTwoFactor->updateLastUsed($userId);
            return true;
        }

        return false;
    }

    /**
     * 使用备份码验证
     * 
     * @param int $userId 用户ID
     * @param string $code 备份码
     * @return bool
     */
    public function verifyBackupCode(int $userId, string $code): bool
    {
        return $this->userTwoFactor->useBackupCode($userId, $code);
    }

    /**
     * 生成QR码URI
     * 
     * @param string $secret 密钥
     * @param string $account 账户名
     * @param string $issuer 发行者
     * @return string
     */
    public function getQRCodeUri(string $secret, string $account, string $issuer = 'WelineFramework'): string
    {
        // 开发环境添加 Dev- 前缀，格式化账户名为 Weline(账户) 格式
        $formattedAccount = (defined('DEV') && DEV ? 'Dev-' : '') . 'Weline(' . $account . ')';
        return TwoFactorAuthHelper::getOtpAuthUri($secret, $formattedAccount, $issuer);
    }

    /**
     * 生成QR码URL
     * 
     * @param string $secret 密钥
     * @param string $account 账户名
     * @param string $issuer 发行者
     * @param int $size 尺寸
     * @return string
     */
    public function getQRCodeUrl(string $secret, string $account, string $issuer = 'WelineFramework', int $size = 250): string
    {
        // 直接调用 getQRCodeUri，格式化逻辑已在其中处理
        $uri = $this->getQRCodeUri($secret, $account, $issuer);
        return TwoFactorAuthHelper::getQRCodeUrl($uri, $size);
    }

    /**
     * 检查用户是否启用了2FA
     * 
     * @param int $userId 用户ID
     * @return bool
     */
    public function isEnabled(int $userId): bool
    {
        return $this->userTwoFactor->isUserEnabled($userId);
    }

    /**
     * 获取用户的备份码
     * 
     * @param int $userId 用户ID
     * @return array
     */
    public function getBackupCodes(int $userId): array
    {
        return $this->userTwoFactor->getBackupCodes($userId);
    }

    /**
     * 重新生成备份码
     * 
     * @param int $userId 用户ID
     * @param string $code 验证码（需要验证身份）
     * @return array|null 新的备份码，失败返回null
     */
    public function regenerateBackupCodes(int $userId, string $code): ?array
    {
        // 验证身份
        if (!$this->verify($userId, $code)) {
            return null;
        }

        $newCodes = TwoFactorAuthHelper::generateBackupCodes(10);
        if ($this->userTwoFactor->regenerateBackupCodes($userId, $newCodes)) {
            return $newCodes;
        }

        return null;
    }

    /**
     * 格式化密钥（便于显示）
     * 
     * @param string $secret 密钥
     * @return string
     */
    public function formatSecret(string $secret): string
    {
        return TwoFactorAuthHelper::formatSecret($secret);
    }

    /**
     * 获取当前验证码剩余秒数
     * 
     * @return int
     */
    public function getRemainingSeconds(): int
    {
        return TwoFactorAuthHelper::getRemainingSeconds();
    }

    /**
     * 获取用户的2FA配置信息
     * 
     * @param int $userId 用户ID
     * @return array|null
     */
    public function getUserConfig(int $userId): ?array
    {
        $record = $this->userTwoFactor->getByUserId($userId);
        if (!$record) {
            return null;
        }

        return [
            'is_enabled' => (bool)$record->getData(UserTwoFactor::fields_IS_ENABLED),
            'last_used_at' => $record->getData(UserTwoFactor::fields_LAST_USED_AT),
            'created_at' => $record->getData(UserTwoFactor::fields_CREATED_AT),
            'backup_codes_count' => count($this->getBackupCodes($userId)),
        ];
    }
}

