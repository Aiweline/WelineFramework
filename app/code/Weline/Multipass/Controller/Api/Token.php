<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Multipass\Controller\Api;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Multipass\Model\MultipassSite;
use Weline\Multipass\Service\MultipassService;

/**
 * Multipass Token API 控制器
 * 提供给第三方站点调用，生成 Multipass token
 */
class Token extends FrontendRestController
{
    private MultipassService $multipassService;

    public function __construct()
    {
        parent::__construct();
        $this->multipassService = ObjectManager::getInstance(MultipassService::class);
    }

    /**
     * 生成 Multipass Token
     * POST /rest/v1/multipass/token/generate
     * 
     * 参数：
     * - site_id: 站点ID
     * - secret_key: 站点密钥（用于验证站点身份）
     * - username: 用户名（可选，如果提供邮箱则不需要）
     * - email: 邮箱（可选，如果提供用户名则不需要）
     * - avatar: 头像URL（可选）
     * - [其他用户数据字段]
     */
    public function postGenerate()
    {
        try {
            $siteId = $this->request->getPost('site_id');
            $secretKey = $this->request->getPost('secret_key');
            $username = $this->request->getPost('username');
            $email = $this->request->getPost('email');

            // 验证必需参数
            if (empty($siteId)) {
                return $this->error(__('缺少站点ID'), '', 400);
            }

            if (empty($secretKey)) {
                return $this->error(__('缺少密钥'), '', 400);
            }

            if (empty($username) && empty($email)) {
                return $this->error(__('用户名或邮箱至少需要提供一个'), '', 400);
            }

            // 获取站点配置
            /** @var MultipassSite $site */
            $site = ObjectManager::getInstance(MultipassSite::class);
            $site->load($siteId);

            if (!$site->getId()) {
                return $this->error(__('站点配置不存在'), '', 404);
            }

            // 验证密钥
            if ($site->getSecretKey() !== $secretKey) {
                return $this->error(__('密钥验证失败'), '', 401);
            }

            // 验证站点是否启用
            if (!$site->getIsEnabled()) {
                return $this->error(__('站点已禁用'), '', 403);
            }

            // 准备用户数据
            $userData = [];
            if (!empty($username)) {
                $userData['username'] = $username;
            }
            if (!empty($email)) {
                $userData['email'] = $email;
            }

            // 添加其他可能的用户数据
            $avatar = $this->request->getPost('avatar');
            if (!empty($avatar)) {
                $userData['avatar'] = $avatar;
            }

            // 获取所有 POST 数据，排除已知字段
            $allPostData = $this->request->getPost();
            $excludedFields = ['site_id', 'secret_key', 'username', 'email', 'avatar'];
            foreach ($allPostData as $key => $value) {
                if (!in_array($key, $excludedFields) && !empty($value)) {
                    $userData[$key] = $value;
                }
            }

            // 生成 token
            $token = $this->multipassService->generateToken($site, $userData);

            // 生成登录URL
            // 获取当前请求的基础URL（去掉API路径）
            $currentUrl = $this->request->getUrlBuilder()->getCurrentUrl();
            $baseUrl = preg_replace('#/rest/v1/.*$#', '', $currentUrl);
            $baseUrl = rtrim($baseUrl, '/');
            
            if ($site->getUserType() === 'frontend') {
                $loginUrl = $baseUrl . '/multipass/frontend/multipass?token=' . urlencode($token) . '&site_id=' . $siteId;
            } else {
                // 后端需要添加 admin key
                $adminKey = $this->request->getEnv('ADMIN_KEY') ?? 'admin';
                $loginUrl = $baseUrl . '/' . $adminKey . '/multipass/backend/multipass?token=' . urlencode($token) . '&site_id=' . $siteId;
            }

            return $this->success(__('Token生成成功'), [
                'token' => $token,
                'login_url' => $loginUrl,
                'site_id' => $siteId,
                'user_type' => $site->getUserType()
            ]);

        } catch (\Exception $e) {
            return $this->error(__('Token生成失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 验证 Token（用于测试）
     * POST /rest/v1/multipass/token/verify
     */
    public function postVerify()
    {
        try {
            $token = $this->request->getPost('token');
            $siteId = $this->request->getPost('site_id');

            if (empty($token)) {
                return $this->error(__('缺少token'), '', 400);
            }

            if (empty($siteId)) {
                return $this->error(__('缺少站点ID'), '', 400);
            }

            // 获取站点配置
            /** @var MultipassSite $site */
            $site = ObjectManager::getInstance(MultipassSite::class);
            $site->load($siteId);

            if (!$site->getId()) {
                return $this->error(__('站点配置不存在'), '', 404);
            }

            // 验证 token
            $userData = $this->multipassService->verifyToken($site, $token);

            if (!$userData) {
                return $this->error(__('Token验证失败或已过期'), '', 401);
            }

            return $this->success(__('Token验证成功'), [
                'user_data' => $userData,
                'site_id' => $siteId,
                'user_type' => $site->getUserType()
            ]);

        } catch (\Exception $e) {
            return $this->error(__('Token验证失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
}

