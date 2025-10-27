<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Controller\Api;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\TwoFactorAuth\Service\TwoFactorAuthService;

/**
 * 2FA验证API
 * 
 * @package Weline\TwoFactorAuth\Controller\Api
 */
class Verify extends FrontendRestController
{
    private TwoFactorAuthService $twoFactorAuthService;

    public function __construct(
        TwoFactorAuthService $twoFactorAuthService
    ) {
        $this->twoFactorAuthService = $twoFactorAuthService;
    }

    /**
     * 验证2FA验证码
     * 
     * POST /api/2fa/verify
     * Body: { "user_id": 1, "code": "123456" }
     */
    public function execute()
    {
        if (!$this->request->isPost()) {
            return $this->error('无效的请求方法', 405);
        }

        $userId = (int)$this->request->getPost('user_id');
        $code = $this->request->getPost('code');

        if (!$userId || !$code) {
            return $this->error('缺少必要参数', 400);
        }

        // 先尝试验证TOTP码
        $success = $this->twoFactorAuthService->verify($userId, $code);

        // 如果TOTP失败，尝试备份码
        if (!$success) {
            $success = $this->twoFactorAuthService->verifyBackupCode($userId, $code);
        }

        if ($success) {
            return $this->success([
                'verified' => true,
                'message' => '验证成功'
            ]);
        } else {
            return $this->error('验证码错误', 401);
        }
    }

    /**
     * 检查用户是否启用2FA
     * 
     * GET /api/2fa/check?user_id=1
     */
    public function check()
    {
        $userId = (int)$this->request->getGet('user_id');

        if (!$userId) {
            return $this->error('缺少用户ID', 400);
        }

        $isEnabled = $this->twoFactorAuthService->isEnabled($userId);

        return $this->success([
            'is_enabled' => $isEnabled
        ]);
    }
}

