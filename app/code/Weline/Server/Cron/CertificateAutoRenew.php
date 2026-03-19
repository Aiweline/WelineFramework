<?php
declare(strict_types=1);

/**
 * Weline Server - 证书自动续签定时任务
 *
 * 到期前 3 天内：发送消息通知（同日同证节流）并尝试自动续签
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Model\SslCertificate;
use Weline\Server\Service\SslCertificateService;

/**
 * 证书自动续签定时任务
 *
 * 功能：
 * - 每天检查即将到期的证书（NOTICE_BEFORE_DAYS 天内）
 * - 到期前 NOTICE_BEFORE_DAYS 天：发送后台消息通知（每证每日最多一次）
 * - 剩余天数 ≤ RENEW_BEFORE_DAYS 且开启自动续签：尝试续签
 * - 只处理启用了自动续签的证书
 */
class CertificateAutoRenew implements CronTaskInterface
{
    /** 提前多少天内纳入审查并发送临期通知 */
    protected const NOTICE_BEFORE_DAYS = 3;
    /** 提前多少天内尝试自动续签 */
    protected const RENEW_BEFORE_DAYS = 3;
    
    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'server_certificate_auto_renew';
    }
    
    /**
     * @inheritDoc
     */
    public function execute_name(): string
    {
        return __('SSL 证书自动续签');
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('每天检查即将到期的证书：%{1} 天内发送通知，%{2} 天内自动续签', [self::NOTICE_BEFORE_DAYS, self::RENEW_BEFORE_DAYS]);
    }
    
    /**
     * @inheritDoc
     * 
     * 每天凌晨 3 点执行
     */
    public function cron_time(): string
    {
        return '0 3 * * *';
    }
    
    /**
     * @inheritDoc
     */
    public function execute(): string
    {
        $results = [
            'checked' => 0,
            'renewed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'reminded' => 0,
            'errors' => [],
        ];
        
        try {
            /** @var SslCertificate $certModel */
            $certModel = ObjectManager::getInstance(SslCertificate::class);
            
            /** @var SslCertificateService $sslService */
            $sslService = ObjectManager::getInstance(SslCertificateService::class);

            // 获取即将到期的证书（NOTICE_BEFORE_DAYS 天内）
            $expiringCerts = $this->getExpiringCertificates($certModel);
            $results['checked'] = \count($expiringCerts);

            if (empty($expiringCerts)) {
                return __('没有需要续签的证书');
            }

            $webroot = \defined('PUB') ? PUB : (BP . 'pub');

            foreach ($expiringCerts as $certData) {
                $domain = $certData[SslCertificate::schema_fields_DOMAIN];
                $autoRenew = (bool) $certData[SslCertificate::schema_fields_AUTO_RENEW];
                $issuer = $certData[SslCertificate::schema_fields_ISSUER] ?? '';
                $expiresAt = (string)($certData[SslCertificate::schema_fields_EXPIRES_AT] ?? '');
                $daysLeft = $expiresAt !== '' ? (int)\floor((\strtotime($expiresAt) - \time()) / 86400) : -1;

                // 跳过自签证书
                if ($issuer === SslCertificateService::ISSUER_SELF_SIGNED) {
                    $results['skipped']++;
                    continue;
                }

                $certIdForNotice = (int) ($certData[SslCertificate::schema_fields_ID] ?? 0);
                // 到期前 NOTICE_BEFORE_DAYS 天内：发送消息通知（每证每日最多一次）
                if ($daysLeft >= 0 && $daysLeft <= self::NOTICE_BEFORE_DAYS) {
                    if ($this->sendExpiryNoticeThrottled($certIdForNotice, $domain, $daysLeft, $expiresAt)) {
                        $results['reminded']++;
                        w_log_info(\sprintf(
                            '[CertificateAutoRenew] %s - %s',
                            $domain,
                            __('证书将在 %{1} 天后过期', [$daysLeft])
                        ), [], 'server_ssl');
                    }
                }

                // 剩余天数 > RENEW_BEFORE_DAYS 时跳过续签
                if ($daysLeft > self::RENEW_BEFORE_DAYS) {
                    $results['skipped']++;
                    continue;
                }
                if (!$autoRenew) {
                    $results['skipped']++;
                    continue;
                }

                try {
                    $cert = clone $certModel;
                    $cert->setData($certData);
                    $email = $this->getContactEmail($domain);
                    $result = $sslService->renewCertificate($cert, $webroot, $email);

                    if ($result['success']) {
                        $results['renewed']++;
                        w_log_info(\sprintf('[CertificateAutoRenew] %s - %s', $domain, __('续签成功')), [], 'server_ssl');
                    } else {
                        $results['failed']++;
                        $results['errors'][] = ['domain' => $domain, 'message' => $result['message'] ?? __('未知错误')];
                        w_log_error(\sprintf(
                            '[CertificateAutoRenew] %s - %s: %s',
                            $domain,
                            __('续签失败'),
                            $result['message'] ?? __('未知错误')
                        ), [], 'server_ssl');
                    }
                } catch (\Throwable $e) {
                    $results['failed']++;
                    $results['errors'][] = ['domain' => $domain, 'message' => $e->getMessage()];
                    w_log_error(\sprintf(
                        '[CertificateAutoRenew] %s - %s: %s',
                        $domain,
                        __('续签异常'),
                        $e->getMessage()
                    ), [], 'server_ssl');
                }
            }

            $message = \sprintf(
                __('证书自动续签完成：检查 %{1} 个，提醒 %{2} 个，成功 %{3} 个，失败 %{4} 个，跳过 %{5} 个'),
                $results['checked'],
                $results['reminded'],
                $results['renewed'],
                $results['failed'],
                $results['skipped']
            );
            
            return $message;
            
        } catch (\Throwable $e) {
            $error = __('证书自动续签任务失败：%{1}', [$e->getMessage()]);
            w_log_error('[CertificateAutoRenew] ' . $error);
            return $error;
        }
    }
    
    /**
     * 发送临期通知；同一 cert_id 同一自然日只发一次。
     *
     * @return bool 是否实际发送（用于统计 reminded）
     */
    protected function sendExpiryNoticeThrottled(int $certId, string $domain, int $daysLeft, string $expiresAt): bool
    {
        if ($certId <= 0) {
            $this->sendExpiryNotice($domain, $daysLeft, $expiresAt);

            return true;
        }
        try {
            $cache = w_cache('default');
            $key = 'ssl_cert_expiry_notice_' . $certId . '_' . \date('Y-m-d');
            $hit = $cache->get($key);
            if ($hit !== null && $hit !== false && $hit !== '') {
                return false;
            }
            $this->sendExpiryNotice($domain, $daysLeft, $expiresAt);
            $cache->set($key, '1', 86400);

            return true;
        } catch (\Throwable) {
            $this->sendExpiryNotice($domain, $daysLeft, $expiresAt);

            return true;
        }
    }

    /**
     * 获取即将到期的证书（NOTICE_BEFORE_DAYS 天内）
     */
    protected function getExpiringCertificates(SslCertificate $certModel): array
    {
        $noticeBeforeDate = \date('Y-m-d H:i:s', \strtotime('+' . self::NOTICE_BEFORE_DAYS . ' days'));
        $now = \date('Y-m-d H:i:s');

        return $certModel->clearQuery()
            ->where(SslCertificate::schema_fields_STATUS, SslCertificate::STATUS_ACTIVE)
            ->where(SslCertificate::schema_fields_EXPIRES_AT, $noticeBeforeDate, '<=')
            ->where(SslCertificate::schema_fields_EXPIRES_AT, $now, '>')
            ->order(SslCertificate::schema_fields_EXPIRES_AT, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * 发送证书即将过期通知（后台系统消息）
     */
    protected function sendExpiryNotice(string $domain, int $daysLeft, string $expiresAt): void
    {
        try {
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $eventsManager->dispatch('Weline_Backend::application::system_notification', [
                'data' => [
                    'topic' => 'system_warning',
                    'type' => $daysLeft <= 3 ? 'warning' : 'info',
                    'title' => __('SSL 证书即将过期'),
                    'content' => __('域名 %{1} 的 SSL 证书将在 %{2} 天后过期（%{3}），请关注续签或手动续签。', [
                        $domain,
                        (string)$daysLeft,
                        $expiresAt,
                    ]),
                    'source_module' => 'Weline_Server',
                    'metadata' => ['domain' => $domain, 'days_left' => $daysLeft, 'expires_at' => $expiresAt],
                ],
            ]);
        } catch (\Throwable $e) {
            w_log_warning('[CertificateAutoRenew] 发送过期通知失败: ' . $e->getMessage(), [], 'server_ssl');
        }
    }
    
    /**
     * 获取联系邮箱
     * 
     * @param string $domain
     * @return string
     */
    protected function getContactEmail(string $domain): string
    {
        // 尝试从环境配置获取
        $env = \Weline\Framework\App\Env::getInstance();
        $email = $env->getConfig('ssl.contact_email') ?? '';
        
        if (empty($email)) {
            // 使用默认邮箱
            $email = 'admin@' . $domain;
        }
        
        return $email;
    }
    
    /**
     * @inheritDoc
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return 30; // 30 分钟超时
    }
}
