<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\BackendUserToken;
use Weline\Framework\Manager\ObjectManager;

/**
 * 后台用户 Token 服务
 *
 * 提供后台用户 API Token 的创建、验证、刷新和撤销功能
 */
class BackendTokenService
{
    /**
     * 为用户创建 API Token
     *
     * @param BackendUser $user 后台用户
     * @param int $expireTime 过期时间（Unix时间戳，0表示使用默认7天）
     * @return string|null Token 字符串
     */
    public function createApiToken(BackendUser $user, int $expireTime = 0): ?string
    {
        try {
            if ($expireTime <= 0) {
                $expireTime = time() + (7 * 24 * 60 * 60);
            }

            $token = $this->generateToken(64);

            /** @var BackendUserToken $tokenModel */
            $tokenModel = ObjectManager::getInstance(BackendUserToken::class);
            $tokenModel->clear()
                ->setUserId($user->getId())
                ->setToken($token)
                ->setExpireTime($expireTime)
                ->setCreatedAt(date('Y-m-d H:i:s'))
                ->save();

            return $token;
        } catch (\Throwable $e) {
            w_log_error('BackendTokenService createApiToken error: ' . $e->getMessage(), [], 'backend_token');
            return null;
        }
    }

    /**
     * 刷新 Token
     *
     * @param string $token 原 Token
     * @param int $expireTime 新过期时间
     * @return string|null 新 Token
     */
    public function refreshToken(string $token, int $expireTime = 0): ?string
    {
        try {
            /** @var BackendUserToken $tokenModel */
            $tokenModel = ObjectManager::getInstance(BackendUserToken::class);
            $tokenModel->where('token', $token)
                ->where('expire_time', '>', time())
                ->find()
                ->fetch();

            if (!$tokenModel->getId()) {
                return null;
            }

            $user = $this->getUserByToken($token);
            if (!$user) {
                return null;
            }

            $this->revokeToken($token);

            return $this->createApiToken($user, $expireTime);
        } catch (\Throwable $e) {
            w_log_error('BackendTokenService refreshToken error: ' . $e->getMessage(), [], 'backend_token');
            return null;
        }
    }

    /**
     * 撤销 Token
     *
     * @param string $token Token 字符串
     * @return bool 是否成功
     */
    public function revokeToken(string $token): bool
    {
        try {
            /** @var BackendUserToken $tokenModel */
            $tokenModel = ObjectManager::getInstance(BackendUserToken::class);
            $tokenModel->where('token', $token)->delete()->fetch();
            return true;
        } catch (\Throwable $e) {
            w_log_error('BackendTokenService revokeToken error: ' . $e->getMessage(), [], 'backend_token');
            return false;
        }
    }

    /**
     * 通过 Token 获取用户
     *
     * @param string $token Token 字符串
     * @return BackendUser|null 后台用户
     */
    public function getUserByToken(string $token): ?BackendUser
    {
        try {
            /** @var BackendUserToken $tokenModel */
            $tokenModel = ObjectManager::getInstance(BackendUserToken::class);
            $tokenModel->where('token', $token)
                ->where('expire_time', '>', time())
                ->find()
                ->fetch();

            if (!$tokenModel->getId()) {
                return null;
            }

            /** @var BackendUser $user */
            $user = ObjectManager::getInstance(BackendUser::class);
            $user->load($tokenModel->getUserId());

            if (!$user->getId() || !$user->getIsEnabled()) {
                return null;
            }

            return $user;
        } catch (\Throwable $e) {
            w_log_error('BackendTokenService getUserByToken error: ' . $e->getMessage(), [], 'backend_token');
            return null;
        }
    }

    /**
     * 获取 Token 信息
     *
     * @param string $token Token 字符串
     * @return array|null Token 信息
     */
    public function getTokenInfo(string $token): ?array
    {
        try {
            /** @var BackendUserToken $tokenModel */
            $tokenModel = ObjectManager::getInstance(BackendUserToken::class);
            $tokenModel->where('token', $token)->find()->fetch();

            if (!$tokenModel->getId()) {
                return null;
            }

            $user = $this->getUserByToken($token);

            return [
                'token' => $token,
                'user_id' => $tokenModel->getUserId(),
                'expire_time' => $tokenModel->getExpireTime(),
                'created_at' => $tokenModel->getCreatedAt(),
                'is_valid' => $tokenModel->getExpireTime() > time(),
                'user' => $user ? [
                    'id' => $user->getId(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail(),
                ] : null,
            ];
        } catch (\Throwable $e) {
            w_log_error('BackendTokenService getTokenInfo error: ' . $e->getMessage(), [], 'backend_token');
            return null;
        }
    }

    /**
     * 验证 Token 是否有效
     *
     * @param string $token Token 字符串
     * @return bool 是否有效
     */
    public function validateToken(string $token): bool
    {
        return $this->getUserByToken($token) !== null;
    }

    /**
     * 生成随机 Token
     */
    private function generateToken(int $length = 64): string
    {
        return \bin2hex(\random_bytes($length / 2));
    }
}
