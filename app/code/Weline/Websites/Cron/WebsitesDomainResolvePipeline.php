<?php
declare(strict_types=1);

/**
 * 合并调度：DNS 解析与子域入池三步流水线
 * ① 自动解析（购买任务 + DNS 迁移 + 全局自动解析）
 * ② 子域解析检测（池内子域 A/AAAA 检测，推进到 origin_ready）
 * ③ 根域解析检测 + 默认子域入池（@、www 等）
 */

namespace Weline\Websites\Cron;

use Weline\Cron\Attribute\CronTestHelp;
use Weline\Cron\CronTaskInterface;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;

#[CronTestHelp(
    description: 'DNS 解析与子域入池三步流水线：① 自动解析 ② 子域解析检测 ③ 根域解析+入池。--domain= 限定根域，-v 详细日志。',
    examples: [
        'php bin/w cron:test --task=websites_domain_resolve_pipeline -v',
        'php bin/w cron:test --task=websites_domain_resolve_pipeline --domain=example.com -v',
    ],
    manual_help: [
        '① 自动解析：执行购买时创建的解析任务、DNS 迁移待推送、全局自动解析未解析根域。',
        '② 子域解析：池内生命周期 registered/awaiting_origin 的子域做 A/AAAA 检测，指向本机则推进到 origin_ready。',
        '③ 根域解析：未建站就绪的根域做解析检测，并确保默认子域（@、www）入池；子域已可建站则纠正根域状态。',
        '根域 cron_resolved=1（默认可建站子域已全部 site_ready）时，本流水线各步跳过该根域/池子，减少无效解析写入。',
        '本流水线不包含 HTTPS 证书校验；证书申请见 websites_certificate_health_daily + websites_pool_certificate_maintenance。',
    ],
)]
class WebsitesDomainResolvePipeline implements CronTaskInterface
{
    use WebsitesCronTestRunnerTrait;

    public function name(): string
    {
        return __('DNS 解析与子域入池');
    }

    public function execute_name(): string
    {
        return 'websites_domain_resolve_pipeline';
    }

    public function tip(): string
    {
        return __('三步：① 自动解析 ② 子域解析检测 ③ 根域解析与默认子域入池');
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
            $parts[] = '[1/3 ' . __('① 自动解析') . '] ' . (new DomainAutoResolve())->execute();
        } catch (\Throwable $e) {
            $parts[] = '[1/3] ' . $e->getMessage();
            w_log_error('[websites_domain_resolve_pipeline] ' . $e->getMessage(), [], 'websites_domain_resolve_pipeline');
        }
        try {
            $parts[] = '[2/3 ' . __('② 子域解析检测') . '] ' . (new DomainPoolResolveCheck())->execute();
        } catch (\Throwable $e) {
            $parts[] = '[2/3] ' . $e->getMessage();
            w_log_error('[websites_domain_resolve_pipeline] ' . $e->getMessage(), [], 'websites_domain_resolve_pipeline');
        }
        try {
            $parts[] = '[3/3 ' . __('③ 根域解析与入池') . '] ' . (new DomainResolveCheck())->execute();
        } catch (\Throwable $e) {
            $parts[] = '[3/3] ' . $e->getMessage();
            w_log_error('[websites_domain_resolve_pipeline] ' . $e->getMessage(), [], 'websites_domain_resolve_pipeline');
        }

        return \implode("\n---\n", $parts);
    }
}
