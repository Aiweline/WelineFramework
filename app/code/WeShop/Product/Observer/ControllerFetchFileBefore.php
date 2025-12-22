<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/12/20
 * 描述：产品数据传递Observer - 检测产品页面并传递产品信息到布局模板
 */

namespace WeShop\Product\Observer;

use WeShop\Product\Model\Product;
use WeShop\Product\Service\ProductLayoutService;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

class ControllerFetchFileBefore implements ObserverInterface
{
    private ProductLayoutService $layoutService;

    public function __construct(ProductLayoutService $layoutService)
    {
        $this->layoutService = $layoutService;
    }

    public function execute(Event &$event): void
    {
        /** @var DataObject $eventData */
        $eventData = $event->getData('data');
        
        if (!$eventData instanceof DataObject) {
            return;
        }

        $layoutType = $eventData->getData('layoutType');
        
        // 只处理产品相关的布局类型
        if (!in_array($layoutType, ['product_detail', 'product_list', 'product'], true)) {
            return;
        }

        $template = Template::getInstance();
        $request = ObjectManager::getInstance(Request::class);

        // 检测产品ID（从请求参数或路由中获取）
        // 注意：product_list页面可能没有productId，这是正常的
        $productId = $this->detectProductId($request, $layoutType);
        
        // 如果是产品详情页但没有productId，直接返回
        if ($layoutType === 'product_detail' && !$productId) {
            return;
        }

        // 如果有productId，加载产品数据并应用布局
        if ($productId) {
            // 加载产品数据
            /** @var Product $product */
            $product = ObjectManager::getInstance(Product::class);
            $product->load($productId);

            if ($product->getId()) {
                // 将产品数据设置到模板中
                $template->setData('product', $product);

                // 获取产品布局（优先级：活动计划 > 产品专属布局 > 默认布局）
                $productLayoutCode = $this->layoutService->getProductLayout($productId, $layoutType);
                
                if ($productLayoutCode) {
                    // 如果找到产品专属布局，更新布局选项
                    // 注意：这里需要修改布局选项，但需要确保不影响Theme模块的Observer
                    // 我们通过设置meta数据来传递布局信息
                    $meta = $template->getData('meta') ?? [];
                    $meta['product_layout_code'] = $productLayoutCode;
                    $meta['product_id'] = $productId;
                    $template->setData('meta', $meta);

                    // 如果layoutType包含点号，需要更新布局选项
                    $currentLayoutType = $eventData->getData('layoutType');
                    if (strpos($currentLayoutType, '.') === false) {
                        // 如果当前布局类型不包含选项，添加产品布局选项
                        $eventData->setData('layoutType', $layoutType . '.' . $productLayoutCode);
                    } else {
                        // 如果已包含选项，替换为产品布局选项
                        $parts = explode('.', $currentLayoutType, 2);
                        $eventData->setData('layoutType', $parts[0] . '.' . $productLayoutCode);
                    }
                } else {
                    // 即使没有专属布局，也设置产品ID到meta中
                    $meta = $template->getData('meta') ?? [];
                    $meta['product_id'] = $productId;
                    $template->setData('meta', $meta);
                }
            }
        } else {
            // 对于product_list页面，即使没有productId也设置meta标记
            $meta = $template->getData('meta') ?? [];
            $meta['is_product_page'] = true;
            $template->setData('meta', $meta);
        }
    }

    /**
     * 检测产品ID
     * 支持多种方式：请求参数、路由参数、控制器数据
     */
    private function detectProductId(Request $request, string $layoutType): ?int
    {
        // 1. 从请求参数中获取
        $productId = $request->getParam('product_id') 
            ?? $request->getParam('id')
            ?? $request->getParam('productId');

        if ($productId) {
            return (int)$productId;
        }

        // 2. 从路由参数中获取
        $routeParams = $request->getRouteParams();
        if (isset($routeParams['product_id'])) {
            return (int)$routeParams['product_id'];
        }
        if (isset($routeParams['id'])) {
            return (int)$routeParams['id'];
        }

        // 3. 从控制器数据中获取（如果控制器已经设置了product）
        $controller = $request->getController();
        if ($controller && method_exists($controller, 'getProduct')) {
            $product = $controller->getProduct();
            if ($product instanceof Product && $product->getId()) {
                return $product->getId();
            }
        }

        // 4. 从模板数据中获取（如果之前已经设置过）
        $template = Template::getInstance();
        $product = $template->getData('product');
        if ($product instanceof Product && $product->getId()) {
            return $product->getId();
        }

        return null;
    }
}

