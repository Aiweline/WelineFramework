<?php

declare(strict_types=1);

namespace Weline\Frontend\Controller\Account;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Frontend\Model\FrontendUserToken;
use Weline\Frontend\Session\FrontendUserSession;

/**
 * 用户登出控制器
 */
class Logout extends \Weline\Framework\App\Controller\FrontendController
{
    private FrontendUserSession $session;
    protected ?string $layoutType = 'account.logout';
    
    public function __construct(
        FrontendUserSession $session
    ) {
        $this->session = $session;
    }

    /**
     * 统一执行登出逻辑
     */
    protected function logoutUser(): void
    {
        // 获取当前用户ID
        $userId = $this->session->getLoginUserID();
        
        // 登出
        $this->session->logout();
        
        // 删除数据库中的token记录
        if ($userId) {
            /** @var FrontendUserToken $userToken */
            $userToken = ObjectManager::getInstance(FrontendUserToken::class);
            $userToken->reset()
                ->where('user_id', $userId)
                ->where('type', 'remember_me')
                ->delete();
        }
        
        // 清除记住我的cookie
        Cookie::set('w_ut', '', -3600, ['path' => '/']);
        Cookie::set('w_sandbox', '', -3600, ['path' => '/']);
        $adminPath = Env::getInstance()->getConfig('admin') ?? '';
        if (!empty($adminPath)) {
            Cookie::set('w_sandbox', '', -3600, ['path' => '/' . ltrim($adminPath, '/')]);
        }
    }

    /**
     * 登出（GET）
     */
    public function getIndex()
    {
        $this->logoutUser();
        $this->redirect('/frontend/account/login');
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
            'redirect' => '/frontend/account/login'
        ]);
    }
}

