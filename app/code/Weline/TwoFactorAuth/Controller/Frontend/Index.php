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
    /** 2FA 管理页：独立全屏壳（Theme blank/full），消费默认主题 head。 */
    protected ?string $layoutType = 'blank';

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
        $this->request->setGet('layout_option', 'full');
        // 获取当前登录用户ID（使用继承的 $this->session）
        $userId = $this->session->getUserId() ?? 1;

        $config = $this->twoFactorAuthService->getUserConfig($userId);
        $isEnabled = $this->twoFactorAuthService->isEnabled($userId);

        $this->assign('user_id', $userId);
        $this->assign('is_enabled', $isEnabled);
        $this->assign('config', $config);
        $this->assign('remaining_seconds', $this->twoFactorAuthService->getRemainingSeconds());

        return $this->fetch();
    }
}

