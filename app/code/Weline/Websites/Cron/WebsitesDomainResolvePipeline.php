<?php
declare(strict_types=1);

/**
 * 合并调度：购买/全局自动解析、域名池解析阶段、根域解析与入池（原 3 个独立 Cron）
 */

namespace Weline\Websites\Cron;

use Weline\Cron\Attribute\CronTestHelp;
use Weline\Cron\CronTaskInterface;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;

#[CronTestHelp(
    description: '合并三段：自动解析、域名池解析阶段、根域解析与子域入池。测试参数：domain / verbose（hourly 无效）。',
    examples: [
        'php bin/w cron:test --task=websites_domain_resolve_pipeline -v',
        'php bin/w cron:test --task=websites_domain_resolve_pipeline --domain=example.com -v',
    ],
    manual_help: [
        '控制台 cron:test：--domain= 限定根域、-v 详细日志。',
        '后台「后缀」未由 execute() 解析时与定时一致；需按域名过滤请用控制台。',
    ],
)]
class WebsitesDomainResolvePipeline implements CronTaskInterface
{
    use WebsitesCronTestRunnerTrait;

    public function name(): string
    {
        return __('域名解析链路');
    }

    public function execute_name(): string
    {
        return 'websites_domain_resolve_pipeline';
    }

    public function tip(): string
    {
        return __('合并：自动解析任务、域名池解析检测、根域解析状态与子域入池');
    }

    public function cron_time(): string
    {
        return '*/10 * * * *';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 45;
    }

    public function execute(): string
    {
        $parts = [];
        try {
            $parts[] = '[1/3 ' . __('自动解析') . '] ' . (new DomainAutoResolve())->execute();
        } catch (\Throwable $e) {
            $parts[] = '[1/3] ' . $e->getMessage();
            w_log_error('[websites_domain_resolve_pipeline] ' . $e->getMessage(), [], 'websites_domain_resolve_pipeline');
        }
        try {
            $parts[] = '[2/3 ' . __('域名池解析') . '] ' . (new DomainPoolResolveCheck())->execute();
        } catch (\Throwable $e) {
            $parts[] = '[2/3] ' . $e->getMessage();
            w_log_error('[websites_domain_resolve_pipeline] ' . $e->getMessage(), [], 'websites_domain_resolve_pipeline');
        }
        try {
            $parts[] = '[3/3 ' . __('根域解析') . '] ' . (new DomainResolveCheck())->execute();
        } catch (\Throwable $e) {
            $parts[] = '[3/3] ' . $e->getMessage();
            w_log_error('[websites_domain_resolve_pipeline] ' . $e->getMessage(), [], 'websites_domain_resolve_pipeline');
        }

        return \implode("\n---\n", $parts);
    }
}
