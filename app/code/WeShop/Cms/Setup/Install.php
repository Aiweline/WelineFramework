<?php

declare(strict_types=1);

namespace WeShop\Cms\Setup;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Data\Setup;
use WeShop\Cms\Model\Page;

class Install implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $pageModel = ObjectManager::getInstance(Page::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($pageModel)->setContext($context);

        if (defined('DEV') && DEV && $modelSetup->tableExist()) {
            $modelSetup->dropTable();
        }

        if (!$modelSetup->tableExist()) {
            $this->createPageTable($modelSetup);
        }

        $this->createDefaultTestPage($pageModel);
    }

    private function createPageTable(ModelSetup $modelSetup): void
    {
        $modelSetup->createTable('WeShop CMS页面表')
            ->addColumn(
                Page::schema_fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '页面ID'
            )
            ->addColumn(
                Page::schema_fields_HANDLE,
                TableInterface::column_type_VARCHAR,
                100,
                'not null unique',
                '页面句柄'
            )
            ->addColumn(
                Page::schema_fields_TYPE,
                TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '页面类型'
            )
            ->addColumn(
                Page::schema_fields_NAME,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '页面名称'
            )
            ->addColumn(
                Page::schema_fields_TITLE,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '页面标题'
            )
            ->addColumn(
                Page::schema_fields_CONTENT,
                TableInterface::column_type_TEXT,
                0,
                '',
                '页面内容'
            )
            ->addColumn(
                Page::schema_fields_PARENT_ID,
                TableInterface::column_type_INTEGER,
                0,
                'default 0',
                '父页面ID'
            )
            ->addColumn(
                Page::schema_fields_GA4_ID,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                'Google Analytics 4 ID'
            )
            ->addColumn(
                Page::schema_fields_GTM_ID,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                'Google Tag Manager ID'
            )
            ->addColumn(
                Page::schema_fields_FB_PIXEL_ID,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                'Facebook Pixel ID'
            )
            ->addColumn(
                Page::schema_fields_LOGO,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                'Logo图片路径'
            )
            ->addColumn(
                Page::schema_fields_ICON,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                'Icon图标路径'
            )
            ->addColumn(
                Page::schema_fields_LOCALES,
                TableInterface::column_type_TEXT,
                0,
                '',
                '选中的语言列表(JSON)'
            )
            ->addColumn(
                Page::schema_fields_DEFAULT_LOCALE,
                TableInterface::column_type_VARCHAR,
                10,
                '',
                '默认语言代码'
            )
            ->addColumn(
                Page::schema_fields_STYLE,
                TableInterface::column_type_VARCHAR,
                100,
                '',
                '页面样式模板名称'
            )
            ->addColumn(
                Page::schema_fields_STYLE_SETTING,
                TableInterface::column_type_TEXT,
                0,
                '',
                '页面样式配置(JSON)'
            )
            ->addColumn(
                Page::schema_fields_META_TITLE,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                'SEO标题'
            )
            ->addColumn(
                Page::schema_fields_META_DESCRIPTION,
                TableInterface::column_type_TEXT,
                0,
                '',
                'SEO描述'
            )
            ->addColumn(
                Page::schema_fields_META_KEYWORDS,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                'SEO关键词'
            )
            ->addColumn(
                Page::schema_fields_REDIRECT_URL,
                TableInterface::column_type_VARCHAR,
                500,
                '',
                '表单提交后跳转URL'
            )
            ->addColumn(
                Page::schema_fields_STATUS,
                TableInterface::column_type_SMALLINT,
                1,
                'not null default 0',
                '状态:0草稿,1已发布'
            )
            ->addColumn(
                Page::schema_fields_CREATE_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addColumn(
                Page::schema_fields_UPDATE_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP',
                '更新时间'
            )
            ->addIndex(TableInterface::index_type_KEY, 'idx_handle', [Page::schema_fields_HANDLE], '句柄索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_type', [Page::schema_fields_TYPE], '类型索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_parent_id', [Page::schema_fields_PARENT_ID], '父页面索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_status', [Page::schema_fields_STATUS], '状态索引')
            ->create();
    }

    private function createDefaultTestPage(Page $pageModel): void
    {
        try {
            if (!$pageModel->getConnection()->getConnector()->tableExist($pageModel->getTable())) {
                return;
            }
            $existing = clone $pageModel;
            $existing->clear()
                ->where(Page::schema_fields_HANDLE, 'test-page')
                ->find()
                ->fetch();
            if ($existing->getId()) {
                return;
            }
            $newPage = clone $pageModel;
            $newPage->clearData()
                ->setData(Page::schema_fields_HANDLE, 'test-page')
                ->setData(Page::schema_fields_TYPE, Page::TYPE_CUSTOM)
                ->setData(Page::schema_fields_NAME, __('测试页面'))
                ->setData(Page::schema_fields_TITLE, __('测试页面'))
                ->setData(Page::schema_fields_CONTENT, '<h1>' . __('欢迎使用CMS页面管理系统') . '</h1><p>' . __('这是一个默认的测试页面，您可以编辑或删除它。') . '</p>')
                ->setData(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED)
                ->save(true);
        } catch (\Throwable $e) {
            \Weline\Framework\App\Env::log_error('weshop_cms', 'Failed to create default test page: ' . $e->getMessage());
        }
    }
}
