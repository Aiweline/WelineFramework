<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Cron;

use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\RuleManager;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Cron\CronTaskInterface;

/**
 * CDN规则推送定时任务
 * 
 * 定时扫描需要推送的规则，触发推送事件
 * 只处理trigger=cron的规则
 * 
 * @package Weline_Cdn
 */
class PushRules implements CronTaskInterface
{
    private RuleManager $ruleManager;
    private EventsManager $eventsManager;
    private Domain $domainModel;

    public function __construct(
        RuleManager $ruleManager,
        EventsManager $eventsManager,
        Domain $domainModel
    ) {
        $this->ruleManager = $ruleManager;
        $this->eventsManager = $eventsManager;
        $this->domainModel = $domainModel;
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'CDN规则推送任务';
    }

    /**
     * @inheritDoc
     */
    public function execute_name(): string
    {
        return 'cdn_push_rules';
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '定时推送CDN缓存规则到各CDN服务商，每15分钟执行一次。只处理trigger=cron的规则。';
    }

    /**
     * @inheritDoc
     */
    public function cron_time(): string
    {
        return '*/15 * * * *'; // 每15分钟执行一次
    }

    /**
     * @inheritDoc
     */
    public function execute(): string
    {
        // 1. 获取所有启用的域名
        $domains = $this->domainModel->clear()
            ->where(Domain::fields_ENABLED, 1)
            ->select()
            ->fetch();

        $pushedCount = 0;
        $failedCount = 0;

        foreach ($domains as $domain) {
            try {
                // 2. 获取合并后的规则（只包含定时触发的规则）
                // trigger=cron 或未指定的规则
                $rules = $this->ruleManager->getMergedRules($domain, 'cron');
                
                if (empty($rules)) {
                    continue;
                }

                // 3. 触发推送事件（所有适配器都会收到）
                $event = new Event([
                    'domain' => $domain,
                    'rules' => $rules, // 通用规则，所有适配器都可以使用
                    'adapter_code' => $domain->getData(Domain::fields_ADAPTER), // 用于适配器过滤
                    'trigger_type' => 'cron' // 标记为定时触发
                ]);
                
                $this->eventsManager->dispatch('Weline_Cdn::push_rules', $event);
                
                $pushedCount++;
                
            } catch (\Exception $e) {
                $failedCount++;
                // 记录错误日志
                error_log("CDN规则推送失败 [域名: {$domain->getData(Domain::fields_DOMAIN_NAME)}]: " . $e->getMessage());
            }
        }

        return sprintf(
            "CDN规则推送完成: 成功 %d 个域名, 失败 %d 个域名",
            $pushedCount,
            $failedCount
        );
    }

    /**
     * @inheritDoc
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return $minute; // 默认30分钟超时解锁
    }
}
