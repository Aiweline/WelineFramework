<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Controller;

use Weline\Api\Model\ApiUser;
use Weline\Api\Service\TokenService;
use Weline\Framework\Controller\AbstractRestController;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

/**
 * API认证控制器
 * 
 * 提供API用户登录、令牌交换、令牌刷新等功能
 */
class Auth extends AbstractRestController
{
    private TokenService $tokenService;
    protected Request $request;

    public function __construct(
        Request $request,
        TokenService $tokenService
    ) {
        $this->request = $request;
        $this->tokenService = $tokenService;
    }

    /**
     * 登录并获取token
     * 
     * 使用用户名和密码登录，返回访问令牌和刷新令牌
     */
    public function login()
    {
        try {
            $username = trim($this->request->getPost('username') ?? '');
            $password = trim($this->request->getPost('password') ?? '');
            $expireTime = (int)($this->request->getPost('expire_time') ?? 0);

            if (empty($username) || empty($password)) {
                return $this->error(__('用户名和密码不能为空'), '', 400);
            }

            /** @var ApiUser $user */
            $user = ObjectManager::getInstance(ApiUser::class);
            $user->where('username', $username)
                ->where('is_deleted', 0)
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
            $accessToken = $this->tokenService->generateAccessToken($user, $expireTime);
            $refreshToken = $this->tokenService->generateRefreshToken($user, $expireTime);

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
     */
    public function exchange()
    {
        try {
            $apiKey = trim($this->request->getPost('api_key') ?? '');
            $apiSecret = trim($this->request->getPost('api_secret') ?? '');
            $expireTime = (int)($this->request->getPost('expire_time') ?? 0);

            if (empty($apiKey) || empty($apiSecret)) {
                return $this->error(__('API Key和API Secret不能为空'), '', 400);
            }

            // 使用TokenService交换令牌
            $result = $this->tokenService->exchangeTokens($apiKey, $apiSecret, $expireTime);

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
     */
    public function refresh()
    {
        try {
            $accessToken = trim($this->request->getPost('access_token') ?? '');
            $passToken = trim($this->request->getPost('pass_token') ?? '');
            $expireTime = (int)($this->request->getPost('expire_time') ?? 0);

            if (empty($accessToken) || empty($passToken)) {
                return $this->error(__('访问令牌和通行令牌不能为空'), '', 400);
            }

            // 使用TokenService刷新令牌（双重验证）
            $result = $this->tokenService->refreshTokens($accessToken, $passToken, $expireTime);

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
            // 从请求中获取token
            $token = $this->getTokenFromRequest();
            if (empty($token)) {
                return $this->error(__('Token不能为空'), '', 400);
            }

            // 验证token并获取用户
            $user = $this->tokenService->validateAccessToken($token);
            if (!$user) {
                return $this->error(__('Token无效或已过期'), '', 401);
            }

            return $this->success(__('获取用户信息成功'), [
                'user' => [
                    'id' => $user->getId(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail(),
                    'is_enabled' => $user->getIsEnabled(),
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

    /**
     * 成功响应
     */
    protected function success(string $msg = '请求成功！', mixed $data = '', int $code = 200)
    {
        return $this->fetch(['msg' => $msg, 'data' => $data, 'code' => $code]);
    }

    /**
     * 错误响应
     */
    protected function error(string $msg = '请求失败！', mixed $data = '', int $code = 404)
    {
        return $this->fetch(['msg' => $msg, 'data' => $data, 'code' => $code]);
    }
}

