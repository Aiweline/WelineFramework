<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 页面+画像每日发文配额模型（关联 PageBuilder 首页获取语言）
 */

namespace GuoLaiRen\Blog\Model;

use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class TrendSiteQuota extends Model
{
    public const table = 'guolairen_blog_trend_site_quota';

    public const fields_ID                   = 'quota_id';
    /** @deprecated 已废弃，使用 page_id 代替 */
    public const fields_SITE_ID             = 'site_id';
    /** 关联 PageBuilder 首页ID（用于获取语言） */
    public const fields_PAGE_ID             = 'page_id';
    public const fields_PROFILE_ID          = 'profile_id';
    public const fields_ARTICLES_PER_DAY    = 'articles_per_day';
    public const fields_DEFAULT_CATEGORY_ID = 'default_category_id';
    public const fields_CREATED_AT         = 'created_at';
    public const fields_UPDATED_AT         = 'updated_at';

    /**
     * 获取关联的 Page 对象
     */
    public function getPage(): ?Page
    {
        $pageId = (int)$this->getData(self::fields_PAGE_ID);
        if ($pageId <= 0) {
            return null;
        }
        /** @var Page $page */
        $page = ObjectManager::getInstance(Page::class);
        $page->clear()->load($pageId);
        return $page->getId() ? $page : null;
    }

    /**
     * 获取关联页面的默认语言
     */
    public function getPageLocale(): string
    {
        $page = $this->getPage();
        if (!$page) {
            return 'en_US';
        }
        return $page->getData(Page::fields_DEFAULT_LOCALE) ?: 'en_US';
    }

    /**
     * 获取关联页面的 website_id（用于文章 site_id）
     */
    public function getPageWebsiteId(): int
    {
        $page = $this->getPage();
        if (!$page) {
            return 0;
        }
        return (int)$page->getData(Page::fields_WEBSITE_ID);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('页面趋势发文配额表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '配额ID'
            )
            ->addColumn(
                self::fields_PAGE_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                'PageBuilder首页ID'
            )
            ->addColumn(
                self::fields_PROFILE_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '画像ID'
            )
            ->addColumn(
                self::fields_ARTICLES_PER_DAY,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '每日发文篇数'
            )
            ->addColumn(
                self::fields_DEFAULT_CATEGORY_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '默认分类ID（该页面站点下博客分类）'
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
                'idx_page_profile',
                [self::fields_PAGE_ID, self::fields_PROFILE_ID],
                '页面+画像索引'
            )
            ->addIndex(
                TableInterface::index_type_UNIQUE,
                'uk_page_profile',
                [self::fields_PAGE_ID, self::fields_PROFILE_ID],
                '页面+画像唯一'
            )
            ->create();
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            return;
        }
        
        // 添加 page_id 字段（如果不存在）
        if (!$setup->hasField(self::fields_PAGE_ID)) {
            $setup->alterTable()->addColumn(
                self::fields_PAGE_ID,
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                'PageBuilder首页ID'
            )->alter();
            
            // 添加索引
            try {
                $tableName = $setup->getTable();
                $connector = $this->getConnection()->getConnector();
                $connector->query("CREATE INDEX IF NOT EXISTS idx_page_profile ON {$tableName} (\"" . self::fields_PAGE_ID . "\", \"" . self::fields_PROFILE_ID . "\")")->fetchArray();
            } catch (\Exception $e) {
                // 索引可能已存在
            }
        }
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
        $this->upgrade($setup, $context);
    }
}
