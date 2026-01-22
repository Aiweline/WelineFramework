<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 页面模型
 */

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Page extends Model
{
    public const table = 'guolairen_page_builder_page';
    
    // 字段定义
    public const fields_ID = 'page_id';
    public const fields_HANDLE = 'handle';
    public const fields_TYPE = 'type';
    public const fields_NAME = 'name';
    public const fields_TITLE = 'title';
    public const fields_CONTENT = 'content';
    public const fields_PARENT_ID = 'parent_id';
    public const fields_GA4_ID = 'ga4_id';
    public const fields_GTM_ID = 'gtm_id';
    public const fields_FB_PIXEL_ID = 'fb_pixel_id';
    public const fields_CTA_EVENT_NAME = 'cta_event_name';
    public const fields_HEADER_CUSTOM_CODE = 'header_custom_code';
    public const fields_FOOTER_CUSTOM_CODE = 'footer_custom_code';
    public const fields_LOGO = 'logo';
    public const fields_ICON = 'icon';
    public const fields_LOCALES = 'locales';
    public const fields_DEFAULT_LOCALE = 'default_locale';
    public const fields_STYLE = 'style';
    public const fields_STYLE_SETTING = 'style_setting';
    public const fields_META_TITLE = 'meta_title';
    public const fields_META_DESCRIPTION = 'meta_description';
    public const fields_META_KEYWORDS = 'meta_keywords';
    public const fields_REDIRECT_URL = 'redirect_url';
    /** 关联站点ID（Weline_Websites::Website） */
    public const fields_WEBSITE_ID = 'website_id';
    public const fields_STATUS = 'status';
    public const fields_CREATE_TIME = 'create_time';
    public const fields_UPDATE_TIME = 'update_time';
    
    // 页面类型常量
    public const TYPE_HOME = 'home_page';
    public const TYPE_ABOUT = 'about_page';
    public const TYPE_CONTACT = 'contact_page';
    public const TYPE_PRIVACY_POLICY = 'privacy_policy';
    public const TYPE_TERMS_OF_SERVICE = 'terms_of_service';
    public const TYPE_REFUND_POLICY = 'refund_policy';
    public const TYPE_SHIPPING_POLICY = 'shipping_policy';
    /** 博客文章类型（单篇博客文章详情页） */
    public const TYPE_BLOG = 'blog_post';
    /** 博客分类类型（博客分类列表页） */
    public const TYPE_BLOG_CATEGORY = 'blog_category';
    /** 博客列表类型（博客文章列表页） */
    public const TYPE_BLOG_LIST = 'blog_list';
    public const TYPE_CUSTOM = 'custom_page';
    
    // 状态常量
    public const STATUS_DRAFT = 0;
    public const STATUS_PUBLISHED = 1;
    
    /**
     * 获取所有页面类型
     */
    public static function getPageTypes(): array
    {
        return [
            self::TYPE_HOME => __('首页'),
            self::TYPE_ABOUT => __('关于我们'),
            self::TYPE_CONTACT => __('联系我们'),
            self::TYPE_PRIVACY_POLICY => __('隐私政策'),
            self::TYPE_TERMS_OF_SERVICE => __('服务条款'),
            self::TYPE_REFUND_POLICY => __('退款政策'),
            self::TYPE_SHIPPING_POLICY => __('配送政策'),
            self::TYPE_BLOG => __('博客文章'),
            self::TYPE_BLOG_CATEGORY => __('博客分类'),
            self::TYPE_BLOG_LIST => __('博客列表'),
            self::TYPE_CUSTOM => __('自定义页面'),
        ];
    }
    
    /**
     * 获取博客相关的页面类型
     */
    public static function getBlogPageTypes(): array
    {
        return [
            self::TYPE_BLOG => __('博客文章'),
            self::TYPE_BLOG_CATEGORY => __('博客分类'),
            self::TYPE_BLOG_LIST => __('博客列表'),
        ];
    }
    
    /**
     * 检查是否是博客类型的页面
     */
    public function isBlogType(): bool
    {
        $type = $this->getData(self::fields_TYPE);
        return in_array($type, [self::TYPE_BLOG, self::TYPE_BLOG_CATEGORY, self::TYPE_BLOG_LIST]);
    }
    
    /**
     * 获取页面类型名称
     */
    public function getTypeName(): string
    {
        $types = self::getPageTypes();
        return $types[$this->getData(self::fields_TYPE)] ?? $this->getData(self::fields_TYPE);
    }
    
    /**
     * 获取状态名称
     */
    public function getStatusName(): string
    {
        return $this->getData(self::fields_STATUS) == self::STATUS_PUBLISHED ? __('已发布') : __('草稿');
    }
    
    /**
     * 获取选中的语言列表
     */
    public function getSelectedLocales(): array
    {
        $locales = $this->getData(self::fields_LOCALES);
        if (empty($locales)) {
            return [];
        }
        return json_decode($locales ?? '', true) ?: [];
    }
    
    /**
     * 设置选中的语言列表
     */
    public function setSelectedLocales(array $locales): self
    {
        return $this->setData(self::fields_LOCALES, json_encode($locales));
    }
    
    /**
     * 获取父页面
     */
    public function getParentPage(): ?Page
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
     * 获取子页面列表
     */
    public function getChildPages(): array
    {
        $children = clone $this;
        return $children->clear()
            ->where(self::fields_PARENT_ID, $this->getId())
            ->select()
            ->fetch()
            ->getItems();
    }
    
    /**
     * 获取样式配置
     */
    public function getStyleSetting(): array
    {
        $setting = $this->getData(self::fields_STYLE_SETTING);
        if (empty($setting)) {
            return [];
        }
        return json_decode($setting ?? '', true) ?: [];
    }
    
    /**
     * 设置样式配置
     */
    public function setStyleSetting(array $setting): self
    {
        return $this->setData(self::fields_STYLE_SETTING, json_encode($setting));
    }
    
    /**
     * 获取样式配置的某个值
     */
    public function getStyleSettingValue(string $key, $default = null)
    {
        $settings = $this->getStyleSetting();
        return $settings[$key] ?? $default;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        // 删除旧表（如果存在）- 仅在重建表结构时临时启用
        // $setup->dropTable();
        
        // 检查表是否已存在
        if ($setup->tableExist()) {
            return;
        }
        
        // 创建新表
        $setup->createTable('页面构建器-页面表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '页面ID'
            )
            ->addColumn(
                self::fields_HANDLE,
                TableInterface::column_type_VARCHAR,
                100,
                'not null unique',
                '页面句柄'
            )
            ->addColumn(
                self::fields_TYPE,
                TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '页面类型'
            )
            ->addColumn(
                self::fields_NAME,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '页面名称'
            )
            ->addColumn(
                self::fields_TITLE,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '页面标题'
            )
            ->addColumn(
                self::fields_CONTENT,
                TableInterface::column_type_TEXT,
                0,
                '',
                '页面内容'
            )
            ->addColumn(
                self::fields_PARENT_ID,
                TableInterface::column_type_INTEGER,
                0,
                'default 0',
                '父页面ID'
            )
            ->addColumn(
                self::fields_WEBSITE_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 1',
                '关联站点ID'
            )
            ->addColumn(
                self::fields_GA4_ID,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                'Google Analytics 4 ID'
            )
            ->addColumn(
                self::fields_GTM_ID,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                'Google Tag Manager ID'
            )
            ->addColumn(
                self::fields_FB_PIXEL_ID,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                'Facebook Pixel ID'
            )
            ->addColumn(
                self::fields_CTA_EVENT_NAME,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                'CTA转化事件名称'
            )
            ->addColumn(
                self::fields_LOGO,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                'Logo图片路径'
            )
            ->addColumn(
                self::fields_ICON,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                'Icon图标路径'
            )
            ->addColumn(
                self::fields_LOCALES,
                TableInterface::column_type_TEXT,
                0,
                '',
                '选中的语言列表(JSON)'
            )
            ->addColumn(
                self::fields_DEFAULT_LOCALE,
                TableInterface::column_type_VARCHAR,
                10,
                '',
                '默认语言代码'
            )
            ->addColumn(
                self::fields_STYLE,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                '页面样式模板名称'
            )
            ->addColumn(
                self::fields_STYLE_SETTING,
                TableInterface::column_type_TEXT,
                0,
                '',
                '页面样式配置(JSON)'
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
                self::fields_REDIRECT_URL,
                TableInterface::column_type_VARCHAR,
                500,
                '',
                '表单提交后跳转URL'
            )
            ->addColumn(
                self::fields_HEADER_CUSTOM_CODE,
                TableInterface::column_type_TEXT,
                0,
                '',
                'Header自定义代码（GSC验证、统计代码等）'
            )
            ->addColumn(
                self::fields_FOOTER_CUSTOM_CODE,
                TableInterface::column_type_TEXT,
                0,
                '',
                'Footer自定义代码（GSC验证、统计代码等）'
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_SMALLINT,
                1,
                'not null default 0',
                '状态:0草稿,1已发布'
            )
            ->addColumn(
                self::fields_CREATE_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addColumn(
                self::fields_UPDATE_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP',
                '更新时间'
            )
            ->addIndex(TableInterface::index_type_KEY, 'idx_handle', [self::fields_HANDLE], '句柄索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_type', [self::fields_TYPE], '类型索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_parent_id', [self::fields_PARENT_ID], '父页面索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_website_id', [self::fields_WEBSITE_ID], '站点索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_status', [self::fields_STATUS], '状态索引')
            ->create();
    }

    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 添加 website_id 字段（如果不存在）
        if ($setup->tableExist() && !$setup->hasField(self::fields_WEBSITE_ID)) {
            try {
                $setup->alterTable()->addColumn(
                    self::fields_WEBSITE_ID,
                    '',
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null default 1',
                    '关联站点ID'
                )->alter();
                
                // 如果通过 AlterWithBackup 仍未成功添加字段，则降级为直接执行 ALTER TABLE 语句作为兜底
                if (!$setup->hasField(self::fields_WEBSITE_ID)) {
                    $tableName = $setup->getTable();
                    // PostgreSQL 需要双引号包裹字段名，表名已经包含引号
                    $fieldName = '"' . self::fields_WEBSITE_ID . '"';
                    $sql = sprintf(
                        'ALTER TABLE %s ADD COLUMN %s INTEGER NOT NULL DEFAULT 1',
                        $tableName,
                        $fieldName
                    );
                    $this->getConnection()
                        ->getConnector()
                        ->query($sql)
                        ->fetchArray();
                    
                    // 添加索引
                    $indexName = 'idx_' . self::fields_WEBSITE_ID;
                    $indexSql = sprintf(
                        'CREATE INDEX IF NOT EXISTS %s ON %s (%s)',
                        $indexName,
                        $tableName,
                        $fieldName
                    );
                    try {
                        $this->getConnection()
                            ->getConnector()
                            ->query($indexSql)
                            ->fetchArray();
                    } catch (\Exception $e) {
                        // 索引可能已存在，忽略错误
                    }
                }
            } catch (\Throwable $e) {
                throw $e;
            }
        }

        // 添加 default_locale 字段（如果不存在）
        if ($setup->tableExist() && !$setup->hasField(self::fields_DEFAULT_LOCALE)) {
            $setup->alterTable()->addColumn(
                self::fields_DEFAULT_LOCALE,
                '',
                TableInterface::column_type_VARCHAR,
                10,
                '',
                '默认语言代码'
            )->alter();
        }
        
        // 添加 header_custom_code 字段（如果不存在）
        // 默认会自动恢复之前备份的数据（如果有）
        if ($setup->tableExist() && !$setup->hasField(self::fields_HEADER_CUSTOM_CODE)) {
            try {
                $setup->alterTable()->addColumn(
                    self::fields_HEADER_CUSTOM_CODE,
                    '',
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    'Header自定义代码（GSC验证、统计代码等）'
                )->alter();
                
                // 如果通过 AlterWithBackup 仍未成功添加字段，则降级为直接执行 ALTER TABLE 语句作为兜底
                if (!$setup->hasField(self::fields_HEADER_CUSTOM_CODE)) {
                    $tableName = $setup->getTable();
                    // PostgreSQL 需要双引号包裹字段名，表名已经包含引号
                    $fieldName = '"' . self::fields_HEADER_CUSTOM_CODE . '"';
                    $sql = sprintf(
                        'ALTER TABLE %s ADD COLUMN %s TEXT',
                        $tableName,
                        $fieldName
                    );
                    $this->getConnection()
                        ->getConnector()
                        ->query($sql)
                        ->fetchArray();
                }
            } catch (\Throwable $e) {
                throw $e;
            }
        }
        
        // 添加 footer_custom_code 字段（如果不存在）
        // 默认会自动恢复之前备份的数据（如果有）
        if ($setup->tableExist() && !$setup->hasField(self::fields_FOOTER_CUSTOM_CODE)) {
            try {
                $setup->alterTable()->addColumn(
                    self::fields_FOOTER_CUSTOM_CODE,
                    '',
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    'Footer自定义代码（GSC验证、统计代码等）'
                )->alter();
                
                // 如果通过 AlterWithBackup 仍未成功添加字段，则降级为直接执行 ALTER TABLE 语句作为兜底
                if (!$setup->hasField(self::fields_FOOTER_CUSTOM_CODE)) {
                    $tableName = $setup->getTable();
                    // PostgreSQL 需要双引号包裹字段名，表名已经包含引号
                    $fieldName = '"' . self::fields_FOOTER_CUSTOM_CODE . '"';
                    $sql = sprintf(
                        'ALTER TABLE %s ADD COLUMN %s TEXT',
                        $tableName,
                        $fieldName
                    );
                    $this->getConnection()
                        ->getConnector()
                        ->query($sql)
                        ->fetchArray();
                }
            } catch (\Throwable $e) {
                throw $e;
            }
        }
    }

    /**
     * 设置表结构
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        // 先安装表（如果是全新安装）
        $this->install($setup, $context);
        // 再执行升级逻辑（为已存在的表补充新字段）
        $this->upgrade($setup, $context);
    }
}

