<?php
/**
 * 数据库搬迁命令行工具
 * 
 * 使用方法：
 * php bin/w FlashForge:ShopifyOrderManager:DbMigration:install   # 删除字段
 * php bin/w FlashForge:ShopifyOrderManager:DbMigration:uninstall # 恢复字段
 * 
 * @author AI Assistant
 * @date 2024-12-19
 */

namespace FlashForge\ShopifyOrderManager\Console;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;

class DbMigration implements CommandInterface
{
    private ConnectionFactory $connectionFactory;

    public function __construct(ConnectionFactory $connectionFactory)
    {
        $this->connectionFactory = $connectionFactory;
    }

    /**
     * 执行删除raw_data字段
     */
    public function install(array $args = [], array $data = []): void
    {
        $date = $args['date'] ?? '20241219';
        $migrationClass = "FlashForge\\ShopifyOrderManager\\Console\\DbMigration\\RemoveRawDataField_{$date}";
        
        if (!class_exists($migrationClass)) {
            echo "❌ 搬迁脚本不存在: {$date}\n";
            return;
        }
        
        echo "开始删除raw_data字段 (日期: {$date})...\n";
        
        try {
            $migration = new $migrationClass($this->connectionFactory);
            $migration->install();
            echo "✅ raw_data字段删除完成！\n";
        } catch (\Exception $e) {
            echo "❌ 删除失败: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * 执行恢复raw_data字段
     */
    public function uninstall(array $args = [], array $data = []): void
    {
        $date = $args['date'] ?? '20241219';
        $migrationClass = "FlashForge\\ShopifyOrderManager\\Console\\DbMigration\\RemoveRawDataField_{$date}";
        
        if (!class_exists($migrationClass)) {
            echo "❌ 搬迁脚本不存在: {$date}\n";
            return;
        }
        
        echo "开始恢复raw_data字段 (日期: {$date})...\n";
        
        try {
            $migration = new $migrationClass($this->connectionFactory);
            $migration->uninstall();
            echo "✅ raw_data字段恢复完成！\n";
        } catch (\Exception $e) {
            echo "❌ 恢复失败: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * 显示帮助信息
     */
    public function help(): void
    {
        echo "ShopifyOrderManager raw_data字段管理工具\n";
        echo "\n";
        echo "使用方法:\n";
        echo "  php bin/w db-migration:install --date=20241219   # 删除raw_data字段\n";
        echo "  php bin/w db-migration:uninstall --date=20241219 # 恢复raw_data字段\n";
        echo "  php bin/w db-migration                           # 显示帮助信息\n";
        echo "\n";
        echo "功能说明:\n";
        echo "  install   - 删除订单表和订单项表中的raw_data字段\n";
        echo "  uninstall - 恢复订单表和订单项表中的raw_data字段\n";
        echo "  help      - 显示此帮助信息\n";
        echo "\n";
        echo "参数说明:\n";
        echo "  --date=YYYYMMDD - 指定搬迁脚本日期 (默认: 20241219)\n";
        echo "\n";
        echo "注意事项:\n";
        echo "  - 删除操作不可逆，请确保已备份数据\n";
        echo "  - 恢复操作会重新创建字段，但不会恢复已删除的数据\n";
        echo "  - 建议在维护窗口期间执行此操作\n";
        echo "  - 搬迁脚本位于: app/code/FlashForge/ShopifyOrderManager/Console/DbMigration/\n";
    }

    /**
     * 执行命令
     */
    public function execute(array $args = [], array $data = []): void
    {
        // 检查是否有子命令
        $subCommand = $args['command'] ?? '';
        
        if (empty($subCommand)) {
            $this->help();
            return;
        }
        
        switch ($subCommand) {
            case 'install':
                $this->install($args, $data);
                break;
            case 'uninstall':
                $this->uninstall($args, $data);
                break;
            case 'help':
                $this->help();
                break;
            default:
                echo "❌ 未知命令: {$subCommand}\n";
                echo "可用命令: install, uninstall, help\n";
                break;
        }
    }

    /**
     * 显示提示信息
     */
    public function tip(): string
    {
        return "使用 'help' 查看详细帮助信息";
    }

    /**
     * 获取命令描述
     */
    public function getDescription(): string
    {
        return '管理ShopifyOrderManager模块中的raw_data字段';
    }

    /**
     * 获取命令名称
     */
    public function getName(): string
    {
        return 'FlashForge:ShopifyOrderManager:DbMigration';
    }
}