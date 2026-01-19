<?php

declare(strict_types=1);

namespace WeShop\Catalog\Service;

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
