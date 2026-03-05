<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 博客分类模型
 */

namespace GuoLaiRen\Blog\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

#[Table(comment: '博客分类表')]
#[Index(name: 'idx_parent_id', columns: ['parent_id'], comment: '父级分类索引')]
#[Index(name: 'idx_status', columns: ['status'], comment: '状态索引')]
#[Index(name: 'idx_sort_order', columns: ['sort_order'], comment: '排序索引')]
#[Index(name: 'idx_site_id', columns: ['site_id'], comment: '站点索引')]
class Category extends Model
{
    public const schema_table = 'guolairen_blog_category';
    public const schema_primary_key = 'category_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '分类ID')]
    public const schema_fields_ID          = 'category_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '所属站点ID')]
    public const schema_fields_SITE_ID    = 'site_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '分类名称')]
    public const schema_fields_NAME        = 'name';
    #[Col(type: 'varchar', length: 255, nullable: false, unique: true, comment: 'URL别名（唯一）')]
    public const schema_fields_SLUG        = 'slug';
    #[Col(type: 'text', nullable: true, comment: '分类描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '封面图片URL')]
    public const schema_fields_COVER_IMAGE = 'cover_image';
    #[Col(type: 'int', nullable: false, default: 0, comment: '父级分类ID')]
    public const schema_fields_PARENT_ID   = 'parent_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER  = 'sort_order';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '状态:0禁用,1启用')]
    public const schema_fields_STATUS      = 'status';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'SEO标题')]
    public const schema_fields_META_TITLE       = 'meta_title';
    #[Col(type: 'text', nullable: true, comment: 'SEO描述')]
    public const schema_fields_META_DESCRIPTION = 'meta_description';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'SEO关键词')]
    public const schema_fields_META_KEYWORDS    = 'meta_keywords';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT  = 'created_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT  = 'updated_at';

    // 状态常量
    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED  = 1;

    /**
     * 获取状态名称
     */
    public function getStatusName(): string
    {
        return $this->getData(self::schema_fields_STATUS) == self::STATUS_ENABLED
            ? __('启用')
            : __('禁用');
    }

    /**
     * 获取状态列表
     */
    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_DISABLED => __('禁用'),
            self::STATUS_ENABLED  => __('启用'),
        ];
    }

    /**
     * 获取父级分类
     */
    public function getParentCategory(): ?Category
    {
        $parentId = $this->getData(self::schema_fields_PARENT_ID);
        if (!$parentId) {
            return null;
        }

        $parent = clone $this;
        $parent->clear()->load($parentId);
        return $parent->getId() ? $parent : null;
    }

    /**
     * 获取子分类列表
     */
    public function getChildCategories(): array
    {
        $children = clone $this;
        $query = $children->clear()
            ->where(self::schema_fields_PARENT_ID, $this->getId())
            ->where(self::schema_fields_STATUS, self::STATUS_ENABLED);
        
        // 如果当前分类有 site_id，只查询同站点的子分类
        $siteId = $this->getData(self::schema_fields_SITE_ID);
        if ($siteId) {
            $query->where(self::schema_fields_SITE_ID, $siteId);
        }
        
        return $query->order(self::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }

    /**
     * 获取分类的文章数量
     */
    public function getPostCount(): int
    {
        $postModel = \Weline\Framework\Manager\ObjectManager::getInstance(Post::class);
        $query = $postModel->clear()
            ->where(Post::schema_fields_CATEGORY_ID, $this->getId())
            ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED);
        
        // 如果当前分类有 site_id，只统计同站点的文章
        $siteId = $this->getData(self::schema_fields_SITE_ID);
        if ($siteId) {
            $query->where(Post::schema_fields_SITE_ID, $siteId);
        }
        
        return (int)$query->count();
    }

    /**
     * 获取分类的文章列表
     */
    public function getPosts(int $page = 1, int $pageSize = 10): array
    {
        $postModel = \Weline\Framework\Manager\ObjectManager::getInstance(Post::class);
        $query = $postModel->clear()
            ->where(Post::schema_fields_CATEGORY_ID, $this->getId())
            ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED);
        
        // 如果当前分类有 site_id，只查询同站点的文章
        $siteId = $this->getData(self::schema_fields_SITE_ID);
        if ($siteId) {
            $query->where(Post::schema_fields_SITE_ID, $siteId);
        }
        
        return $query->order(Post::schema_fields_PUBLISHED_AT, 'DESC')
            ->page($page, $pageSize)
            ->select()
            ->fetch()
            ->getItems();
    }

    /**
     * 获取分类树（用于下拉选择）
     * 
     * @param int $excludeId 排除的分类ID
     * @param int|null $siteId 站点ID，如果提供则只返回该站点的分类
     */
    public static function getCategoryTree(int $excludeId = 0, ?int $siteId = null): array
    {
        $model = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        $query = $model->clear()
            ->where(self::schema_fields_STATUS, self::STATUS_ENABLED);
        
        // 如果提供了 site_id，只查询该站点的分类
        if ($siteId !== null) {
            $query->where(self::schema_fields_SITE_ID, $siteId);
        }
        
        $categories = $query->order(self::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $tree = [];
        $map = [];

        // 构建映射
        foreach ($categories as $category) {
            $id = (int)$category->getId();
            if ($id === $excludeId) {
                continue;
            }
            $map[$id] = [
                'id' => $id,
                'name' => $category->getData(self::schema_fields_NAME),
                'slug' => $category->getData(self::schema_fields_SLUG),
                'parent_id' => (int)$category->getData(self::schema_fields_PARENT_ID),
                'level' => 0,
                'children' => [],
            ];
        }

        // 构建树
        foreach ($map as $id => &$item) {
            $parentId = $item['parent_id'];
            if ($parentId > 0 && isset($map[$parentId])) {
                $map[$parentId]['children'][] = &$item;
                $item['level'] = $map[$parentId]['level'] + 1;
            } else {
                $tree[] = &$item;
            }
        }

        return $tree;
    }

    /**
     * 获取扁平化的分类列表（带层级缩进）
     * 
     * @param int $excludeId 排除的分类ID
     * @param int|null $siteId 站点ID，如果提供则只返回该站点的分类
     */
    public static function getFlatCategoryList(int $excludeId = 0, ?int $siteId = null): array
    {
        $tree = self::getCategoryTree($excludeId, $siteId);
        $flat = [];

        $flatten = function($items, $prefix = '') use (&$flatten, &$flat) {
            foreach ($items as $item) {
                $flat[$item['id']] = $prefix . $item['name'];
                if (!empty($item['children'])) {
                    $flatten($item['children'], $prefix . '— ');
                }
            }
        };

        $flatten($tree);
        return $flat;
    }

}
