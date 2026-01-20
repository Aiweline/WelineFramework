<?php

declare(strict_types=1);

namespace Weline\Ai\Setup\Db\Migration;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\Schema;

/**
 * 为ai_assistant表添加租赁相关字段
 * 支持助手租赁系统
 */
class add_assistant_rental_fields_20250114_v2_0_0
{
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        $table = $setup->getConnection()->getTableName('ai_assistant');
        
        // 所有权和租赁设置
        if (!$setup->getConnection()->tableColumnExist($table, 'owner_id')) {
            $setup->getConnection()->addColumn(
                $table,
                'owner_id',
                TableInterface::column_type_INTEGER,
                0,
                '所有者用户ID'
            );
            echo "✅ 已添加 ai_assistant.owner_id 字段\n";
        }
        
        if (!$setup->getConnection()->tableColumnExist($table, 'is_rentable')) {
            $setup->getConnection()->addColumn(
                $table,
                'is_rentable',
                TableInterface::column_type_TINYINT . '(1)',
                0,
                '是否可租用'
            );
            echo "✅ 已添加 ai_assistant.is_rentable 字段\n";
        }
        
        if (!$setup->getConnection()->tableColumnExist($table, 'rental_type')) {
            $setup->getConnection()->addColumn(
                $table,
                'rental_type',
                TableInterface::column_type_VARCHAR . '(20)',
                'per_use',
                '租赁类型'
            );
            echo "✅ 已添加 ai_assistant.rental_type 字段\n";
        }
        
        if (!$setup->getConnection()->tableColumnExist($table, 'rental_price')) {
            $setup->getConnection()->addColumn(
                $table,
                'rental_price',
                TableInterface::column_type_DECIMAL . '(10,4)',
                0.0000,
                '租赁价格'
            );
            echo "✅ 已添加 ai_assistant.rental_price 字段\n";
        }
        
        if (!$setup->getConnection()->tableColumnExist($table, 'rental_currency')) {
            $setup->getConnection()->addColumn(
                $table,
                'rental_currency',
                TableInterface::column_type_VARCHAR . '(10)',
                'USD',
                '货币类型'
            );
            echo "✅ 已添加 ai_assistant.rental_currency 字段\n";
        }
        
        // 统计数据
        if (!$setup->getConnection()->tableColumnExist($table, 'rating_average')) {
            $setup->getConnection()->addColumn(
                $table,
                'rating_average',
                TableInterface::column_type_DECIMAL . '(3,2)',
                0.00,
                '平均评分'
            );
            echo "✅ 已添加 ai_assistant.rating_average 字段\n";
        }
        
        if (!$setup->getConnection()->tableColumnExist($table, 'rating_count')) {
            $setup->getConnection()->tableColumnExist($table, 'rating_count');
            $setup->getConnection()->addColumn(
                $table,
                'rating_count',
                TableInterface::column_type_INTEGER,
                0,
                '评分数量'
            );
            echo "✅ 已添加 ai_assistant.rating_count 字段\n";
        }
        
        if (!$setup->getConnection()->tableColumnExist($table, 'rental_count')) {
            $setup->getConnection()->addColumn(
                $table,
                'rental_count',
                TableInterface::column_type_INTEGER,
                0,
                '累计租赁次数'
            );
            echo "✅ 已添加 ai_assistant.rental_count 字段\n";
        }
        
        if (!$setup->getConnection()->tableColumnExist($table, 'usage_count')) {
            $setup->getConnection()->addColumn(
                $table,
                'usage_count',
                TableInterface::column_type_INTEGER,
                0,
                '累计使用次数'
            );
            echo "✅ 已添加 ai_assistant.usage_count 字段\n";
        }
        
        if (!$setup->getConnection()->tableColumnExist($table, 'revenue_total')) {
            $setup->getConnection()->addColumn(
                $table,
                'revenue_total',
                TableInterface::column_type_DECIMAL . '(12,4)',
                0.0000,
                '累计收入'
            );
            echo "✅ 已添加 ai_assistant.revenue_total 字段\n";
        }
        
        // 展示信息
        if (!$setup->getConnection()->tableColumnExist($table, 'cover_image')) {
            $setup->getConnection()->addColumn(
                $table,
                'cover_image',
                TableInterface::column_type_VARCHAR . '(255)',
                '',
                '封面图片'
            );
            echo "✅ 已添加 ai_assistant.cover_image 字段\n";
        }
        
        if (!$setup->getConnection()->tableColumnExist($table, 'tags')) {
            $setup->getConnection()->addColumn(
                $table,
                'tags',
                TableInterface::column_type_JSON,
                '',
                '标签'
            );
            echo "✅ 已添加 ai_assistant.tags 字段\n";
        }
        
        if (!$setup->getConnection()->tableColumnExist($table, 'category')) {
            $setup->getConnection()->addColumn(
                $table,
                'category',
                TableInterface::column_type_VARCHAR . '(50)',
                '',
                '分类'
            );
            echo "✅ 已添加 ai_assistant.category 字段\n";
        }
        
        // 审核状态
        if (!$setup->getConnection()->tableColumnExist($table, 'audit_status')) {
            $setup->getConnection()->addColumn(
                $table,
                'audit_status',
                TableInterface::column_type_VARCHAR . '(20)',
                'pending',
                '审核状态'
            );
            echo "✅ 已添加 ai_assistant.audit_status 字段\n";
        }
        
        if (!$setup->getConnection()->tableColumnExist($table, 'audit_note')) {
            $setup->getConnection()->addColumn(
                $table,
                'audit_note',
                TableInterface::column_type_TEXT,
                '',
                '审核备注'
            );
            echo "✅ 已添加 ai_assistant.audit_note 字段\n";
        }
        
        // 添加索引
        if (!$setup->getConnection()->indexExist($table, 'idx_owner')) {
            $setup->getConnection()->addIndex(
                $table,
                'idx_owner',
                'owner_id',
                TableInterface::index_type_DEFAULT
            );
            echo "✅ 已添加 ai_assistant.owner_id 索引\n";
        }
        
        if (!$setup->getConnection()->indexExist($table, 'idx_rentable')) {
            $setup->getConnection()->addIndex(
                $table,
                'idx_rentable',
                'is_rentable',
                TableInterface::index_type_DEFAULT
            );
            echo "✅ 已添加 ai_assistant.is_rentable 索引\n";
        }
        
        if (!$setup->getConnection()->indexExist($table, 'idx_rating')) {
            $setup->getConnection()->addIndex(
                $table,
                'idx_rating',
                'rating_average',
                TableInterface::index_type_DEFAULT
            );
            echo "✅ 已添加 ai_assistant.rating_average 索引\n";
        }
        
        if (!$setup->getConnection()->indexExist($table, 'idx_category')) {
            $setup->getConnection()->addIndex(
                $table,
                'idx_category',
                'category',
                TableInterface::index_type_DEFAULT
            );
            echo "✅ 已添加 ai_assistant.category 索引\n";
        }
        
        echo "✅ 助手租赁字段迁移完成\n";
    }
}

