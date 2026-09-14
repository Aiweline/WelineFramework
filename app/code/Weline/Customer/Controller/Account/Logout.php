<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use Weline\Customer\Service\CustomerAuthReturnUrlService;
use Weline\Customer\Service\CustomerRememberDeviceService;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

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

    private function logoutRedirectTarget(): string
    {
        // Url generator once — never hand a already-prefixed path to redirect(),
        // which would call getFrontendUrl and duplicate /{locale}/.
        return (string)$this->getUrl('customer/account/login', [
            CustomerAuthReturnUrlService::AUTH_REFRESH_QUERY => CustomerAuthReturnUrlService::AUTH_REFRESH_LOGOUT_VALUE,
        ]);
    }

    /**
     * 登出（GET）
     */
    public function getIndex()
    {
        $this->logoutUser();
        // Relative route (no leading "/") → PcController::redirect → getUrl once.
        $this->redirect('customer/account/login', [
            CustomerAuthReturnUrlService::AUTH_REFRESH_QUERY => CustomerAuthReturnUrlService::AUTH_REFRESH_LOGOUT_VALUE,
        ]);
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
            'redirect' => $this->logoutRedirectTarget(),
        ]);
    }
}
