<?php

declare(strict_types=1);

namespace WeShop\Catalog\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Catalog\Model\Category;

/**
 * 分类服务
 */
class CategoryService
{
    /**
     * 获取分类
     * 
     * @param int $categoryId 分类ID
     * @return Category|null
     */
    public function getCategory(int $categoryId): ?Category
    {
        /** @var Category $category */
        $category = ObjectManager::getInstance(Category::class);
        $category->load($categoryId);
        
        if ($category->getId()) {
            return $category;
        }
        
        return null;
    }
    
    /**
     * 通过handle获取分类
     * 
     * @param string $handle Handle标识（支持层级路径，如 "men/shirts"）
     * @return Category|null
     */
    public function getCategoryByHandle(string $handle): ?Category
    {
        /** @var Category $categoryModel */
        $categoryModel = ObjectManager::getInstance(Category::class);
        
        $decodedHandle = urldecode($handle);

        // 1. 优先用完整 handle 精确匹配（支持层级路径已直接存入 handle 的场景）
        // 克隆模型以避免状态污染
        $category = clone $categoryModel;
        $category->clear()
            ->where(Category::fields_HANDLE, $decodedHandle)
            ->where(Category::fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        if ($category->getId()) {
            return $category;
        }

        // 2. 如果包含层级路径（如 men/shirts），尝试使用最后一段作为 handle 匹配
        if (str_contains($decodedHandle, '/')) {
            $leafHandle = basename($decodedHandle);
            if ($leafHandle !== '') {
                $category = clone $categoryModel;
                $category->clear()
                    ->where(Category::fields_HANDLE, $leafHandle)
                    ->where(Category::fields_IS_ACTIVE, 1)
                    ->find()
                    ->fetch();

                if ($category->getId()) {
                    return $category;
                }
            }
        }

        // 3. 最后退回到原始 handle 精确匹配（兼容部分场景）
        if ($decodedHandle !== $handle) {
            $category = clone $categoryModel;
            $category->clear()
                ->where(Category::fields_HANDLE, $handle)
                ->where(Category::fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();

            if ($category->getId()) {
                return $category;
            }
        }

        return null;
    }
    
    /**
     * 获取子分类
     * 
     * @param int $parentId 父分类ID
     * @return array
     */
    public function getChildCategories(int $parentId): array
    {
        /** @var Category $category */
        $category = ObjectManager::getInstance(Category::class);
        
        return $category->clear()
            ->where(Category::fields_PARENT_ID, $parentId)
            ->where(Category::fields_IS_ACTIVE, 1)
            ->order(Category::fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetchArray();
    }
    
    /**
     * 获取分类树
     * 
     * @param int $parentId 父分类ID（默认0）
     * @param bool $includeRightMenuOnly 是否只包含右侧菜单分类（默认false）
     * @return array
     */
    public function getCategoryTree(int $parentId = 0, bool $includeRightMenuOnly = false): array
    {
        $categories = $this->getChildCategories($parentId);
        
        foreach ($categories as &$category) {
            // 加载 EAV 属性
            $categoryModel = ObjectManager::getInstance(Category::class);
            $categoryModel->load($category['category_id']);
            
            // 获取 is_right_menu 属性值
            try {
                $isRightMenuValue = $categoryModel->getAttributeValue('is_right_menu');
                $category['is_right_menu'] = $isRightMenuValue ? (int)$isRightMenuValue : 0;
            } catch (\Exception $e) {
                $category['is_right_menu'] = 0;
            }
            
            // 获取图标属性值（用于子分类筛选器显示）
            try {
                $iconValue = $categoryModel->getAttributeValue('icon');
                $category['icon'] = $iconValue ?: '';
            } catch (\Exception $e) {
                $category['icon'] = '';
            }
            
            // 获取 show_icon 属性值（控制是否显示图标，默认为 true）
            try {
                $showIconValue = $categoryModel->getAttributeValue('show_icon');
                $category['show_icon'] = $showIconValue !== null ? (bool)$showIconValue : true;
            } catch (\Exception $e) {
                $category['show_icon'] = true; // 默认显示图标
            }
            
            // 如果只需要右侧菜单分类，且当前分类不是右侧菜单分类，则跳过
            if ($includeRightMenuOnly && $category['is_right_menu'] != 1) {
                continue;
            }
            
            $category['children'] = $this->getCategoryTree($category['category_id'], $includeRightMenuOnly);
        }
        
        // 过滤掉被跳过的分类
        $categories = array_values(array_filter($categories, function($cat) use ($includeRightMenuOnly) {
            return !$includeRightMenuOnly || $cat['is_right_menu'] == 1;
        }));
        
        return $categories;
    }
    
    /**
     * 获取右侧菜单分类树
     * 
     * @param int $parentId 父分类ID（默认0）
     * @return array
     */
    public function getRightMenuCategoryTree(int $parentId = 0): array
    {
        return $this->getCategoryTree($parentId, true);
    }
    
    /**
     * 获取所有子分类ID（递归，包括子分类的子分类）
     * 
     * @param int $parentId 父分类ID
     * @return array 所有子分类ID数组（包括传入的父分类ID本身）
     */
    public function getAllDescendantCategoryIds(int $parentId): array
    {
        $categoryIds = [$parentId]; // 包含当前分类ID
        
        // 递归获取所有子分类ID
        $getChildrenIds = function(int $catId) use (&$getChildrenIds, &$categoryIds) {
            $children = $this->getChildCategories($catId);
            foreach ($children as $child) {
                $childId = (int)($child['category_id'] ?? 0);
                if ($childId > 0 && !in_array($childId, $categoryIds)) {
                    $categoryIds[] = $childId;
                    // 递归获取子分类的子分类
                    $getChildrenIds($childId);
                }
            }
        };
        
        $getChildrenIds($parentId);
        
        return array_unique($categoryIds);
    }
    
    /**
     * 保存分类
     * 
     * @param array $categoryData 分类数据
     * @return Category
     */
    public function saveCategory(array $categoryData): Category
    {
        /** @var Category $category */
        $category = ObjectManager::getInstance(Category::class);
        
        if (!empty($categoryData[Category::fields_ID])) {
            $category->load($categoryData[Category::fields_ID]);
        }
        
        foreach ($categoryData as $key => $value) {
            if ($key !== Category::fields_ID) {
                $category->setData($key, $value);
            }
        }
        
        $category->save();
        
        return $category;
    }
}
