<?php

declare(strict_types=1);

namespace Weline\Ai\Setup\Db\Migration;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\Schema;

/**
 * 为frontend_user表添加余额相关字段
 * 支持API计费系统
 */
class add_user_balance_fields_20250114_v2_0_0
{
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        $table = $setup->getConnection()->getTableName('frontend_user');
        
        if (!$setup->getConnection()->tableColumnExist($table, 'balance')) {
            $setup->getConnection()->addColumn(
                $table,
                'balance',
                TableInterface::column_type_DECIMAL . '(12,4)',
                0.0000,
                '账户余额'
            );
            echo "✅ 已添加 frontend_user.balance 字段\n";
        }
        
        if (!$setup->getConnection()->tableColumnExist($table, 'total_recharge')) {
            $setup->getConnection()->addColumn(
                $table,
                'total_recharge',
                TableInterface::column_type_DECIMAL . '(12,4)',
                0.0000,
                '累计充值金额'
            );
            echo "✅ 已添加 frontend_user.total_recharge 字段\n";
        }
        
        if (!$setup->getConnection()->tableColumnExist($table, 'total_consumption')) {
            $setup->getConnection()->addColumn(
                $table,
                'total_consumption',
                TableInterface::column_type_DECIMAL . '(12,4)',
                0.0000,
                '累计消费金额'
            );
            echo "✅ 已添加 frontend_user.total_consumption 字段\n";
        }
        
        if (!$setup->getConnection()->tableColumnExist($table, 'currency')) {
            $setup->getConnection()->addColumn(
                $table,
                'currency',
                TableInterface::column_type_VARCHAR . '(10)',
                'CNY',
                '货币类型'
            );
            echo "✅ 已添加 frontend_user.currency 字段\n";
        }
        
        // 添加索引
        if (!$setup->getConnection()->indexExist($table, 'idx_balance')) {
            $setup->getConnection()->addIndex(
                $table,
                'idx_balance',
                'balance',
                TableInterface::index_type_DEFAULT
            );
            echo "✅ 已添加 frontend_user.balance 索引\n";
        }
        
        echo "✅ 用户余额字段迁移完成\n";
    }
}

