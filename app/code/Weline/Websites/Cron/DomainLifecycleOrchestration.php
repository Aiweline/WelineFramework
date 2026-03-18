<?php
declare(strict_types=1);

namespace Weline\Websites\Cron;

use Weline\Cron\Attribute\CronTestHelp;
use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\DomainLifecycleOrchestrationService;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;
use Weline\Websites\Service\WebsitesCronTestContext;

#[CronTestHelp(
    description: 'Websites 域名生命周期订单轮询。domain 过滤仅处理该根域订单。',
    examples: [
        'php bin/w cron:test --task=domain_lifecycle_orchestration --domain=example.com -v',
    ],
    manual_help: [
        '控制台 --domain= 仅处理该根域生命周期订单。',
        '后台「后缀」未解析时与定时全量一致。',
    ],
)]
class DomainLifecycleOrchestration implements CronTaskInterface
{
    use WebsitesCronTestRunnerTrait;

    public function name(): string
    {
        return __('域名生命周期编排');
    }

    public function execute_name(): string
    {
        return 'domain_lifecycle_orchestration';
    }

    public function tip(): string
    {
        return __('轮询域名生命周期订单，推进 DNS、解析、验证与 HTTPS 流程');
    }

    public function cron_time(): string
    {
        return '* * * * *';
    }

    public function execute(): string
    {
        try {
            /** @var DomainLifecycleOrchestrationService $service */
            $service = ObjectManager::getInstance(DomainLifecycleOrchestrationService::class);
            $df = WebsitesCronTestContext::getDomainFilter();
            $result = $service->processPendingOrders(20, $df);
            WebsitesCronTestContext::detail('DomainLifecycleOrchestration', ['domain_filter' => $df, 'result' => $result]);

            return (string) __('域名生命周期轮询完成：处理 %{processed} 条，完成 %{completed} 条，失败 %{failed} 条', [
                'processed' => (int) ($result['processed'] ?? 0),
                'completed' => (int) ($result['completed'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            $message = __('域名生命周期轮询失败：%{error}', ['error' => $e->getMessage()]);
            w_log_error((string) $message, [], 'domain_lifecycle');
            return (string) $message;
        }
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}
