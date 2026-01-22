<?php

declare(strict_types=1);

namespace WeShop\Catalog\Controller\Frontend\Category;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Catalog\Service\CategoryService;
use Weline\Framework\Manager\MessageManager;
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
    protected ?string $layoutType = 'category';
    
    /**
     * 分类详情页
     */
    public function index(): string
    {
        /** @var CategoryService $categoryService */
        $categoryService = ObjectManager::getInstance(CategoryService::class);
        
        // 如果 Router 已经在 Request 上挂载了 categories 数据，则优先使用其中的 current 做为上下文
        $categoriesCtx = $this->request->getData('categories') ?? null;
        
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
            MessageManager::error(__('分类不存在'));
            return $this->redirect('weshop') ?? '';
        }
        
        // 检查分类是否启用
        $isActive = (int)($category->getData(\WeShop\Catalog\Model\Category::fields_IS_ACTIVE) ?? 0);
        if ($isActive !== 1) {
            MessageManager::error(__('分类已禁用'));
            return $this->redirect('weshop') ?? '';
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
        $pathSegments = [];
        while ($parentId > 0) {
            $parentCategory = $categoryService->getCategory($parentId);
            if ($parentCategory && $parentCategory->getId()) {
                $handleValue = trim((string)($parentCategory->getData(\WeShop\Catalog\Model\Category::fields_HANDLE) ?? ''), '/');
                if ($handleValue !== '') {
                    $pathSegments[] = $handleValue;
                    $path = implode('/', $pathSegments);
                } else {
                    $path = '';
                }
                array_unshift($breadcrumbs, [
                    'category_id' => $parentCategory->getId(),
                    'name' => $parentCategory->getData(\WeShop\Catalog\Model\Category::fields_NAME) ?? '',
                    'handle' => $handleValue,
                    'path' => $path,
                ]);
                $parentId = (int)($parentCategory->getData(\WeShop\Catalog\Model\Category::fields_PARENT_ID) ?? 0);
            } else {
                break;
            }
        }
        $categoryData['breadcrumbs'] = $breadcrumbs;

        // 如果 Request 上还没有统一的 categories 结构，则按照 Router 的格式补充一份
        if (!is_array($categoriesCtx) || empty($categoriesCtx['current']['category_id'])) {
            // 重新基于 breadcrumbs 构建从根到当前的路径
            $pathSegments = [];
            foreach ($breadcrumbs as $bc) {
                $h = trim((string)($bc['handle'] ?? ''), '/');
                if ($h !== '') {
                    $pathSegments[] = $h;
                }
            }
            $currentHandle = trim((string)($categoryData['handle'] ?? ''), '/');
            if ($currentHandle !== '') {
                $pathSegments[] = $currentHandle;
            }
            $path = implode('/', $pathSegments);

            $currentCategory = [
                'category_id'  => $categoryData['category_id'],
                'name'         => $categoryData['name'],
                'handle'       => $currentHandle,
                'path'         => $path,
                'description'  => $categoryData['description'],
                'image'        => $categoryData['image'],
                'parent_id'    => $categoryData['parent_id'],
                'sort_order'   => $categoryData['sort_order'],
                'breadcrumbs'  => $breadcrumbs,
            ];

            $this->request->setData('categories', [
                'current'     => $currentCategory,
                'breadcrumbs' => $breadcrumbs,
                'path'        => $path,
            ]);
        }
        
        // 准备模板数据
        $this->assign('category', $categoryData);
        
        // SEO数据
        $this->assign('title', $categoryData['name']);
        $this->assign('meta_title', $category->getData('meta_title') ?? $categoryData['name']);
        $this->assign('meta_description', $category->getData('meta_description') ?? $categoryData['description']);
        $this->assign('meta_keywords', $category->getData('meta_keywords') ?? '');
        
        // 直接返回内容模板，Theme 的 ControllerFetchFileAfter 观察者会自动将内容渲染到 meta.content 中
        // Theme模块会根据 layoutType 和主题配置自动加载对应的布局，并将此模板内容放入布局的 {{meta.content}} 中
        return $this->fetch('WeShop_Catalog::Frontend/Category/content.phtml');
    }
}
