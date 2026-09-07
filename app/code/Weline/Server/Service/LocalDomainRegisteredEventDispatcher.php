<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Api\Domain\LocalDomainPolicy;

/**
 * Dispatches domain events after WLS registers a managed local hostname in hosts.
 */
final class LocalDomainRegisteredEventDispatcher
{
    public const EVENT_NAME = 'Weline_Server::domain::local_domain_registered';

    public static function dispatch(string $domain, string $ip, string $status): void
    {
        $domain = LocalDomainPolicy::normalizeDomain($domain);
        $ip = \trim($ip);
        $status = \trim($status);
        if ($domain === '' || $ip === '' || $status === '') {
            return;
        }
        if (!LocalDomainPolicy::isManagedLocalDomain($domain)) {
            return;
        }

        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $payload = [
                'domain' => $domain,
                'ip' => $ip,
                'status' => $status,
                'is_new' => !\in_array($status, ['already_exists', 'external_satisfied'], true),
                'is_standard_project_host' => LocalDomainPolicy::isStandardProjectHost($domain),
                'source' => 'wls_hosts',
            ];
            $eventsManager->dispatch(self::EVENT_NAME, $payload);
        } catch (\Throwable $e) {
            // Privileged hosts editor boots with vendor autoload only; framework
            // helpers such as w_log_error()/__() may be unavailable. Never let
            // event side-effects turn a successful hosts write into failure.
            if (\function_exists('w_log_error')) {
                $detail = \function_exists('__')
                    ? (string)__('WLS 本地域名注册事件调度失败：%{1}', [$e->getMessage()])
                    : ('WLS local domain registered event dispatch failed: ' . $e->getMessage());
                w_log_error('[LocalDomainRegisteredEventDispatcher] ' . $detail);
            }
        }
    }
}
