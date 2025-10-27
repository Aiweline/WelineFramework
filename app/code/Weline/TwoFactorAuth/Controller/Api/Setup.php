<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Controller\Api;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\TwoFactorAuth\Service\TwoFactorAuthService;

/**
 * 2FA设置API
 * 
 * @package Weline\TwoFactorAuth\Controller\Api
 */
class Setup extends FrontendRestController
{
    private TwoFactorAuthService $twoFactorAuthService;

    public function __construct(
        TwoFactorAuthService $twoFactorAuthService
    ) {
        $this->twoFactorAuthService = $twoFactorAuthService;
    }

    /**
     * 初始化2FA
     * 
     * POST /api/2fa/initialize
     * Body: { "user_id": 1, "account": "user@example.com" }
     */
    public function initialize()
    {
        if (!$this->request->isPost()) {
            return $this->error('无效的请求方法', 405);
        }

        $userId = (int)$this->request->getPost('user_id');
        $account = $this->request->getPost('account');

        if (!$userId || !$account) {
            return $this->error('缺少必要参数', 400);
        }

        $data = $this->twoFactorAuthService->initialize($userId);
        $secret = $data['secret'];
        $backupCodes = $data['backup_codes'];

        $qrCodeUrl = $this->twoFactorAuthService->getQRCodeUrl($secret, $account);
        $qrCodeUri = $this->twoFactorAuthService->getQRCodeUri($secret, $account);

        return $this->success([
            'secret' => $secret,
            'formatted_secret' => $this->twoFactorAuthService->formatSecret($secret),
            'backup_codes' => $backupCodes,
            'qr_code_url' => $qrCodeUrl,
            'qr_code_uri' => $qrCodeUri,
        ]);
    }

    /**
     * 启用2FA
     * 
     * POST /api/2fa/enable
     * Body: { "user_id": 1, "secret": "...", "code": "123456", "backup_codes": [...] }
     */
    public function enable()
    {
        if (!$this->request->isPost()) {
            return $this->error('无效的请求方法', 405);
        }

        $userId = (int)$this->request->getPost('user_id');
        $secret = $this->request->getPost('secret');
        $code = $this->request->getPost('code');
        $backupCodes = $this->request->getPost('backup_codes');

        if (!$userId || !$secret || !$code) {
            return $this->error('缺少必要参数', 400);
        }

        // 解析备份码
        if (is_string($backupCodes)) {
            $backupCodes = json_decode($backupCodes, true) ?? [];
        }

        $success = $this->twoFactorAuthService->enable($userId, $secret, $code, $backupCodes);

        if ($success) {
            return $this->success([
                'message' => '2FA已启用'
            ]);
        } else {
            return $this->error('验证码错误', 400);
        }
    }

    /**
     * 禁用2FA
     * 
     * POST /api/2fa/disable
     * Body: { "user_id": 1, "code": "123456" }
     */
    public function disable()
    {
        if (!$this->request->isPost()) {
            return $this->error('无效的请求方法', 405);
        }

        $userId = (int)$this->request->getPost('user_id');
        $code = $this->request->getPost('code');

        if (!$userId || !$code) {
            return $this->error('缺少必要参数', 400);
        }

        $success = $this->twoFactorAuthService->disable($userId, $code);

        if ($success) {
            return $this->success([
                'message' => '2FA已禁用'
            ]);
        } else {
            return $this->error('验证码错误或未启用2FA', 400);
        }
    }

    /**
     * 获取用户配置
     * 
     * GET /api/2fa/config?user_id=1
     */
    public function config()
    {
        $userId = (int)$this->request->getGet('user_id');

        if (!$userId) {
            return $this->error('缺少用户ID', 400);
        }

        $config = $this->twoFactorAuthService->getUserConfig($userId);

        if ($config) {
            return $this->success($config);
        } else {
            return $this->error('未找到用户配置', 404);
        }
    }
}

