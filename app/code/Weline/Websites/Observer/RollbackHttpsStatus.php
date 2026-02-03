<?php
declare(strict_types=1);

/**
 * Weline Websites - HTTPS 状态回退观察者
 * 
 * 监听证书禁用事件，自动回退域名的 HTTPS 状态到 HTTP
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
 * 证书禁用观察者
 * 
 * 当 HTTPS 被禁用或证书失效时：
 * 1. 清除域名的 cert_id
 * 2. 禁用 HTTPS
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
        
        if (empty($domain)) {
            return;
        }
        
        try {
            /** @var WebsiteDomain $domainModel */
            $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
            
            // 回退 HTTPS 状态
            $domainModel->rollbackHttps($domain);
            
        } catch (\Throwable $e) {
            // 记录错误但不阻止其他观察者执行
            \error_log('[RollbackHttpsStatus] ' . __('回退 HTTPS 状态失败：%{1}', [$e->getMessage()]));
        }
    }
}
