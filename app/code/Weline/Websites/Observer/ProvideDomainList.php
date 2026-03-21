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
use Weline\Websites\Service\WebsiteSslCertificateStatusService;

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
        
        $rows = $query->order(WebsiteDomain::schema_fields_ROOT_DOMAIN, 'ASC')
            ->order(WebsiteDomain::schema_fields_DOMAIN, 'ASC')
            ->select()
            ->fetchArray();

        /** @var WebsiteSslCertificateStatusService $sslStatusService */
        $sslStatusService = ObjectManager::getInstance(WebsiteSslCertificateStatusService::class);
        $domains = [];
        foreach ($rows as $row) {
            $preferredCertId = !empty($row[WebsiteDomain::schema_fields_CERT_ID])
                ? (int) $row[WebsiteDomain::schema_fields_CERT_ID]
                : null;
            $managedCertId = $this->resolveManagedCertificateId(
                $sslStatusService,
                (string) ($row[WebsiteDomain::schema_fields_DOMAIN] ?? ''),
                $preferredCertId
            );

            if (isset($filter['has_certificate']) && (bool) $filter['has_certificate'] !== ($managedCertId !== null)) {
                continue;
            }

            $domains[] = [
                'domain' => $row[WebsiteDomain::schema_fields_DOMAIN],
                'root_domain' => $row[WebsiteDomain::schema_fields_ROOT_DOMAIN],
                'website_id' => (int) $row[WebsiteDomain::schema_fields_WEBSITE_ID],
                'cert_id' => $managedCertId,
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

        /** @var WebsiteSslCertificateStatusService $sslStatusService */
        $sslStatusService = ObjectManager::getInstance(WebsiteSslCertificateStatusService::class);
        $domains = [];
        foreach ($rows as $row) {
            $preferredCertId = !empty($row[DomainPool::schema_fields_CERT_ID])
                ? (int) $row[DomainPool::schema_fields_CERT_ID]
                : null;
            $managedCertId = $this->resolveManagedCertificateId(
                $sslStatusService,
                (string) ($row[DomainPool::schema_fields_DOMAIN] ?? ''),
                $preferredCertId
            );

            if (isset($filter['has_certificate']) && (bool) $filter['has_certificate'] !== ($managedCertId !== null)) {
                continue;
            }

            $domains[] = [
                'domain' => $row[DomainPool::schema_fields_DOMAIN],
                'root_domain' => $row[DomainPool::schema_fields_ROOT_DOMAIN],
                'description' => $row[DomainPool::schema_fields_DESCRIPTION] ?? '',
                'cert_id' => $managedCertId,
                'https_enabled' => $managedCertId !== null,
                'source' => 'domain_pool',
            ];
        }
        
        return $domains;
    }

    private function resolveManagedCertificateId(
        WebsiteSslCertificateStatusService $sslStatusService,
        string $domain,
        ?int $preferredCertId
    ): ?int {
        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return null;
        }

        try {
            $cert = $sslStatusService->resolveManagedCertificate($preferredCertId, $domain);
        } catch (\Throwable) {
            return $preferredCertId !== null && $preferredCertId > 0 ? $preferredCertId : null;
        }

        $certId = (int) ($cert['cert_id'] ?? 0);

        return $certId > 0 ? $certId : null;
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
