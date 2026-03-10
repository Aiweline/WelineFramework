<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名池模型
 * 
 * 全局域名池，存储可用于建站的具体域名（如 www.example.com）
 * 包含解析状态、HTTPS 状态、建站就绪状态
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 域名池模型
 * 
 * 存储可建站的具体域名（子域名）
 * - 关联根域名（Domain）
 * - 包含解析状态、HTTPS 证书状态
 * - 自动计算建站就绪状态（site_ready）
 */
#[Table(comment: '域名池表')]
#[Index(name: 'uk_domain', columns: ['domain'], type: 'UNIQUE')]
#[Index(name: 'idx_parent_domain', columns: ['parent_domain_id'])]
#[Index(name: 'idx_root_domain', columns: ['root_domain'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_resolve_status', columns: ['resolve_status'])]
#[Index(name: 'idx_dns_status', columns: ['dns_status'])]
#[Index(name: 'idx_cdn_status', columns: ['cdn_status'])]
#[Index(name: 'idx_https_status', columns: ['https_status'])]
#[Index(name: 'idx_site_ready', columns: ['site_ready'])]
#[Index(name: 'idx_site_created', columns: ['site_created'])]
#[Index(name: 'idx_cert', columns: ['cert_id'])]
class DomainPool extends Model
{
    public const schema_table = 'weline_websites_domain_pool';
    public const schema_primary_key = 'pool_id';

    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '域名池ID')]
    public const schema_fields_ID = 'pool_id';
    #[Col('int', 11, nullable: true, default: 0, comment: '关联根域名ID')]
    public const schema_fields_PARENT_DOMAIN_ID = 'parent_domain_id';
    #[Col('varchar', 255, nullable: false, comment: '完整域名')]
    public const schema_fields_DOMAIN = 'domain';
    #[Col('varchar', 255, nullable: true, default: '', comment: '根域名')]
    public const schema_fields_ROOT_DOMAIN = 'root_domain';
    #[Col('varchar', 500, nullable: true, default: '', comment: '域名描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('varchar', 20, nullable: true, default: 'active', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 20, nullable: true, default: 'pending', comment: '解析状态')]
    public const schema_fields_RESOLVE_STATUS = 'resolve_status';
    #[Col('varchar', 20, nullable: true, default: 'pending', comment: 'DNS就绪状态')]
    public const schema_fields_DNS_STATUS = 'dns_status';
    #[Col('varchar', 20, nullable: true, default: 'pending', comment: 'CDN就绪状态')]
    public const schema_fields_CDN_STATUS = 'cdn_status';
    #[Col('varchar', 45, nullable: true, default: '', comment: '解析到的IPv4')]
    public const schema_fields_RESOLVED_IP = 'resolved_ip';
    #[Col('varchar', 45, nullable: true, default: '', comment: '解析到的IPv6')]
    public const schema_fields_RESOLVED_IPV6 = 'resolved_ipv6';
    #[Col('smallint', 1, nullable: true, default: 0, comment: '是否指向本服务器')]
    public const schema_fields_IS_LOCAL_SERVER = 'is_local_server';
    #[Col('datetime', nullable: true, comment: '解析检测时间')]
    public const schema_fields_RESOLVE_CHECKED_AT = 'resolve_checked_at';
    #[Col('text', nullable: true, comment: '解析错误信息')]
    public const schema_fields_RESOLVE_ERROR = 'resolve_error';
    #[Col('varchar', 20, nullable: true, default: 'none', comment: 'HTTPS证书状态')]
    public const schema_fields_HTTPS_STATUS = 'https_status';
    #[Col('date', nullable: true, comment: '证书过期时间')]
    public const schema_fields_HTTPS_EXPIRES_AT = 'https_expires_at';
    #[Col('text', nullable: true, comment: '证书申请错误')]
    public const schema_fields_HTTPS_ERROR = 'https_error';
    #[Col('int', 11, nullable: true, comment: '关联SSL证书ID')]
    public const schema_fields_CERT_ID = 'cert_id';
    #[Col('smallint', 1, nullable: true, default: 0, comment: '是否可建站')]
    public const schema_fields_SITE_READY = 'site_ready';
    #[Col('smallint', 1, nullable: true, default: 0, comment: '是否已建站（已被网站使用，创建站点时不再展示）')]
    public const schema_fields_SITE_CREATED = 'site_created';
    #[Col('varchar', 50, nullable: true, default: '', comment: 'DNS服务商代码')]
    public const schema_fields_DNS_PROVIDER = 'dns_provider';
    #[Col('datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    
    // 状态常量
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    
    // 解析状态常量
    public const RESOLVE_STATUS_PENDING = 'pending';
    public const RESOLVE_STATUS_RESOLVING = 'resolving';  // 解析中
    public const RESOLVE_STATUS_RESOLVED = 'resolved';
    public const RESOLVE_STATUS_ERROR = 'error';

    // DNS/CDN 状态常量
    public const INFRA_STATUS_PENDING = 'pending';
    public const INFRA_STATUS_READY = 'ready';
    public const INFRA_STATUS_ERROR = 'error';
    
    // HTTPS 状态常量
    public const HTTPS_STATUS_NONE = 'none';
    public const HTTPS_STATUS_PENDING = 'pending';
    public const HTTPS_STATUS_VALID = 'valid';
    public const HTTPS_STATUS_EXPIRED = 'expired';
    public const HTTPS_STATUS_ERROR = 'error';
    
    /**
     * 保存前自动更新时间戳并解析根域名
     */
    public function save_before(): void
    {
        parent::save_before();
        
        $now = \date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        
        if (!$this->getData(self::schema_fields_ID)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        
        // 域名转小写并解析根域
        $domain = $this->getData(self::schema_fields_DOMAIN);
        if ($domain) {
            $domain = \strtolower(\trim($domain));
            $this->setData(self::schema_fields_DOMAIN, $domain);
            
            // 使用 PSL 库解析根域名
            $rootDomain = $this->parseRootDomain($domain);
            $this->setData(self::schema_fields_ROOT_DOMAIN, $rootDomain);
        }
    }
    
    /**
     * 使用 DomainParserService 解析根域名
     */
    protected function parseRootDomain(string $domain): string
    {
        try {
            /** @var \Weline\Websites\Service\DomainParserService $parser */
            $parser = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Websites\Service\DomainParserService::class
            );
            return $parser->parseRootDomain($domain);
        } catch (\Throwable $e) {
            // 回退到简单解析
            $parts = \explode('.', $domain);
            if (\count($parts) >= 2) {
                return $parts[\count($parts) - 2] . '.' . $parts[\count($parts) - 1];
            }
            return $domain;
        }
    }
    
    // =============== Getter/Setter 方法 ===============
    
    public function getPoolId(): int
    {
        return (int) $this->getData(self::schema_fields_ID);
    }
    
    public function setDomain(string $domain): self
    {
        $this->setData(self::schema_fields_DOMAIN, \strtolower(\trim($domain)));
        return $this;
    }
    
    public function getDomain(): string
    {
        return (string) $this->getData(self::schema_fields_DOMAIN);
    }
    
    public function getRootDomain(): string
    {
        return (string) $this->getData(self::schema_fields_ROOT_DOMAIN);
    }
    
    public function setDescription(string $description): self
    {
        $this->setData(self::schema_fields_DESCRIPTION, $description);
        return $this;
    }
    
    public function getDescription(): string
    {
        return (string) $this->getData(self::schema_fields_DESCRIPTION);
    }
    
    public function setStatus(string $status): self
    {
        $this->setData(self::schema_fields_STATUS, $status);
        return $this;
    }
    
    public function getStatus(): string
    {
        return (string) $this->getData(self::schema_fields_STATUS);
    }
    
    // =============== 解析相关 Getter/Setter ===============
    
    public function setParentDomainId(int $parentDomainId): self
    {
        $this->setData(self::schema_fields_PARENT_DOMAIN_ID, $parentDomainId);
        return $this;
    }
    
    public function getParentDomainId(): int
    {
        return (int) $this->getData(self::schema_fields_PARENT_DOMAIN_ID);
    }
    
    public function setResolveStatus(string $status): self
    {
        $this->setData(self::schema_fields_RESOLVE_STATUS, $status);
        return $this;
    }
    
    public function getResolveStatus(): string
    {
        return (string) ($this->getData(self::schema_fields_RESOLVE_STATUS) ?: self::RESOLVE_STATUS_PENDING);
    }

    public function setDnsStatus(string $status): self
    {
        $this->setData(self::schema_fields_DNS_STATUS, $status);
        return $this;
    }

    public function getDnsStatus(): string
    {
        return (string) ($this->getData(self::schema_fields_DNS_STATUS) ?: self::INFRA_STATUS_PENDING);
    }

    public function setCdnStatus(string $status): self
    {
        $this->setData(self::schema_fields_CDN_STATUS, $status);
        return $this;
    }

    public function getCdnStatus(): string
    {
        return (string) ($this->getData(self::schema_fields_CDN_STATUS) ?: self::INFRA_STATUS_PENDING);
    }
    
    public function setResolvedIp(string $ip): self
    {
        $this->setData(self::schema_fields_RESOLVED_IP, $ip);
        return $this;
    }
    
    public function getResolvedIp(): string
    {
        return (string) $this->getData(self::schema_fields_RESOLVED_IP);
    }
    
    public function setResolvedIpv6(string $ip): self
    {
        $this->setData(self::schema_fields_RESOLVED_IPV6, $ip);
        return $this;
    }
    
    public function getResolvedIpv6(): string
    {
        return (string) $this->getData(self::schema_fields_RESOLVED_IPV6);
    }
    
    public function setIsLocalServer(bool $isLocal): self
    {
        $this->setData(self::schema_fields_IS_LOCAL_SERVER, $isLocal ? 1 : 0);
        return $this;
    }
    
    public function isLocalServer(): bool
    {
        return (int) $this->getData(self::schema_fields_IS_LOCAL_SERVER) === 1;
    }
    
    public function setResolveCheckedAt(string $datetime): self
    {
        $this->setData(self::schema_fields_RESOLVE_CHECKED_AT, $datetime);
        return $this;
    }
    
    public function getResolveCheckedAt(): string
    {
        return (string) $this->getData(self::schema_fields_RESOLVE_CHECKED_AT);
    }
    
    public function setResolveError(string $error): self
    {
        $this->setData(self::schema_fields_RESOLVE_ERROR, $error);
        return $this;
    }
    
    public function getResolveError(): string
    {
        return (string) $this->getData(self::schema_fields_RESOLVE_ERROR);
    }
    
    // =============== HTTPS 相关 Getter/Setter ===============
    
    public function setHttpsStatus(string $status): self
    {
        $this->setData(self::schema_fields_HTTPS_STATUS, $status);
        return $this;
    }
    
    public function getHttpsStatus(): string
    {
        return (string) ($this->getData(self::schema_fields_HTTPS_STATUS) ?: self::HTTPS_STATUS_NONE);
    }
    
    public function setHttpsExpiresAt(?string $date): self
    {
        $this->setData(self::schema_fields_HTTPS_EXPIRES_AT, $date);
        return $this;
    }
    
    public function getHttpsExpiresAt(): ?string
    {
        $value = $this->getData(self::schema_fields_HTTPS_EXPIRES_AT);
        return $value ? (string) $value : null;
    }
    
    public function setHttpsError(string $error): self
    {
        $this->setData(self::schema_fields_HTTPS_ERROR, $error);
        return $this;
    }
    
    public function getHttpsError(): string
    {
        return (string) $this->getData(self::schema_fields_HTTPS_ERROR);
    }
    
    public function setCertId(?int $certId): self
    {
        $this->setData(self::schema_fields_CERT_ID, $certId);
        return $this;
    }
    
    public function getCertId(): ?int
    {
        $value = $this->getData(self::schema_fields_CERT_ID);
        return $value !== null && $value !== '' ? (int) $value : null;
    }
    
    // =============== 建站就绪 Getter/Setter ===============
    
    public function setSiteReady(bool $ready): self
    {
        $this->setData(self::schema_fields_SITE_READY, $ready ? 1 : 0);
        return $this;
    }
    
    public function isSiteReady(): bool
    {
        return (int) $this->getData(self::schema_fields_SITE_READY) === 1;
    }
    
    public function setSiteCreated(bool $created): self
    {
        $this->setData(self::schema_fields_SITE_CREATED, $created ? 1 : 0);
        return $this;
    }
    
    public function isSiteCreated(): bool
    {
        return (int) $this->getData(self::schema_fields_SITE_CREATED) === 1;
    }
    
    /**
     * 根据 website_domain 表同步所有 pool 的 site_created 状态
     * 调用时机：saveWebsiteDomains 后、或 Upgrade 时回填
     */
    public function syncSiteCreatedFromWebsiteDomainTable(): void
    {
        /** @var \Weline\Websites\Model\WebsiteDomain $wdModel */
        $wdModel = \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Websites\Model\WebsiteDomain::class
        );
        $rows = $wdModel->clearQuery()
            ->fields(WebsiteDomain::schema_fields_POOL_ID)
            ->where(WebsiteDomain::schema_fields_POOL_ID, 0, '>')
            ->select()
            ->fetchArray();
        $poolIds = array_values(array_unique(array_filter(array_column($rows, WebsiteDomain::schema_fields_POOL_ID))));
        
        // 1. 在 website_domain 中有记录的 pool 设为 site_created=1
        if (!empty($poolIds)) {
            $this->clearQuery()
                ->where(self::schema_fields_ID, $poolIds, 'IN')
                ->setData(self::schema_fields_SITE_CREATED, 1)
                ->update()
                ->fetch();
        }
        
        // 2. 不在 website_domain 中的 pool 设为 site_created=0
        $base = $this->clearQuery();
        if (!empty($poolIds)) {
            $base->where(self::schema_fields_ID, $poolIds, 'NOT IN');
        }
        $base->setData(self::schema_fields_SITE_CREATED, 0)->update()->fetch();
    }
    
    // =============== DNS 服务商 Getter/Setter ===============
    
    public function getDnsProvider(): string
    {
        return (string) $this->getData(self::schema_fields_DNS_PROVIDER);
    }
    
    public function setDnsProvider(string $provider): self
    {
        $this->setData(self::schema_fields_DNS_PROVIDER, $provider);
        return $this;
    }
    
    /**
     * 计算并更新建站就绪状态
     * 
     * 条件：解析正常 + 指向本服务器 + HTTPS 有效
     */
    public function calculateSiteReady(): bool
    {
        $isReady = $this->getResolveStatus() === self::RESOLVE_STATUS_RESOLVED
            && $this->getDnsStatus() === self::INFRA_STATUS_READY
            && $this->getCdnStatus() === self::INFRA_STATUS_READY
            && $this->isLocalServer()
            && $this->getHttpsStatus() === self::HTTPS_STATUS_VALID;
        
        $this->setSiteReady($isReady);
        return $isReady;
    }
    
    // =============== 业务方法 ===============
    
    /**
     * 获取按根域分组的所有可选域名
     * 
     * @return array<string, array> 以根域为键的分组数据
     */
    public function getDomainsGroupedByRoot(): array
    {
        $domains = $this->clearQuery()
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::schema_fields_ROOT_DOMAIN, 'ASC')
            ->order(self::schema_fields_DOMAIN, 'ASC')
            ->select()
            ->fetchArray();
        
        $grouped = [];
        foreach ($domains as $domain) {
            $rootDomain = $domain[self::schema_fields_ROOT_DOMAIN] ?: $domain[self::schema_fields_DOMAIN];
            if (!isset($grouped[$rootDomain])) {
                $grouped[$rootDomain] = [];
            }
            $grouped[$rootDomain][] = $domain;
        }
        
        return $grouped;
    }
    
    /**
     * 根据域名查找记录
     */
    public function loadByDomain(string $domain): self
    {
        $this->clearQuery()
            ->where(self::schema_fields_DOMAIN, \strtolower(\trim($domain)))
            ->find()
            ->fetch();
        return $this;
    }
    
    /**
     * 获取所有活跃域名列表
     */
    public function getActiveDomains(): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::schema_fields_ROOT_DOMAIN, 'ASC')
            ->order(self::schema_fields_DOMAIN, 'ASC')
            ->select()
            ->fetchArray();
    }
    
    /**
     * 根据根域获取所有子域名
     */
    public function getDomainsByRoot(string $rootDomain): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_ROOT_DOMAIN, \strtolower(\trim($rootDomain)))
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::schema_fields_DOMAIN, 'ASC')
            ->select()
            ->fetchArray();
    }
    
    /**
     * 批量添加域名到池
     * 
     * @param array $domains 域名数组
     * @return int 成功添加的数量
     */
    public function addDomainsToPool(array $domains): int
    {
        $added = 0;
        foreach ($domains as $domain) {
            $domainStr = \is_array($domain) ? ($domain['domain'] ?? '') : $domain;
            $description = \is_array($domain) ? ($domain['description'] ?? '') : '';
            
            if (empty($domainStr)) {
                continue;
            }
            
            // 检查是否已存在
            $this->clearData(true);
            $existing = $this->loadByDomain($domainStr);
            if ($existing->getPoolId()) {
                continue;
            }
            
            // 添加新域名
            $newModel = clone $this;
            $newModel->clearData();
            $newModel->setDomain($domainStr);
            $newModel->setDescription($description);
            $newModel->setStatus(self::STATUS_ACTIVE);
            $newModel->save();
            $added++;
        }
        
        return $added;
    }
    
    /**
     * 获取可选的建站域名（用于创建站点时选择）
     * 条件：site_ready=1 且 site_created=0（未被任何站点使用）
     *
     * @return array<string, array>
     */
    public function getSelectableForSiteGroupedByRoot(): array
    {
        $siteCreatedCondition = '(' . self::schema_fields_SITE_CREATED . ' IS NULL OR ' . self::schema_fields_SITE_CREATED . ' = 0)';
        $domains = $this->clearQuery()
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->where(self::schema_fields_SITE_READY, 1)
            ->whereRaw($siteCreatedCondition, 'AND')
            ->order(self::schema_fields_ROOT_DOMAIN, 'ASC')
            ->order(self::schema_fields_DOMAIN, 'ASC')
            ->select()
            ->fetchArray();
        $grouped = [];
        foreach ($domains as $domain) {
            $rootDomain = $domain[self::schema_fields_ROOT_DOMAIN] ?: $domain[self::schema_fields_DOMAIN];
            if (!isset($grouped[$rootDomain])) {
                $grouped[$rootDomain] = [];
            }
            $grouped[$rootDomain][] = $domain;
        }
        return $grouped;
    }

    /**
     * 获取域名选择器数据（用于创建站点时选择）
     * 仅返回可建站且未已被使用的域名
     *
     * @return array
     */
    public function getSelectOptions(): array
    {
        $grouped = $this->getSelectableForSiteGroupedByRoot();
        $options = [];
        
        foreach ($grouped as $rootDomain => $domains) {
            $group = [
                'label' => $rootDomain,
                'options' => []
            ];
            
            foreach ($domains as $domain) {
                $group['options'][] = [
                    'value' => $domain[self::schema_fields_DOMAIN],
                    'label' => $domain[self::schema_fields_DOMAIN],
                    'description' => $domain[self::schema_fields_DESCRIPTION] ?? ''
                ];
            }
            
            $options[] = $group;
        }
        
        return $options;
    }
    
    /**
     * 获取所有建站就绪的域名
     * 
     * @return array
     */
    public function getReadyDomains(): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->where(self::schema_fields_SITE_READY, 1)
            ->order(self::schema_fields_ROOT_DOMAIN, 'ASC')
            ->order(self::schema_fields_DOMAIN, 'ASC')
            ->select()
            ->fetchArray();
    }
    
    /**
     * 获取建站就绪的域名（按根域名分组）
     * 
     * @return array<string, array>
     */
    public function getReadyDomainsGroupedByRoot(): array
    {
        $domains = $this->getReadyDomains();
        
        $grouped = [];
        foreach ($domains as $domain) {
            $rootDomain = $domain[self::schema_fields_ROOT_DOMAIN] ?: $domain[self::schema_fields_DOMAIN];
            if (!isset($grouped[$rootDomain])) {
                $grouped[$rootDomain] = [];
            }
            $grouped[$rootDomain][] = $domain;
        }
        
        return $grouped;
    }
    
    /**
     * 同步证书状态
     * 
     * 从关联的证书信息同步证书状态
     * 此方法可被证书管理服务调用来更新域名池的 HTTPS 状态
     * 
     * @param string $status 证书状态
     * @param string|null $expiresAt 过期时间
     * @param string $error 错误信息
     * @return self
     */
    public function syncCertificateStatus(string $status, ?string $expiresAt = null, string $error = ''): self
    {
        if ($status === 'revoked' || $status === 'error') {
            $this->setHttpsStatus(self::HTTPS_STATUS_ERROR);
        } elseif ($expiresAt && \strtotime($expiresAt) < \time()) {
            $this->setHttpsStatus(self::HTTPS_STATUS_EXPIRED);
        } elseif ($status === 'active' || $status === 'valid') {
            $this->setHttpsStatus(self::HTTPS_STATUS_VALID);
        } elseif ($status === 'pending' || $status === 'requesting') {
            $this->setHttpsStatus(self::HTTPS_STATUS_PENDING);
        } else {
            $this->setHttpsStatus(self::HTTPS_STATUS_NONE);
        }
        
        $this->setHttpsExpiresAt($expiresAt);
        $this->setHttpsError($error);
        $this->calculateSiteReady();
        
        return $this;
    }
    
    /**
     * 清除证书关联
     * 
     * @return self
     */
    public function clearCertificate(): self
    {
        $this->setCertId(null);
        $this->setHttpsStatus(self::HTTPS_STATUS_NONE);
        $this->setHttpsExpiresAt(null);
        $this->setHttpsError('');
        $this->calculateSiteReady();
        
        return $this;
    }
    
    /**
     * 根据 pool_id 加载记录
     * 
     * @param int $poolId
     * @return self
     */
    public function loadByPoolId(int $poolId): self
    {
        $this->clearQuery()
            ->where(self::schema_fields_ID, $poolId)
            ->find()
            ->fetch();
        return $this;
    }
    
    /**
     * 获取所有未建站就绪的域名（用于全流程处理：DNS 检测 + HTTPS 申请）
     *
     * @param int $limit
     * @return array
     */
    public function getDomainsNotSiteReady(int $limit = 100): array
    {
        $siteReadyCondition = '(' . self::schema_fields_SITE_READY . ' IS NULL OR ' . self::schema_fields_SITE_READY . ' = 0)';
        return $this->clearQuery()
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->whereRaw($siteReadyCondition, 'AND')
            ->order(self::schema_fields_RESOLVE_CHECKED_AT, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();
    }

    /**
     * 获取需要检测解析的域名列表（仅未建站就绪的域名，就绪后不再检测）
     *
     * @param int $limit
     * @return array
     */
    public function getDomainsNeedResolveCheck(int $limit = 100): array
    {
        // 使用数据库函数避免 PHP 日期字符串中的冒号被 PDO 误解析为命名参数（PostgreSQL: time zone "p11:p40" not recognized）
        $thresholdExpr = "(NOW() - INTERVAL '10 minutes')";
        $siteReadyCondition = '(' . self::schema_fields_SITE_READY . ' IS NULL OR ' . self::schema_fields_SITE_READY . ' = 0)';
        $resolveCheckedCondition = '(' . self::schema_fields_RESOLVE_CHECKED_AT . ' IS NULL OR '
            . self::schema_fields_RESOLVE_CHECKED_AT . ' < ' . $thresholdExpr . ')';

        return $this->clearQuery()
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->whereRaw($siteReadyCondition, 'AND')
            ->whereRaw($resolveCheckedCondition, 'AND')
            ->order(self::schema_fields_RESOLVE_CHECKED_AT, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();
    }
    
    /**
     * 获取需要申请证书的域名列表（仅未建站就绪的域名）
     *
     * 条件：解析正常 + 指向本服务器 + 没有有效证书 + 未建站就绪
     *
     * @param int $limit
     * @return array
     */
    public function getDomainsNeedCertificate(int $limit = 50): array
    {
        $siteReadyCondition = '(' . self::schema_fields_SITE_READY . ' IS NULL OR ' . self::schema_fields_SITE_READY . ' = 0)';

        return $this->clearQuery()
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->whereRaw($siteReadyCondition, 'AND')
            ->where(self::schema_fields_RESOLVE_STATUS, self::RESOLVE_STATUS_RESOLVED)
            ->where(self::schema_fields_DNS_STATUS, self::INFRA_STATUS_READY)
            ->where(self::schema_fields_CDN_STATUS, self::INFRA_STATUS_READY)
            ->where(self::schema_fields_IS_LOCAL_SERVER, 1)
            ->where(self::schema_fields_HTTPS_STATUS, [self::HTTPS_STATUS_NONE, self::HTTPS_STATUS_EXPIRED, self::HTTPS_STATUS_ERROR], 'IN')
            ->order(self::schema_fields_HTTPS_STATUS, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();
    }
    
    /**
     * 根据根域名ID获取所有子域名
     * 
     * @param int $parentDomainId
     * @return array
     */
    public function getByParentDomainId(int $parentDomainId): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_PARENT_DOMAIN_ID, $parentDomainId)
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::schema_fields_DOMAIN, 'ASC')
            ->select()
            ->fetchArray();
    }
}
