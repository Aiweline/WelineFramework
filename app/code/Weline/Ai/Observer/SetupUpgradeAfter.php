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
use Weline\Ai\Service\AgentScanner;
use Weline\Ai\Service\ModelCollector;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Output\Cli\Printing;

/**
 * 系统升级后自动扫描AI模块资源 Observer
 * 
 * 功能：
 * - 监听系统升级完成事件（Weline_Framework_Setup::upgrade_after）
 * - 自动扫描场景适配器
 * - 自动扫描AI模型
 * - 注册新发现的适配器和模型
 * - 更新适配器和模型信息
 * 
 * 注意：此观察者只在系统升级完成后执行一次，确保所有模块升级完成后再扫描
 */
class SetupUpgradeAfter implements ObserverInterface
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
     * @var ModelCollector
     */
    private ModelCollector $modelCollector;
    
    /**
     * @var Printing
     */
    private Printing $printing;
    
    /**
     * 构造函数
     * 
     * @param AdapterScanner $adapterScanner
     * @param AgentScanner $agentScanner
     * @param ModelCollector $modelCollector
     * @param Printing $printing
     */
    public function __construct(
        AdapterScanner $adapterScanner,
        AgentScanner $agentScanner,
        ModelCollector $modelCollector,
        Printing $printing
    ) {
        $this->adapterScanner = $adapterScanner;
        $this->agentScanner = $agentScanner;
        $this->modelCollector = $modelCollector;
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
        
        // 如果是部分更新模式，跳过 AI 模块资源扫描（资源扫描应该在完整升级时执行）
        if ($isPartialUpgrade) {
            return;
        }
        
        try {
            $this->printing->note(__('开始扫描AI模块资源...'));
            
            // 1. 扫描场景适配器
            $this->printing->note(__('正在扫描场景适配器...'));
            $scannedAdapters = $this->adapterScanner->scanAllAdapters();
            if (!empty($scannedAdapters)) {
                $this->printing->success(__('扫描到 %{count} 个场景适配器', ['count' => count($scannedAdapters)]));
            } else {
                $this->printing->note(__('未发现新的场景适配器'));
            }
            
            // 2. 扫描智能体
            $this->printing->note(__('正在扫描智能体...'));
            $scannedAgents = $this->agentScanner->scanAllAgents();
            if (!empty($scannedAgents)) {
                $this->printing->success(__('扫描到 %{count} 个智能体', ['count' => count($scannedAgents)]));
            } else {
                $this->printing->note(__('未发现新的智能体'));
            }
            
            // 3. 扫描AI模型
            $this->printing->note(__('正在扫描AI模型...'));
            $collectedModels = $this->modelCollector->collectAllModels();
            if (!empty($collectedModels)) {
                $this->printing->success(__('扫描到 %{count} 个AI模型', ['count' => count($collectedModels)]));
            } else {
                $this->printing->note(__('未发现新的AI模型'));
            }
            
            $this->printing->success(__('AI模块资源扫描完成'));
            
        } catch (\Throwable $e) {
            // 捕获所有异常，记录错误但不中断系统升级流程
            error_log("AI模块资源扫描失败: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->printing->error(__('AI模块资源扫描失败: %{error}', ['error' => $e->getMessage()]));
        }
    }
}
