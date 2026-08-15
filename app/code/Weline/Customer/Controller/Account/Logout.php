<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Customer\Service\CustomerRememberDeviceService;

/**
 * 用户登出控制器
 */
class Logout extends \Weline\Framework\App\Controller\FrontendController
{
    protected ?string $layoutType = 'account.logout';

    /**
     * 统一执行登出逻辑
     */
    protected function logoutUser(): void
    {
        // 获取当前用户ID
        $userId = $this->session->getUserId();
        
        // 登出
        $this->session->logout();
        
        ObjectManager::getInstance(CustomerRememberDeviceService::class)
            ->clearAfterLogout((int)($userId ?? 0));
        \Weline\Framework\Http\Cookie::set('w_sandbox', '', -3600, ['path' => '/']);
        $adminPath = Env::getAreaRoutePrefix('backend') ?? '';
        if (!empty($adminPath)) {
            \Weline\Framework\Http\Cookie::set('w_sandbox', '', -3600, ['path' => '/' . ltrim($adminPath, '/')]);
        }
    }

    /**
     * 登出（GET）
     */
    public function getIndex()
    {
        $this->logoutUser();
        $this->redirect('/customer/account/login');
    }

    /**
     * 登出（POST，供AJAX/Fetch使用）
     */
    public function postIndex()
    {
        $this->logoutUser();

        return $this->fetchJson([
            'success' => true,
            'message' => __('退出成功'),
            'redirect' => '/customer/account/login'
        ]);
    }
}
