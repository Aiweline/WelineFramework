<?php

declare(strict_types=1);

namespace WeShop\Cart\Controller\Frontend\Cart;

use WeShop\Frontend\Controller\BaseController;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Cart\Service\CartService;
use WeShop\Customer\Session\CustomerSession;

/**
 * 购物车页面控制器
 * 
 * 支持4种布局变体：
 * - shopping_cart_page_1
 * - shopping_cart_page_2
 * - shopping_cart_page_3
 * - shopping_cart_page_4
 * 
 * 布局变体通过主题配置设置：layouts.cart = shopping_cart_page_1
 */
class Index extends BaseController
{
    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     */
    protected ?string $layoutType = 'cart';
    
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
        
        // 获取购物车数据
        $items = $cartService->getCartItems($customer->getId());
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
                'original_price' => $item->getOriginalPrice(),
                'qty' => $item->getQty(),
                'stock_status' => $item->getStockStatus() ?? 'in_stock',
                'stock_qty' => $item->getStockQty() ?? 0,
                'option' => $item->getOption() ?? null,
                'seller' => $item->getSeller() ?? null,
            ];
            $cartCount += $item->getQty();
        }
        
        // 准备模板数据
        $this->assign('cart_items', $cartItems);
        $this->assign('cart_count', $cartCount);
        $this->assign('cart_total', $totals['subtotal'] ?? 0);
        $this->assign('shipping', $totals['shipping'] ?? 0);
        $this->assign('tax', $totals['tax'] ?? 0);
        
        // 获取推荐商品（可选）
        // TODO: 实现推荐商品逻辑
        $this->assign('recommendations', []);
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/cart/shopping_cart_page_{variant}.phtml
        return $this->fetch();
    }
}
