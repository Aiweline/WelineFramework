<?php

namespace Weline\Api\Api\Rest\V1\Backend;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\App\Controller\BackendRestController;
use Weline\Framework\App\Session\BackendApiSession;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class Auth extends BackendRestController
{
    private BackendApiSession $apiSession;
    protected Request $request;

    public function __construct(
        Request $request,
        BackendApiSession $apiSession
    ) {
        $this->request = $request;
        $this->apiSession = $apiSession;
        parent::__construct($apiSession);
    }

    /**
     * 登录并获取token
     * 
     * 使用后端管理员用户名和密码登录，返回访问令牌
     * 
     * @param string $username 用户名（必填，通过POST参数获取）
     * @param string $password 密码（必填，通过POST参数获取）
     * @param int $expire_time 令牌过期时间（可选，秒数，通过POST参数获取，默认7天）
     * @return array 返回数据格式：{"code": 200, "msg": "登录成功", "data": {"token": "...", "user": {...}, "expire_time": 1234567890}}
     * @throws \Exception 登录失败时抛出异常
     * @Document(summary='后端管理员登录', description='使用后端管理员用户名和密码登录，返回访问令牌。需要提供有效的后端管理员账户。', tags=['认证', '登录', '后端'], category='认证接口')
     * @example
     * Method: POST
     * Path: /{api_admin}/rest/v1/weline_api/backend/auth/login
     * Header:
     * - Content-Type: application/json
     * Body:
     * {
     *   "username": "admin",
     *   "password": "admin123",
     *   "expire_time": 604800
     * }
     * Response:
     * {
     *   "code": 200,
     *   "msg": "登录成功",
     *   "data": {
     *     "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
     *     "user": {
     *       "id": 1,
     *       "username": "admin",
     *       "email": "admin@example.com",
     *       "avatar": ""
     *     },
     *     "expire_time": 1735689600
     *   }
     * }
     * @example-end
     */
    public function login()
    {
        try {
            $username = $this->request->getBodyParam('username');
            if ($username === null) {
                $username = $this->request->getPost('username');
            }
            $username = is_string($username) ? trim($username) : '';

            $password = $this->request->getBodyParam('password');
            if ($password === null) {
                $password = $this->request->getPost('password');
            }
            $password = is_string($password) ? trim($password) : '';

            $expireTime = $this->request->getBodyParam('expire_time');
            if ($expireTime === null) {
                $expireTime = $this->request->getPost('expire_time', 0);
            }
            $expireTime = (int)$expireTime;

            if (empty($username) || empty($password)) {
                return $this->error(__('用户名和密码不能为空'), '', 400);
            }

            /** @var BackendUser $user */
            $user = ObjectManager::getInstance(BackendUser::class);
            $user->where('username', $username)->find()->fetch();

            if (!$user->getId()) {
                return $this->error(__('用户不存在'), '', 401);
            }

            if (!$user->getIsEnabled()) {
                return $this->error(__('用户已被禁用'), '', 401);
            }

            // 验证密码
            if (!password_verify($password, $user->getPassword())) {
                // 增加登录失败次数
                $user->addAttemptTimes()->save();
                return $this->error(__('密码错误'), '', 401);
            }

            // 重置登录失败次数
            $user->resetAttemptTimes()->save();

            // 创建API token
            $token = $this->apiSession->createApiToken($user, $expireTime);
            if (!$token) {
                return $this->error(__('创建token失败'), '', 500);
            }

            // 更新用户登录信息
            $user->setLoginIp($this->request->clientIP())->save();

            return $this->success(__('登录成功'), [
                'token' => $token,
                'user' => [
                    'id' => $user->getId(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail(),
                    'avatar' => $user->getAvatar(),
                    'is_sandbox' => $user->isSandboxAccount(),
                ],
                'expire_time' => $expireTime > 0 ? $expireTime : time() + (7 * 24 * 60 * 60)
            ]);

        } catch (\Exception $e) {
            return $this->error(__('登录失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 刷新token
     * 
     * 使用当前token刷新获取新的访问令牌
     * 
     * @param string $token 当前访问令牌（必填，通过Authorization头、X-API-Token头、URL参数或POST参数获取）
     * @param int $expire_time 新令牌过期时间（可选，秒数，通过POST参数获取，默认7天）
     * @return array 返回数据格式：{"code": 200, "msg": "Token刷新成功", "data": {"token": "...", "expire_time": 1234567890}}
     * @throws \Exception 刷新失败时抛出异常
     * @Document(summary='刷新访问令牌', description='使用当前有效的访问令牌刷新获取新的访问令牌。需要提供有效的token。', tags=['认证', '令牌', '后端'], category='认证接口')
     * @example
     * Method: POST
     * Path: /{api_admin}/rest/v1/weline_api/backend/auth/refresh
     * Header:
     * - Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
     * - Content-Type: application/json
     * Body:
     * {
     *   "expire_time": 604800
     * }
     * Response:
     * {
     *   "code": 200,
     *   "msg": "Token刷新成功",
     *   "data": {
     *     "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
     *     "expire_time": 1735689600
     *   }
     * }
     * @example-end
     */
    public function refresh()
    {
        try {
            $token = $this->getTokenFromRequest();
            if (empty($token)) {
                return $this->error(__('Token不能为空'), '', 400);
            }

            $expireTime = $this->request->getBodyParam('expire_time');
            if ($expireTime === null) {
                $expireTime = $this->request->getPost('expire_time', 0);
            }
            $expireTime = (int)$expireTime;
            $newToken = $this->apiSession->refreshToken($token, $expireTime);

            if (!$newToken) {
                return $this->error(__('Token无效或已过期'), '', 401);
            }

            return $this->success(__('Token刷新成功'), [
                'token' => $newToken,
                'expire_time' => $expireTime > 0 ? $expireTime : time() + (7 * 24 * 60 * 60)
            ]);

        } catch (\Exception $e) {
            return $this->error(__('刷新token失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 撤销token
     * 
     * 撤销当前访问令牌，使其立即失效
     * 
     * @param string $token 访问令牌（必填，通过Authorization头、X-API-Token头、URL参数或POST参数获取）
     * @return array 返回数据格式：{"code": 200, "msg": "Token已撤销", "data": null}
     * @throws \Exception 撤销失败时抛出异常
     * @Document(summary='撤销访问令牌', description='撤销当前访问令牌，使其立即失效。需要提供有效的token。', tags=['认证', '令牌', '后端'], category='认证接口')
     * @example
     * Method: POST
     * Path: /{api_admin}/rest/v1/weline_api/backend/auth/logout
     * Header:
     * - Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
     * - Content-Type: application/json
     * Response:
     * {
     *   "code": 200,
     *   "msg": "Token已撤销",
     *   "data": null
     * }
     * @example-end
     */
    public function logout()
    {
        try {
            $token = $this->getTokenFromRequest();
            if (empty($token)) {
                return $this->error(__('Token不能为空'), '', 400);
            }

            $result = $this->apiSession->revokeToken($token);
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
     * 获取当前登录的后端管理员用户信息
     * 
     * @return array 返回数据格式：{"code": 200, "msg": "获取用户信息成功", "data": {"user": {...}}}
     * @throws \Exception 获取失败时抛出异常
     * @Document(summary='获取当前用户信息', description='获取当前登录的后端管理员用户的详细信息，包括ID、用户名、邮箱、头像等。需要已登录状态。', tags=['用户', '后端'], category='用户接口')
     * @example
     * Method: GET
     * Path: /{api_admin}/rest/v1/weline_api/backend/auth/me
     * Header:
     * - Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
     * Response:
     * {
     *   "code": 200,
     *   "msg": "获取用户信息成功",
     *   "data": {
     *     "user": {
     *       "id": 1,
     *       "username": "admin",
     *       "email": "admin@example.com",
     *       "avatar": "",
     *       "is_enabled": 1,
     *       "login_ip": "127.0.0.1",
     *       "login_time": "2024-01-01 12:00:00"
     *     }
     *   }
     * }
     * @example-end
     */
    public function me()
    {
        try {
            $user = $this->apiSession->getApiUser();
            if (!$user) {
                return $this->error(__('用户未登录'), '', 401);
            }

            return $this->success(__('获取用户信息成功'), [
                'user' => [
                    'id' => $user->getId(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail(),
                    'avatar' => $user->getAvatar(),
                    'is_enabled' => $user->getIsEnabled(),
                    'login_ip' => $user->getLoginIp(),
                    'login_time' => $user->getLoginTime(),
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
     * 获取指定访问令牌的详细信息
     * 
     * @param string $token 访问令牌（必填，通过Authorization头、X-API-Token头、URL参数或POST参数获取）
     * @return array 返回数据格式：{"code": 200, "msg": "获取token信息成功", "data": {...}}
     * @throws \Exception 获取失败时抛出异常
     * @Document(summary='获取token信息', description='获取指定访问令牌的详细信息，包括令牌状态、过期时间等。需要提供有效的token。', tags=['认证', '令牌', '后端'], category='认证接口')
     * @example
     * Method: GET
     * Path: /{api_admin}/rest/v1/weline_api/backend/auth/token-info
     * Header:
     * - Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
     * Response:
     * {
     *   "code": 200,
     *   "msg": "获取token信息成功",
     *   "data": {
     *     "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
     *     "user_id": 1,
     *     "expire_time": 1735689600,
     *     "created_at": "2024-01-01 12:00:00"
     *   }
     * }
     * @example-end
     */
    public function tokenInfo()
    {
        try {
            $token = $this->getTokenFromRequest();
            if (empty($token)) {
                return $this->error(__('Token不能为空'), '', 400);
            }

            $tokenInfo = $this->apiSession->getTokenInfo($token);
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
}

