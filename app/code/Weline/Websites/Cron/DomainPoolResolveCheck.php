<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名池解析检测定时任务
 *
 * 定期检测 DomainPool 中所有域名的解析状态，更新解析 IP、本服务器指向状态和建站就绪状态
 * 此任务检测的是可建站的具体子域名（如 www.example.com），而非根域名
 */

namespace Weline\Websites\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\DomainPoolResolveService;
use Weline\Websites\Service\ServerIpService;

class DomainPoolResolveCheck implements CronTaskInterface
{
    public function name(): string
    {
        return __('域名池解析状态检测');
    }

    public function execute_name(): string
    {
        return 'domain_pool_resolve_check';
    }

    public function tip(): string
    {
        return __('定期检测域名池中尚未建站就绪的域名的 DNS 解析状态，验证是否指向本服务器；全部就绪后标记可建站并不再检测');
    }

    public function cron_time(): string
    {
        return '*/10 * * * *';
    }

    public function execute(): string
    {
        try {
            $domainPoolModel = ObjectManager::getInstance(DomainPool::class);
            $resolveService = ObjectManager::getInstance(DomainPoolResolveService::class);
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $serverIpService = ObjectManager::getInstance(ServerIpService::class);

            $domains = $domainPoolModel->getDomainsNeedResolveCheck(100);

            $total = \count($domains);
            $resolved = 0;
            $local = 0;
            $ready = 0;
            $errors = 0;

            foreach ($domains as $row) {
                $poolDomain = ObjectManager::getInstance(DomainPool::class, [], false);
                $poolDomain->setData($row);

                try {
                    $result = $resolveService->checkResolve($poolDomain);

                    if ($result['resolved']) {
                        $resolved++;
                    } else {
                        $errors++;
                    }

                    if ($result['is_local']) {
                        $local++;
                    }

                    if ($result['site_ready']) {
                        $ready++;
                    }

                    if (!empty($result['resolve_off_local'])) {
                        $eventData = [
                            'data' => [
                                'domain' => $poolDomain->getDomain(),
                                'pool_id' => (int) $poolDomain->getPoolId(),
                                'resolved_ip' => $result['ipv4'] ?: $result['ipv6'] ?? '',
                                'expected_ip' => $serverIpService->getPublicIpv4() ?: $serverIpService->getPublicIpv6() ?? '',
                            ],
                        ];
                        $eventsManager->dispatch('Weline_Websites::domain_pool::resolve_off_local', $eventData);
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    w_log_warning("域名 {$row[DomainPool::schema_fields_DOMAIN]} 检测失败: {$e->getMessage()}", [], 'domain_pool_resolve_check');
                }
            }

            $message = \sprintf(
                '检测完成: 共 %d 个域名, %d 个已解析, %d 个指向本服务器, %d 个建站就绪, %d 个错误',
                $total,
                $resolved,
                $local,
                $ready,
                $errors
            );

            w_log_info($message, [], 'domain_pool_resolve_check');

            return $message;
        } catch (\Throwable $e) {
            $errorMsg = '域名池解析检测任务异常: ' . $e->getMessage();
            w_log_error($errorMsg, [], 'domain_pool_resolve_check');
            return $errorMsg;
        }
    }

    public function unlock_timeout(int $minute = 20): int
    {
        return $minute;
    }
}
