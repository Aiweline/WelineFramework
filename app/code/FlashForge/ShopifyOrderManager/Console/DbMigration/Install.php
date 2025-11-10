<?php
/**
 * 删除raw_data字段命令
 * 
 * @author AI Assistant
 * @date 2024-12-19
 */

namespace FlashForge\ShopifyOrderManager\Console\DbMigration;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

class Install implements CommandInterface
{

    private ConnectionFactory $connectionFactory;

    public function __construct(ConnectionFactory $connectionFactory)
    {
        $this->connectionFactory = $connectionFactory;
    }

    /**
     * 执行删除raw_data字段
     */
    public function execute(array $args = [], array $data = []): void
    {
        $date = $args['date'] ?? '20250828';
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
     * 显示提示信息
     */
    public function tip(): string
    {
        return "删除ShopifyOrderManager模块中的raw_data字段";
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            []
        );
    }
}
