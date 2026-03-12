<?php
declare(strict_types=1);

/**
 * Weline Websites - 证书删除后清除域名池 HTTPS 状态观察者
 *
 * 监听 Weline_Server::domain::certificate_deleted 事件，
 * 清除域名池中对应域名的 HTTPS 状态和可建站状态。
 */

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\WebsiteDomain;

class ClearDomainPoolCertificate implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();

        $domain = (string) ($data['domain'] ?? '');
        if ($domain === '') {
            return;
        }

        try {
            /** @var DomainPool $poolModel */
            $poolModel = ObjectManager::getInstance(DomainPool::class, [], false);
            $poolModel->loadByDomain($domain);

            if ($poolModel->getPoolId()) {
                $poolModel->clearCertificate();
                $poolModel->calculateSiteReady();
                $poolModel->save();
            }

            /** @var WebsiteDomain $domainModel */
            $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
            $domainModel->rollbackHttps($domain);
        } catch (\Throwable $e) {
            w_log_error(
                '[ClearDomainPoolCertificate] ' . __('清除域名池证书状态失败：%{1}', [$e->getMessage()]),
                [],
                'websites'
            );
        }
    }
}
