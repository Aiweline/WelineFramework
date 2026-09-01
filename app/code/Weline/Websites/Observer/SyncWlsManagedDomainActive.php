<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Api\Domain\LocalDomainPolicy;
use Weline\Websites\Service\WlsDomainPoolSyncService;

/** WLS 启用域名时，若域名池缺失则补写。 */
class SyncWlsManagedDomainActive implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $domain = (string)($data['domain'] ?? '');
        if ($domain === '') {
            return;
        }

        try {
            /** @var WlsDomainPoolSyncService $service */
            $service = ObjectManager::getInstance(WlsDomainPoolSyncService::class);
            $ip = LocalDomainPolicy::isManagedLocalDomain($domain) ? '127.0.0.1' : '';
            $service->ensurePoolEntryIfMissing(
                $domain,
                $ip !== '' ? $ip : '127.0.0.1',
                (string)($data['role'] ?? 'wls_runtime'),
            );
        } catch (\Throwable $e) {
            w_log_error(
                '[SyncWlsManagedDomainActive] '
                . (string)__(
                    'WLS 域名启用后补写域名池失败（%{1}）：%{2}',
                    [$domain, $e->getMessage()]
                ),
                [],
                'websites'
            );
        }
    }
}
