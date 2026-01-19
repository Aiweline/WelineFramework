<?php

declare(strict_types=1);

namespace WeShop\Customer\Controller\Frontend\Account;

use Weline\Framework\App\Controller\FrontendController;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Manager\ObjectManager;

/**
 * 客户登出控制器
 */
class Logout extends FrontendController
{
    /**
     * 登出
     */
    public function index(): string
    {
        /** @var CustomerSession $customerSession */
        $customerSession = ObjectManager::getInstance(CustomerSession::class);
        $customerSession->logout();
        
        $this->getMessageManager()->addSuccess(__('已成功登出'));
        return $this->redirect('weshop/customer/account/login');
    }
}
