<?php
declare(strict_types=1);

namespace Weline\Websites\Cron;

use Weline\Framework\Cron\Attribute\CronTestHelp;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\DomainLifecycleOrchestrationService;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;
use Weline\Websites\Service\WebsitesCronTestContext;

#[CronTestHelp(
    description: '先执行 DNS/CDN 自动切换，再轮询域名购买/生命周期订单，推进「待 DNS → 待解析 → 待证书 → 完成」等状态。--domain= 仅处理该根域订单。',
    examples: [
        'php bin/w cron:test --task=domain_lifecycle_orchestration --domain=example.com -v',
    ],
    manual_help: [
        '① 先执行 DnsCdnAutoSwitch：识别需设置 DNS 的根域（dns_switch_deferred/ dns_switch_pending），调用统一切换逻辑并标记根域与子域 DNS/CDN 完成。',
        '② 脏数据修复与订单推进：读取待处理的域名生命周期订单，按状态推进（如同步根域、创建子域入池、修正脏数据、标记完成）。',
        '根域 cron_resolved=1 时不再推进订单（证书与健康由白名单任务维护）。',
        'VERIFY 步仅检查 HTTP 连通性（HealthCheckService::checkDomain(..., false)）；不通过 HTTPS 请求判断证书是否有效。证书由「子域 HTTPS 证书维护」与 SSL 证书管理表处理。',
    ],
)]
class DomainLifecycleOrchestration implements CronTaskInterface
{
    use WebsitesCronTestRunnerTrait;

    public function name(): string
    {
        return __('域名订单生命周期处理');
    }

    public function execute_name(): string
    {
        return 'domain_lifecycle_orchestration';
    }

    public function tip(): string
    {
        return __('轮询购买/生命周期订单，推进 DNS、解析、证书等状态');
    }

    public function cron_time(): string
    {
        return '* * * * *';
    }

    public function execute(): string
    {
        try {
            $df = WebsitesCronTestContext::getDomainFilter();

            $dnsSwitchMsg = (new DnsCdnAutoSwitch())->execute();
            WebsitesCronTestContext::detail('DnsCdnAutoSwitch', ['domain_filter' => $df, 'result' => $dnsSwitchMsg]);

            /** @var DomainLifecycleOrchestrationService $service */
            $service = ObjectManager::getInstance(DomainLifecycleOrchestrationService::class);
            $repair = $service->repairLifecycleDirtyData($df, 100);
            WebsitesCronTestContext::detail('repairLifecycleDirtyData', ['domain_filter' => $df, 'repair' => $repair]);

            $result = $service->processPendingOrders(20, $df);
            WebsitesCronTestContext::detail('DomainLifecycleOrchestration', ['domain_filter' => $df, 'result' => $result]);

            $msg = (string) __('域名生命周期轮询完成：处理 %{processed} 条，完成 %{completed} 条，失败 %{failed} 条', [
                'processed' => (int) ($result['processed'] ?? 0),
                'completed' => (int) ($result['completed'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
            ]);
            if ($dnsSwitchMsg !== '') {
                $msg = __('[DNS/CDN 切换] %{1}', [$dnsSwitchMsg]) . "\n" . $msg;
            }
            if (($repair['synced_domain_count'] ?? 0) > 0 || ($repair['marked_completed_count'] ?? 0) > 0) {
                $msg .= ' ' . __('（脏数据修复：同步根域 %{1} 条，补标完成 %{2} 条）', [
                    (string) ($repair['synced_domain_count'] ?? 0),
                    (string) ($repair['marked_completed_count'] ?? 0),
                ]);
            }
            return $msg;
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
