<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名解析检测定时任务
 *
 * 定期检测所有域名的解析状态，更新解析 IP 和本服务器指向状态
 */

namespace Weline\Websites\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Service\DomainResolveService;

class DomainResolveCheck implements CronTaskInterface
{
    public function name(): string
    {
        return __('域名解析状态检测');
    }

    public function execute_name(): string
    {
        return 'domain_resolve_check';
    }

    public function tip(): string
    {
        return __('定期检测域名 DNS 解析状态，验证是否指向本服务器');
    }

    public function cron_time(): string
    {
        return '*/10 * * * *';
    }

    public function execute(): string
    {
        try {
            $domainModel = ObjectManager::getInstance(Domain::class);
            $resolveService = ObjectManager::getInstance(DomainResolveService::class);

            $domains = $domainModel->clearQuery()
                ->where(Domain::fields_STATUS, Domain::STATUS_ACTIVE)
                ->select()
                ->fetchArray();

            $total = \count($domains);
            $resolved = 0;
            $local = 0;
            $errors = 0;

            foreach ($domains as $row) {
                $domain = clone $domainModel;
                $domain->setData($row);

                try {
                    $result = $resolveService->checkResolve($domain);

                    if ($result['resolved']) {
                        $resolved++;
                    } else {
                        $errors++;
                    }

                    if ($result['is_local']) {
                        $local++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    Env::log_warning('domain_resolve_check', "域名 {$row[Domain::fields_DOMAIN]} 检测失败: {$e->getMessage()}");
                }
            }

            $message = \sprintf(
                '检测完成: 共 %d 个域名, %d 个已解析, %d 个指向本服务器, %d 个错误',
                $total,
                $resolved,
                $local,
                $errors
            );

            Env::log_info('domain_resolve_check', $message);

            return $message;
        } catch (\Throwable $e) {
            $errorMsg = '解析检测任务异常: ' . $e->getMessage();
            Env::log_error('domain_resolve_check', $errorMsg);
            return $errorMsg;
        }
    }

    public function unlock_timeout(int $minute = 20): int
    {
        return $minute;
    }
}
