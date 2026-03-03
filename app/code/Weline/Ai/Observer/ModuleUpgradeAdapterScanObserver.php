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

namespace Weline\Ai\Observer;

use Weline\Ai\Service\AdapterScanner;
use Weline\Ai\Service\AgentScanner;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
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
class ModuleUpgradeAdapterScanObserver implements ObserverInterface
{
    /**
     * @var AdapterScanner
     */
    private AdapterScanner $adapterScanner;
    
    /**
     * @var AgentScanner
     */
    private AgentScanner $agentScanner;
    
    /**
     * @var Printing
     */
    private Printing $printing;
    
    /**
     * 构造函数
     * 
     * @param AdapterScanner $adapterScanner
     * @param AgentScanner $agentScanner
     * @param Printing $printing
     */
    public function __construct(
        AdapterScanner $adapterScanner,
        AgentScanner $agentScanner,
        Printing $printing
    ) {
        $this->adapterScanner = $adapterScanner;
        $this->agentScanner = $agentScanner;
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
        try {
            // 从事件中获取数据
            $eventData = $event->getData('data');
            if (is_array($eventData)) {
                $moduleName = $eventData['module_name'] ?? '';
            } elseif (is_object($eventData) && method_exists($eventData, 'getData')) {
                $moduleName = $eventData->getData('module_name') ?? '';
            } else {
                $moduleName = '';
            }
            
            // 静默扫描，不输出信息，避免干扰升级流程
            // 扫描所有适配器
            $scannedAdapters = $this->adapterScanner->scanAllAdapters();
            if (!empty($scannedAdapters)) {
                $this->printing->note(__('扫描到 %{count} 个场景适配器', ['count' => count($scannedAdapters)]));
            }
            
            // 扫描所有智能体
            $scannedAgents = $this->agentScanner->scanAllAgents();
            if (!empty($scannedAgents)) {
                $this->printing->note(__('扫描到 %{count} 个智能体', ['count' => count($scannedAgents)]));
            }
            
        } catch (\Throwable $e) {
            // 捕获所有异常，静默处理，避免影响模块升级流程
            w_log_error("场景适配器扫描失败: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            // 不输出错误信息，避免干扰升级流程
        }
    }
}

