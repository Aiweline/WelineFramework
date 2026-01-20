<?php

declare(strict_types=1);

namespace WeShop\Catalog\Controller\Frontend\Category;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Catalog\Service\CategoryService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 分类详情页控制器
 * 
 * 支持通过 handle 或 id 参数访问分类
 * URL格式：
 * - /catalog/category/{handle} (通过Router重写)
 * - /catalog/category/view?handle={handle}
 * - /catalog/category/view?id={id}
 */
class View extends BaseController
{
    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     */
    protected ?string $layoutType = 'catalog';
    
    /**
     * 分类详情页
     */
    public function index(): string
    {
        /** @var CategoryService $categoryService */
        $categoryService = ObjectManager::getInstance(CategoryService::class);
        
        // 优先使用 handle 参数（从Router重写或URL参数）
        $handle = $this->request->getParam('handle') ?? $this->request->getGet('handle');
        $categoryId = (int)($this->request->getParam('id') ?? $this->request->getGet('id') ?? 0);
        
        $category = null;
        
        // 优先通过 handle 获取分类
        if ($handle) {
            $category = $categoryService->getCategoryByHandle($handle);
        }
        
        // 如果通过 handle 没找到，尝试通过 ID 获取
        if (!$category && $categoryId) {
            $category = $categoryService->getCategory($categoryId);
        }
        
        // 如果都没找到，返回404
        if (!$category || !$category->getId()) {
            $this->getMessageManager()->addError(__('分类不存在'));
            return $this->redirect('weshop');
        }
        
        // 检查分类是否启用
        $isActive = (int)($category->getData(\WeShop\Catalog\Model\Category::fields_IS_ACTIVE) ?? 0);
        if ($isActive !== 1) {
            $this->getMessageManager()->addError(__('分类已禁用'));
            return $this->redirect('weshop');
        }
        
        // 格式化分类数据
        $categoryData = [
            'category_id' => $category->getId(),
            'name' => $category->getData(\WeShop\Catalog\Model\Category::fields_NAME) ?? '',
            'description' => $category->getData(\WeShop\Catalog\Model\Category::fields_DESCRIPTION) ?? '',
            'handle' => $category->getData(\WeShop\Catalog\Model\Category::fields_HANDLE) ?? '',
            'image' => $category->getData(\WeShop\Catalog\Model\Category::fields_IMAGE) ?? '',
            'parent_id' => (int)($category->getData(\WeShop\Catalog\Model\Category::fields_PARENT_ID) ?? 0),
            'sort_order' => (int)($category->getData(\WeShop\Catalog\Model\Category::fields_SORT_ORDER) ?? 0),
        ];
        
        // 获取子分类
        $childCategories = $categoryService->getChildCategories($category->getId());
        $categoryData['children'] = $childCategories;
        
        // 获取父分类路径（面包屑导航）
        $breadcrumbs = [];
        $parentId = $categoryData['parent_id'];
        while ($parentId > 0) {
            $parentCategory = $categoryService->getCategory($parentId);
            if ($parentCategory && $parentCategory->getId()) {
                array_unshift($breadcrumbs, [
                    'category_id' => $parentCategory->getId(),
                    'name' => $parentCategory->getData(\WeShop\Catalog\Model\Category::fields_NAME) ?? '',
                    'handle' => $parentCategory->getData(\WeShop\Catalog\Model\Category::fields_HANDLE) ?? '',
                ]);
                $parentId = (int)($parentCategory->getData(\WeShop\Catalog\Model\Category::fields_PARENT_ID) ?? 0);
            } else {
                break;
            }
        }
        $categoryData['breadcrumbs'] = $breadcrumbs;
        
        // 准备模板数据
        $this->assign('category', $categoryData);
        
        // SEO数据
        $this->assign('title', $categoryData['name']);
        $this->assign('meta_title', $category->getData('meta_title') ?? $categoryData['name']);
        $this->assign('meta_description', $category->getData('meta_description') ?? $categoryData['description']);
        $this->assign('meta_keywords', $category->getData('meta_keywords') ?? '');
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/pages/catalog/category.phtml
        return $this->fetch();
    }
}
