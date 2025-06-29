<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App\Session;

use Weline\Backend\Model\BackendUserToken;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;

class BackendApiSession extends Session
{
    public const login_KEY = 'WL_BACKEND_API';
    public const login_KEY_ID = 'WL_BACKEND_API_ID';
    public const login_USER_MODEL = 'WL_BACKEND_API_MODEL';
    
    private ?BackendUser $apiUser = null;
    private Request $request;

    public function __construct()
    {
        $this->request = ObjectManager::getInstance(Request::class);
        parent::__construct();
    }

    public function __init()
    {
        parent::__init();
        $this->setType('backend_api');
        
        // 尝试从token自动登录
        $this->tryTokenLogin();
    }

    /**
     * 尝试通过token进行自动登录
     */
    private function tryTokenLogin(): void
    {
        // 如果已经登录，不需要再次验证
        if ($this->isLogin()) {
            return;
        }

        // 从请求头获取token
        $token = $this->getTokenFromRequest();
        if (empty($token)) {
            return;
        }

        // 验证token并自动登录
        $this->loginByToken($token);
    }

    /**
     * 从请求中获取token
     */
    private function getTokenFromRequest(): ?string
    {
        // 1. 从Authorization头获取Bearer token
        $authHeader = $this->request->getAuth('bearer');
        if (!empty($authHeader)) {
            return $authHeader;
        }

        // 2. 从X-API-Token头获取
        $apiToken = $this->request->getHeader('X-API-Token');
        if (!empty($apiToken)) {
            return $apiToken;
        }

        // 3. 从请求参数获取
        $tokenParam = $this->request->getParam('token');
        if (!empty($tokenParam)) {
            return $tokenParam;
        }

        // 4. 从POST数据获取
        $postToken = $this->request->getPost('token');
        if (!empty($postToken)) {
            return $postToken;
        }

        return null;
    }

    /**
     * 通过token登录
     */
    public function loginByToken(string $token): bool
    {
        try {
            /** @var BackendUserToken $backendUserToken */
            $backendUserToken = ObjectManager::getInstance(BackendUserToken::class);
            
            // 查找token记录
            $tokenRecord = $backendUserToken
                ->where($backendUserToken::fields_token, $token)
                ->where($backendUserToken::fields_type, 'api_token')
                ->find()
                ->fetch();

            if (!$tokenRecord->getId()) {
                return false;
            }

            // 检查token是否过期
            $expireTime = (int)$tokenRecord->getData($backendUserToken::fields_token_expire_time);
            if ($expireTime > 0 && $expireTime < time()) {
                // token已过期，删除记录
                $tokenRecord->delete();
                return false;
            }

            // 获取用户信息
            $userId = $tokenRecord->getData($backendUserToken::fields_ID);
            /** @var BackendUser $user */
            $user = ObjectManager::getInstance(BackendUser::class)->load($userId);
            
            if (!$user->getId()) {
                // 用户不存在，删除token记录
                $tokenRecord->delete();
                return false;
            }

            // 检查用户状态
            if (!$user->getIsEnabled()) {
                return false;
            }

            // 执行登录
            $this->login($user);
            
            // 更新用户登录信息
            $user->setSessionId($this->getSessionId())
                ->setLoginIp($this->request->clientIP())
                ->resetAttemptTimes()
                ->save();

            return true;

        } catch (\Exception $e) {
            error_log('BackendApiSession loginByToken error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 创建API token
     */
    public function createApiToken(BackendUser $user, int $expireTime = 0): ?string
    {
        try {
            if ($expireTime <= 0) {
                // 默认7天过期
                $expireTime = time() + (7 * 24 * 60 * 60);
            }

            // 生成token
            $token = $this->generateToken();

            /** @var BackendUserToken $backendUserToken */
            $backendUserToken = ObjectManager::getInstance(BackendUserToken::class);
            
            // 删除该用户之前的API token
            $backendUserToken
                ->where($backendUserToken::fields_ID, $user->getId())
                ->where($backendUserToken::fields_type, 'api_token')
                ->delete();

            // 创建新的token记录
            $backendUserToken
                ->setData($backendUserToken::fields_ID, $user->getId())
                ->setData($backendUserToken::fields_token, $token)
                ->setData($backendUserToken::fields_type, 'api_token')
                ->setData($backendUserToken::fields_token_expire_time, $expireTime)
                ->save();

            return $token;

        } catch (\Exception $e) {
            error_log('BackendApiSession createApiToken error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 生成token
     */
    private function generateToken(int $length = 64): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * 刷新token
     */
    public function refreshToken(string $token, int $expireTime = 0): ?string
    {
        try {
            if ($expireTime <= 0) {
                $expireTime = time() + (7 * 24 * 60 * 60);
            }

            /** @var BackendUserToken $backendUserToken */
            $backendUserToken = ObjectManager::getInstance(BackendUserToken::class);
            
            $tokenRecord = $backendUserToken
                ->where($backendUserToken::fields_token, $token)
                ->where($backendUserToken::fields_type, 'api_token')
                ->find()
                ->fetch();

            if (!$tokenRecord->getId()) {
                return null;
            }

            // 更新过期时间
            $tokenRecord
                ->setData($backendUserToken::fields_token_expire_time, $expireTime)
                ->save();

            return $token;

        } catch (\Exception $e) {
            error_log('BackendApiSession refreshToken error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 撤销token
     */
    public function revokeToken(string $token): bool
    {
        try {
            /** @var BackendUserToken $backendUserToken */
            $backendUserToken = ObjectManager::getInstance(BackendUserToken::class);
            
            return $backendUserToken
                ->where($backendUserToken::fields_token, $token)
                ->where($backendUserToken::fields_type, 'api_token')
                ->delete()->fetch();

        } catch (\Exception $e) {
            error_log('BackendApiSession revokeToken error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取当前API用户
     */
    public function getApiUser(): ?BackendUser
    {
        if ($this->apiUser) {
            return $this->apiUser;
        }

        if (!$this->isLogin()) {
            return null;
        }

        $userId = $this->getLoginUserID();
        if (!$userId) {
            return null;
        }

        $this->apiUser = ObjectManager::getInstance(BackendUser::class)->load($userId);
        return $this->apiUser;
    }

    /**
     * 重写登录方法，支持API token
     */
    public function login(\Weline\Framework\Database\Model $user): static
    {
        parent::login($user);
        $this->setData(self::login_KEY_ID, $user->getId());
        $this->setData(self::login_USER_MODEL, $user::class);
        return $this;
    }

    /**
     * 重写登出方法
     */
    public function logout(): bool
    {
        $this->apiUser = null;
        return parent::logout();
    }

    /**
     * 检查是否是API请求
     */
    public function isApiRequest(): bool
    {
        return $this->request->isApi() || $this->request->isApiBackend();
    }

    /**
     * 获取token信息
     */
    public function getTokenInfo(string $token): ?array
    {
        try {
            /** @var BackendUserToken $backendUserToken */
            $backendUserToken = ObjectManager::getInstance(BackendUserToken::class);
            
            $tokenRecord = $backendUserToken
                ->where($backendUserToken::fields_token, $token)
                ->where($backendUserToken::fields_type, 'api_token')
                ->find()
                ->fetch();

            if (!$tokenRecord->getId()) {
                return null;
            }

            return [
                'user_id' => $tokenRecord->getData($backendUserToken::fields_ID),
                'token' => $tokenRecord->getData($backendUserToken::fields_token),
                'type' => $tokenRecord->getData($backendUserToken::fields_type),
                'expire_time' => $tokenRecord->getData($backendUserToken::fields_token_expire_time),
                'is_expired' => (int)$tokenRecord->getData($backendUserToken::fields_token_expire_time) < time()
            ];

        } catch (\Exception $e) {
            error_log('BackendApiSession getTokenInfo error: ' . $e->getMessage());
            return null;
        }
    }
}
