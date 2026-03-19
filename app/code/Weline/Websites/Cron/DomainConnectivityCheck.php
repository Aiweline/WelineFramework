<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名连通性检测定时任务
 *
 * 定期对根域与子域（域名池）做 HTTP(S) 探测，更新 connectivity_status / connectivity_checked_at / connectivity_detail
 */

namespace Weline\Websites\Cron;

use Weline\Cron\Attribute\CronTestHelp;
use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\DomainConnectivityService;
use Weline\Websites\Cron\Concern\WebsitesCronTestRunnerTrait;
use Weline\Websites\Service\WebsitesCronTestContext;

#[CronTestHelp(
    description: '对根域与子域做 HTTP(S) 探测，更新「是否可访问」状态与详情（列表 hover 展示）。--domain= 可限定根域或子域。',
    examples: [
        'php bin/w cron:test --task=domain_connectivity_check --domain=example.com -v',
        'php bin/w cron:test --task=domain_connectivity_check --domain=www.example.com -v',
    ],
    manual_help: [
        '逻辑：先试 HTTPS，失败再试 HTTP；2xx/3xx 视为可访问。结果写入 connectivity_status / connectivity_checked_at / connectivity_detail。',
        '每 15 分钟跑一批根域与一批子域，用于后台列表「连通性」列与 hover 详情，不参与建站就绪判断。',
    ],
)]
class DomainConnectivityCheck implements CronTaskInterface
{
    use WebsitesCronTestRunnerTrait;

    private const LOG_KEY = 'domain_connectivity_check';
    private const ROOT_BATCH = 80;
    private const POOL_BATCH = 120;

    public function name(): string
    {
        return __('域名可访问性检测');
    }

    public function execute_name(): string
    {
        return 'domain_connectivity_check';
    }

    public function tip(): string
    {
        return __('HTTP(S) 探测根域与子域，更新可访问状态与详情（列表 hover）');
    }

    public function cron_time(): string
    {
        return '*/15 * * * *';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 35;
    }

    public function execute(): string
    {
        /** @var DomainConnectivityService $service */
        $service = ObjectManager::getInstance(DomainConnectivityService::class);
        $domainFilter = WebsitesCronTestContext::getDomainFilter();

        $rootOk = 0;
        $rootErr = 0;
        $poolOk = 0;
        $poolErr = 0;

        $domainModel = ObjectManager::getInstance(Domain::class);
        $rootRows = $domainModel->clearQuery()
            ->order(Domain::schema_fields_DOMAIN, 'ASC')
            ->limit(self::ROOT_BATCH)
            ->select()
            ->fetchArray();

        foreach ($rootRows as $row) {
            $domainName = (string) ($row[Domain::schema_fields_DOMAIN] ?? '');
            if ($domainName === '') {
                continue;
            }
            if ($domainFilter !== null && !WebsitesCronTestContext::matchesSubject($domainName, $domainName)) {
                continue;
            }
            $result = $service->probe($domainName);
            $domain = ObjectManager::getInstance(Domain::class, [], false);
            $domain->setData($row);
            $domain->setConnectivityStatus($result['status'] === 'ok' ? Domain::CONNECTIVITY_OK : Domain::CONNECTIVITY_ERROR);
            $domain->setConnectivityCheckedAt($result['checked_at']);
            $domain->setConnectivityDetail($result['detail']);
            $domain->save();
            if ($result['status'] === 'ok') {
                $rootOk++;
            } else {
                $rootErr++;
            }
        }

        $poolModel = ObjectManager::getInstance(DomainPool::class);
        $poolRows = $poolModel->clearQuery()
            ->where(DomainPool::schema_fields_STATUS, DomainPool::STATUS_ACTIVE)
            ->order(DomainPool::schema_fields_DOMAIN, 'ASC')
            ->limit(self::POOL_BATCH)
            ->select()
            ->fetchArray();

        foreach ($poolRows as $row) {
            $domainName = (string) ($row[DomainPool::schema_fields_DOMAIN] ?? '');
            if ($domainName === '') {
                continue;
            }
            if ($domainFilter !== null && !WebsitesCronTestContext::matchesSubject($domainName, $domainName)) {
                continue;
            }
            $result = $service->probe($domainName);
            $pool = ObjectManager::getInstance(DomainPool::class, [], false);
            $pool->setData($row);
            $pool->setConnectivityStatus($result['status'] === 'ok' ? DomainPool::CONNECTIVITY_OK : DomainPool::CONNECTIVITY_ERROR);
            $pool->setConnectivityCheckedAt($result['checked_at']);
            $pool->setConnectivityDetail($result['detail']);
            $pool->save();
            if ($result['status'] === 'ok') {
                $poolOk++;
            } else {
                $poolErr++;
            }
        }

        $msg = \sprintf(
            __('连通性检测：根域 %d 成功 / %d 失败，子域 %d 成功 / %d 失败'),
            $rootOk,
            $rootErr,
            $poolOk,
            $poolErr
        );
        w_log_info('[DomainConnectivityCheck] ' . $msg, [], self::LOG_KEY);
        return $msg;
    }
}
