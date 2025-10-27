<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\TwoFactorAuth\Service\TwoFactorAuthService;

/**
 * 2FA后台管理主页
 * 
 * @package Weline\TwoFactorAuth\Controller\Backend
 */
class Index extends BackendController
{
    private TwoFactorAuthService $twoFactorAuthService;

    public function __construct(
        TwoFactorAuthService $twoFactorAuthService
    ) {
        $this->twoFactorAuthService = $twoFactorAuthService;
    }

    /**
     * 显示2FA管理页面
     */
    public function index()
    {
        // 获取当前登录用户ID（需要根据您的框架实际情况调整）
        $userId = $this->getSession()->getData('user_id') ?? 1;

        $config = $this->twoFactorAuthService->getUserConfig($userId);
        $isEnabled = $this->twoFactorAuthService->isEnabled($userId);

        $this->assign('user_id', $userId);
        $this->assign('is_enabled', $isEnabled);
        $this->assign('config', $config);
        $this->assign('remaining_seconds', $this->twoFactorAuthService->getRemainingSeconds());

        return $this->fetch();
    }
}

