<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Api\Domain\LocalDomainPolicy;
use Weline\Websites\Service\DefaultWebsiteService;
use Weline\Websites\Service\WlsDomainPoolSyncService;

/** WLS 本地域名注册完成后，同步写入 Websites 域名池；标准项目 Host 绑定默认站。 */
class SyncWlsLocalDomainPool implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $domain = (string)($data['domain'] ?? '');
        $ip = (string)($data['ip'] ?? '');
        if ($domain === '' || $ip === '') {
            return;
        }

        try {
            /** @var WlsDomainPoolSyncService $service */
            $service = ObjectManager::getInstance(WlsDomainPoolSyncService::class);
            $result = $service->syncFromWlsRegistration(
                $domain,
                $ip,
                (string)($data['status'] ?? ''),
            );

            if (\class_exists(LocalDomainPolicy::class)
                && LocalDomainPolicy::isStandardProjectHost($domain)
            ) {
                /** @var DefaultWebsiteService $defaultWebsite */
                $defaultWebsite = ObjectManager::getInstance(DefaultWebsiteService::class);
                $defaultWebsite->ensureWebsiteDomainBinding(
                    $domain,
                    (int)($result['pool_id'] ?? 0),
                );
            }
        } catch (\Throwable $e) {
            w_log_error(
                '[SyncWlsLocalDomainPool] '
                . (string)__(
                    'WLS 本地域名注册后同步域名池失败（%{1}）：%{2}',
                    [$domain, $e->getMessage()]
                ),
                [],
                'websites'
            );
        }
    }
}
