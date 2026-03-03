<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Observer;

use Weline\Cdn\Service\CdnRuleCollector;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * CDN规则收集观察者
 * 
 * 监听系统升级后事件，自动收集CDN规则
 * 
 * @package Weline_Cdn
 */
class CollectRules implements ObserverInterface
{
    private CdnRuleCollector $ruleCollector;

    public function __construct(CdnRuleCollector $ruleCollector)
    {
        $this->ruleCollector = $ruleCollector;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 检查是否是部分更新模式
        $eventData = $event->getData();
        $isPartialUpgrade = $eventData['is_partial_upgrade'] ?? false;
        $modelOnly = $eventData['model_only'] ?? false;
        
        // 如果是仅更新模型模式，跳过 CDN 规则收集（CDN 规则与路由相关，路由更新时可能需要收集）
        if ($modelOnly) {
            if (defined('CLI') && CLI) {
                echo "检测到仅更新模型模式，跳过 CDN 规则收集\n";
            }
            return;
        }
        
        try {
            // 收集所有规则
            $collected = $this->ruleCollector->collectAll();
            
            // 记录日志
            if (defined('CLI') && CLI) {
                echo sprintf(
                    "CDN规则收集完成: 共收集 %d 条规则\n",
                    count($collected)
                );
            }
        } catch (\Exception $e) {
            // 记录错误但不中断升级流程
            w_log_error("CDN规则收集失败: " . $e->getMessage());
            if (defined('CLI') && CLI) {
                echo "CDN规则收集失败: " . $e->getMessage() . "\n";
            }
        }
    }
}
