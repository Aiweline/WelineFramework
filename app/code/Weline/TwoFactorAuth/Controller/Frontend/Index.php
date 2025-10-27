<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\TwoFactorAuth\Service\TwoFactorAuthService;

/**
 * 2FA前端用户设置页面
 * 
 * @package Weline\TwoFactorAuth\Controller\Frontend
 */
class Index extends FrontendController
{
    private TwoFactorAuthService $twoFactorAuthService;

    public function __construct(
        TwoFactorAuthService $twoFactorAuthService
    ) {
        $this->twoFactorAuthService = $twoFactorAuthService;
    }

    /**
     * 显示2FA用户设置页面
     */
    public function index()
    {
        // 获取当前登录用户ID
        /**@var \Weline\Frontend\Session\FrontendSession $session */
        $session = \Weline\Framework\App\Env::getInstance(\Weline\Frontend\Session\FrontendSession::class);
        $userId = $session->getLoginUserID() ?? 1;

        $config = $this->twoFactorAuthService->getUserConfig($userId);
        $isEnabled = $this->twoFactorAuthService->isEnabled($userId);

        $this->assign('user_id', $userId);
        $this->assign('is_enabled', $isEnabled);
        $this->assign('config', $config);
        $this->assign('remaining_seconds', $this->twoFactorAuthService->getRemainingSeconds());

        return $this->fetch();
    }
}

