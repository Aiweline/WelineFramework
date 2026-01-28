<?php

declare(strict_types=1);

namespace WeShop\Product\Observer;

use WeShop\Product\Model\Product;
use WeShop\Product\Service\ProductUrlRewriteService;
use Weline\Framework\Event\Event;

/**
 * 产品 URL 重写观察者
 * 
 * 监听产品保存和删除事件，同步 URL 重写规则
 */
class ProductUrlRewriteObserver implements \Weline\Framework\Event\ObserverInterface
{
    private ProductUrlRewriteService $urlRewriteService;

    public function __construct(
        ProductUrlRewriteService $urlRewriteService
    ) {
        $this->urlRewriteService = $urlRewriteService;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $eventName = $event->getEventName();
        $data = $event->getData();

        // 确保 data 是数组
        if (!is_array($data)) {
            $data = ['data' => $data];
        }

        switch ($eventName) {
            case 'WeShop_Product::product_save_after':
                $this->handleProductSave($data);
                break;

            case 'WeShop_Product::product_delete_after':
            case 'WeShop_Product::product_delete_before':
                $this->handleProductDelete($data);
                break;
        }
    }

    /**
     * 处理产品保存事件
     * 
     * @param array $data 事件数据
     */
    private function handleProductSave(array $data): void
    {
        /** @var Product|null $product */
        $product = $data['product'] ?? null;

        if (!$product instanceof Product || !$product->getId()) {
            return;
        }

        // 同步 URL 重写规则
        try {
            $this->urlRewriteService->syncProductUrlRewrites($product);
        } catch (\Exception $e) {
            // 静默失败，不影响产品保存流程
            // 可以在这里记录日志
        }
    }

    /**
     * 处理产品删除事件
     * 
     * @param array $data 事件数据
     */
    private function handleProductDelete(array $data): void
    {
        /** @var Product|null $product */
        $product = $data['product'] ?? null;
        $productId = $data['product_id'] ?? null;

        // 获取产品ID
        if ($product instanceof Product && $product->getId()) {
            $productId = (int)$product->getId();
        } elseif (is_numeric($productId)) {
            $productId = (int)$productId;
        } else {
            return;
        }

        // 删除 URL 重写规则
        try {
            $this->urlRewriteService->deleteProductUrlRewrites($productId);
        } catch (\Exception $e) {
            // 静默失败，不影响产品删除流程
        }
    }
}
