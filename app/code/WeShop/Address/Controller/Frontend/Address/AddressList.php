<?php

declare(strict_types=1);

namespace WeShop\Address\Controller\Frontend\Address;

use Weline\Framework\App\Controller\FrontendController;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Address\Service\AddressService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 地址列表控制器
 */
class AddressList extends FrontendController
{
    /**
     * 获取地址列表
     */
    public function index(): string
    {
        try {
            /** @var CustomerSession $customerSession */
            $customerSession = ObjectManager::getInstance(CustomerSession::class);
            $customer = $customerSession->getCustomer();
            
            if (!$customer || !$customer->getId()) {
                return $this->fetchJson(['success' => false, 'message' => __('请先登录')]);
            }
            
            /** @var AddressService $addressService */
            $addressService = ObjectManager::getInstance(AddressService::class);
            $addresses = $addressService->getCustomerAddresses($customer->getId());
            
            return $this->fetchJson([
                'success' => true,
                'data' => $addresses,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
