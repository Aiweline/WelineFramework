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

namespace Weline\Ai\Observer;

use Weline\Ai\Service\AdapterScanner;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\Observer\ObserverAbstract;
use Weline\Framework\Output\Cli\Printing;

/**
 * 模块升级后自动扫描场景适配器 Observer
 * 
 * 功能：
 * - 监听模块升级事件
 * - 自动扫描场景适配器
 * - 注册新发现的适配器
 * - 更新适配器信息
 */
class ModuleUpgradeAdapterScanObserver extends ObserverAbstract implements ObserverInterface
{
    /**
     * @var AdapterScanner
     */
    private AdapterScanner $adapterScanner;
    
    /**
     * @var Printing
     */
    private Printing $printing;
    
    /**
     * 构造函数
     * 
     * @param AdapterScanner $adapterScanner
     * @param Printing $printing
     */
    public function __construct(
        AdapterScanner $adapterScanner,
        Printing $printing
    ) {
        $this->adapterScanner = $adapterScanner;
        $this->printing = $printing;
    }
    
    /**
     * 执行 Observer 逻辑
     * 
     * @param ObserverAbstract $observer
     * @param array $data
     * @return void
     */
    public function execute(ObserverAbstract $observer, array $data = []): void
    {
        try {
            $moduleName = $data['module_name'] ?? '';
            
            $this->printing->info(__('开始扫描场景适配器...'));
            
            // 扫描所有适配器
            $scannedAdapters = $this->adapterScanner->scanAllAdapters();
            
            if (empty($scannedAdapters)) {
                $this->printing->info(__('未找到任何适配器'));
            } else {
                $this->printing->success(__('成功扫描并注册 %{count} 个适配器', ['count' => count($scannedAdapters)]));
                
                // 显示扫描到的适配器
                foreach ($scannedAdapters as $adapter) {
                    $this->printing->println(sprintf(
                        "  • %s (%s) v%s - %s",
                        $adapter->getName(),
                        $adapter->getCode(),
                        $adapter->getVersion(),
                        $adapter->getDescription()
                    ));
                }
            }
            
            // 显示统计信息
            $stats = $this->adapterScanner->getAdapterStats();
            $this->printing->info(__("\n适配器统计:"));
            $this->printing->info(__('总数：%{total}', ['total' => $stats['total']]));
            $this->printing->info(__('激活：%{active}', ['active' => $stats['active']]));
            $this->printing->info(__('未激活：%{inactive}', ['inactive' => $stats['inactive']]));
            
            $this->printing->success(__("\n场景适配器扫描完成！"));
            
        } catch (\Exception $e) {
            $this->printing->error(__('场景适配器扫描失败: %{error}', ['error' => $e->getMessage()]));
            error_log("场景适配器扫描失败: " . $e->getMessage());
            // 不抛出异常，避免影响模块升级流程
        }
    }
}

