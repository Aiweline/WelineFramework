<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 博客分类模型
 */

namespace GuoLaiRen\Blog\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Category extends Model
{
    public const table = 'guolairen_blog_category';

    // 字段定义
    public const fields_ID          = 'category_id';
    public const fields_SITE_ID    = 'site_id';
    public const fields_NAME        = 'name';
    public const fields_SLUG        = 'slug';
    public const fields_DESCRIPTION = 'description';
    public const fields_COVER_IMAGE = 'cover_image';
    public const fields_PARENT_ID   = 'parent_id';
    public const fields_SORT_ORDER  = 'sort_order';
    public const fields_STATUS      = 'status';
    public const fields_META_TITLE       = 'meta_title';
    public const fields_META_DESCRIPTION = 'meta_description';
    public const fields_META_KEYWORDS    = 'meta_keywords';
    public const fields_CREATED_AT  = 'created_at';
    public const fields_UPDATED_AT  = 'updated_at';

    // 状态常量
    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED  = 1;

    /**
     * 获取状态名称
     */
    public function getStatusName(): string
    {
        return $this->getData(self::fields_STATUS) == self::STATUS_ENABLED
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
        $parentId = $this->getData(self::fields_PARENT_ID);
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
            ->where(self::fields_PARENT_ID, $this->getId())
            ->where(self::fields_STATUS, self::STATUS_ENABLED);
        
        // 如果当前分类有 site_id，只查询同站点的子分类
        $siteId = $this->getData(self::fields_SITE_ID);
        if ($siteId) {
            $query->where(self::fields_SITE_ID, $siteId);
        }
        
        return $query->order(self::fields_SORT_ORDER, 'ASC')
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
            ->where(Post::fields_CATEGORY_ID, $this->getId())
            ->where(Post::fields_STATUS, Post::STATUS_PUBLISHED);
        
        // 如果当前分类有 site_id，只统计同站点的文章
        $siteId = $this->getData(self::fields_SITE_ID);
        if ($siteId) {
            $query->where(Post::fields_SITE_ID, $siteId);
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
            ->where(Post::fields_CATEGORY_ID, $this->getId())
            ->where(Post::fields_STATUS, Post::STATUS_PUBLISHED);
        
        // 如果当前分类有 site_id，只查询同站点的文章
        $siteId = $this->getData(self::fields_SITE_ID);
        if ($siteId) {
            $query->where(Post::fields_SITE_ID, $siteId);
        }
        
        return $query->order(Post::fields_PUBLISHED_AT, 'DESC')
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
            ->where(self::fields_STATUS, self::STATUS_ENABLED);
        
        // 如果提供了 site_id，只查询该站点的分类
        if ($siteId !== null) {
            $query->where(self::fields_SITE_ID, $siteId);
        }
        
        $categories = $query->order(self::fields_SORT_ORDER, 'ASC')
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
                'name' => $category->getData(self::fields_NAME),
                'slug' => $category->getData(self::fields_SLUG),
                'parent_id' => (int)$category->getData(self::fields_PARENT_ID),
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

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('博客分类表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '分类ID'
            )
            ->addColumn(
                self::fields_SITE_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '所属站点ID'
            )
            ->addColumn(
                self::fields_NAME,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '分类名称'
            )
            ->addColumn(
                self::fields_SLUG,
                TableInterface::column_type_VARCHAR,
                255,
                'not null unique',
                'URL别名（唯一）'
            )
            ->addColumn(
                self::fields_DESCRIPTION,
                TableInterface::column_type_TEXT,
                0,
                '',
                '分类描述'
            )
            ->addColumn(
                self::fields_COVER_IMAGE,
                TableInterface::column_type_VARCHAR,
                500,
                '',
                '封面图片URL'
            )
            ->addColumn(
                self::fields_PARENT_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '父级分类ID'
            )
            ->addColumn(
                self::fields_SORT_ORDER,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '排序'
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_SMALLINT,
                1,
                'not null default 1',
                '状态:0禁用,1启用'
            )
            ->addColumn(
                self::fields_META_TITLE,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                'SEO标题'
            )
            ->addColumn(
                self::fields_META_DESCRIPTION,
                TableInterface::column_type_TEXT,
                0,
                '',
                'SEO描述'
            )
            ->addColumn(
                self::fields_META_KEYWORDS,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                'SEO关键词'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addColumn(
                self::fields_UPDATED_AT,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP',
                '更新时间'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_parent_id',
                [self::fields_PARENT_ID],
                '父级分类索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_status',
                [self::fields_STATUS],
                '状态索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_sort_order',
                [self::fields_SORT_ORDER],
                '排序索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_site_id',
                [self::fields_SITE_ID],
                '站点索引'
            )
            ->create();
    }

    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            return;
        }
        
        // 添加 site_id 字段
        if (!$setup->hasField(self::fields_SITE_ID)) {
            $setup->alterTable()->addColumn(
                self::fields_SITE_ID,
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '所属站点ID'
            )->addIndex(
                TableInterface::index_type_KEY,
                'idx_site_id',
                [self::fields_SITE_ID],
                '站点索引'
            )->alter();
        }
    }

    /**
     * 设置表结构
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
}
