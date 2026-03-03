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

namespace Weline\Framework\Database\Observer;

use Weline\Framework\Database\Service\AdapterScanner;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Output\Cli\Printing;

/**
 * 系统升级后自动扫描数据库适配器 Observer
 * 
 * 功能：
 * - 监听系统升级完成事件（Weline_Framework_Setup::upgrade_after）
 * - 自动扫描数据库适配器
 * - 注册新发现的适配器
 * - 更新适配器信息到 driver.php
 * 
 * 注意：此观察者只在系统升级完成后执行一次，不会在每个模块升级时重复执行
 */
class ModuleUpgradeAdapterScanObserver implements ObserverInterface
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
     * 静态变量：记录是否已经扫描过适配器
     * 防止事件被多次触发时重复扫描
     */
    private static bool $hasScanned = false;
    
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
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        // 检查是否是部分更新模式
        $eventData = $event->getData();
        $isPartialUpgrade = $eventData['is_partial_upgrade'] ?? false;
        
        // 如果是部分更新模式，跳过数据库适配器扫描（适配器扫描应该在完整升级时执行）
        if ($isPartialUpgrade) {
            return;
        }
        
        try {
            // 如果已经扫描过，直接返回，避免重复扫描和输出
            if (self::$hasScanned) {
                return;
            }
            
            // 扫描所有适配器
            $scannedAdapters = $this->adapterScanner->scanAllAdapters();
            
            // 标记已扫描
            self::$hasScanned = true;
            
            // 只在有结果时输出
            if (!empty($scannedAdapters)) {
                $this->printing->note(__('扫描到 %{count} 个数据库适配器', ['count' => count($scannedAdapters)]));
            }
            
        } catch (\Throwable $e) {
            // 捕获所有异常，静默处理，避免影响系统升级流程
            w_log_error("数据库适配器扫描失败: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            // 不输出错误信息，避免干扰升级流程
        }
    }
}

