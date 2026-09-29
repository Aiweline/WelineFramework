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
use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Cron\CronTaskInterface;

/**
 * CDN规则推送定时任务
 * 
 * 定时扫描需要推送的规则，触发推送事件
 * 完整 Zone 期望规则，补齐未完成同步
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
        return '定时推送CDN缓存规则到各CDN服务商，每15分钟执行一次。完整 Zone 期望规则，补齐未完成同步。';
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
        $policies = ObjectManager::getInstance(\Weline\Cdn\Service\FpcPolicyManagementService::class);
        $collected = $policies->collectDeclarations();
        if (!($collected['success'] ?? false)) {
            throw new \RuntimeException((string)$collected['message']);
        }
        $sync = ObjectManager::getInstance(\Weline\Cdn\Service\FpcPolicySyncService::class);
        $result = $sync->requestSync(['trigger'=>'cron','desired_version'=>$collected['data']['desired_version'],'domain_ids'=>[],'purge_targets'=>[]]);
        $reconciled = $sync->reconcile();
        return json_encode(['submitted'=>$result,'reconciled'=>$reconciled], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @inheritDoc
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return $minute; // 默认30分钟超时解锁
    }
}
