<?php
declare(strict_types=1);

/**
 * Weline Websites - 证书更新观察者
 * 
 * 监听证书更新事件，同步更新状态
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
 * 证书更新观察者
 * 
 * 当 SSL 证书续签或更新时，确保 HTTPS 状态保持启用
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
        
        if (empty($domain) || $certId <= 0) {
            return;
        }
        
        try {
            /** @var WebsiteDomain $domainModel */
            $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
            
            // 确保证书更新后 HTTPS 保持启用
            $domainModel->syncDomainCertificate($domain, $certId, true);
            
        } catch (\Throwable $e) {
            // 记录错误但不阻止其他观察者执行
            \error_log('[UpdateCertificateStatus] ' . __('更新证书状态失败：%{1}', [$e->getMessage()]));
        }
    }
}
