<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Service;

use Weline\Api\Model\ApiUser;
use Weline\Api\Model\ApiUserToken;
use Weline\Framework\Manager\ObjectManager;

/**
 * Token服务类
 * 
 * 负责API令牌的生成、验证、刷新和撤销
 */
class TokenService
{
    /**
     * 生成访问令牌
     * 
     * @param ApiUser $user API用户
     * @param int $expireTime 过期时间（Unix时间戳，0表示使用用户默认配置）
     * @return string|null 访问令牌
     */
    public function generateAccessToken(ApiUser $user, int $expireTime = 0): ?string
    {
        try {
            if ($expireTime <= 0) {
                // 使用用户配置的过期时间
                $expireTime = time() + $user->getTokenExpireTime();
            }

            // 生成token
            $token = $this->generateToken(64);

            /** @var ApiUserToken $tokenModel */
            $tokenModel = ObjectManager::getInstance(ApiUserToken::class);
            
            // 创建访问令牌记录
            $tokenModel->clear()
                ->setUserId($user->getId())
                ->setToken($token)
                ->setType(ApiUserToken::TYPE_ACCESS_TOKEN)
                ->setTokenExpireTime($expireTime)
                ->save();

            return $token;

        } catch (\Exception $e) {
            error_log('TokenService generateAccessToken error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 生成刷新令牌
     * 
     * @param ApiUser $user API用户
     * @param int $expireTime 过期时间（Unix时间戳，0表示使用用户默认配置）
     * @return string|null 刷新令牌
     */
    public function generateRefreshToken(ApiUser $user, int $expireTime = 0): ?string
    {
        try {
            if ($expireTime <= 0) {
                // 使用用户配置的刷新令牌过期时间
                $expireTime = time() + $user->getRefreshTokenExpireTime();
            }

            // 生成token
            $token = $this->generateToken(64);

            /** @var ApiUserToken $tokenModel */
            $tokenModel = ObjectManager::getInstance(ApiUserToken::class);
            
            // 创建刷新令牌记录
            $tokenModel->clear()
                ->setUserId($user->getId())
                ->setToken($token)
                ->setType(ApiUserToken::TYPE_REFRESH_TOKEN)
                ->setTokenExpireTime($expireTime)
                ->save();

            return $token;

        } catch (\Exception $e) {
            error_log('TokenService generateRefreshToken error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 生成通行令牌（API Key，长期有效）
     * 
     * @param ApiUser $user API用户
     * @return string|null 通行令牌（API Key）
     */
    public function generatePassToken(ApiUser $user): ?string
    {
        try {
            // 通行令牌就是API Key，从用户模型中获取
            return $user->getApiKey();

        } catch (\Exception $e) {
            error_log('TokenService generatePassToken error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 验证访问令牌
     * 
     * @param string $token 访问令牌
     * @return ApiUser|null API用户，如果令牌无效返回null
     */
    public function validateAccessToken(string $token): ?ApiUser
    {
        try {
            /** @var ApiUserToken $tokenModel */
            $tokenModel = ObjectManager::getInstance(ApiUserToken::class);
            
            $tokenRecord = $tokenModel->clear()
                ->where('token', $token)
                ->where('type', ApiUserToken::TYPE_ACCESS_TOKEN)
                ->find()
                ->fetch();

            if (!$tokenRecord->getId()) {
                return null;
            }

            // 检查是否过期
            if ($tokenRecord->isExpired()) {
                // 删除过期令牌
                $tokenRecord->delete();
                return null;
            }

            // 获取用户
            /** @var ApiUser $user */
            $user = ObjectManager::getInstance(ApiUser::class);
            $user->load($tokenRecord->getUserId());

            if (!$user->getId() || !$user->getIsEnabled() || $user->getIsDeleted()) {
                return null;
            }

            return $user;

        } catch (\Exception $e) {
            error_log('TokenService validateAccessToken error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 验证刷新令牌
     * 
     * @param string $token 刷新令牌
     * @return ApiUser|null API用户，如果令牌无效返回null
     */
    public function validateRefreshToken(string $token): ?ApiUser
    {
        try {
            /** @var ApiUserToken $tokenModel */
            $tokenModel = ObjectManager::getInstance(ApiUserToken::class);
            
            $tokenRecord = $tokenModel->clear()
                ->where('token', $token)
                ->where('type', ApiUserToken::TYPE_REFRESH_TOKEN)
                ->find()
                ->fetch();

            if (!$tokenRecord->getId()) {
                return null;
            }

            // 检查是否过期
            if ($tokenRecord->isExpired()) {
                // 删除过期令牌
                $tokenRecord->delete();
                return null;
            }

            // 获取用户
            /** @var ApiUser $user */
            $user = ObjectManager::getInstance(ApiUser::class);
            $user->load($tokenRecord->getUserId());

            if (!$user->getId() || !$user->getIsEnabled() || $user->getIsDeleted()) {
                return null;
            }

            return $user;

        } catch (\Exception $e) {
            error_log('TokenService validateRefreshToken error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 验证通行令牌（API Key）
     * 
     * @param string $apiKey API Key
     * @param string $apiSecret API Secret
     * @return ApiUser|null API用户，如果验证失败返回null
     */
    public function validatePassToken(string $apiKey, string $apiSecret): ?ApiUser
    {
        try {
            /** @var ApiUser $user */
            $user = ObjectManager::getInstance(ApiUser::class);
            $user->where('api_key', $apiKey)
                ->where('is_deleted', 0)
                ->find()
                ->fetch();

            if (!$user->getId()) {
                return null;
            }

            // 验证API Secret
            if (!$user->verifySecret($apiSecret)) {
                return null;
            }

            // 检查用户状态
            if (!$user->getIsEnabled()) {
                return null;
            }

            return $user;

        } catch (\Exception $e) {
            error_log('TokenService validatePassToken error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 刷新访问令牌（使用访问令牌和通行令牌双重验证）
     * 
     * @param string $accessToken 访问令牌
     * @param string $passToken 通行令牌（API Key）
     * @param int $expireTime 新的过期时间（Unix时间戳，0表示使用用户默认配置）
     * @return array|null ['access_token' => string, 'refresh_token' => string]，如果刷新失败返回null
     */
    public function refreshTokens(string $accessToken, string $passToken, int $expireTime = 0): ?array
    {
        try {
            // 验证访问令牌
            $user = $this->validateAccessToken($accessToken);
            if (!$user) {
                return null;
            }

            // 验证通行令牌（API Key）
            if ($user->getApiKey() !== $passToken) {
                return null;
            }

            // 删除旧的访问令牌和刷新令牌
            $this->revokeUserTokens($user->getId(), [ApiUserToken::TYPE_ACCESS_TOKEN, ApiUserToken::TYPE_REFRESH_TOKEN]);

            // 生成新的访问令牌和刷新令牌
            $newAccessToken = $this->generateAccessToken($user, $expireTime);
            $newRefreshToken = $this->generateRefreshToken($user, $expireTime);

            if (!$newAccessToken || !$newRefreshToken) {
                return null;
            }

            return [
                'access_token' => $newAccessToken,
                'refresh_token' => $newRefreshToken,
                'expire_time' => $expireTime > 0 ? $expireTime : (time() + $user->getTokenExpireTime()),
                'user' => [
                    'id' => $user->getId(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail(),
                    'is_sandbox' => $user->isSandboxAccount(),
                ]
            ];

        } catch (\Exception $e) {
            error_log('TokenService refreshTokens error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 撤销令牌
     * 
     * @param string $token 令牌
     * @return bool 是否成功
     */
    public function revokeToken(string $token): bool
    {
        try {
            /** @var ApiUserToken $tokenModel */
            $tokenModel = ObjectManager::getInstance(ApiUserToken::class);
            
            return $tokenModel->clear()
                ->where('token', $token)
                ->delete()
                ->fetch() !== false;

        } catch (\Exception $e) {
            error_log('TokenService revokeToken error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 撤销用户的所有令牌
     * 
     * @param int $userId 用户ID
     * @param array $types 要撤销的令牌类型（空数组表示撤销所有类型）
     * @return bool 是否成功
     */
    public function revokeUserTokens(int $userId, array $types = []): bool
    {
        try {
            /** @var ApiUserToken $tokenModel */
            $tokenModel = ObjectManager::getInstance(ApiUserToken::class);
            
            $query = $tokenModel->clear()->where('user_id', $userId);
            
            if (!empty($types)) {
                $query->where('type', $types, 'in');
            }
            
            return $query->delete()->fetch() !== false;

        } catch (\Exception $e) {
            error_log('TokenService revokeUserTokens error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 使用API Key/Secret交换令牌
     * 
     * @param string $apiKey API Key
     * @param string $apiSecret API Secret
     * @param int $expireTime 过期时间（Unix时间戳，0表示使用用户默认配置）
     * @return array|null ['access_token' => string, 'refresh_token' => string]，如果交换失败返回null
     */
    public function exchangeTokens(string $apiKey, string $apiSecret, int $expireTime = 0): ?array
    {
        try {
            // 验证API Key和Secret
            $user = $this->validatePassToken($apiKey, $apiSecret);
            if (!$user) {
                return null;
            }

            // 删除用户之前的访问令牌和刷新令牌
            $this->revokeUserTokens($user->getId(), [ApiUserToken::TYPE_ACCESS_TOKEN, ApiUserToken::TYPE_REFRESH_TOKEN]);

            // 生成新的访问令牌和刷新令牌
            $accessToken = $this->generateAccessToken($user, $expireTime);
            $refreshToken = $this->generateRefreshToken($user, $expireTime);

            if (!$accessToken || !$refreshToken) {
                return null;
            }

            return [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expire_time' => $expireTime > 0 ? $expireTime : (time() + $user->getTokenExpireTime()),
                'user' => [
                    'id' => $user->getId(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail(),
                    'is_sandbox' => $user->isSandboxAccount(),
                ]
            ];

        } catch (\Exception $e) {
            error_log('TokenService exchangeTokens error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 生成随机令牌
     * 
     * @param int $length 长度（字节数，最终字符串长度为length*2）
     * @return string 十六进制编码的令牌
     */
    private function generateToken(int $length = 64): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * 获取令牌信息
     * 
     * @param string $token 令牌
     * @return array|null 令牌信息，如果令牌无效返回null
     */
    public function getTokenInfo(string $token): ?array
    {
        try {
            /** @var ApiUserToken $tokenModel */
            $tokenModel = ObjectManager::getInstance(ApiUserToken::class);
            
            $tokenRecord = $tokenModel->clear()
                ->where('token', $token)
                ->find()
                ->fetch();

            if (!$tokenRecord->getId()) {
                return null;
            }

            /** @var ApiUser $user */
            $user = ObjectManager::getInstance(ApiUser::class);
            $user->load($tokenRecord->getUserId());

            return [
                'user_id' => $tokenRecord->getUserId(),
                'token' => $tokenRecord->getToken(),
                'type' => $tokenRecord->getType(),
                'expire_time' => $tokenRecord->getTokenExpireTime(),
                'is_expired' => $tokenRecord->isExpired(),
                'is_sandbox' => $user->getId() ? $user->isSandboxAccount() : false
            ];

        } catch (\Exception $e) {
            error_log('TokenService getTokenInfo error: ' . $e->getMessage());
            return null;
        }
    }
}

