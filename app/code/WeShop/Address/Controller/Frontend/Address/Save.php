<?php

declare(strict_types=1);

namespace WeShop\Address\Controller\Frontend\Address;

use Weline\Framework\App\Controller\FrontendController;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Address\Service\AddressService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 保存地址控制器
 */
class Save extends FrontendController
{
    /**
     * 保存地址
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
            
            $addressData = [
                'customer_id' => $customer->getId(),
                'firstname' => $this->request->getParam('firstname') ?? '',
                'lastname' => $this->request->getParam('lastname') ?? '',
                'phone' => $this->request->getParam('phone') ?? '',
                'country' => $this->request->getParam('country') ?? '',
                'region' => $this->request->getParam('region') ?? '',
                'city' => $this->request->getParam('city') ?? '',
                'street' => $this->request->getParam('street') ?? '',
                'postcode' => $this->request->getParam('postcode') ?? '',
                'is_default' => (bool)($this->request->getParam('is_default') ?? false),
                'locale' => $this->request->getParam('locale') ?? 'zh_CN',
            ];
            
            if (!empty($this->request->getParam('address_id'))) {
                $addressData['address_id'] = (int)$this->request->getParam('address_id');
            }
            
            /** @var AddressService $addressService */
            $addressService = ObjectManager::getInstance(AddressService::class);
            $address = $addressService->saveAddress($addressData);
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('地址保存成功'),
                'data' => $address->getData(),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
