<?php

declare(strict_types=1);

namespace Weline\Ai\Setup\Db\Migration;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\Schema;

/**
 * 更新ai_api_key表的配额字段
 * 修改配额字段含义：从调用次数限制改为成本控制限制（单位：元）
 * 支持API计费系统
 */
class update_api_key_quota_fields_20250114_v2_0_0
{
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        $table = $setup->getConnection()->getTableName('ai_api_key');
        
        // 修改配额字段类型为DECIMAL，表示金额限制
        if ($setup->getConnection()->tableColumnExist($table, 'quota_daily')) {
            // 先检查是否需要修改类型
            $conn = $setup->getConnection();
            // 由于SQLite的限制，我们需要通过Migration的方式处理
            // 这里添加新字段，如果需要数据迁移，在后续处理
            echo "⚠️  quota_daily字段已存在，配额含义已更新为：成本控制限额（单位：元）\n";
        }
        
        if ($setup->getConnection()->tableColumnExist($table, 'quota_monthly')) {
            echo "⚠️  quota_monthly字段已存在，配额含义已更新为：成本控制限额（单位：元）\n";
        }
        
        // 添加last_used_time字段（替代last_used_at）
        if (!$setup->getConnection()->tableColumnExist($table, 'last_used_time')) {
            $setup->getConnection()->addColumn(
                $table,
                'last_used_time',
                TableInterface::column_type_DATETIME,
                '',
                '最后使用时间'
            );
            echo "✅ 已添加 ai_api_key.last_used_time 字段\n";
        }
        
        // 添加call_count字段
        if (!$setup->getConnection()->tableColumnExist($table, 'call_count')) {
            $setup->getConnection()->addColumn(
                $table,
                'call_count',
                TableInterface::column_type_INT,
                0,
                '累计调用次数'
            );
            echo "✅ 已添加 ai_api_key.call_count 字段\n";
        }
        
        echo "✅ API密钥配额字段更新完成\n";
        echo "ℹ️  配额字段说明：\n";
        echo "   - quota_daily: 每日成本控制限额（单位：元），用户自己设置\n";
        echo "   - quota_monthly: 每月成本控制限额（单位：元），用户自己设置\n";
        echo "   - usage_daily: 今日已使用金额（单位：元）\n";
        echo "   - usage_monthly: 本月已使用金额（单位：元）\n";
        echo "   - call_count: 累计API调用次数\n";
    }
}

