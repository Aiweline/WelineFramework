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

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 域名池模型
 * 
 * 存储可建站的具体域名（子域名）
 * - 关联根域名（Domain）
 * - 包含解析状态、HTTPS 证书状态
 * - 自动计算建站就绪状态（site_ready）
 */
class DomainPool extends Model
{
    public const fields_ID = 'pool_id';
    public const fields_PARENT_DOMAIN_ID = 'parent_domain_id';  // 关联 Domain.domain_id（根域名）
    public const fields_DOMAIN = 'domain';                      // 完整域名（如 www.example.com）
    public const fields_ROOT_DOMAIN = 'root_domain';            // 根域名（如 example.com）
    public const fields_DESCRIPTION = 'description';            // 域名描述/备注
    public const fields_STATUS = 'status';                      // 状态：active/disabled
    
    // 解析相关字段
    public const fields_RESOLVE_STATUS = 'resolve_status';      // 解析状态：pending/resolved/error
    public const fields_RESOLVED_IP = 'resolved_ip';            // 解析到的 IPv4
    public const fields_RESOLVED_IPV6 = 'resolved_ipv6';        // 解析到的 IPv6
    public const fields_IS_LOCAL_SERVER = 'is_local_server';    // 是否指向本服务器
    public const fields_RESOLVE_CHECKED_AT = 'resolve_checked_at'; // 解析检测时间
    public const fields_RESOLVE_ERROR = 'resolve_error';        // 解析错误信息
    
    // HTTPS 相关字段
    public const fields_HTTPS_STATUS = 'https_status';          // 证书状态：none/pending/valid/expired/error
    public const fields_HTTPS_EXPIRES_AT = 'https_expires_at';  // 证书过期时间
    public const fields_HTTPS_ERROR = 'https_error';            // 证书申请错误信息
    public const fields_CERT_ID = 'cert_id';                    // 关联 SslCertificate.cert_id
    
    // 建站就绪
    public const fields_SITE_READY = 'site_ready';              // 是否可建站（计算字段）
    
    // DNS 服务商（继承自根域名）
    public const fields_DNS_PROVIDER = 'dns_provider';          // DNS服务商代码（如 cloudflare, gname）
    
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    // 状态常量
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    
    // 解析状态常量
    public const RESOLVE_STATUS_PENDING = 'pending';
    public const RESOLVE_STATUS_RESOLVED = 'resolved';
    public const RESOLVE_STATUS_ERROR = 'error';
    
    // HTTPS 状态常量
    public const HTTPS_STATUS_NONE = 'none';
    public const HTTPS_STATUS_PENDING = 'pending';
    public const HTTPS_STATUS_VALID = 'valid';
    public const HTTPS_STATUS_EXPIRED = 'expired';
    public const HTTPS_STATUS_ERROR = 'error';
    
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
        if (!$setup->tableExist()) {
            $this->install($setup, $context);
            return;
        }
        
        // v1.5.0: 新增解析/HTTPS/建站就绪相关字段
        $alter = $setup->alterTable();
        $hasChanges = false;
        
        // 关联根域名
        if (!$setup->hasField(self::fields_PARENT_DOMAIN_ID)) {
            $alter->addColumn(
                self::fields_PARENT_DOMAIN_ID,
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                11,
                'default 0',
                '关联根域名ID'
            );
            $hasChanges = true;
        }
        
        // 解析状态
        if (!$setup->hasField(self::fields_RESOLVE_STATUS)) {
            $alter->addColumn(
                self::fields_RESOLVE_STATUS,
                self::fields_STATUS,
                TableInterface::column_type_VARCHAR,
                20,
                "default 'pending'",
                '解析状态'
            );
            $hasChanges = true;
        }
        
        // 解析到的 IPv4
        if (!$setup->hasField(self::fields_RESOLVED_IP)) {
            $alter->addColumn(
                self::fields_RESOLVED_IP,
                self::fields_RESOLVE_STATUS,
                TableInterface::column_type_VARCHAR,
                45,
                "default ''",
                '解析到的IPv4'
            );
            $hasChanges = true;
        }
        
        // 解析到的 IPv6
        if (!$setup->hasField(self::fields_RESOLVED_IPV6)) {
            $alter->addColumn(
                self::fields_RESOLVED_IPV6,
                self::fields_RESOLVED_IP,
                TableInterface::column_type_VARCHAR,
                45,
                "default ''",
                '解析到的IPv6'
            );
            $hasChanges = true;
        }
        
        // 是否指向本服务器
        if (!$setup->hasField(self::fields_IS_LOCAL_SERVER)) {
            $alter->addColumn(
                self::fields_IS_LOCAL_SERVER,
                self::fields_RESOLVED_IPV6,
                TableInterface::column_type_SMALLINT,
                1,
                'default 0',
                '是否指向本服务器'
            );
            $hasChanges = true;
        }
        
        // 解析检测时间
        if (!$setup->hasField(self::fields_RESOLVE_CHECKED_AT)) {
            $alter->addColumn(
                self::fields_RESOLVE_CHECKED_AT,
                self::fields_IS_LOCAL_SERVER,
                TableInterface::column_type_DATETIME,
                0,
                '',
                '解析检测时间'
            );
            $hasChanges = true;
        }
        
        // 解析错误信息
        if (!$setup->hasField(self::fields_RESOLVE_ERROR)) {
            $alter->addColumn(
                self::fields_RESOLVE_ERROR,
                self::fields_RESOLVE_CHECKED_AT,
                TableInterface::column_type_TEXT,
                0,
                '',
                '解析错误信息'
            );
            $hasChanges = true;
        }
        
        // HTTPS 状态
        if (!$setup->hasField(self::fields_HTTPS_STATUS)) {
            $alter->addColumn(
                self::fields_HTTPS_STATUS,
                self::fields_RESOLVE_ERROR,
                TableInterface::column_type_VARCHAR,
                20,
                "default 'none'",
                'HTTPS证书状态'
            );
            $hasChanges = true;
        }
        
        // 证书过期时间
        if (!$setup->hasField(self::fields_HTTPS_EXPIRES_AT)) {
            $alter->addColumn(
                self::fields_HTTPS_EXPIRES_AT,
                self::fields_HTTPS_STATUS,
                TableInterface::column_type_DATE,
                0,
                '',
                '证书过期时间'
            );
            $hasChanges = true;
        }
        
        // 证书申请错误
        if (!$setup->hasField(self::fields_HTTPS_ERROR)) {
            $alter->addColumn(
                self::fields_HTTPS_ERROR,
                self::fields_HTTPS_EXPIRES_AT,
                TableInterface::column_type_TEXT,
                0,
                '',
                '证书申请错误'
            );
            $hasChanges = true;
        }
        
        // 关联证书ID
        if (!$setup->hasField(self::fields_CERT_ID)) {
            $alter->addColumn(
                self::fields_CERT_ID,
                self::fields_HTTPS_ERROR,
                TableInterface::column_type_INTEGER,
                11,
                'default null',
                '关联SSL证书ID'
            );
            $hasChanges = true;
        }
        
        // 建站就绪
        if (!$setup->hasField(self::fields_SITE_READY)) {
            $alter->addColumn(
                self::fields_SITE_READY,
                self::fields_CERT_ID,
                TableInterface::column_type_SMALLINT,
                1,
                'default 0',
                '是否可建站'
            );
            $hasChanges = true;
        }
        
        // DNS 服务商（继承自根域名）
        if (!$setup->hasField(self::fields_DNS_PROVIDER)) {
            $alter->addColumn(
                self::fields_DNS_PROVIDER,
                self::fields_SITE_READY,
                TableInterface::column_type_VARCHAR,
                50,
                "default ''",
                'DNS服务商代码'
            );
            $hasChanges = true;
        }
        
        if ($hasChanges) {
            $alter->alter();
        }
        
        // 添加索引
        if (!$setup->hasIndex('idx_parent_domain')) {
            $setup->alterTable()
                ->addIndex(TableInterface::index_type_KEY, 'idx_parent_domain', self::fields_PARENT_DOMAIN_ID)
                ->alter();
        }
        
        if (!$setup->hasIndex('idx_resolve_status')) {
            $setup->alterTable()
                ->addIndex(TableInterface::index_type_KEY, 'idx_resolve_status', self::fields_RESOLVE_STATUS)
                ->alter();
        }
        
        if (!$setup->hasIndex('idx_https_status')) {
            $setup->alterTable()
                ->addIndex(TableInterface::index_type_KEY, 'idx_https_status', self::fields_HTTPS_STATUS)
                ->alter();
        }
        
        if (!$setup->hasIndex('idx_site_ready')) {
            $setup->alterTable()
                ->addIndex(TableInterface::index_type_KEY, 'idx_site_ready', self::fields_SITE_READY)
                ->alter();
        }
        
        if (!$setup->hasIndex('idx_cert')) {
            $setup->alterTable()
                ->addIndex(TableInterface::index_type_KEY, 'idx_cert', self::fields_CERT_ID)
                ->alter();
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
        
        $setup->createTable('域名池表')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', '域名池ID')
            ->addColumn(self::fields_PARENT_DOMAIN_ID, TableInterface::column_type_INTEGER, 11, 'default 0', '关联根域名ID')
            ->addColumn(self::fields_DOMAIN, TableInterface::column_type_VARCHAR, 255, 'not null', '完整域名')
            ->addColumn(self::fields_ROOT_DOMAIN, TableInterface::column_type_VARCHAR, 255, "default ''", '根域名')
            ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_VARCHAR, 500, "default ''", '域名描述')
            ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'active'", '状态')
            // 解析相关
            ->addColumn(self::fields_RESOLVE_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'pending'", '解析状态')
            ->addColumn(self::fields_RESOLVED_IP, TableInterface::column_type_VARCHAR, 45, "default ''", '解析到的IPv4')
            ->addColumn(self::fields_RESOLVED_IPV6, TableInterface::column_type_VARCHAR, 45, "default ''", '解析到的IPv6')
            ->addColumn(self::fields_IS_LOCAL_SERVER, TableInterface::column_type_SMALLINT, 1, 'default 0', '是否指向本服务器')
            ->addColumn(self::fields_RESOLVE_CHECKED_AT, TableInterface::column_type_DATETIME, 0, '', '解析检测时间')
            ->addColumn(self::fields_RESOLVE_ERROR, TableInterface::column_type_TEXT, 0, '', '解析错误信息')
            // HTTPS 相关
            ->addColumn(self::fields_HTTPS_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'none'", 'HTTPS证书状态')
            ->addColumn(self::fields_HTTPS_EXPIRES_AT, TableInterface::column_type_DATE, 0, '', '证书过期时间')
            ->addColumn(self::fields_HTTPS_ERROR, TableInterface::column_type_TEXT, 0, '', '证书申请错误')
            ->addColumn(self::fields_CERT_ID, TableInterface::column_type_INTEGER, 11, 'default null', '关联SSL证书ID')
            // 建站就绪
            ->addColumn(self::fields_SITE_READY, TableInterface::column_type_SMALLINT, 1, 'default 0', '是否可建站')
            // DNS 服务商
            ->addColumn(self::fields_DNS_PROVIDER, TableInterface::column_type_VARCHAR, 50, "default ''", 'DNS服务商代码')
            ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, '', '创建时间')
            ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, '', '更新时间')
            // 索引
            ->addIndex(TableInterface::index_type_UNIQUE, 'uk_domain', self::fields_DOMAIN)
            ->addIndex(TableInterface::index_type_KEY, 'idx_parent_domain', self::fields_PARENT_DOMAIN_ID)
            ->addIndex(TableInterface::index_type_KEY, 'idx_root_domain', self::fields_ROOT_DOMAIN)
            ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS)
            ->addIndex(TableInterface::index_type_KEY, 'idx_resolve_status', self::fields_RESOLVE_STATUS)
            ->addIndex(TableInterface::index_type_KEY, 'idx_https_status', self::fields_HTTPS_STATUS)
            ->addIndex(TableInterface::index_type_KEY, 'idx_site_ready', self::fields_SITE_READY)
            ->addIndex(TableInterface::index_type_KEY, 'idx_cert', self::fields_CERT_ID)
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
        
        // 域名转小写并解析根域
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
        return (int) $this->getData(self::fields_ID);
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
    
    public function getRootDomain(): string
    {
        return (string) $this->getData(self::fields_ROOT_DOMAIN);
    }
    
    public function setDescription(string $description): self
    {
        $this->setData(self::fields_DESCRIPTION, $description);
        return $this;
    }
    
    public function getDescription(): string
    {
        return (string) $this->getData(self::fields_DESCRIPTION);
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
    
    // =============== 解析相关 Getter/Setter ===============
    
    public function setParentDomainId(int $parentDomainId): self
    {
        $this->setData(self::fields_PARENT_DOMAIN_ID, $parentDomainId);
        return $this;
    }
    
    public function getParentDomainId(): int
    {
        return (int) $this->getData(self::fields_PARENT_DOMAIN_ID);
    }
    
    public function setResolveStatus(string $status): self
    {
        $this->setData(self::fields_RESOLVE_STATUS, $status);
        return $this;
    }
    
    public function getResolveStatus(): string
    {
        return (string) ($this->getData(self::fields_RESOLVE_STATUS) ?: self::RESOLVE_STATUS_PENDING);
    }
    
    public function setResolvedIp(string $ip): self
    {
        $this->setData(self::fields_RESOLVED_IP, $ip);
        return $this;
    }
    
    public function getResolvedIp(): string
    {
        return (string) $this->getData(self::fields_RESOLVED_IP);
    }
    
    public function setResolvedIpv6(string $ip): self
    {
        $this->setData(self::fields_RESOLVED_IPV6, $ip);
        return $this;
    }
    
    public function getResolvedIpv6(): string
    {
        return (string) $this->getData(self::fields_RESOLVED_IPV6);
    }
    
    public function setIsLocalServer(bool $isLocal): self
    {
        $this->setData(self::fields_IS_LOCAL_SERVER, $isLocal ? 1 : 0);
        return $this;
    }
    
    public function isLocalServer(): bool
    {
        return (int) $this->getData(self::fields_IS_LOCAL_SERVER) === 1;
    }
    
    public function setResolveCheckedAt(string $datetime): self
    {
        $this->setData(self::fields_RESOLVE_CHECKED_AT, $datetime);
        return $this;
    }
    
    public function getResolveCheckedAt(): string
    {
        return (string) $this->getData(self::fields_RESOLVE_CHECKED_AT);
    }
    
    public function setResolveError(string $error): self
    {
        $this->setData(self::fields_RESOLVE_ERROR, $error);
        return $this;
    }
    
    public function getResolveError(): string
    {
        return (string) $this->getData(self::fields_RESOLVE_ERROR);
    }
    
    // =============== HTTPS 相关 Getter/Setter ===============
    
    public function setHttpsStatus(string $status): self
    {
        $this->setData(self::fields_HTTPS_STATUS, $status);
        return $this;
    }
    
    public function getHttpsStatus(): string
    {
        return (string) ($this->getData(self::fields_HTTPS_STATUS) ?: self::HTTPS_STATUS_NONE);
    }
    
    public function setHttpsExpiresAt(?string $date): self
    {
        $this->setData(self::fields_HTTPS_EXPIRES_AT, $date);
        return $this;
    }
    
    public function getHttpsExpiresAt(): ?string
    {
        $value = $this->getData(self::fields_HTTPS_EXPIRES_AT);
        return $value ? (string) $value : null;
    }
    
    public function setHttpsError(string $error): self
    {
        $this->setData(self::fields_HTTPS_ERROR, $error);
        return $this;
    }
    
    public function getHttpsError(): string
    {
        return (string) $this->getData(self::fields_HTTPS_ERROR);
    }
    
    public function setCertId(?int $certId): self
    {
        $this->setData(self::fields_CERT_ID, $certId);
        return $this;
    }
    
    public function getCertId(): ?int
    {
        $value = $this->getData(self::fields_CERT_ID);
        return $value !== null && $value !== '' ? (int) $value : null;
    }
    
    // =============== 建站就绪 Getter/Setter ===============
    
    public function setSiteReady(bool $ready): self
    {
        $this->setData(self::fields_SITE_READY, $ready ? 1 : 0);
        return $this;
    }
    
    public function isSiteReady(): bool
    {
        return (int) $this->getData(self::fields_SITE_READY) === 1;
    }
    
    // =============== DNS 服务商 Getter/Setter ===============
    
    public function getDnsProvider(): string
    {
        return (string) $this->getData(self::fields_DNS_PROVIDER);
    }
    
    public function setDnsProvider(string $provider): self
    {
        $this->setData(self::fields_DNS_PROVIDER, $provider);
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
     * 获取所有活跃域名列表
     */
    public function getActiveDomains(): array
    {
        return $this->clearQuery()
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::fields_ROOT_DOMAIN, 'ASC')
            ->order(self::fields_DOMAIN, 'ASC')
            ->select()
            ->fetchArray();
    }
    
    /**
     * 根据根域获取所有子域名
     */
    public function getDomainsByRoot(string $rootDomain): array
    {
        return $this->clearQuery()
            ->where(self::fields_ROOT_DOMAIN, \strtolower(\trim($rootDomain)))
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::fields_DOMAIN, 'ASC')
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
     * 获取域名选择器数据（用于 UI 选择组件）
     * 
     * 返回格式适合前端下拉/标签选择器使用
     * 
     * @return array
     */
    public function getSelectOptions(): array
    {
        $grouped = $this->getDomainsGroupedByRoot();
        $options = [];
        
        foreach ($grouped as $rootDomain => $domains) {
            $group = [
                'label' => $rootDomain,
                'options' => []
            ];
            
            foreach ($domains as $domain) {
                $group['options'][] = [
                    'value' => $domain[self::fields_DOMAIN],
                    'label' => $domain[self::fields_DOMAIN],
                    'description' => $domain[self::fields_DESCRIPTION] ?? ''
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
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->where(self::fields_SITE_READY, 1)
            ->order(self::fields_ROOT_DOMAIN, 'ASC')
            ->order(self::fields_DOMAIN, 'ASC')
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
            $rootDomain = $domain[self::fields_ROOT_DOMAIN] ?: $domain[self::fields_DOMAIN];
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
            ->where(self::fields_ID, $poolId)
            ->find()
            ->fetch();
        return $this;
    }
    
    /**
     * 获取需要检测解析的域名列表
     * 
     * @param int $limit
     * @return array
     */
    public function getDomainsNeedResolveCheck(int $limit = 100): array
    {
        $checkThreshold = \date('Y-m-d H:i:s', \strtotime('-10 minutes'));
        
        return $this->clearQuery()
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->where(
                "(" . self::fields_RESOLVE_CHECKED_AT . " IS NULL OR " . 
                self::fields_RESOLVE_CHECKED_AT . " < '{$checkThreshold}')",
                null,
                'RAW'
            )
            ->order(self::fields_RESOLVE_CHECKED_AT, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();
    }
    
    /**
     * 获取需要申请证书的域名列表
     * 
     * 条件：解析正常 + 指向本服务器 + 没有有效证书
     * 
     * @param int $limit
     * @return array
     */
    public function getDomainsNeedCertificate(int $limit = 50): array
    {
        $expiryThreshold = \date('Y-m-d', \strtotime('+30 days'));
        
        return $this->clearQuery()
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->where(self::fields_RESOLVE_STATUS, self::RESOLVE_STATUS_RESOLVED)
            ->where(self::fields_IS_LOCAL_SERVER, 1)
            ->where(self::fields_HTTPS_STATUS, [self::HTTPS_STATUS_NONE, self::HTTPS_STATUS_EXPIRED, self::HTTPS_STATUS_ERROR], 'IN')
            ->order(self::fields_HTTPS_STATUS, 'ASC')
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
            ->where(self::fields_PARENT_DOMAIN_ID, $parentDomainId)
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::fields_DOMAIN, 'ASC')
            ->select()
            ->fetchArray();
    }
}
