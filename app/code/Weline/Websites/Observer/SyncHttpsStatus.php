<?php
declare(strict_types=1);

/**
 * Weline Websites - HTTPS 状态同步观察者
 *
 * 监听 WLS 证书签发/就绪事件，确保 DomainPool 存在并写入证书状态。
 */

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Service\WlsDomainPoolSyncService;

class SyncHttpsStatus implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();

        $domain = (string)($data['domain'] ?? '');
        $certId = (int)($data['cert_id'] ?? 0);
        $expiresAt = $data['expires_at'] ?? null;

        if ($domain === '' || $certId <= 0) {
            return;
        }

        try {
            /** @var WlsDomainPoolSyncService $poolSync */
            $poolSync = ObjectManager::getInstance(WlsDomainPoolSyncService::class);
            $certType = (string)($data['cert_type'] ?? 'exact');
            $poolSync->applyCertificate($domain, $certId, \is_string($expiresAt) ? $expiresAt : null, $certType);

            /** @var WebsiteDomain $domainModel */
            $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
            $domainModel->syncDomainCertificate($domain, $certId, true);
        } catch (\Throwable $e) {
            w_log_error('[SyncHttpsStatus] ' . __('同步 HTTPS 状态失败：%{1}', [$e->getMessage()]), [], 'websites');
        }
    }
}
