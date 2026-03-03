<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Controller\Api;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

/**
 * 检查登录状态API
 * 用于PWA应用检查用户登录状态
 * 
 * @package Weline\TwoFactorAuth\Controller\Api
 */
class CheckLogin extends FrontendRestController
{
    private AuthenticatedSessionInterface $session;

    public function __construct()
    {
        $this->session = SessionFactory::getInstance()->createFrontendSession();
        parent::__construct();
    }

    /**
     * GET /api/2fa/check-login
     * 检查用户登录状态
     */
    public function execute()
    {
        if ($this->session->isLoggedIn()) {
            $user = $this->session->getLoginUser(\Weline\Frontend\Model\FrontendUser::class);
            
            return $this->success([
                'logged_in' => true,
                'user' => [
                    'id' => $user ? $user->getId() : 0,
                    'username' => $user ? $user->getUsername() : '',
                    'email' => $user ? $user->getEmail() : '',
                ]
            ], __('已登录'));
        }
        
        return $this->error(
            __('未登录，请先登录'),
            401,
            [
                'logged_in' => false,
                'login_url' => '/frontend/account/login'
            ]
        );
    }
}

