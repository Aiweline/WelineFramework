<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/09
 */

namespace Weline\Ai\Console\Ai\Adapter;

use Weline\Ai\Service\AdapterScanner;
use Weline\Framework\Console\CommandInterface;

/**
 * 场景适配器扫描CLI命令
 * 
 * 功能：
 * - 扫描并注册场景适配器
 * - 更新适配器信息到数据库
 * - 清理无效适配器
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
        return 'ai:adapter:scan 扫描并注册场景适配器';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'ai:adapter:scan',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                '--clean' => '清理无效的适配器',
                '-v, --verbose' => '显示详细信息',
            ],
            [
                'php bin/m ai:adapter:scan' => '扫描并注册所有场景适配器',
                'php bin/m ai:adapter:scan --clean' => '扫描并清理无效适配器',
                'php bin/m ai:adapter:scan -v' => '显示详细的扫描过程',
            ],
            [
                '场景适配器用于优化特定场景的AI生成效果。',
                '适配器目录：app/code/Weline/Ai/Adapter/',
                '每个适配器必须实现 ScenarioAdapterInterface 接口。'
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
        $clean = isset($args['clean']);
        $verbose = isset($args['v']) || isset($args['verbose']);

        echo "开始扫描场景适配器...\n";

        try {
            // 扫描适配器
            $scannedAdapters = $this->adapterScanner->scanAllAdapters();
            
            if (empty($scannedAdapters)) {
                echo "未找到任何适配器\n";
            } else {
                echo sprintf("✓ 成功扫描 %d 个适配器：\n\n", count($scannedAdapters));
                
                foreach ($scannedAdapters as $adapter) {
                    if ($verbose) {
                        echo sprintf(
                            "  • %s (%s) v%s\n",
                            $adapter->getName(),
                            $adapter->getCode(),
                            $adapter->getVersion()
                        );
                        echo sprintf(
                            "    描述: %s\n",
                            $adapter->getDescription()
                        );
                        echo sprintf(
                            "    支持的模型类型: %s\n",
                            implode(', ', $adapter->getSupportedModelTypes())
                        );
                        echo "\n";
                    } else {
                        echo sprintf(
                            "  • %s (%s) - %s\n",
                            $adapter->getName(),
                            $adapter->getCode(),
                            $adapter->getDescription()
                        );
                    }
                }
            }

            // 清理无效适配器
            if ($clean) {
                echo "\n" . str_repeat("-", 60) . "\n\n";
                echo "清理无效适配器...\n";
                $cleanedCount = $this->adapterScanner->cleanupInvalidAdapters();
                
                if ($cleanedCount > 0) {
                    echo sprintf("✓ 清理了 %d 个无效适配器\n", $cleanedCount);
                } else {
                    echo "✓ 没有发现无效适配器\n";
                }
            }

            // 显示统计信息
            $stats = $this->adapterScanner->getAdapterStats();
            echo "\n" . str_repeat("=", 60) . "\n\n";
            echo "适配器统计信息：\n";
            echo sprintf("  总数：%d\n", $stats['total']);
            echo sprintf("  激活：%d\n", $stats['active']);
            echo sprintf("  未激活：%d\n", $stats['inactive']);

            echo "\n适配器扫描完成！\n";

        } catch (\Exception $e) {
            echo "✗ 扫描适配器失败：" . $e->getMessage() . "\n";
            if ($verbose) {
                echo "错误堆栈：\n" . $e->getTraceAsString() . "\n";
            }
        }
    }
}

