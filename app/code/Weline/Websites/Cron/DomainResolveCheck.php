<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名解析检测定时任务
 *
 * 定期检测所有域名的解析状态，更新解析 IP 和本服务器指向状态
 */

namespace Weline\Websites\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Service\DomainResolveService;
use Weline\Websites\Service\SubdomainGeneratorService;

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

            // 仅检测“需要处理”的域名：未建站就绪（site_ready != 1）。
            $domains = $domainModel->clearQuery()
                ->where(Domain::schema_fields_SITE_READY, 0)
                ->select()
                ->fetchArray();

            $total = \count($domains);
            $resolved = 0;
            $local = 0;
            $errors = 0;
            $poolAdded = 0;

            $subdomainGenerator = ObjectManager::getInstance(SubdomainGeneratorService::class);

            foreach ($domains as $row) {
                $domain = clone $domainModel;
                $domain->setData($row);

                // 无论解析成功或失败，都立即确保子域名接入域名池
                try {
                    $poolResult = $subdomainGenerator->generateDefaultSubdomains($domain);
                    $poolAdded += $poolResult['added'] ?? 0;
                } catch (\Throwable $e) {
                    w_log_warning(__('子域名入池失败: %{1}, %{2}', [$domain->getDomain(), $e->getMessage()]), [], 'domain_resolve_check');
                }

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
                    w_log_warning("域名 {$row[Domain::schema_fields_DOMAIN]} 检测失败: {$e->getMessage()}", [], 'domain_resolve_check');
                }
            }

            $message = \sprintf(
                '检测完成: 共 %d 个域名, %d 个已解析, %d 个指向本服务器, %d 个错误',
                $total,
                $resolved,
                $local,
                $errors
            );
            if ($poolAdded > 0) {
                $message .= \sprintf(', 子域名入池 %d 个', $poolAdded);
            }

            w_log_info($message, [], 'domain_resolve_check');

            return $message;
        } catch (\Throwable $e) {
            $errorMsg = '解析检测任务异常: ' . $e->getMessage();
            w_log_error($errorMsg, [], 'domain_resolve_check');
            return $errorMsg;
        }
    }

    public function unlock_timeout(int $minute = 20): int
    {
        return $minute;
    }
}
