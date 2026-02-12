<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * AI 组件草稿模型 - 用于 component-stream 生成完成后暂存装配好的 phtml，供「按 id 取预览」使用
 */

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class AiComponentDraft extends Model
{
    public const table = 'guolairen_page_builder_ai_component_draft';

    public const fields_ID = 'draft_id';
    public const fields_TEMPLATE_CONTENT = 'template_content';
    public const fields_COMPONENT_META = 'component_meta';
    public const fields_SESSION_ID = 'session_id';
    public const fields_CREATED_AT = 'created_at';

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('PageBuilder AI组件草稿表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '草稿ID'
            )
            ->addColumn(
                self::fields_TEMPLATE_CONTENT,
                TableInterface::column_type_LONG_TEXT,
                0,
                '',
                '装配后的完整phtml'
            )
            ->addColumn(
                self::fields_COMPONENT_META,
                TableInterface::column_type_TEXT,
                0,
                '',
                '组件元信息JSON(name,code,region,style_code等)'
            )
            ->addColumn(
                self::fields_SESSION_ID,
                TableInterface::column_type_VARCHAR,
                128,
                '',
                '会话ID(可选,用于清理过期草稿)'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                TableInterface::column_type_INTEGER,
                0,
                '',
                '创建时间戳'
            )
            ->addIndex(TableInterface::index_type_KEY, 'idx_session', [self::fields_SESSION_ID], '会话索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_created', [self::fields_CREATED_AT], '创建时间索引')
            ->create();
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 暂无升级逻辑
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
}
