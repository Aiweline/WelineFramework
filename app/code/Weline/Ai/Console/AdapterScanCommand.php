<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Console;

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
class AdapterScanCommand implements CommandInterface
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

    /**
     * 执行命令
     * 
     * @param array $args
     * @param array $data
     * @return void
     */
    public function execute(array $args = [], array $data = [])
    {
        echo "开始扫描场景适配器...\n";

        try {
            // 扫描适配器
            $scannedAdapters = $this->adapterScanner->scanAllAdapters();
            
            if (empty($scannedAdapters)) {
                echo "未找到任何适配器\n";
            } else {
                echo sprintf("成功扫描 %d 个适配器：\n", count($scannedAdapters));
                
                foreach ($scannedAdapters as $adapter) {
                    echo sprintf(
                        "  - %s (%s) v%s - %s\n",
                        $adapter->getName(),
                        $adapter->getCode(),
                        $adapter->getVersion(),
                        $adapter->getDescription()
                    );
                }
            }

            // 清理无效适配器
            echo "\n";
            echo "清理无效适配器...\n";
            $cleanedCount = $this->adapterScanner->cleanupInvalidAdapters();
            
            if ($cleanedCount > 0) {
                echo sprintf("清理了 %d 个无效适配器\n", $cleanedCount);
            } else {
                echo "没有发现无效适配器\n";
            }

            // 显示统计信息
            $stats = $this->adapterScanner->getAdapterStats();
            echo "\n";
            echo "适配器统计信息：\n";
            echo sprintf("  总数：%d\n", $stats['total']);
            echo sprintf("  激活：%d\n", $stats['active']);
            echo sprintf("  未激活：%d\n", $stats['inactive']);

            echo "适配器扫描完成！\n";

        } catch (\Exception $e) {
            echo "扫描适配器失败：" . $e->getMessage() . "\n";
        }
    }
}
