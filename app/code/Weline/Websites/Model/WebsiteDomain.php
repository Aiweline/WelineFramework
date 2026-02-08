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

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 网站域名模型
 * 
 * 每个网站可以关联多个域名
 */
class WebsiteDomain extends Model
{
    public const fields_ID = 'domain_id';
    public const fields_WEBSITE_ID = 'website_id';      // 关联的网站 ID
    public const fields_DOMAIN = 'domain';              // 域名（子域名或完整域名）
    public const fields_ROOT_DOMAIN = 'root_domain';    // 根域名（由 domain 自动解析归属）
    public const fields_SUB_PATH = 'sub_path';          // 子路径（可选，如 /shop）
    public const fields_CERT_ID = 'cert_id';            // 关联的 SSL 证书 ID（可选）
    public const fields_IS_PRIMARY = 'is_primary';      // 是否主域名
    public const fields_HTTPS_ENABLED = 'https_enabled';// 是否启用 HTTPS（自动根据证书状态同步）
    public const fields_STATUS = 'status';              // 状态：active/disabled
    public const fields_HEALTH_STATUS = 'health_status';// 健康状态：healthy/unhealthy/unknown
    public const fields_HEALTH_CODE = 'health_code';    // 健康检查 HTTP 状态码
    public const fields_HEALTH_MESSAGE = 'health_message'; // 健康检查消息
    public const fields_HEALTH_CHECKED_AT = 'health_checked_at'; // 最后健康检查时间
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    // 状态常量
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    
    // 健康状态常量
    public const HEALTH_HEALTHY = 'healthy';
    public const HEALTH_UNHEALTHY = 'unhealthy';
    public const HEALTH_UNKNOWN = 'unknown';
    
    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
    
    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 如果表不存在，执行安装
        if (!$setup->tableExist()) {
            $this->install($setup, $context);
            return;
        }
        // 新增 root_domain 字段（填写子域名时自动归属根域名）
        if (!$setup->hasField(self::fields_ROOT_DOMAIN)) {
            $setup->alterTable()->addColumn(
                self::fields_ROOT_DOMAIN,
                self::fields_DOMAIN,
                TableInterface::column_type_VARCHAR,
                255,
                "default ''",
                '根域名（由域名自动解析）'
            )->alter();
        }
        // 新增 sub_path 字段（子路径，可选）
        if (!$setup->hasField(self::fields_SUB_PATH)) {
            $setup->alterTable()->addColumn(
                self::fields_SUB_PATH,
                self::fields_ROOT_DOMAIN,
                TableInterface::column_type_VARCHAR,
                255,
                "default ''",
                '子路径（如 /shop）'
            )->alter();
        }
        // 新增 cert_id 字段（关联 SSL 证书，可选）
        if (!$setup->hasField(self::fields_CERT_ID)) {
            $setup->alterTable()->addColumn(
                self::fields_CERT_ID,
                self::fields_SUB_PATH,
                TableInterface::column_type_INTEGER,
                11,
                "default null",
                '关联的SSL证书ID'
            )->alter();
        }
        // 新增 health_status 字段（健康状态）
        if (!$setup->hasField(self::fields_HEALTH_STATUS)) {
            $setup->alterTable()->addColumn(
                self::fields_HEALTH_STATUS,
                self::fields_STATUS,
                TableInterface::column_type_VARCHAR,
                20,
                "default 'unknown'",
                '健康状态'
            )->alter();
        }
        // 新增 health_code 字段（HTTP 状态码）
        if (!$setup->hasField(self::fields_HEALTH_CODE)) {
            $setup->alterTable()->addColumn(
                self::fields_HEALTH_CODE,
                self::fields_HEALTH_STATUS,
                TableInterface::column_type_INTEGER,
                4,
                "default null",
                '健康检查HTTP状态码'
            )->alter();
        }
        // 新增 health_message 字段（检查消息）
        if (!$setup->hasField(self::fields_HEALTH_MESSAGE)) {
            $setup->alterTable()->addColumn(
                self::fields_HEALTH_MESSAGE,
                self::fields_HEALTH_CODE,
                TableInterface::column_type_VARCHAR,
                500,
                "default ''",
                '健康检查消息'
            )->alter();
        }
        // 新增 health_checked_at 字段（最后检查时间）
        if (!$setup->hasField(self::fields_HEALTH_CHECKED_AT)) {
            $setup->alterTable()->addColumn(
                self::fields_HEALTH_CHECKED_AT,
                self::fields_HEALTH_MESSAGE,
                TableInterface::column_type_DATETIME,
                0,
                'default null',
                '最后健康检查时间'
            )->alter();
        }
    }
    
    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }
        
        $setup->createTable('网站域名表')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', '域名ID')
            ->addColumn(self::fields_WEBSITE_ID, TableInterface::column_type_INTEGER, 11, 'not null', '网站ID')
            ->addColumn(self::fields_DOMAIN, TableInterface::column_type_VARCHAR, 255, 'not null', '域名')
            ->addColumn(self::fields_ROOT_DOMAIN, TableInterface::column_type_VARCHAR, 255, "default ''", '根域名（自动解析）')
            ->addColumn(self::fields_SUB_PATH, TableInterface::column_type_VARCHAR, 255, "default ''", '子路径')
            ->addColumn(self::fields_CERT_ID, TableInterface::column_type_INTEGER, 11, 'default null', '关联的SSL证书ID')
            ->addColumn(self::fields_IS_PRIMARY, TableInterface::column_type_INTEGER, 1, 'default 0', '是否主域名')
            ->addColumn(self::fields_HTTPS_ENABLED, TableInterface::column_type_INTEGER, 1, 'default 0', '启用HTTPS')
            ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'active'", '状态')
            ->addColumn(self::fields_HEALTH_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'unknown'", '健康状态')
            ->addColumn(self::fields_HEALTH_CODE, TableInterface::column_type_INTEGER, 4, 'default null', '健康检查HTTP状态码')
            ->addColumn(self::fields_HEALTH_MESSAGE, TableInterface::column_type_VARCHAR, 500, "default ''", '健康检查消息')
            ->addColumn(self::fields_HEALTH_CHECKED_AT, TableInterface::column_type_DATETIME, 0, 'default null', '最后健康检查时间')
            ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, '', '创建时间')
            ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, '', '更新时间')
            ->addIndex(TableInterface::index_type_UNIQUE, 'uk_domain_subpath', self::fields_DOMAIN . ',' . self::fields_SUB_PATH)
            ->addIndex(TableInterface::index_type_KEY, 'idx_website', self::fields_WEBSITE_ID)
            ->addIndex(TableInterface::index_type_KEY, 'idx_root_domain', self::fields_ROOT_DOMAIN)
            ->addIndex(TableInterface::index_type_KEY, 'idx_cert', self::fields_CERT_ID)
            ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS)
            ->addIndex(TableInterface::index_type_KEY, 'idx_health', self::fields_HEALTH_STATUS)
            ->create();
    }
    
    /**
     * 保存前自动更新时间戳并解析根域名
     */
    public function save_before(): void
    {
        parent::save_before();
        
        $now = \date('Y-m-d H:i:s');
        $this->setData(self::fields_UPDATED_AT, $now);
        
        if (!$this->getData(self::fields_ID)) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
        
        // 域名转小写
        $domain = $this->getData(self::fields_DOMAIN);
        if ($domain) {
            $domain = \strtolower(\trim($domain));
            $this->setData(self::fields_DOMAIN, $domain);
            
            // 使用 PSL 库解析根域名
            $rootDomain = $this->parseRootDomain($domain);
            $this->setData(self::fields_ROOT_DOMAIN, $rootDomain);
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
        return (int) $this->getData(self::fields_ID);
    }
    
    public function setWebsiteId(int $websiteId): self
    {
        $this->setData(self::fields_WEBSITE_ID, $websiteId);
        return $this;
    }
    
    public function getWebsiteId(): int
    {
        return (int) $this->getData(self::fields_WEBSITE_ID);
    }
    
    public function setDomain(string $domain): self
    {
        $this->setData(self::fields_DOMAIN, \strtolower(\trim($domain)));
        return $this;
    }
    
    public function getDomain(): string
    {
        return (string) $this->getData(self::fields_DOMAIN);
    }
    
    public function setIsPrimary(bool $isPrimary): self
    {
        $this->setData(self::fields_IS_PRIMARY, $isPrimary ? 1 : 0);
        return $this;
    }
    
    public function isPrimary(): bool
    {
        return (bool) $this->getData(self::fields_IS_PRIMARY);
    }
    
    public function setHttpsEnabled(bool $enabled): self
    {
        $this->setData(self::fields_HTTPS_ENABLED, $enabled ? 1 : 0);
        return $this;
    }
    
    public function isHttpsEnabled(): bool
    {
        return (bool) $this->getData(self::fields_HTTPS_ENABLED);
    }
    
    public function setStatus(string $status): self
    {
        $this->setData(self::fields_STATUS, $status);
        return $this;
    }
    
    public function getStatus(): string
    {
        return (string) $this->getData(self::fields_STATUS);
    }
    
    public function getRootDomain(): string
    {
        return (string) $this->getData(self::fields_ROOT_DOMAIN);
    }
    
    public function setSubPath(string $subPath): self
    {
        $this->setData(self::fields_SUB_PATH, $subPath);
        return $this;
    }
    
    public function getSubPath(): string
    {
        return (string) $this->getData(self::fields_SUB_PATH);
    }
    
    public function setCertId(?int $certId): self
    {
        $this->setData(self::fields_CERT_ID, $certId);
        return $this;
    }
    
    public function getCertId(): ?int
    {
        $certId = $this->getData(self::fields_CERT_ID);
        return $certId !== null ? (int) $certId : null;
    }
    
    public function setHealthStatus(string $status): self
    {
        $this->setData(self::fields_HEALTH_STATUS, $status);
        return $this;
    }
    
    public function getHealthStatus(): string
    {
        return (string) ($this->getData(self::fields_HEALTH_STATUS) ?: self::HEALTH_UNKNOWN);
    }
    
    public function setHealthCode(?int $code): self
    {
        $this->setData(self::fields_HEALTH_CODE, $code);
        return $this;
    }
    
    public function getHealthCode(): ?int
    {
        $code = $this->getData(self::fields_HEALTH_CODE);
        return $code !== null ? (int) $code : null;
    }
    
    public function setHealthMessage(string $message): self
    {
        $this->setData(self::fields_HEALTH_MESSAGE, $message);
        return $this;
    }
    
    public function getHealthMessage(): string
    {
        return (string) $this->getData(self::fields_HEALTH_MESSAGE);
    }
    
    public function setHealthCheckedAt(?string $datetime): self
    {
        $this->setData(self::fields_HEALTH_CHECKED_AT, $datetime);
        return $this;
    }
    
    public function getHealthCheckedAt(): ?string
    {
        return $this->getData(self::fields_HEALTH_CHECKED_AT) ?: null;
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
        $certId = $this->getCertId();
        if (!$certId) {
            return false;
        }
        
        try {
            // 检查证书状态
            if (\class_exists(\Weline\Server\Model\SslCertificate::class)) {
                /** @var \Weline\Server\Model\SslCertificate $certModel */
                $certModel = \Weline\Framework\Manager\ObjectManager::getInstance(
                    \Weline\Server\Model\SslCertificate::class
                );
                $certModel->clearQuery()
                    ->where('cert_id', $certId)
                    ->find()
                    ->fetch();
                
                if ($certModel->getCertId()) {
                    // 证书存在且有效（未过期）
                    return $certModel->getStatus() === \Weline\Server\Model\SslCertificate::STATUS_ACTIVE
                        && !$certModel->isExpired();
                }
            }
        } catch (\Throwable $e) {
            // 忽略错误
        }
        
        return false;
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
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::fields_IS_PRIMARY, 'DESC')
            ->select()
            ->fetchArray();
    }
    
    /**
     * 获取网站的主域名
     */
    public function getPrimaryDomain(int $websiteId): ?self
    {
        $this->clearQuery()
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->where(self::fields_IS_PRIMARY, 1)
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
            ->where(self::fields_DOMAIN, \strtolower(\trim($domain)))
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
        $row = $this->clearQuery()
            ->where(self::fields_DOMAIN, $domain)
            ->where(self::fields_SUB_PATH, $subPath)
            ->find()
            ->fetch();
        if (!$row || !$this->getDomainId()) {
            return null;
        }
        $websiteId = (int) $this->getData(self::fields_WEBSITE_ID);
        if ($excludeWebsiteId !== null && $websiteId === $excludeWebsiteId) {
            return null;
        }
        $website = ObjectManager::getInstance(Website::class);
        $website->load($websiteId);
        $name = $website->getId() ? (string) $website->getData(Website::fields_NAME) : (string) $websiteId;
        return ['website_id' => $websiteId, 'website_name' => $name];
    }
    
    /**
     * 获取所有启用 HTTPS 的域名
     */
    public function getHttpsEnabledDomains(): array
    {
        return $this->clearQuery()
            ->where(self::fields_HTTPS_ENABLED, 1)
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
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
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->setData(self::fields_IS_PRIMARY, 0)
            ->update()
            ->fetch();
        
        // 设置新的主域名
        $this->clearQuery()
            ->where(self::fields_ID, $domainId)
            ->setData(self::fields_IS_PRIMARY, 1)
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
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::fields_ROOT_DOMAIN, 'ASC')
            ->order(self::fields_DOMAIN, 'ASC')
            ->select()
            ->fetchArray();
        
        $grouped = [];
        foreach ($domains as $domain) {
            $rootDomain = $domain[self::fields_ROOT_DOMAIN] ?: $domain[self::fields_DOMAIN];
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
            ->where(self::fields_ROOT_DOMAIN, $rootDomain)
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::fields_DOMAIN, 'ASC')
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
            ->where(self::fields_DOMAIN, \strtolower(\trim($domain)))
            ->setData(self::fields_CERT_ID, $certId)
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
            ->where(self::fields_DOMAIN, \strtolower(\trim($domain)))
            ->setData(self::fields_CERT_ID, $certId)
            ->setData(self::fields_HTTPS_ENABLED, $httpsEnabled ? 1 : 0)
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
            ->where(self::fields_DOMAIN, \strtolower(\trim($domain)))
            ->setData(self::fields_HTTPS_ENABLED, 0)
            ->setData(self::fields_CERT_ID, null)
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
            ->where(self::fields_ID, $domainId)
            ->setData(self::fields_HEALTH_STATUS, $status)
            ->setData(self::fields_HEALTH_CODE, $code)
            ->setData(self::fields_HEALTH_MESSAGE, $message)
            ->setData(self::fields_HEALTH_CHECKED_AT, \date('Y-m-d H:i:s'))
            ->update()
            ->fetch();
    }
    
    /**
     * 获取所有活跃域名（用于健康检查）
     */
    public function getAllActiveDomainsForHealthCheck(): array
    {
        return $this->clearQuery()
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->select()
            ->fetchArray();
    }
    
    /**
     * 获取不健康的域名
     */
    public function getUnhealthyDomains(): array
    {
        return $this->clearQuery()
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->where(self::fields_HEALTH_STATUS, self::HEALTH_UNHEALTHY)
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
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::fields_IS_PRIMARY, 'DESC')
            ->select()
            ->fetchArray();
    }
}
