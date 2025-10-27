<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\TwoFactorAuth\Service\TwoFactorAuthService;

/**
 * 2FA设置控制器
 * 
 * @package Weline\TwoFactorAuth\Controller\Backend
 */
class Setup extends BackendController
{
    private TwoFactorAuthService $twoFactorAuthService;

    public function __construct(
        TwoFactorAuthService $twoFactorAuthService
    ) {
        $this->twoFactorAuthService = $twoFactorAuthService;
    }

    /**
     * 初始化2FA设置页面
     */
    public function index()
    {
        $userId = $this->getSession()->getData('user_id') ?? 1;
        $userEmail = $this->getSession()->getData('user_email') ?? 'user@example.com';

        // 生成新的密钥和备份码
        $data = $this->twoFactorAuthService->initialize($userId);
        $secret = $data['secret'];
        $backupCodes = $data['backup_codes'];

        // 生成二维码
        $qrCodeUrl = $this->twoFactorAuthService->getQRCodeUrl($secret, $userEmail);
        $qrCodeUri = $this->twoFactorAuthService->getQRCodeUri($secret, $userEmail);

        $this->assign('user_id', $userId);
        $this->assign('user_email', $userEmail);
        $this->assign('secret', $secret);
        $this->assign('formatted_secret', $this->twoFactorAuthService->formatSecret($secret));
        $this->assign('backup_codes', $backupCodes);
        $this->assign('qr_code_url', $qrCodeUrl);
        $this->assign('qr_code_uri', $qrCodeUri);

        return $this->fetch();
    }

    /**
     * 启用2FA
     */
    public function enable()
    {
        if (!$this->request->isPost()) {
            return $this->json(['success' => false, 'message' => '无效的请求方法']);
        }

        $userId = $this->getSession()->getData('user_id') ?? 1;
        $secret = $this->request->getPost('secret');
        $code = $this->request->getPost('code');
        $backupCodes = $this->request->getPost('backup_codes');

        if (!$secret || !$code) {
            return $this->json(['success' => false, 'message' => '缺少必要参数']);
        }

        // 解析备份码
        if (is_string($backupCodes)) {
            $backupCodes = json_decode($backupCodes, true) ?? [];
        }

        $success = $this->twoFactorAuthService->enable($userId, $secret, $code, $backupCodes);

        if ($success) {
            return $this->json([
                'success' => true,
                'message' => '双因素身份验证已启用'
            ]);
        } else {
            return $this->json([
                'success' => false,
                'message' => '验证码错误，请重试'
            ]);
        }
    }

    /**
     * 禁用2FA
     */
    public function disable()
    {
        if (!$this->request->isPost()) {
            return $this->json(['success' => false, 'message' => '无效的请求方法']);
        }

        $userId = $this->getSession()->getData('user_id') ?? 1;
        $code = $this->request->getPost('code');

        if (!$code) {
            return $this->json(['success' => false, 'message' => '请提供验证码']);
        }

        $success = $this->twoFactorAuthService->disable($userId, $code);

        if ($success) {
            return $this->json([
                'success' => true,
                'message' => '双因素身份验证已禁用'
            ]);
        } else {
            return $this->json([
                'success' => false,
                'message' => '验证码错误或未启用2FA'
            ]);
        }
    }

    /**
     * 重新生成备份码
     */
    public function regenerateBackupCodes()
    {
        if (!$this->request->isPost()) {
            return $this->json(['success' => false, 'message' => '无效的请求方法']);
        }

        $userId = $this->getSession()->getData('user_id') ?? 1;
        $code = $this->request->getPost('code');

        if (!$code) {
            return $this->json(['success' => false, 'message' => '请提供验证码']);
        }

        $newCodes = $this->twoFactorAuthService->regenerateBackupCodes($userId, $code);

        if ($newCodes) {
            return $this->json([
                'success' => true,
                'message' => '备份码已重新生成',
                'backup_codes' => $newCodes
            ]);
        } else {
            return $this->json([
                'success' => false,
                'message' => '验证码错误'
            ]);
        }
    }
}

