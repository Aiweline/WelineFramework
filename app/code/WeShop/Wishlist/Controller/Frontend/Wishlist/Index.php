<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Controller\Frontend\Wishlist;

use Weline\Framework\App\Controller\FrontendController;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Wishlist\Service\WishlistService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 心愿单列表控制器
 */
class Index extends FrontendController
{
    /**
     * 心愿单列表页
     */
    public function index(): string
    {
        /** @var CustomerSession $customerSession */
        $customerSession = ObjectManager::getInstance(CustomerSession::class);
        $customer = $customerSession->getCustomer();
        
        if (!$customer || !$customer->getId()) {
            $this->getMessageManager()->addError(__('请先登录'));
            return $this->redirect('weshop/customer/account/login');
        }
        
        /** @var WishlistService $wishlistService */
        $wishlistService = ObjectManager::getInstance(WishlistService::class);
        $wishlist = $wishlistService->getCustomerWishlist($customer->getId());
        
        $this->assign('wishlist', $wishlist);
        
        return $this->fetch();
    }
}
