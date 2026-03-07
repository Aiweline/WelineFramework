<?php
declare(strict_types=1);

/**
 * Weline Server - 证书自动续签定时任务
 * 
 * 到期前一周自动续签 SSL 证书
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Model\SslCertificate;
use Weline\Server\Service\SslCertificateService;

/**
 * 证书自动续签定时任务
 * 
 * 功能：
 * - 每天检查即将到期的证书（7 天内）
 * - 自动为符合条件的证书续签
 * - 只处理启用了自动续签的证书
 * - 续签后自动同步 HTTPS 状态
 */
class CertificateAutoRenew implements CronTaskInterface
{
    /**
     * 提前续签天数
     */
    protected const RENEW_BEFORE_DAYS = 7;
    
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
        return __('每天检查即将到期的证书（%{1} 天内），自动续签', [self::RENEW_BEFORE_DAYS]);
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
            
            // 获取即将到期的证书（7 天内）
            $expiringCerts = $this->getExpiringCertificates($certModel);
            $results['checked'] = \count($expiringCerts);
            
            if (empty($expiringCerts)) {
                return __('没有需要续签的证书');
            }
            
            // 默认 webroot 路径
            $webroot = BP . 'pub';
            
            foreach ($expiringCerts as $certData) {
                $domain = $certData[SslCertificate::schema_fields_DOMAIN];
                $certId = (int) $certData[SslCertificate::schema_fields_ID];
                $autoRenew = (bool) $certData[SslCertificate::schema_fields_AUTO_RENEW];
                $issuer = $certData[SslCertificate::schema_fields_ISSUER] ?? '';
                
                // 跳过未启用自动续签的证书
                if (!$autoRenew) {
                    $results['skipped']++;
                    continue;
                }
                
                // 跳过自签证书（自签证书会在服务器启动时自动重新生成）
                if ($issuer === SslCertificateService::ISSUER_SELF_SIGNED) {
                    $results['skipped']++;
                    continue;
                }
                
                try {
                    // 加载证书模型
                    $cert = clone $certModel;
                    $cert->setData($certData);
                    $expiresAt = (string)($certData[SslCertificate::schema_fields_EXPIRES_AT] ?? '');
                    $daysLeft = $expiresAt !== '' ? (int)\floor((\strtotime($expiresAt) - \time()) / 86400) : -1;

                    if ($daysLeft >= 0 && $daysLeft <= self::RENEW_BEFORE_DAYS) {
                        $results['reminded']++;
                        w_log_warning(\sprintf(
                            '[CertificateAutoRenew] %s - %s',
                            $domain,
                            __('证书将在 %{1} 天后过期，已触发续签尝试', [$daysLeft])
                        ));
                    }
                    
                    // 执行续签
                    $email = $this->getContactEmail($domain);
                    $result = $sslService->renewCertificate($cert, $webroot, $email);
                    
                    if ($result['success']) {
                        $results['renewed']++;
                        w_log_info(\sprintf(
                            '[CertificateAutoRenew] %s - %s',
                            $domain,
                            __('续签成功')
                        ));
                    } else {
                        $results['failed']++;
                        $results['errors'][] = [
                            'domain' => $domain,
                            'message' => $result['message'] ?? __('未知错误'),
                        ];
                        w_log_error(\sprintf(
                            '[CertificateAutoRenew] %s - %s: %s',
                            $domain,
                            __('续签失败'),
                            $result['message'] ?? __('未知错误')
                        ));
                    }
                } catch (\Throwable $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'domain' => $domain,
                        'message' => $e->getMessage(),
                    ];
                    w_log_error(\sprintf(
                        '[CertificateAutoRenew] %s - %s: %s',
                        $domain,
                        __('续签异常'),
                        $e->getMessage()
                    ));
                }
            }
            
            // 构建结果消息
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
     * 获取即将到期的证书
     * 
     * @param SslCertificate $certModel
     * @return array
     */
    protected function getExpiringCertificates(SslCertificate $certModel): array
    {
        $renewBeforeDate = \date('Y-m-d H:i:s', \strtotime('+' . self::RENEW_BEFORE_DAYS . ' days'));
        $now = \date('Y-m-d H:i:s');
        
        return $certModel->clearQuery()
            ->where(SslCertificate::schema_fields_STATUS, SslCertificate::STATUS_ACTIVE)
            ->where(SslCertificate::schema_fields_EXPIRES_AT, $renewBeforeDate, '<=')
            ->where(SslCertificate::schema_fields_EXPIRES_AT, $now, '>')
            ->order(SslCertificate::schema_fields_EXPIRES_AT, 'ASC')
            ->select()
            ->fetchArray();
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
