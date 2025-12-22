<?php
/**
 * 删除raw_data字段的搬迁脚本
 * 创建时间: 2024-12-19
 * 
 * 功能：
 * 1. 删除订单表和订单项表中的raw_data字段
 * 2. 支持回滚恢复raw_data字段
 * 
 * @author AI Assistant
 * @date 2024-12-19
 */

namespace FlashForge\ShopifyOrderManager\Console\DbMigration;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\App\Exception;

class RemoveRawDataField_20250828
{
    private ConnectionFactory $connectionFactory;

    public function __construct(ConnectionFactory $connectionFactory)
    {
        $this->connectionFactory = $connectionFactory;
    }

    /**
     * 执行搬迁 - 删除raw_data字段
     */
    public function install(): void
    {
        try {
            echo "开始删除raw_data字段...\n";
            
            // 删除订单表中的raw_data字段
            $this->removeRawDataFromOrderTable();
            
            // 删除订单项表中的raw_data字段
            $this->removeRawDataFromOrderItemTable();
            
            echo "✅ 成功删除raw_data字段\n";
            
        } catch (\Exception $e) {
            throw new Exception("删除raw_data字段失败: " . $e->getMessage());
        }
    }

    /**
     * 回滚搬迁 - 恢复raw_data字段
     */
    public function uninstall(): void
    {
        try {
            echo "开始恢复raw_data字段...\n";
            
            // 恢复订单表中的raw_data字段
            $this->restoreRawDataToOrderTable();
            
            // 恢复订单项表中的raw_data字段
            $this->restoreRawDataToOrderItemTable();
            
            echo "✅ 成功恢复raw_data字段\n";
            
        } catch (\Exception $e) {
            throw new Exception("恢复raw_data字段失败: " . $e->getMessage());
        }
    }

    /**
     * 删除订单表中的raw_data字段
     */
    private function removeRawDataFromOrderTable(): void
    {
        $tableName = 'flashforge_shopify_order_manager_order';
        
        // 检查字段是否存在
        if ($this->hasFields($tableName, 'raw_data')) {
            $sql = "ALTER TABLE `{$tableName}` DROP COLUMN `raw_data`";
            $this->connectionFactory->query($sql);
            
            echo "✅ 已删除订单表中的raw_data字段\n";
        } else {
            echo "ℹ️ 订单表中不存在raw_data字段\n";
        }
    }

    /**
     * 删除订单项表中的raw_data字段
     */
    private function removeRawDataFromOrderItemTable(): void
    {
        $tableName = 'flashforge_shopify_order_manager_order_item';
        
        // 检查字段是否存在
        if ($this->hasFields($tableName, 'raw_data')) {
            $sql = "ALTER TABLE `{$tableName}` DROP COLUMN `raw_data`";
            $this->connectionFactory->query($sql);
            
            echo "✅ 已删除订单项表中的raw_data字段\n";
        } else {
            echo "ℹ️ 订单项表中不存在raw_data字段\n";
        }
    }

    /**
     * 恢复订单表中的raw_data字段
     */
    private function restoreRawDataToOrderTable(): void
    {
        $tableName = 'flashforge_shopify_order_manager_order';
        
        // 检查字段是否已存在
        if (!$this->hasFields($tableName, 'raw_data')) {
            $sql = "ALTER TABLE `{$tableName}` ADD COLUMN `raw_data` TEXT NULL COMMENT '原始数据JSON'";
            $this->connectionFactory->query($sql);
            
            echo "✅ 已恢复订单表中的raw_data字段\n";
        } else {
            echo "ℹ️ 订单表中已存在raw_data字段\n";
        }
    }

    /**
     * 恢复订单项表中的raw_data字段
     */
    private function restoreRawDataToOrderItemTable(): void
    {
        $tableName = 'flashforge_shopify_order_manager_order_item';
        
        // 检查字段是否已存在
        if (!$this->hasFields($tableName, 'raw_data')) {
            $sql = "ALTER TABLE `{$tableName}` ADD COLUMN `raw_data` TEXT NULL COMMENT '原始数据JSON'";
            $this->connectionFactory->query($sql);
            
            echo "✅ 已恢复订单项表中的raw_data字段\n";
        } else {
            echo "ℹ️ 订单项表中已存在raw_data字段\n";
        }
    }

    /**
     * 检查表中是否存在指定字段
     */
    private function hasFields(string $tableName, string $columnName): bool
    {
        try {
            // 使用SHOW COLUMNS查询字段是否存在
            $result = $this->connectionFactory->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
            return !empty($result);
        } catch (\Exception $e) {
            // 如果SHOW COLUMNS不可用，尝试直接查询表结构
            try {
                $this->connectionFactory->query("SELECT {$columnName} FROM `{$tableName}` LIMIT 1")->fetch();
                return true;
            } catch (\Exception $e2) {
                return false;
            }
        }
    }

    /**
     * 获取搬迁版本
     */
    public function getVersion(): string
    {
        return '20250828';
    }

    /**
     * 获取搬迁描述
     */
    public function getDescription(): string
    {
        return '删除ShopifyOrderManager模块中的raw_data字段 (2025-08-28)';
    }

    /**
     * 获取搬迁作者
     */
    public function getAuthor(): string
    {
        return 'AI Assistant';
    }
}
