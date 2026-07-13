<?php
declare(strict_types=1);

/**
 * Weline Websites - 网站域名模型
 * 
 * 支持每个网站配置多个域名
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;

/**
 * 网站域名模型
 * 
 * 每个网站可以关联多个域名
 * 
 * 注意：v1.6.0 开始推荐使用 pool_id 关联 DomainPool 模型
 * domain 字段保留用于向后兼容
 */
#[Table(comment: '网站域名表')]
#[Index(name: 'uk_domain_subpath', columns: ['domain', 'sub_path'], type: 'UNIQUE')]
#[Index(name: 'idx_website', columns: ['website_id'])]
#[Index(name: 'idx_pool', columns: ['pool_id'])]
#[Index(name: 'idx_root_domain', columns: ['root_domain'])]
#[Index(name: 'idx_cert', columns: ['cert_id'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_health', columns: ['health_status'])]
class WebsiteDomain extends Model
{
    public const schema_table = 'weline_websites_website_domain';
    public const schema_primary_key = 'domain_id';


    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '域名ID')]
    public const schema_fields_ID = 'domain_id';
    #[Col('int', 11, nullable: false, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('int', 11, nullable: true, comment: '关联域名池ID')]
    public const schema_fields_POOL_ID = 'pool_id';
    #[Col('varchar', 255, nullable: false, comment: '域名')]
    public const schema_fields_DOMAIN = 'domain';
    #[Col('varchar', 255, nullable: true, default: '', comment: '根域名（自动解析）')]
    public const schema_fields_ROOT_DOMAIN = 'root_domain';
    #[Col('varchar', 255, nullable: true, default: '', comment: '子路径')]
    public const schema_fields_SUB_PATH = 'sub_path';
    #[Col('int', 11, nullable: true, comment: '关联的SSL证书ID')]
    public const schema_fields_CERT_ID = 'cert_id';
    #[Col('int', 1, nullable: true, default: 0, comment: '是否主域名')]
    public const schema_fields_IS_PRIMARY = 'is_primary';
    #[Col('int', 1, nullable: true, default: 0, comment: '启用HTTPS')]
    public const schema_fields_HTTPS_ENABLED = 'https_enabled';
    #[Col('varchar', 20, nullable: true, default: 'active', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 20, nullable: true, default: 'unknown', comment: '健康状态')]
    public const schema_fields_HEALTH_STATUS = 'health_status';
    #[Col('int', 4, nullable: true, comment: '健康检查HTTP状态码')]
    public const schema_fields_HEALTH_CODE = 'health_code';
    #[Col('varchar', 500, nullable: true, default: '', comment: '健康检查消息')]
    public const schema_fields_HEALTH_MESSAGE = 'health_message';
    #[Col('datetime', nullable: true, comment: '最后健康检查时间')]
    public const schema_fields_HEALTH_CHECKED_AT = 'health_checked_at';
    #[Col('datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    
    // 状态常量
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    
    // 健康状态常量
    public const HEALTH_HEALTHY = 'healthy';
    public const HEALTH_UNHEALTHY = 'unhealthy';
    public const HEALTH_UNKNOWN = 'unknown';
    
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
        
        // 域名转小写
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
     * 保存后清除网站缓存，使多域名探测与 Url 解析能加载到最新绑定域名
     */
    public function save_after(): void
    {
        parent::save_after();
        try {
            w_cache('website')->clear();
            \Weline\Framework\Http\Url::bumpWebsiteParserSitesVersion();
            \Weline\Websites\Observer\DetectWebsite::clearProcessCache();
        } catch (\Throwable $e) {
            // 忽略
        }
    }
    
    /**
     * 使用 DomainParserService 解析根域名
     * 
     * @param string $domain 完整域名（如 www.example.co.uk）
     * @return string 根域名（如 example.co.uk）
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
            return $this->fallbackParseRootDomain($domain);
        }
    }
    
    /**
     * 简单的根域解析回退逻辑
     * 
     * 仅用于 DomainParserService 不可用时
     */
    protected function fallbackParseRootDomain(string $domain): string
    {
        $parts = \explode('.', $domain);
        if (\count($parts) >= 2) {
            return $parts[\count($parts) - 2] . '.' . $parts[\count($parts) - 1];
        }
        return $domain;
    }
    
    // =============== Getter/Setter 方法 ===============
    
    public function getDomainId(): int
    {
        return (int) $this->getData(self::schema_fields_ID);
    }
    
    public function setWebsiteId(int $websiteId): self
    {
        $this->setData(self::schema_fields_WEBSITE_ID, $websiteId);
        return $this;
    }
    
    public function getWebsiteId(): int
    {
        return (int) $this->getData(self::schema_fields_WEBSITE_ID);
    }
    
    public function setPoolId(?int $poolId): self
    {
        $this->setData(self::schema_fields_POOL_ID, $poolId);
        return $this;
    }
    
    public function getPoolId(): ?int
    {
        $poolId = $this->getData(self::schema_fields_POOL_ID);
        return $poolId !== null && $poolId !== '' ? (int) $poolId : null;
    }
    
    /**
     * 从关联的 DomainPool 同步域名信息
     * 
     * @return self
     */
    public function syncFromPool(): self
    {
        $poolId = $this->getPoolId();
        if (!$poolId) {
            return $this;
        }
        
        try {
            $pool = ObjectManager::getInstance(DomainPool::class, [], false);
            $pool->loadByPoolId($poolId);
            
            if ($pool->getPoolId()) {
                $this->setDomain($pool->getDomain());
                // 同步 HTTPS 状态
                if ($pool->getHttpsStatus() === DomainPool::HTTPS_STATUS_VALID) {
                    $this->setHttpsEnabled(true);
                    $this->setCertId($pool->getCertId());
                }
            }
        } catch (\Throwable $e) {
            // 忽略错误
        }
        
        return $this;
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
    
    public function setIsPrimary(bool $isPrimary): self
    {
        $this->setData(self::schema_fields_IS_PRIMARY, $isPrimary ? 1 : 0);
        return $this;
    }
    
    public function isPrimary(): bool
    {
        return (bool) $this->getData(self::schema_fields_IS_PRIMARY);
    }
    
    public function setHttpsEnabled(bool $enabled): self
    {
        $this->setData(self::schema_fields_HTTPS_ENABLED, $enabled ? 1 : 0);
        return $this;
    }
    
    public function isHttpsEnabled(): bool
    {
        return (bool) $this->getData(self::schema_fields_HTTPS_ENABLED);
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
    
    public function getRootDomain(): string
    {
        return (string) $this->getData(self::schema_fields_ROOT_DOMAIN);
    }
    
    public function setSubPath(string $subPath): self
    {
        $this->setData(self::schema_fields_SUB_PATH, $subPath);
        return $this;
    }
    
    public function getSubPath(): string
    {
        return (string) $this->getData(self::schema_fields_SUB_PATH);
    }
    
    public function setCertId(?int $certId): self
    {
        $this->setData(self::schema_fields_CERT_ID, $certId);
        return $this;
    }
    
    public function getCertId(): ?int
    {
        $certId = $this->getData(self::schema_fields_CERT_ID);
        return $certId !== null ? (int) $certId : null;
    }
    
    public function setHealthStatus(string $status): self
    {
        $this->setData(self::schema_fields_HEALTH_STATUS, $status);
        return $this;
    }
    
    public function getHealthStatus(): string
    {
        return (string) ($this->getData(self::schema_fields_HEALTH_STATUS) ?: self::HEALTH_UNKNOWN);
    }
    
    public function setHealthCode(?int $code): self
    {
        $this->setData(self::schema_fields_HEALTH_CODE, $code);
        return $this;
    }
    
    public function getHealthCode(): ?int
    {
        $code = $this->getData(self::schema_fields_HEALTH_CODE);
        return $code !== null ? (int) $code : null;
    }
    
    public function setHealthMessage(string $message): self
    {
        $this->setData(self::schema_fields_HEALTH_MESSAGE, $message);
        return $this;
    }
    
    public function getHealthMessage(): string
    {
        return (string) $this->getData(self::schema_fields_HEALTH_MESSAGE);
    }
    
    public function setHealthCheckedAt(?string $datetime): self
    {
        $this->setData(self::schema_fields_HEALTH_CHECKED_AT, $datetime);
        return $this;
    }
    
    public function getHealthCheckedAt(): ?string
    {
        return $this->getData(self::schema_fields_HEALTH_CHECKED_AT) ?: null;
    }
    
    public function isHealthy(): bool
    {
        return $this->getHealthStatus() === self::HEALTH_HEALTHY;
    }
    
    /**
     * 获取有效的访问 URL
     * 
     * 根据域名是否有有效证书自动选择 http 或 https
     * 
     * @return string 完整的访问 URL（如 https://example.com 或 http://example.com）
     */
    public function getEffectiveUrl(): string
    {
        $domain = $this->getDomain();
        if (empty($domain)) {
            return '';
        }
        
        $protocol = $this->hasValidCertificate() ? 'https' : 'http';
        $subPath = $this->getSubPath();
        
        $url = $protocol . '://' . $domain;
        if (!empty($subPath)) {
            $url .= '/' . \ltrim($subPath, '/');
        }
        
        return $url;
    }
    
    /**
     * 检查域名是否有有效证书
     * 
     * 只有当 cert_id 存在且对应证书有效时返回 true
     */
    public function hasValidCertificate(): bool
    {
        $domain = \strtolower(\trim($this->getDomain()));
        if ($domain === '' || !\function_exists('w_query')) {
            return false;
        }

        $certId = (int)$this->getCertId();
        try {
            return (bool)\w_query('server', 'hasValidManagedCertificate', [
                'hostname' => $domain,
                'preferred_cert_id' => $certId > 0 ? $certId : null,
            ]);
        } catch (\Throwable) {
            return false;
        }
    }
    
    /**
     * 同步 HTTPS 状态
     * 
     * 根据证书状态自动更新 https_enabled 字段
     */
    public function syncHttpsStatus(): void
    {
        $hasValidCert = $this->hasValidCertificate();
        $this->setHttpsEnabled($hasValidCert);
    }
    
    // =============== 业务方法 ===============
    
    /**
     * 获取网站的所有域名
     */
    public function getWebsiteDomains(int $websiteId): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::schema_fields_IS_PRIMARY, 'DESC')
            ->select()
            ->fetchArray();
    }
    
    /**
     * 获取网站的主域名
     */
    public function getPrimaryDomain(int $websiteId): ?self
    {
        $this->clearQuery()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_IS_PRIMARY, 1)
            ->find()
            ->fetch();
        
        return $this->getDomainId() ? $this : null;
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
     * 检查 (域名, 子路径) 是否已被其他站点占用
     *
     * @param string $domain 域名
     * @param string $subPath 子路径（如 '' 或 '/shop'）
     * @param int|null $excludeWebsiteId 排除的网站 ID（编辑时传入当前站 id，不视为冲突）
     * @return array|null 若被占用返回 ['website_id' => int, 'website_name' => string]，否则 null
     */
    public function findConflict(string $domain, string $subPath, ?int $excludeWebsiteId = null): ?array
    {
        $domain = \strtolower(\trim($domain));
        $subPath = $subPath === '' ? '' : (\str_starts_with($subPath, '/') ? $subPath : '/' . $subPath);
        $query = $this->clearQuery()
            ->where(self::schema_fields_DOMAIN, $domain)
            ->where(self::schema_fields_SUB_PATH, $subPath);
        // 编辑时在查询层排除当前网站，避免把自己判为冲突
        if ($excludeWebsiteId !== null) {
            $query->where(self::schema_fields_WEBSITE_ID, $excludeWebsiteId, '!=');
        }
        $rows = $query->select()->fetchArray();
        if (empty($rows)) {
            return null;
        }
        $row = $rows[0];
        $websiteId = (int) ($row[self::schema_fields_WEBSITE_ID] ?? 0);
        if ($websiteId < Website::ID_DEFAULT) {
            return null;
        }
        $website = ObjectManager::getInstance(Website::class);
        $websiteRow = $website->clearQuery()->clearData()
            ->where(Website::schema_fields_ID, $websiteId)
            ->find()
            ->fetchArray();
        $name = \is_array($websiteRow) && \array_key_exists(Website::schema_fields_NAME, $websiteRow)
            ? (string) $websiteRow[Website::schema_fields_NAME]
            : (string) $websiteId;
        return ['website_id' => $websiteId, 'website_name' => $name];
    }
    
    /**
     * 获取所有启用 HTTPS 的域名
     */
    public function getHttpsEnabledDomains(): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_HTTPS_ENABLED, 1)
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->select()
            ->fetchArray();
    }
    
    /**
     * 设置主域名（同时取消其他主域名）
     */
    public function setPrimaryForWebsite(int $websiteId, int $domainId): bool
    {
        // 先取消所有主域名
        $this->clearQuery()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->setData(self::schema_fields_IS_PRIMARY, 0)
            ->update()
            ->fetch();
        
        // 设置新的主域名
        $this->clearQuery()
            ->where(self::schema_fields_ID, $domainId)
            ->setData(self::schema_fields_IS_PRIMARY, 1)
            ->update()
            ->fetch();
        
        return true;
    }
    
    /**
     * 获取按根域分组的所有域名
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
     * 根据根域查询所有子域名
     */
    public function getDomainsByRoot(string $rootDomain): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_ROOT_DOMAIN, $rootDomain)
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::schema_fields_DOMAIN, 'ASC')
            ->select()
            ->fetchArray();
    }
    
    /**
     * 更新同一域名的所有记录的 cert_id
     * 
     * 当证书签发成功后，更新所有使用该域名的 WebsiteDomain 记录
     */
    public function updateCertIdByDomain(string $domain, int $certId): void
    {
        $this->clearQuery()
            ->where(self::schema_fields_DOMAIN, \strtolower(\trim($domain)))
            ->setData(self::schema_fields_CERT_ID, $certId)
            ->update()
            ->fetch();
    }
    
    /**
     * 查询可选的域名池（用于建站选择）
     * 
     * 返回所有活跃域名，按根域分组展示
     */
    public function getDomainPoolGrouped(): array
    {
        return $this->getDomainsGroupedByRoot();
    }
    
    /**
     * 同步指定域名的 HTTPS 状态和 cert_id
     * 
     * 当证书签发成功后调用此方法
     * 
     * @param string $domain 域名
     * @param int $certId 证书 ID
     * @param bool $httpsEnabled 是否启用 HTTPS
     */
    public function syncDomainCertificate(string $domain, int $certId, bool $httpsEnabled): void
    {
        $this->clearQuery()
            ->where(self::schema_fields_DOMAIN, \strtolower(\trim($domain)))
            ->setData(self::schema_fields_CERT_ID, $certId)
            ->setData(self::schema_fields_HTTPS_ENABLED, $httpsEnabled ? 1 : 0)
            ->update()
            ->fetch();
    }
    
    /**
     * 批量回退域名的 HTTPS 状态（证书失效或被删除时）
     * 
     * @param string $domain 域名
     */
    public function rollbackHttps(string $domain): void
    {
        $this->clearQuery()
            ->where(self::schema_fields_DOMAIN, \strtolower(\trim($domain)))
            ->setData(self::schema_fields_HTTPS_ENABLED, 0)
            ->setData(self::schema_fields_CERT_ID, null)
            ->update()
            ->fetch();
    }
    
    /**
     * 更新健康检查结果
     * 
     * @param int $domainId 域名 ID
     * @param string $status 健康状态
     * @param int|null $code HTTP 状态码
     * @param string $message 消息
     */
    public function updateHealthCheck(int $domainId, string $status, ?int $code, string $message = ''): void
    {
        $this->clearQuery()
            ->where(self::schema_fields_ID, $domainId)
            ->setData(self::schema_fields_HEALTH_STATUS, $status)
            ->setData(self::schema_fields_HEALTH_CODE, $code)
            ->setData(self::schema_fields_HEALTH_MESSAGE, $message)
            ->setData(self::schema_fields_HEALTH_CHECKED_AT, \date('Y-m-d H:i:s'))
            ->update()
            ->fetch();
    }
    
    /**
     * 获取所有活跃域名（用于健康检查）
     */
    public function getAllActiveDomainsForHealthCheck(): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->select()
            ->fetchArray();
    }
    
    /**
     * 获取不健康的域名
     */
    public function getUnhealthyDomains(): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->where(self::schema_fields_HEALTH_STATUS, self::HEALTH_UNHEALTHY)
            ->select()
            ->fetchArray();
    }
    
    /**
     * 获取域名及其 HTTPS 状态的摘要信息
     * 
     * @param int $websiteId 网站 ID
     * @return array 包含域名、HTTPS状态、健康状态的数组
     */
    public function getDomainsWithStatus(int $websiteId): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::schema_fields_IS_PRIMARY, 'DESC')
            ->select()
            ->fetchArray();
    }
}
