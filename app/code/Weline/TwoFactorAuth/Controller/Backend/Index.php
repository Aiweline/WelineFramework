<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\TwoFactorAuth\Service\TwoFactorAuthService;

/**
 * 2FA后台账户管理
 * 管理员查看和管理用户的2FA状态
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
     * 显示所有用户的2FA状态（管理页面）
     */
    public function index()
    {
        // TODO: 获取所有用户列表和他们的2FA状态
        $this->assign('page_title', '双因素认证账户管理');
        
        return $this->fetch();
    }
}

