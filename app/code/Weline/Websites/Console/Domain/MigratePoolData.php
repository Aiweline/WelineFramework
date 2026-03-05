<?php

declare(strict_types=1);

/**
 * v1.6.0 数据迁移命令
 * 
 * 功能：
 * 1. 将 Domain 模型中的解析/HTTPS 状态数据迁移到 DomainPool
 * 2. 为 WebsiteDomain 关联 pool_id
 * 3. 生成缺失的子域名到 DomainPool
 */

namespace Weline\Websites\Console\Domain;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Service\SubdomainGeneratorService;

class MigratePoolData extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        $printing->printing(__('开始 v1.6.0 域名池数据迁移...'), 'warning');
        
        $dryRun = in_array('--dry-run', $args, true);
        if ($dryRun) {
            $printing->printing(__('（试运行模式，不会实际修改数据）'), 'note');
        }
        
        $stats = [
            'domains_processed' => 0,
            'pool_created' => 0,
            'pool_updated' => 0,
            'website_domain_linked' => 0,
            'errors' => [],
        ];
        
        // 1. 从 Domain 生成子域名到 DomainPool
        $printing->printing(__('步骤 1: 为根域名生成子域名到域名池...'), 'info');
        $domainModel = ObjectManager::getInstance(Domain::class);
        $domains = $domainModel->clearQuery()
            ->where(Domain::schema_fields_STATUS, Domain::STATUS_ACTIVE)
            ->select()
            ->fetchArray();
        
        $subdomainGenerator = ObjectManager::getInstance(SubdomainGeneratorService::class);
        
        foreach ($domains as $row) {
            $stats['domains_processed']++;
            $domain = ObjectManager::getInstance(Domain::class, [], false);
            $domain->setData($row);
            
            $printing->printing(__('  处理根域名: %{1}', [$domain->getDomain()]), 'debug');
            
            if (!$dryRun) {
                try {
                    $result = $subdomainGenerator->generateDefaultSubdomains($domain);
                    $stats['pool_created'] += $result['added'];
                    if ($result['added'] > 0) {
                        $printing->printing(__('    新增 %{1} 个子域名', [$result['added']]), 'success');
                    }
                } catch (\Throwable $e) {
                    $stats['errors'][] = $domain->getDomain() . ': ' . $e->getMessage();
                    $printing->printing(__('    错误: %{1}', [$e->getMessage()]), 'error');
                }
            }
        }
        
        // 2. 迁移 Domain 的解析状态到 DomainPool（如果 Domain 有这些字段）
        $printing->printing(__('步骤 2: 迁移解析状态数据...'), 'info');
        $poolModel = ObjectManager::getInstance(DomainPool::class);
        $pools = $poolModel->clearQuery()->select()->fetchArray();
        
        foreach ($pools as $poolRow) {
            $poolDomain = ObjectManager::getInstance(DomainPool::class, [], false);
            $poolDomain->setData($poolRow);
            
            // 检查是否有关联的根域名
            $parentDomainId = $poolDomain->getParentDomainId();
            if ($parentDomainId > 0) {
                $parentDomain = ObjectManager::getInstance(Domain::class, [], false);
                $parentDomain->load($parentDomainId);
                
                // 如果父域名有解析数据且 DomainPool 没有，则迁移
                if ($parentDomain->getDomainId()) {
                    $needUpdate = false;
                    
                    // 检查 resolved_ip（如果 DomainPool 没有但 Domain 有）
                    $parentResolvedIp = $parentDomain->getData('resolved_ip') ?? '';
                    if (!empty($parentResolvedIp) && empty($poolDomain->getResolvedIp())) {
                        if (!$dryRun) {
                            $poolDomain->setResolvedIp($parentResolvedIp);
                        }
                        $needUpdate = true;
                    }
                    
                    if ($needUpdate && !$dryRun) {
                        $poolDomain->save();
                        $stats['pool_updated']++;
                    }
                }
            }
        }
        
        // 3. 为 WebsiteDomain 关联 pool_id
        $printing->printing(__('步骤 3: 为 WebsiteDomain 关联 pool_id...'), 'info');
        $websiteDomainModel = ObjectManager::getInstance(WebsiteDomain::class);
        $websiteDomains = $websiteDomainModel->clearQuery()
            ->where(WebsiteDomain::schema_fields_POOL_ID, 0)
            ->select()
            ->fetchArray();
        
        foreach ($websiteDomains as $wdRow) {
            $wd = ObjectManager::getInstance(WebsiteDomain::class, [], false);
            $wd->setData($wdRow);
            
            $domain = $wd->getDomain();
            if (empty($domain)) {
                continue;
            }
            
            // 查找 DomainPool 中匹配的记录
            $matchingPool = ObjectManager::getInstance(DomainPool::class, [], false);
            $matchingPool->clearQuery()
                ->where(DomainPool::schema_fields_DOMAIN, strtolower($domain))
                ->find()
                ->fetch();
            
            if ($matchingPool->getPoolId()) {
                $printing->printing(__('  关联 %{1} -> pool_id=%{2}', [$domain, $matchingPool->getPoolId()]), 'debug');
                
                if (!$dryRun) {
                    $wd->setPoolId($matchingPool->getPoolId());
                    $wd->save();
                    $stats['website_domain_linked']++;
                }
            } else {
                $printing->printing(__('  未找到匹配的池域名: %{1}', [$domain]), 'warning');
            }
        }
        
        // 输出统计
        $printing->printing('', 'info');
        $printing->printing(__('迁移完成！统计：'), 'success');
        $printing->printing(__('  - 处理根域名: %{1}', [$stats['domains_processed']]), 'info');
        $printing->printing(__('  - 新建池域名: %{1}', [$stats['pool_created']]), 'info');
        $printing->printing(__('  - 更新池域名: %{1}', [$stats['pool_updated']]), 'info');
        $printing->printing(__('  - 关联 WebsiteDomain: %{1}', [$stats['website_domain_linked']]), 'info');
        
        if (!empty($stats['errors'])) {
            $printing->printing(__('  - 错误数: %{1}', [count($stats['errors'])]), 'error');
            foreach ($stats['errors'] as $error) {
                $printing->printing(__('    %{1}', [$error]), 'error');
            }
        }
        
        if ($dryRun) {
            $printing->printing(__('（试运行模式已完成，移除 --dry-run 参数以实际执行）'), 'note');
        }
        
        return __('迁移完成');
    }
    
    public function tip(): string
    {
        return __('v1.6.0 域名池数据迁移');
    }
}
