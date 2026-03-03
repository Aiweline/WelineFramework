<?php
declare(strict_types=1);

/**
 * Weline Websites - HTTPS 状态回退观察者
 * 
 * 监听证书禁用事件，自动回退域名的 HTTPS 状态到 HTTP
 * 
 * v1.6.0: 同时回退 DomainPool 模型的 HTTPS 状态
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
 * 证书禁用观察者
 * 
 * 当 HTTPS 被禁用或证书失效时：
 * 1. 回退 DomainPool 的 HTTPS 状态（v1.6.0）
 * 2. 清除域名的 cert_id
 * 3. 禁用 HTTPS
 */
class RollbackHttpsStatus implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        
        $domain = $data['domain'] ?? '';
        $reason = $data['reason'] ?? '';
        
        if (empty($domain)) {
            return;
        }
        
        try {
            // v1.6.0: 回退 DomainPool 的 HTTPS 状态
            $this->rollbackDomainPoolHttpsStatus($domain, $reason);
            
            /** @var WebsiteDomain $domainModel */
            $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
            
            // 回退 HTTPS 状态
            $domainModel->rollbackHttps($domain);
            
        } catch (\Throwable $e) {
            w_log_error('[RollbackHttpsStatus] ' . __('回退 HTTPS 状态失败：%{1}', [$e->getMessage()]), [], 'websites');
        }
    }
    
    /**
     * v1.6.0: 回退 DomainPool 的 HTTPS 状态
     */
    protected function rollbackDomainPoolHttpsStatus(string $domain, string $reason): void
    {
        /** @var DomainPool $poolModel */
        $poolModel = ObjectManager::getInstance(DomainPool::class, [], false);
        $poolModel->clearQuery()
            ->where(DomainPool::fields_DOMAIN, strtolower($domain))
            ->find()
            ->fetch();
        
        if ($poolModel->getPoolId()) {
            $poolModel->clearCertificate();
            if (!empty($reason)) {
                $poolModel->setHttpsError($reason);
            }
            $poolModel->calculateSiteReady();
            $poolModel->save();
        }
    }
}
