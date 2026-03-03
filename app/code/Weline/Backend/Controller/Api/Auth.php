<?php

namespace Weline\Backend\Controller\Api;

use Weline\Backend\Model\BackendUser;
use Weline\Backend\Service\BackendTokenService;
use Weline\Framework\App\Controller\BackendRestController;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class Auth extends BackendRestController
{
    private BackendTokenService $tokenService;

    public function __construct(
        Request $request,
        BackendTokenService $tokenService
    ) {
        parent::__construct();
        $this->request = $request;
        $this->tokenService = $tokenService;
    }

    /**
     * 登录并获取token
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
            $token = $this->tokenService->createApiToken($user, $expireTime);
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
                ],
                'expire_time' => $expireTime > 0 ? $expireTime : time() + (7 * 24 * 60 * 60)
            ]);

        } catch (\Exception $e) {
            return $this->error(__('登录失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 刷新token
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
            $newToken = $this->tokenService->refreshToken($token, $expireTime);

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
     */
    public function logout()
    {
        try {
            $token = $this->getTokenFromRequest();
            if (empty($token)) {
                return $this->error(__('Token不能为空'), '', 400);
            }

            $result = $this->tokenService->revokeToken($token);
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
     */
    public function me()
    {
        try {
            $token = $this->getTokenFromRequest();
            $user = $token ? $this->tokenService->getUserByToken($token) : null;
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
                ]
            ]);

        } catch (\Exception $e) {
            return $this->error(__('获取用户信息失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 获取token信息
     */
    public function tokenInfo()
    {
        try {
            $token = $this->getTokenFromRequest();
            if (empty($token)) {
                return $this->error(__('Token不能为空'), '', 400);
            }

            $tokenInfo = $this->tokenService->getTokenInfo($token);
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