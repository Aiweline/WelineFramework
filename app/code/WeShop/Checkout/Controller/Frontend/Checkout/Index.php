<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller\Frontend\Checkout;

use WeShop\Frontend\Controller\BaseController;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Cart\Service\CartService;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Address\Service\AddressService;

/**
 * 结账页面控制器
 * 
 * 支持4种布局变体：
 * - checkout_page_1
 * - checkout_page_2
 * - checkout_page_3
 * - checkout_page_4
 * 
 * 布局变体通过主题配置设置：layouts.checkout = checkout_page_1
 */
class Index extends BaseController
{
    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     */
    protected ?string $layoutType = 'checkout';
    
    public function index()
    {
        /** @var CustomerSession $session */
        $session = ObjectManager::getInstance(CustomerSession::class);
        $customer = $session->getCustomer();
        
        if (!$customer) {
            $this->redirect('customer/account/login');
            return;
        }
        
        /** @var CartService $cartService */
        $cartService = ObjectManager::getInstance(CartService::class);
        
        // 检查购物车是否为空
        $items = $cartService->getCartItems($customer->getId());
        if (empty($items)) {
            $this->getMessageManager()->addWarning(__('购物车为空，无法结账'));
            return $this->redirect('weshop/cart');
        }
        
        // 获取购物车总计
        $totals = $cartService->calculateTotals($customer->getId());
        
        // 格式化购物车商品数据
        $cartItems = [];
        $cartCount = 0;
        foreach ($items as $item) {
            $cartItems[] = [
                'item_id' => $item->getId(),
                'product_id' => $item->getProductId(),
                'name' => $item->getProductName(),
                'image' => $item->getProductImage() ?? '',
                'price' => $item->getPrice(),
                'qty' => $item->getQty(),
            ];
            $cartCount += $item->getQty();
        }
        
        // 获取配送地址列表
        /** @var AddressService $addressService */
        $addressService = ObjectManager::getInstance(AddressService::class);
        $addresses = $addressService->getCustomerAddresses($customer->getId());
        
        // 格式化地址数据
        $shippingAddresses = [];
        foreach ($addresses as $address) {
            $shippingAddresses[] = [
                'address_id' => $address->getId(),
                'firstname' => $address->getFirstname(),
                'lastname' => $address->getLastname(),
                'street' => $address->getStreet(),
                'city' => $address->getCity(),
                'region' => $address->getRegion(),
                'region_id' => $address->getRegionId(),
                'postcode' => $address->getPostcode(),
                'telephone' => $address->getTelephone(),
            ];
        }
        
        // 获取地区列表（用于地址选择）
        // TODO: 实现地区列表获取逻辑
        $regions = [];
        
        // 准备模板数据
        $this->assign('cart_items', $cartItems);
        $this->assign('cart_count', $cartCount);
        $this->assign('item_count', $cartCount);
        $this->assign('cart_total', $totals['subtotal'] ?? 0);
        $this->assign('shipping', $totals['shipping'] ?? 0);
        $this->assign('tax', $totals['tax'] ?? 0);
        $this->assign('shipping_addresses', $shippingAddresses);
        $this->assign('regions', $regions);
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/checkout/checkout_page_{variant}.phtml
        return $this->fetch();
    }
}
