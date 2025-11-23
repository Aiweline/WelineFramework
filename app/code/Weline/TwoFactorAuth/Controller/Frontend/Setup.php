<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\Message;
use Weline\Frontend\Session\FrontendUserSession;
use Weline\TwoFactorAuth\Service\TwoFactorAuthService;

/**
 * 2FA前端设置控制器
 * 
 * @package Weline\TwoFactorAuth\Controller\Frontend
 */
class Setup extends FrontendController
{
    private TwoFactorAuthService $twoFactorAuthService;
    private FrontendUserSession $session;

    public function __construct(
        TwoFactorAuthService $twoFactorAuthService,
        FrontendUserSession $session
    ) {
        $this->twoFactorAuthService = $twoFactorAuthService;
        $this->session = $session;
    }

    /**
     * 初始化2FA设置页面
     */
    public function index()
    {
        /**@var FrontendUserSession $session */
        $session = \Weline\Framework\App\Env::getInstance(FrontendUserSession::class);
        $userId = $session->getLoginUserID();

        if (!$userId) {
            Message::error(__('请先登录'));
            return $this->redirect('/frontend/account/login');
        }

        // 检查用户是否已启用2FA
        if ($this->twoFactorAuthService->isEnabled($userId)) {
            // 获取来源URL或默认URL
            $referer = $this->request->getReferer();
            $currentUrl = $this->request->getUrl();
            
            // 确定重定向URL
            $redirectUrl = '/two-factor-auth/frontend'; // 默认重定向到2FA管理页面
            
            // 如果referer存在且不是当前URL，且不是setup页面，则使用referer
            if ($referer && 
                $referer !== $currentUrl && 
                strpos($referer, '/two-factor-auth/frontend/setup') === false) {
                // 验证referer是否是同站URL
                $parsedReferer = parse_url($referer);
                $parsedCurrent = parse_url($currentUrl);
                if (isset($parsedReferer['host']) && isset($parsedCurrent['host']) && 
                    $parsedReferer['host'] === $parsedCurrent['host']) {
                    $redirectUrl = $referer;
                }
            }
            
            Message::warning(__('您已经启用了双因素身份验证，无需重复设置'));
            return $this->redirect($redirectUrl);
        }

        // 从用户模型获取邮箱
        $user = $session->getLoginUser();
        $userEmail = $user->getUsername().':'.$user->getEmail();

            // 生成新的密钥和备份码
            $data = $this->twoFactorAuthService->initialize($userId);
            if (!isset($data['secret']) || !isset($data['backup_codes'])) {
                throw new \Exception('初始化2FA失败：返回数据格式不正确');
            }
            
            $secret = $data['secret'];
            $backupCodes = $data['backup_codes'];

        // 生成格式化后的账户显示名称（先生成，确保在生成二维码时使用）
        $formattedAccountLabel = \Weline\TwoFactorAuth\Helper\TwoFactorAuthHelper::getFormattedAccountLabel($userEmail);
        
        // 生成二维码（使用格式化后的标签）
        // 注意：getOtpAuthUri 内部会调用 getFormattedAccountLabel 生成格式化后的 label
        $qrCodeUrl = $this->twoFactorAuthService->getQRCodeUrl($secret, $userEmail);
        $qrCodeUri = $this->twoFactorAuthService->getQRCodeUri($secret, $userEmail);
        
        // 调试：验证生成的 URI 是否包含格式化后的标签
        // otpauth URI 格式为 otpauth://totp/{issuer}:{label}?secret=...
        // label 部分应该显示在验证器应用中
        // 如果 URI 中包含格式化后的 label，扫描后应该显示格式化后的名称

        $this->assign('user_id', $userId);
        $this->assign('user_email', $userEmail);
        $this->assign('formatted_account_label', $formattedAccountLabel);
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
            return $this->json(['success' => false, 'message' => __('无效的请求方法')]);
        }

        /**@var \Weline\Framework\App\Session\FrontendSession $session */
        $session = \Weline\Framework\App\Env::getInstance(\Weline\Framework\App\Session\FrontendSession::class);
        $userId = $session->getLoginUserID() ?? 1;
        $secret = $this->request->getPost('secret');
        $code = $this->request->getPost('code');
        $backupCodes = $this->request->getPost('backup_codes');

        if (!$secret || !$code) {
            return $this->json(['success' => false, 'message' => __('缺少必要参数')]);
        }

        // 解析备份码
        if (is_string($backupCodes)) {
            $backupCodes = json_decode($backupCodes, true) ?? [];
        }

        $success = $this->twoFactorAuthService->enable($userId, $secret, $code, $backupCodes);

        // 获取跳转地址
        $referer = $this->request->getPost('referer') ?? '/frontend/account';
        
        if ($success) {
            return $this->json([
                'success' => true,
                'message' => __('双因素身份验证已启用')
            ]);
        } else {
            return $this->json([
                'success' => false,
                'message' => __('验证码错误，请重试')
            ]);
        }
    }

    /**
     * 禁用2FA
     */
    public function disable()
    {
        if (!$this->request->isPost()) {
            return $this->json(['success' => false, 'message' => __('无效的请求方法')]);
        }

        /**@var \Weline\Framework\App\Session\FrontendSession $session */
        $session = \Weline\Framework\App\Env::getInstance(\Weline\Framework\App\Session\FrontendSession::class);
        $userId = $session->getLoginUserID() ?? 1;
        $code = $this->request->getPost('code');

        if (!$code) {
            return $this->json(['success' => false, 'message' => __('请提供验证码')]);
        }

        $success = $this->twoFactorAuthService->disable($userId, $code);

        if ($success) {
            return $this->json([
                'success' => true,
                'message' => __('双因素身份验证已禁用')
            ]);
        } else {
            return $this->json([
                'success' => false,
                'message' => __('验证码错误或未启用2FA')
            ]);
        }
    }

    /**
     * 重新生成备份码
     */
    public function regenerateBackupCodes()
    {
        if (!$this->request->isPost()) {
            return $this->json(['success' => false, 'message' => __('无效的请求方法')]);
        }

        /**@var \Weline\Framework\App\Session\FrontendSession $session */
        $session = \Weline\Framework\App\Env::getInstance(\Weline\Framework\App\Session\FrontendSession::class);
        $userId = $session->getLoginUserID() ?? 1;
        $code = $this->request->getPost('code');

        if (!$code) {
            return $this->json(['success' => false, 'message' => __('请提供验证码')]);
        }

        $newCodes = $this->twoFactorAuthService->regenerateBackupCodes($userId, $code);

        if ($newCodes) {
            return $this->json([
                'success' => true,
                'message' => __('备份码已重新生成'),
                'backup_codes' => $newCodes
            ]);
        } else {
            return $this->json([
                'success' => false,
                'message' => __('验证码错误')
            ]);
        }
    }

    /**
     * 返回JSON响应
     */
    private function json(array $data): string
    {
        header('Content-Type: application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}

