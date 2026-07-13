<?php
declare(strict_types=1);

/**
 * 每日证书健康：池内 PEM/文件校验（原 PoolCertificateMaintenance 整点/半点段）。
 */
namespace Weline\Websites\Cron;

use Weline\Framework\Cron\Attribute\CronTestHelp;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;

#[CronTestHelp(
    description: '每日执行子域池证书 PEM/文件校验（与 Server 证书续期「临期 3 天」策略同节拍）；无效则回退状态并触发后续申请队列。',
    examples: ['php bin/w cron:test --task=websites_certificate_health_daily --domain=example.com -v'],
    manual_help: [
        '调用 DomainPoolCertificateVerify；不受根域 cron_resolved 影响（证书健康白名单）。',
        '高频证书申请仍由 websites_pool_certificate_maintenance 每 10 分钟处理。',
    ],
)]
class WebsitesCertificateHealthDaily implements CronTaskInterface
{
    use WebsitesCronTestRunnerTrait;

    public function name(): string
    {
        return __('域名池证书健康（每日）');
    }

    public function execute_name(): string
    {
        return 'websites_certificate_health_daily';
    }

    public function tip(): string
    {
        return __('每日校验池内已签发证书 PEM；与 SSL 表续期通知节拍一致');
    }

    public function cron_time(): string
    {
        return '30 4 * * *';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 45;
    }

    public function execute(): string
    {
        return (new DomainPoolCertificateVerify())->execute();
    }
}
