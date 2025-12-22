<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/01/15
 * 描述：产品库存管理控制器
 */

namespace WeShop\Inventory\Controller\Backend\Inventory;

use Weline\Framework\App\Controller\BackendController;
use WeShop\Inventory\Model\Source;
use WeShop\Inventory\Model\SourceItem as SourceItemModel;
use WeShop\Product\Model\Product;

class SourceItem extends BackendController
{
    private SourceItemModel $sourceItem;
    private Source $source;
    private Product $product;

    public function __construct(
        SourceItemModel $sourceItem,
        Source $source,
        Product $product
    ) {
        $this->sourceItem = $sourceItem;
        $this->source = $source;
        $this->product = $product;
    }

    /**
     * 产品库存列表
     */
    public function index()
    {
        $sourceId = (int)$this->request->getGet('source_id', 0);
        $search = $this->request->getGet('search', '');

        $this->sourceItem->reset()
            ->joinModel(Source::class, 'source', 'main_table.source_id = source.source_id', 'left', 'source.name as source_name, source.code as source_code')
            ->joinModel(Product::class, 'product', 'main_table.product_id = product.product_id', 'left', 'product.name as product_name, product.price as product_price');

        if ($sourceId > 0) {
            $this->sourceItem->where('main_table.source_id', $sourceId);
        }

        if ($search) {
            $this->sourceItem->where('(main_table.sku LIKE ? OR product.name LIKE ?)', ["%$search%", "%$search%"]);
        }

        $items = $this->sourceItem
            ->pagination()
            ->order('main_table.source_item_id', 'DESC')
            ->select()
            ->fetch()
            ->getItems();

        // 获取所有库存源用于筛选
        $sources = $this->source->reset()->getEnabledSources();

        $this->assign('items', $items);
        $this->assign('sources', $sources);
        $this->assign('current_source_id', $sourceId);
        $this->assign('search', $search);
        $this->assign('pagination', $this->sourceItem->getPagination());
        return $this->fetch();
    }

    /**
     * 编辑产品库存
     */
    public function edit()
    {
        $id = (int)$this->request->getGet('id');

        if ($this->request->isPost()) {
            try {
                $data = $this->request->getPost();
                $this->sourceItem->load($id);
                if (!$this->sourceItem->getId()) {
                    throw new \Exception(__('库存记录不存在！'));
                }

                // 更新库存数量和状态
                $quantity = (float)($data['quantity'] ?? 0);
                $status = $quantity > 0 ? SourceItemModel::STATUS_IN_STOCK : SourceItemModel::STATUS_OUT_OF_STOCK;

                $this->sourceItem
                    ->setQuantity($quantity)
                    ->setStatus($status)
                    ->setLowStockThreshold((int)($data['low_stock_threshold'] ?? 0))
                    ->save();

                $this->getMessageManager()->addSuccess(__('库存保存成功！'));
            } catch (\Exception $e) {
                $this->getMessageManager()->addError(__('库存保存失败！') . (DEV ? $e->getMessage() : ''));
            }
            $this->redirect('*/backend/inventory/source-item/edit', ['id' => $id]);
        }

        $item = $this->sourceItem->reset()
            ->joinModel(Source::class, 'source', 'main_table.source_id = source.source_id', 'left', 'source.name as source_name')
            ->joinModel(Product::class, 'product', 'main_table.product_id = product.product_id', 'left', 'product.name as product_name, product.sku as product_sku')
            ->where('main_table.source_item_id', $id)
            ->find()
            ->fetch();

        if (!$item->getId()) {
            $this->getMessageManager()->addError(__('库存记录不存在！'));
            $this->redirect('*/backend/inventory/source-item');
        }

        $this->assign('item', $item);
        $this->assign('action', $this->request->getUrlBuilder()->getCurrentUrl());
        return $this->fetch('form');
    }

    /**
     * 批量调整库存
     */
    public function postBatchAdjust(): string
    {
        try {
            $adjustments = $this->request->getPost('adjustments', []);

            foreach ($adjustments as $adjustment) {
                $productId = (int)($adjustment['product_id'] ?? 0);
                $sourceId = (int)($adjustment['source_id'] ?? 0);
                $quantityChange = (float)($adjustment['quantity_change'] ?? 0);

                if ($productId <= 0 || $sourceId <= 0 || $quantityChange == 0) {
                    continue;
                }

                if ($quantityChange > 0) {
                    $this->sourceItem->increaseStock($productId, $sourceId, $quantityChange);
                } else {
                    $this->sourceItem->decreaseStock($productId, $sourceId, abs($quantityChange));
                }
            }

            return $this->fetchJson(['success' => true, 'message' => __('库存调整成功！')]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => __('库存调整失败！') . (DEV ? $e->getMessage() : '')]);
        }
    }

    /**
     * 获取产品库存详情（API）
     */
    public function getProductStock(): string
    {
        $productId = (int)$this->request->getGet('product_id');

        if ($productId <= 0) {
            return $this->fetchJson(['error' => __('产品ID无效！')]);
        }

        $items = $this->sourceItem->getItemsByProductId($productId);
        $totalQty = $this->sourceItem->getTotalQuantityByProductId($productId);

        return $this->fetchJson([
            'product_id' => $productId,
            'total_quantity' => $totalQty,
            'sources' => $items
        ]);
    }
}

