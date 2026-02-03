<?php
declare(strict_types=1);

/**
 * Weline Websites - HTTPS 状态同步观察者
 * 
 * 监听证书签发事件，自动同步域名的 HTTPS 状态
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\WebsiteDomain;

/**
 * 证书签发完成观察者
 * 
 * 当 SSL 证书签发成功后：
 * 1. 更新所有使用该域名的 WebsiteDomain 记录的 cert_id
 * 2. 自动启用 HTTPS
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
        
        if (empty($domain) || $certId <= 0) {
            return;
        }
        
        try {
            /** @var WebsiteDomain $domainModel */
            $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
            
            // 同步证书 ID 和启用 HTTPS
            $domainModel->syncDomainCertificate($domain, $certId, true);
            
            // 处理泛域名证书（更新所有子域名）
            $certType = $data['cert_type'] ?? 'exact';
            if ($certType === 'wildcard') {
                $this->syncWildcardDomains($domain, $certId);
            }
            
        } catch (\Throwable $e) {
            // 记录错误但不阻止其他观察者执行
            \error_log('[SyncHttpsStatus] ' . __('同步 HTTPS 状态失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 同步泛域名证书下的所有子域名
     */
    protected function syncWildcardDomains(string $wildcardDomain, int $certId): void
    {
        // 从 *.example.com 提取 example.com
        $rootDomain = \ltrim($wildcardDomain, '*.');
        
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
