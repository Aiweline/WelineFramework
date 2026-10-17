<?php
declare(strict_types=1);

namespace Weline\Mail\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Service\StalwartTlsCertificateSyncService;

/**
 * 域名池/Server 签发完成后：本机 mail./smtp. 主机自动导入 Stalwart。
 */
final class CertificateIssuedStalwartTls implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $domain = strtolower(rtrim(trim((string)$event->getData('domain')), '.'));
        if ($domain === '') {
            return;
        }
        if (!str_starts_with($domain, 'mail.') && !str_starts_with($domain, 'smtp.')) {
            return;
        }
        try {
            /** @var StalwartTlsCertificateSyncService $sync */
            $sync = ObjectManager::getInstance(StalwartTlsCertificateSyncService::class);
            $sync->ensureForHostname($domain);
        } catch (\Throwable $e) {
            w_log_error(
                '[Mail] ' . __('证书签发后导入 Stalwart 失败：%{1}', [$e->getMessage()]),
                ['hostname' => $domain],
                'mail'
            );
        }
    }
}
