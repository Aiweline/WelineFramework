<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Api\Domain\LocalDomainPolicy;

/** Dispatches when WLS adopts a hostname for serving (startup / route gate). */
final class WlsManagedDomainActiveEventDispatcher
{
    public const EVENT_NAME = 'Weline_Server::domain::managed_domain_active';

    public static function dispatch(string $domain, string $role, string $instanceName = 'default'): void
    {
        $domain = LocalDomainPolicy::normalizeDomain($domain);
        $role = \trim($role);
        $instanceName = \trim($instanceName) !== '' ? \trim($instanceName) : 'default';
        if ($domain === '' || self::shouldSkip($domain)) {
            return;
        }

        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $payload = [
                'domain' => $domain,
                'role' => $role,
                'instance_name' => $instanceName,
                'is_managed_local' => LocalDomainPolicy::isManagedLocalDomain($domain),
                'is_standard_project_host' => LocalDomainPolicy::isStandardProjectHost($domain),
                'source' => 'wls_runtime',
            ];
            $eventsManager->dispatch(self::EVENT_NAME, $payload);
        } catch (\Throwable $e) {
            w_log_error(
                '[WlsManagedDomainActiveEventDispatcher] '
                . (string)__('WLS 域名启用事件调度失败：%{1}', [$e->getMessage()])
            );
        }
    }

    private static function shouldSkip(string $domain): bool
    {
        return \in_array($domain, ['127.0.0.1', '0.0.0.0', 'localhost'], true)
            || \str_contains($domain, '*');
    }
}
