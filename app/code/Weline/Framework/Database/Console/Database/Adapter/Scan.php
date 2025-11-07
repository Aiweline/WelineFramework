<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/01/XX
 */

namespace Weline\Framework\Database\Console\Database\Adapter;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Database\Service\AdapterScanner;

/**
 * 数据库适配器扫描CLI命令
 * 
 * 功能：
 * - 扫描并注册数据库适配器
 * - 更新适配器信息到 driver.php 映射文件
 * - 显示适配器统计信息
 */
class Scan implements CommandInterface
{
    /**
     * @var AdapterScanner
     */
    private AdapterScanner $adapterScanner;

    /**
     * 构造函数
     * 
     * @param AdapterScanner $adapterScanner
     */
    public function __construct(AdapterScanner $adapterScanner)
    {
        $this->adapterScanner = $adapterScanner;
    }

    /**
     * 命令注释
     * 
     * @return string
     */
    public function tip(): string
    {
        return 'database:adapter:scan 扫描并注册数据库适配器';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'database:adapter:scan',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                '-v, --verbose' => '显示详细信息',
            ],
            [
                'php bin/m database:adapter:scan' => '扫描并注册所有数据库适配器',
                'php bin/m database:adapter:scan -v' => '显示详细的扫描过程',
            ],
            [
                '数据库适配器用于连接不同类型的数据库。',
                '适配器目录：app/code/Weline/Framework/Database/Connection/Adapter/',
                '扩展适配器目录：extends/Weline_Framework/Connection/Adapter/',
                '每个适配器必须实现 ConnectorInterface 接口。'
            ]
        );
    }

    /**
     * 执行命令
     * 
     * @param array $args
     * @param array $data
     * @return void
     */
    public function execute(array $args = [], array $data = [])
    {
        $verbose = isset($args['v']) || isset($args['verbose']);

        echo "开始扫描数据库适配器...\n";

        try {
            // 扫描适配器
            $adapters = $this->adapterScanner->scanAllAdapters();

            if ($verbose) {
                echo "\n扫描结果：\n";
                echo str_repeat('-', 60) . "\n";
                foreach ($adapters as $driverType => $className) {
                    echo "驱动类型: {$driverType}\n";
                    echo "类名: {$className}\n";
                    echo str_repeat('-', 60) . "\n";
                }
            }

            // 获取统计信息
            $stats = $this->adapterScanner->getAdapterStats();

            echo "\n扫描完成！\n";
            echo "共发现 {$stats['total']} 个适配器\n";
            if (!empty($stats['drivers'])) {
                echo "驱动类型: " . implode(', ', $stats['drivers']) . "\n";
            }
            echo "\n驱动映射文件已更新: generated/database/driver.php\n";

        } catch (\Exception $e) {
            echo "❌ 扫描失败: " . $e->getMessage() . "\n";
            echo "错误详情: " . $e->getTraceAsString() . "\n";
            exit(1);
        }
    }
}

