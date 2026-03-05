<?php
declare(strict_types=1);

/**
 * Weline Websites - 证书更新观察者
 * 
 * 监听证书更新事件，同步更新状态
 * 
 * v1.6.0: 同时更新 DomainPool 模型的 HTTPS 状态
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\WebsiteDomain;

/**
 * 证书更新观察者
 * 
 * 当 SSL 证书续签或更新时：
 * 1. 更新 DomainPool 的 HTTPS 状态（v1.6.0）
 * 2. 确保 HTTPS 状态保持启用
 */
class UpdateCertificateStatus implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        
        $domain = $data['domain'] ?? '';
        $certId = (int) ($data['cert_id'] ?? 0);
        $expiresAt = $data['expires_at'] ?? null;
        
        if (empty($domain) || $certId <= 0) {
            return;
        }
        
        try {
            // v1.6.0: 更新 DomainPool 的 HTTPS 状态
            $this->updateDomainPoolHttpsStatus($domain, $certId, $expiresAt);
            
            /** @var WebsiteDomain $domainModel */
            $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
            
            // 确保证书更新后 HTTPS 保持启用
            $domainModel->syncDomainCertificate($domain, $certId, true);
            
        } catch (\Throwable $e) {
            w_log_error('[UpdateCertificateStatus] ' . __('更新证书状态失败：%{1}', [$e->getMessage()]), [], 'websites');
        }
    }
    
    /**
     * v1.6.0: 更新 DomainPool 的 HTTPS 状态
     */
    protected function updateDomainPoolHttpsStatus(string $domain, int $certId, ?string $expiresAt): void
    {
        /** @var DomainPool $poolModel */
        $poolModel = ObjectManager::getInstance(DomainPool::class, [], false);
        $poolModel->clearQuery()
            ->where(DomainPool::schema_fields_DOMAIN, strtolower($domain))
            ->find()
            ->fetch();
        
        if ($poolModel->getPoolId()) {
            $poolModel->setCertId($certId);
            $poolModel->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID);
            $poolModel->setHttpsExpiresAt($expiresAt);
            $poolModel->setHttpsError('');
            $poolModel->calculateSiteReady();
            $poolModel->save();
        }
    }
}
