<?php
declare(strict_types=1);

namespace Weline\Mail\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Service\MailWebVhostService;

final class CertificateIssuedMailWebVhost implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $domain = strtolower(rtrim(trim((string)$event->getData('domain')), '.'));
        if ($domain === '' || !str_starts_with($domain, 'mail.')) {
            return;
        }
        try {
            ObjectManager::getInstance(MailWebVhostService::class)->ensureForHostname($domain);
        } catch (\Throwable $e) {
            w_log_error(
                '[Mail] ' . __('证书签发后配置邮件 Web 代理失败：%{1}', [$e->getMessage()]),
                ['hostname' => $domain],
                'mail'
            );
        }
    }
}
