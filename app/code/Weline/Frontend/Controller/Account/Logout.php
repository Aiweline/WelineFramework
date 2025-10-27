<?php

declare(strict_types=1);

namespace Weline\Frontend\Controller\Account;

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

    public function __construct(
        FrontendUserSession $session
    ) {
        $this->session = $session;
        parent::__construct();
    }

    /**
     * 登出
     */
    public function getIndex()
    {
        // 获取当前用户ID
        $userId = $this->session->getLoginUserID();
        
        // 登出
        $this->session->logout();
        
        // 删除数据库中的token记录
        if ($userId) {
            /** @var FrontendUserToken $userToken */
            $userToken = ObjectManager::getInstance(FrontendUserToken::class);
            $userToken->builder()
                ->where('user_id', $userId)
                ->where('type', 'remember_me')
                ->delete();
        }
        
        // 清除记住我的cookie
        Cookie::set('frontend_user_token', '', -3600, ['path' => '/']);

        $this->redirect('/frontend/account/login');
    }
}

