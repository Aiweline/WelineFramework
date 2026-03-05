<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名池证书自动申请定时任务
 *
 * 定期检测域名池中解析已生效且指向本服务器的域名，自动申请 HTTPS 证书
 * 条件：resolve_status=resolved + is_local_server=1 + https_status in (none, expired, error)
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\DomainPool;

class DomainPoolCertificateRequest implements CronTaskInterface
{
    public function name(): string
    {
        return __('域名池证书自动申请');
    }

    public function execute_name(): string
    {
        return 'domain_pool_certificate_request';
    }

    public function tip(): string
    {
        return __('定期为域名池内解析已生效且指向本服务器的域名自动申请 Let\'s Encrypt 证书');
    }

    public function cron_time(): string
    {
        return '*/30 * * * *';
    }

    public function execute(): string
    {
        $results = [
            'checked' => 0,
            'requested' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        try {
            $domainPoolModel = ObjectManager::getInstance(DomainPool::class);
            $domains = $domainPoolModel->getDomainsNeedCertificate(50);
            $results['checked'] = \count($domains);

            if ($domains === []) {
                return __('没有需要申请证书的域名池域名');
            }

            $webroot = \defined('PUB') ? PUB : (BP . 'pub');
            $email = Env::getInstance()->getConfig('ssl.contact_email') ?? '';

            foreach ($domains as $row) {
                $domain = (string) ($row[DomainPool::schema_fields_DOMAIN] ?? '');
                $poolId = (int) ($row[DomainPool::schema_fields_ID] ?? 0);
                if ($domain === '' || $poolId <= 0) {
                    $results['skipped']++;
                    continue;
                }

                $poolDomain = ObjectManager::getInstance(DomainPool::class, [], false);
                $poolDomain->setData($row);

                // 标记为 pending 避免重复申请
                $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING);
                $poolDomain->save();

                $reqEmail = $email !== '' ? $email : 'admin@' . $domain;
                $results['requested']++;

                try {
                    $result = w_query('server', 'requestCertificate', [
                        'domain'     => $domain,
                        'webroot'    => $webroot,
                        'email'      => $reqEmail,
                        'website_id' => 0,
                        'provider'   => 'letsencrypt',
                    ]);

                    if ($result['success'] ?? false) {
                        $results['success']++;
                        // 证书签发成功后由 Weline_Server::domain::certificate_issued 事件
                        // 触发 SyncHttpsStatus 更新 DomainPool 的 cert_id、https_status
                        w_log_info(__('[DomainPoolCertificateRequest] %{1} 证书申请成功', [$domain]), [], 'domain_pool_cert');
                    } else {
                        $results['failed']++;
                        $msg = $result['message'] ?? __('未知错误');
                        $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
                        $poolDomain->setHttpsError($msg);
                        $poolDomain->save();
                        w_log_error(__('[DomainPoolCertificateRequest] %{1} 证书申请失败: %{2}', [$domain, $msg]), [], 'domain_pool_cert');
                    }
                } catch (\Throwable $e) {
                    $results['failed']++;
                    $poolDomain->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
                    $poolDomain->setHttpsError($e->getMessage());
                    $poolDomain->save();
                    w_log_error(__('[DomainPoolCertificateRequest] %{1} 证书申请异常: %{2}', [$domain, $e->getMessage()]), [], 'domain_pool_cert');
                }
            }

            return __('域名池证书申请完成：检查 %{1} 个，申请 %{2} 个，成功 %{3} 个，失败 %{4} 个', [
                $results['checked'],
                $results['requested'],
                $results['success'],
                $results['failed'],
            ]);
        } catch (\Throwable $e) {
            $err = __('域名池证书申请任务异常：%{1}', [$e->getMessage()]);
            w_log_error('[DomainPoolCertificateRequest] ' . $err, [], 'domain_pool_cert');
            return $err;
        }
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}
