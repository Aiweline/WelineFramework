<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Api\Rest\V1;

use Weline\Api\Model\ApiUser;
use Weline\Api\Service\TokenService;
use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;

/**
 * API认证控制器
 * 
 * 提供API用户登录、令牌交换、令牌刷新等功能
 */
class Auth extends FrontendRestController
{
    private ?TokenService $tokenService = null;
    
    private function getTokenService(): TokenService
    {
        if ($this->tokenService === null) {
            $this->tokenService = ObjectManager::getInstance(TokenService::class);
        }
        return $this->tokenService;
    }

    /**
     * 登录并获取token
     * 
     * 使用用户名和密码登录，返回访问令牌和刷新令牌
     * 
     * @param string $username 用户名（必填，通过POST参数获取）
     * @param string $password 密码（必填，通过POST参数获取）
     * @param int $expire_time 令牌过期时间（可选，秒数，通过POST参数获取，默认使用用户配置的过期时间）
     * @return array 返回数据格式：{"code": 200, "msg": "登录成功", "data": {"access_token": "...", "refresh_token": "...", "expire_time": 1234567890, "user": {...}}}
     * @throws \Exception 登录失败时抛出异常
     * @Document(summary='用户登录', description='使用用户名和密码登录，返回访问令牌和刷新令牌', tags=['认证', '登录'], category='认证接口')
     * @example
     * Method: POST
     * Path: /api/rest/v1/auth/login
     * Header:
     * - Content-Type: application/json
     * Body:
     * {
     *   "username": "admin",
     *   "password": "password123",
     *   "expire_time": 604800
     * }
     * Response:
     * {
     *   "code": 200,
     *   "msg": "登录成功",
     *   "data": {
     *     "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
     *     "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
     *     "expire_time": 1735689600,
     *     "user": {
     *       "id": 1,
     *       "username": "admin",
     *       "email": "admin@example.com"
     *     }
     *   }
     * }
     * @example-end
     */
    public function postLogin()
    {
        try {
            // 优先从请求体获取（支持JSON），如果没有则从POST获取
            $username = trim($this->request->getBodyParam('username') ?? $this->request->getPost('username') ?? '');
            $password = trim($this->request->getBodyParam('password') ?? $this->request->getPost('password') ?? '');
            $expireTime = (int)($this->request->getBodyParam('expire_time') ?? $this->request->getPost('expire_time') ?? 0);

            if (empty($username) || empty($password)) {
                return $this->error(__('用户名和密码不能为空'), '', 400);
            }

            /** @var ApiUser $user */
            $user = ObjectManager::getInstance(ApiUser::class);
            $user->clear()
                ->where(ApiUser::schema_fields_username, $username)
                ->where(ApiUser::schema_fields_is_deleted, 0)
                ->find()
                ->fetch();
            if (!$user->getId()) {
                return $this->error(__('用户不存在'), '', 401);
            }

            if (!$user->getIsEnabled()) {
                return $this->error(__('用户已被禁用'), '', 401);
            }

            // 验证密码
            if (!$user->verifyPassword($password)) {
                return $this->error(__('密码错误'), '', 401);
            }

            // 生成访问令牌和刷新令牌
            $accessToken = $this->getTokenService()->generateAccessToken($user, $expireTime);
            $refreshToken = $this->getTokenService()->generateRefreshToken($user, $expireTime);

            if (!$accessToken || !$refreshToken) {
                return $this->error(__('创建token失败'), '', 500);
            }

            $expireTime = $expireTime > 0 ? $expireTime : (time() + $user->getTokenExpireTime());

            return $this->success(__('登录成功'), [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expire_time' => $expireTime,
                'user' => [
                    'id' => $user->getId(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail(),
                    'is_sandbox' => $user->isSandboxAccount(),
                ],
            ]);

        } catch (\Exception $e) {
            return $this->error(__('登录失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 令牌交换
     * 
     * 使用API Key和API Secret交换访问令牌和刷新令牌
     * 
     * @param string $api_key API密钥（必填，通过POST参数获取）
     * @param string $api_secret API密钥（必填，通过POST参数获取）
     * @param int $expire_time 令牌过期时间（可选，秒数，通过POST参数获取，默认使用用户配置的过期时间）
     * @return array 返回数据格式：{"code": 200, "msg": "令牌交换成功", "data": {"access_token": "...", "refresh_token": "...", "expire_time": 1234567890}}
     * @throws \Exception 令牌交换失败时抛出异常
     * @Document(summary='令牌交换', description='使用API Key和API Secret交换访问令牌和刷新令牌', tags=['认证', '令牌'], category='认证接口')
     * @example
     * Method: POST
     * Path: /api/rest/v1/weline_api/auth/exchange
     * Header:
     * - Content-Type: application/json
     * Body:
     * {
     *   "api_key": "your_api_key_here",
     *   "api_secret": "your_api_secret_here",
     *   "expire_time": 604800
     * }
     * Response:
     * {
     *   "code": 200,
     *   "msg": "令牌交换成功",
     *   "data": {
     *     "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
     *     "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
     *     "expire_time": 1735689600
     *   }
     * }
     * @example-end
     */
    public function postExchange()
    {
        try {
            // 优先从请求体获取（支持JSON），如果没有则从POST获取
            $apiKey = trim($this->request->getBodyParam('api_key') ?? $this->request->getPost('api_key') ?? '');
            $apiSecret = trim($this->request->getBodyParam('api_secret') ?? $this->request->getPost('api_secret') ?? '');
            $expireTime = (int)($this->request->getBodyParam('expire_time') ?? $this->request->getPost('expire_time') ?? 0);

            if (empty($apiKey) || empty($apiSecret)) {
                return $this->error(__('API Key和API Secret不能为空'), '', 400);
            }

            // 使用TokenService交换令牌
            $result = $this->getTokenService()->exchangeTokens($apiKey, $apiSecret, $expireTime);

            if (!$result) {
                return $this->error(__('API Key或API Secret无效'), '', 401);
            }

            return $this->success(__('令牌交换成功'), $result);

        } catch (\Exception $e) {
            return $this->error(__('令牌交换失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 刷新token
     * 
     * 使用访问令牌和通行令牌（API Key）刷新访问令牌和刷新令牌（双重验证）
     * 
     * @param string $access_token 访问令牌（必填，通过POST参数获取）
     * @param string $pass_token 通行令牌/API Key（必填，通过POST参数获取）
     * @param int $expire_time 令牌过期时间（可选，秒数，通过POST参数获取，默认使用用户配置的过期时间）
     * @return array 返回数据格式：{"code": 200, "msg": "Token刷新成功", "data": {"access_token": "...", "refresh_token": "...", "expire_time": 1234567890}}
     * @throws \Exception 刷新失败时抛出异常
     * @Document(summary='刷新令牌', description='使用访问令牌和通行令牌（API Key）刷新访问令牌和刷新令牌', tags=['认证', '令牌'], category='认证接口')
     * @example
     * Method: POST
     * Path: /api/rest/v1/weline_api/auth/refresh
     * Header:
     * - Content-Type: application/json
     * Body:
     * {
     *   "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
     *   "pass_token": "your_api_key_here",
     *   "expire_time": 604800
     * }
     * Response:
     * {
     *   "code": 200,
     *   "msg": "Token刷新成功",
     *   "data": {
     *     "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
     *     "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
     *     "expire_time": 1735689600
     *   }
     * }
     * @example-end
     */
    public function postRefresh()
    {
        try {
            // 优先从请求体获取（支持JSON），如果没有则从POST获取
            $accessToken = trim($this->request->getBodyParam('access_token') ?? $this->request->getPost('access_token') ?? '');
            $passToken = trim($this->request->getBodyParam('pass_token') ?? $this->request->getPost('pass_token') ?? '');
            $expireTime = (int)($this->request->getBodyParam('expire_time') ?? $this->request->getPost('expire_time') ?? 0);

            if (empty($accessToken) || empty($passToken)) {
                return $this->error(__('访问令牌和通行令牌不能为空'), '', 400);
            }

            // 使用TokenService刷新令牌（双重验证）
            $result = $this->getTokenService()->refreshTokens($accessToken, $passToken, $expireTime);

            if (!$result) {
                return $this->error(__('令牌无效或已过期'), '', 401);
            }

            return $this->success(__('Token刷新成功'), $result);

        } catch (\Exception $e) {
            return $this->error(__('刷新token失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 撤销token
     * 
     * @param string $token 访问令牌（必填，通过Authorization头、X-API-Token头、URL参数或POST参数获取）
     * @return array 返回数据格式：{"code": 200, "msg": "Token已撤销", "data": ""}
     * @throws \Exception 撤销失败时抛出异常
     * @Document(summary='撤销令牌', description='撤销当前访问令牌', tags=['认证', '令牌'], category='认证接口')
     * @example
     * Method: POST
     * Path: /api/rest/v1/weline_api/auth/logout
     * Header:
     * - Content-Type: application/json
     * - Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
     * Body:
     * {
     *   "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
     * }
     * Response:
     * {
     *   "code": 200,
     *   "msg": "Token已撤销",
     *   "data": ""
     * }
     * @example-end
     */
    public function postLogout()
    {
        try {
            $token = $this->getTokenFromRequest();
            if (empty($token)) {
                return $this->error(__('Token不能为空'), '', 400);
            }

            $result = $this->getTokenService()->revokeToken($token);
            if (!$result) {
                return $this->error(__('撤销token失败'), '', 500);
            }

            return $this->success(__('Token已撤销'));

        } catch (\Exception $e) {
            return $this->error(__('撤销token失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 获取当前用户信息
     * 
     * @param string $token 访问令牌（必填，通过Authorization头、X-API-Token头、URL参数或POST参数获取）
     * @return array 返回数据格式：{"code": 200, "msg": "获取用户信息成功", "data": {"user": {"id": 1, "username": "...", "email": "...", "is_enabled": true}}}
     * @throws \Exception 获取失败时抛出异常
     * @Document(summary='获取用户信息', description='获取当前登录用户的信息', tags=['认证', '用户'], category='认证接口')
     * @example
     * Method: GET
     * Path: /api/rest/v1/weline_api/auth/me
     * Header:
     * - Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
     * Request Parameters:
     * - token: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9... (可选，如果Header中已提供Authorization则不需要)
     * Response:
     * {
     *   "code": 200,
     *   "msg": "获取用户信息成功",
     *   "data": {
     *     "user": {
     *       "id": 1,
     *       "username": "admin",
     *       "email": "admin@example.com",
     *       "is_enabled": true
     *     }
     *   }
     * }
     * @example-end
     */
    public function getMe()
    {
        try {
            // 从请求中获取token
            $token = $this->getTokenFromRequest();
            if (empty($token)) {
                return $this->error(__('Token不能为空'), '', 400);
            }

            // 验证token并获取用户
            $user = $this->getTokenService()->validateAccessToken($token);
            if (!$user) {
                return $this->error(__('Token无效或已过期'), '', 401);
            }

            return $this->success(__('获取用户信息成功'), [
                'user' => [
                    'id' => $user->getId(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail(),
                    'is_enabled' => $user->getIsEnabled(),
                    'is_sandbox' => $user->isSandboxAccount(),
                ]
            ]);

        } catch (\Exception $e) {
            return $this->error(__('获取用户信息失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 获取token信息
     * 
     * @param string $token 访问令牌（必填，通过Authorization头、X-API-Token头、URL参数或POST参数获取）
     * @return array 返回数据格式：{"code": 200, "msg": "获取token信息成功", "data": {"token_info": {...}}}
     * @throws \Exception 获取失败时抛出异常
     * @Document(summary='获取令牌信息', description='获取当前访问令牌的详细信息', tags=['认证', '令牌'], category='认证接口')
     * @example
     * Method: GET
     * Path: /api/rest/v1/weline_api/auth/token-info
     * Header:
     * - Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
     * Request Parameters:
     * - token: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9... (可选，如果Header中已提供Authorization则不需要)
     * Response:
     * {
     *   "code": 200,
     *   "msg": "获取token信息成功",
     *   "data": {
     *     "token_info": {
     *       "user_id": 1,
     *       "expire_time": 1735689600,
     *       "created_at": "2025-01-01 00:00:00"
     *     }
     *   }
     * }
     * @example-end
     */
    public function getTokenInfo()
    {
        try {
            $token = $this->getTokenFromRequest();
            if (empty($token)) {
                return $this->error(__('Token不能为空'), '', 400);
            }

            $tokenInfo = $this->getTokenService()->getTokenInfo($token);
            if (!$tokenInfo) {
                return $this->error(__('Token无效'), '', 401);
            }

            return $this->success(__('获取token信息成功'), $tokenInfo);

        } catch (\Exception $e) {
            return $this->error(__('获取token信息失败：%{1}', [$e->getMessage()]), '', 500);
        }
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
     * 成功响应
     */
    protected function success(string $msg = '请求成功！', mixed $data = '', int $code = 200): array|string
    {
        return parent::success($msg, $data, $code);
    }

    /**
     * 错误响应
     */
    protected function error(string $msg = '请求失败！', mixed $data = '', int $code = 400, ?string $title = null): array|string
    {
        return parent::error($msg, $data, $code, $title);
    }
}

