<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名列表提供者观察者
 * 
 * 监听 Server 模块的域名列表请求事件，提供可选域名数据
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Model\DomainPool;

/**
 * 域名列表提供者观察者
 * 
 * 当 Server 模块需要获取可选域名列表时，提供 Websites 模块管理的域名数据
 */
class ProvideDomainList implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $filter = $data['filter'] ?? [];
        
        try {
            // 获取现有域名列表
            $domains = $data['domains'] ?? [];
            
            // 从 WebsiteDomain 获取所有域名
            $websiteDomains = $this->getWebsiteDomains($filter);
            
            // 从 DomainPool 获取域名池
            $poolDomains = $this->getDomainPoolDomains($filter);
            
            // 合并并去重
            $domains = \array_merge($domains, $websiteDomains, $poolDomains);
            $domains = $this->deduplicateDomains($domains);
            
            // 按根域分组
            if ($filter['group_by_root'] ?? false) {
                $domains = $this->groupByRoot($domains);
            }
            
            // 更新事件数据
            $event->setData('domains', $domains);
            $event->setData('provider', 'Weline_Websites');
            
        } catch (\Throwable $e) {
            w_log_error('[ProvideDomainList] ' . __('获取域名列表失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 获取 WebsiteDomain 中的域名
     */
    protected function getWebsiteDomains(array $filter): array
    {
        /** @var WebsiteDomain $model */
        $model = ObjectManager::getInstance(WebsiteDomain::class);
        
        $query = $model->clearQuery()
            ->where(WebsiteDomain::schema_fields_STATUS, WebsiteDomain::STATUS_ACTIVE);
        
        // 应用过滤条件
        if (!empty($filter['website_id'])) {
            $query->where(WebsiteDomain::schema_fields_WEBSITE_ID, $filter['website_id']);
        }
        
        if (!empty($filter['root_domain'])) {
            $query->where(WebsiteDomain::schema_fields_ROOT_DOMAIN, $filter['root_domain']);
        }
        
        if (isset($filter['has_certificate'])) {
            if ($filter['has_certificate']) {
                $query->where(WebsiteDomain::schema_fields_CERT_ID, 'null', 'is not');
            } else {
                $query->where(WebsiteDomain::schema_fields_CERT_ID, 'null', 'is');
            }
        }
        
        $rows = $query->order(WebsiteDomain::schema_fields_ROOT_DOMAIN, 'ASC')
            ->order(WebsiteDomain::schema_fields_DOMAIN, 'ASC')
            ->select()
            ->fetchArray();
        
        $domains = [];
        foreach ($rows as $row) {
            $domains[] = [
                'domain' => $row[WebsiteDomain::schema_fields_DOMAIN],
                'root_domain' => $row[WebsiteDomain::schema_fields_ROOT_DOMAIN],
                'website_id' => (int) $row[WebsiteDomain::schema_fields_WEBSITE_ID],
                'cert_id' => $row[WebsiteDomain::schema_fields_CERT_ID] ? (int) $row[WebsiteDomain::schema_fields_CERT_ID] : null,
                'https_enabled' => (bool) $row[WebsiteDomain::schema_fields_HTTPS_ENABLED],
                'is_primary' => (bool) $row[WebsiteDomain::schema_fields_IS_PRIMARY],
                'health_status' => $row[WebsiteDomain::schema_fields_HEALTH_STATUS] ?? 'unknown',
                'source' => 'website_domain',
            ];
        }
        
        return $domains;
    }
    
    /**
     * 获取域名池中的域名
     */
    protected function getDomainPoolDomains(array $filter): array
    {
        if (!\class_exists(DomainPool::class)) {
            return [];
        }
        
        /** @var DomainPool $model */
        $model = ObjectManager::getInstance(DomainPool::class);
        
        $rows = $model->getActiveDomains();
        
        $domains = [];
        foreach ($rows as $row) {
            $domains[] = [
                'domain' => $row[DomainPool::schema_fields_DOMAIN],
                'root_domain' => $row[DomainPool::schema_fields_ROOT_DOMAIN],
                'description' => $row[DomainPool::schema_fields_DESCRIPTION] ?? '',
                'cert_id' => null,
                'https_enabled' => false,
                'source' => 'domain_pool',
            ];
        }
        
        return $domains;
    }
    
    /**
     * 去重域名
     */
    protected function deduplicateDomains(array $domains): array
    {
        $unique = [];
        $seen = [];
        
        foreach ($domains as $domain) {
            $key = $domain['domain'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $domain;
            }
        }
        
        return $unique;
    }
    
    /**
     * 按根域分组
     */
    protected function groupByRoot(array $domains): array
    {
        $grouped = [];
        
        foreach ($domains as $domain) {
            $root = $domain['root_domain'] ?: $domain['domain'];
            if (!isset($grouped[$root])) {
                $grouped[$root] = [];
            }
            $grouped[$root][] = $domain;
        }
        
        return $grouped;
    }
}
