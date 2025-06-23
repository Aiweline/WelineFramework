<?php

namespace WeShop\Product\Controller\Backend\Product;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\ProductCategory;

class Delete extends BackendController
{
    public function postIndex()
    {
        $id = $this->request->getGet('product_id') ?? $this->request->getPost('product_id');
        if (!$id) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => __('缺少产品ID')]);
            exit;
        }
        try {
            // 删除产品分类关联
            $productCategoryModel = ObjectManager::getInstance(ProductCategory::class);
            $productCategoryModel->where('product_id', $id)->delete()->fetch();
            // 删除产品本身
            $productModel = ObjectManager::getInstance(Product::class);
            $productModel->where('product_id', $id,'=','or')
            ->where('parent_id', $id)
            ->delete()->fetch();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => __('删除成功')]);
            exit;
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => __('删除失败: ') . (DEV ? $e->getMessage() : '')]);
            exit;
        }
    }
}
