<?php
declare(strict_types=1);

/**
 * Weline Websites - HTTPS 状态同步观察者
 * 
 * 监听证书签发事件，自动同步域名的 HTTPS 状态
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
 * 证书签发完成观察者
 * 
 * 当 SSL 证书签发成功后：
 * 1. 更新 DomainPool 的 HTTPS 状态和证书信息（v1.6.0）
 * 2. 更新所有使用该域名的 WebsiteDomain 记录的 cert_id
 * 3. 自动启用 HTTPS
 */
class SyncHttpsStatus implements ObserverInterface
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
            // v1.6.0: 先更新 DomainPool 的 HTTPS 状态
            $this->syncDomainPoolHttpsStatus($domain, $certId, $expiresAt);
            
            /** @var WebsiteDomain $domainModel */
            $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
            
            // 同步证书 ID 和启用 HTTPS
            $domainModel->syncDomainCertificate($domain, $certId, true);
            
            // 处理泛域名证书（更新所有子域名）
            $certType = $data['cert_type'] ?? 'exact';
            if ($certType === 'wildcard') {
                $this->syncWildcardDomains($domain, $certId, $expiresAt);
            }
            
        } catch (\Throwable $e) {
            \Weline\Framework\App\Env::log_error('websites', '[SyncHttpsStatus] ' . __('同步 HTTPS 状态失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * v1.6.0: 更新 DomainPool 的 HTTPS 状态
     */
    protected function syncDomainPoolHttpsStatus(string $domain, int $certId, ?string $expiresAt): void
    {
        /** @var DomainPool $poolModel */
        $poolModel = ObjectManager::getInstance(DomainPool::class, [], false);
        $poolModel->clearQuery()
            ->where(DomainPool::fields_DOMAIN, strtolower($domain))
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
    
    /**
     * 同步泛域名证书下的所有子域名
     * 
     * v1.6.0: 同时更新 DomainPool
     */
    protected function syncWildcardDomains(string $wildcardDomain, int $certId, ?string $expiresAt): void
    {
        // 从 *.example.com 提取 example.com
        $rootDomain = \ltrim($wildcardDomain, '*.');
        
        // v1.6.0: 更新 DomainPool 中该根域下所有子域名
        /** @var DomainPool $poolModel */
        $poolModel = ObjectManager::getInstance(DomainPool::class);
        $pools = $poolModel->clearQuery()
            ->where(DomainPool::fields_ROOT_DOMAIN, $rootDomain)
            ->where(DomainPool::fields_CERT_ID, 0)
            ->select()
            ->fetchArray();
        
        foreach ($pools as $poolRow) {
            $pool = ObjectManager::getInstance(DomainPool::class, [], false);
            $pool->setData($poolRow);
            $pool->setCertId($certId);
            $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID);
            $pool->setHttpsExpiresAt($expiresAt);
            $pool->setHttpsError('');
            $pool->calculateSiteReady();
            $pool->save();
        }
        
        /** @var WebsiteDomain $domainModel */
        $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
        
        // 获取该根域下所有没有独立证书的子域名
        $subdomains = $domainModel->getDomainsByRoot($rootDomain);
        
        foreach ($subdomains as $subdomain) {
            // 如果子域名没有独立证书，使用泛域名证书
            if (empty($subdomain[WebsiteDomain::fields_CERT_ID])) {
                $domainModel->syncDomainCertificate(
                    $subdomain[WebsiteDomain::fields_DOMAIN],
                    $certId,
                    true
                );
            }
        }
    }
}
