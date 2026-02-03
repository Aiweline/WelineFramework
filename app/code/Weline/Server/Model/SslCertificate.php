<?php
declare(strict_types=1);

/**
 * Weline Server - SSL 证书模型
 * 
 * 存储多域名 SSL 证书信息
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * SSL 证书模型
 * 
 * 存储每个域名的证书信息、到期时间、状态等
 */
class SslCertificate extends Model
{
    public const fields_ID = 'cert_id';
    public const fields_DOMAIN = 'domain';              // 主域名
    public const fields_CERT_TYPE = 'cert_type';        // 证书类型：exact（精确域名）/ wildcard（泛域名）
    public const fields_WEBSITE_ID = 'website_id';      // 关联的网站 ID
    public const fields_CERT_PATH = 'cert_path';        // 证书文件路径
    public const fields_KEY_PATH = 'key_path';          // 私钥文件路径
    public const fields_CHAIN_PATH = 'chain_path';      // 证书链路径（可选）
    public const fields_ISSUER = 'issuer';              // 颁发机构
    public const fields_ISSUED_AT = 'issued_at';        // 颁发时间
    public const fields_EXPIRES_AT = 'expires_at';      // 到期时间
    public const fields_STATUS = 'status';              // 状态：pending/active/expired/revoked
    public const fields_AUTO_RENEW = 'auto_renew';      // 是否自动续签
    public const fields_HTTPS_ENABLED = 'https_enabled';// 是否启用 HTTPS
    public const fields_LAST_RENEW_AT = 'last_renew_at';// 最后续签时间
    public const fields_RENEW_ERROR = 'renew_error';    // 续签错误信息
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    // 证书状态
    public const STATUS_PENDING = 'pending';     // 待申请
    public const STATUS_ACTIVE = 'active';       // 有效
    public const STATUS_EXPIRED = 'expired';     // 已过期
    public const STATUS_REVOKED = 'revoked';     // 已吊销
    public const STATUS_ERROR = 'error';         // 申请失败
    
    // 证书类型
    public const CERT_TYPE_EXACT = 'exact';         // 精确域名（如 www.example.com）
    public const CERT_TYPE_WILDCARD = 'wildcard';   // 泛域名（如 *.example.com）
    
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
        // 新增 cert_type 字段（证书类型：exact/wildcard）
        if (!$setup->hasField(self::fields_CERT_TYPE)) {
            $setup->alterTable()->addColumn(
                self::fields_CERT_TYPE,
                self::fields_DOMAIN,
                TableInterface::column_type_VARCHAR,
                20,
                "default 'exact'",
                '证书类型（exact/wildcard）'
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
        
        $setup->createTable('SSL证书表')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', '证书ID')
            ->addColumn(self::fields_DOMAIN, TableInterface::column_type_VARCHAR, 255, 'not null', '主域名')
            ->addColumn(self::fields_CERT_TYPE, TableInterface::column_type_VARCHAR, 20, "default 'exact'", '证书类型（exact/wildcard）')
            ->addColumn(self::fields_WEBSITE_ID, TableInterface::column_type_INTEGER, 11, 'default 0', '关联网站ID')
            ->addColumn(self::fields_CERT_PATH, TableInterface::column_type_VARCHAR, 500, '', '证书文件路径')
            ->addColumn(self::fields_KEY_PATH, TableInterface::column_type_VARCHAR, 500, '', '私钥文件路径')
            ->addColumn(self::fields_CHAIN_PATH, TableInterface::column_type_VARCHAR, 500, '', '证书链路径')
            ->addColumn(self::fields_ISSUER, TableInterface::column_type_VARCHAR, 100, "default 'Let''s Encrypt'", '颁发机构')
            ->addColumn(self::fields_ISSUED_AT, TableInterface::column_type_DATETIME, 0, '', '颁发时间')
            ->addColumn(self::fields_EXPIRES_AT, TableInterface::column_type_DATETIME, 0, '', '到期时间')
            ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'pending'", '状态')
            ->addColumn(self::fields_AUTO_RENEW, TableInterface::column_type_INTEGER, 1, 'default 1', '自动续签')
            ->addColumn(self::fields_HTTPS_ENABLED, TableInterface::column_type_INTEGER, 1, 'default 0', '启用HTTPS')
            ->addColumn(self::fields_LAST_RENEW_AT, TableInterface::column_type_DATETIME, 0, '', '最后续签时间')
            ->addColumn(self::fields_RENEW_ERROR, TableInterface::column_type_TEXT, 0, '', '续签错误信息')
            ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, '', '创建时间')
            ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, '', '更新时间')
            ->addIndex(TableInterface::index_type_UNIQUE, 'uk_domain', self::fields_DOMAIN)
            ->addIndex(TableInterface::index_type_KEY, 'idx_website', self::fields_WEBSITE_ID)
            ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS)
            ->addIndex(TableInterface::index_type_KEY, 'idx_expires', self::fields_EXPIRES_AT)
            ->create();
    }
    
    /**
     * 保存前自动更新时间戳
     */
    public function save_before(): void
    {
        parent::save_before();
        
        $now = \date('Y-m-d H:i:s');
        $this->setData(self::fields_UPDATED_AT, $now);
        
        if (!$this->getData(self::fields_ID)) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
    }
    
    // =============== Getter/Setter 方法 ===============
    
    public function getCertId(): int
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
    
    public function setCertType(string $certType): self
    {
        $this->setData(self::fields_CERT_TYPE, $certType);
        return $this;
    }
    
    public function getCertType(): string
    {
        return (string) ($this->getData(self::fields_CERT_TYPE) ?: self::CERT_TYPE_EXACT);
    }
    
    public function isWildcard(): bool
    {
        return $this->getCertType() === self::CERT_TYPE_WILDCARD;
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
    
    public function setCertPath(string $path): self
    {
        $this->setData(self::fields_CERT_PATH, $path);
        return $this;
    }
    
    public function getCertPath(): string
    {
        return (string) $this->getData(self::fields_CERT_PATH);
    }
    
    public function setKeyPath(string $path): self
    {
        $this->setData(self::fields_KEY_PATH, $path);
        return $this;
    }
    
    public function getKeyPath(): string
    {
        return (string) $this->getData(self::fields_KEY_PATH);
    }
    
    public function setChainPath(string $path): self
    {
        $this->setData(self::fields_CHAIN_PATH, $path);
        return $this;
    }
    
    public function getChainPath(): string
    {
        return (string) $this->getData(self::fields_CHAIN_PATH);
    }
    
    public function setIssuer(string $issuer): self
    {
        $this->setData(self::fields_ISSUER, $issuer);
        return $this;
    }
    
    public function getIssuer(): string
    {
        return (string) $this->getData(self::fields_ISSUER);
    }
    
    public function setIssuedAt(string $datetime): self
    {
        $this->setData(self::fields_ISSUED_AT, $datetime);
        return $this;
    }
    
    public function getIssuedAt(): string
    {
        return (string) $this->getData(self::fields_ISSUED_AT);
    }
    
    public function setExpiresAt(string $datetime): self
    {
        $this->setData(self::fields_EXPIRES_AT, $datetime);
        return $this;
    }
    
    public function getExpiresAt(): string
    {
        return (string) $this->getData(self::fields_EXPIRES_AT);
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
    
    public function setAutoRenew(bool $autoRenew): self
    {
        $this->setData(self::fields_AUTO_RENEW, $autoRenew ? 1 : 0);
        return $this;
    }
    
    public function isAutoRenew(): bool
    {
        return (bool) $this->getData(self::fields_AUTO_RENEW);
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
    
    public function setLastRenewAt(string $datetime): self
    {
        $this->setData(self::fields_LAST_RENEW_AT, $datetime);
        return $this;
    }
    
    public function getLastRenewAt(): string
    {
        return (string) $this->getData(self::fields_LAST_RENEW_AT);
    }
    
    public function setRenewError(string $error): self
    {
        $this->setData(self::fields_RENEW_ERROR, $error);
        return $this;
    }
    
    public function getRenewError(): string
    {
        return (string) $this->getData(self::fields_RENEW_ERROR);
    }
    
    // =============== 业务方法 ===============
    
    /**
     * 检查证书是否即将过期（30天内）
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        $expiresAt = $this->getExpiresAt();
        if (empty($expiresAt)) {
            return false;
        }
        
        $expiresTime = \strtotime($expiresAt);
        $warningTime = \time() + ($days * 86400);
        
        return $expiresTime <= $warningTime;
    }
    
    /**
     * 检查证书是否已过期
     */
    public function isExpired(): bool
    {
        $expiresAt = $this->getExpiresAt();
        if (empty($expiresAt)) {
            return true;
        }
        
        return \strtotime($expiresAt) < \time();
    }
    
    /**
     * 检查证书文件是否存在
     */
    public function certificateFilesExist(): bool
    {
        $certPath = $this->getCertPath();
        $keyPath = $this->getKeyPath();
        
        return !empty($certPath) && !empty($keyPath) 
            && \is_file($certPath) && \is_file($keyPath);
    }
    
    /**
     * 获取证书目录路径
     */
    public function getCertificateDir(): string
    {
        $domain = $this->getDomain();
        if (empty($domain)) {
            return '';
        }
        
        // 证书存储在 app/etc/ssl/{domain}/ 目录下
        return \dirname(\Weline\Framework\App\Env::path_ENV_FILE) . DS . 'ssl' . DS . $domain . DS;
    }
    
    /**
     * 根据域名查找证书
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
     * 获取所有启用 HTTPS 且有效的证书
     */
    public function getActiveCertificates(): array
    {
        return $this->clearQuery()
            ->where(self::fields_HTTPS_ENABLED, 1)
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->select()
            ->fetchArray();
    }
    
    /**
     * 获取需要续签的证书（30天内过期）
     */
    public function getCertificatesNeedRenew(int $days = 30): array
    {
        $warningDate = \date('Y-m-d H:i:s', \time() + ($days * 86400));
        
        return $this->clearQuery()
            ->where(self::fields_AUTO_RENEW, 1)
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->where(self::fields_EXPIRES_AT, $warningDate, '<=')
            ->select()
            ->fetchArray();
    }
    
    /**
     * 查找覆盖指定域名的证书（先精确匹配，再泛域名匹配）
     * 
     * @param string $domain 要查找的域名
     * @return self|null 找到的证书，或 null
     */
    public function findCertificateForDomain(string $domain): ?self
    {
        $domain = \strtolower(\trim($domain));
        
        // 1. 先查精确匹配
        $this->clearQuery()
            ->where(self::fields_DOMAIN, $domain)
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->find()
            ->fetch();
        
        if ($this->getCertId()) {
            return $this;
        }
        
        // 2. 再查泛域名匹配（*.example.com 覆盖 www.example.com）
        $parts = \explode('.', $domain);
        if (\count($parts) >= 2) {
            // 移除第一段，构造泛域名（如 www.example.com -> *.example.com）
            \array_shift($parts);
            $wildcardDomain = '*.' . \implode('.', $parts);
            
            $this->clearQuery()
                ->where(self::fields_DOMAIN, $wildcardDomain)
                ->where(self::fields_CERT_TYPE, self::CERT_TYPE_WILDCARD)
                ->where(self::fields_STATUS, self::STATUS_ACTIVE)
                ->find()
                ->fetch();
            
            if ($this->getCertId()) {
                return $this;
            }
        }
        
        return null;
    }
    
    /**
     * 查找根域的泛证书
     * 
     * @param string $rootDomain 根域（如 example.com）
     * @return self|null
     */
    public function findWildcardByRoot(string $rootDomain): ?self
    {
        $wildcardDomain = '*.' . \strtolower(\trim($rootDomain));
        
        $this->clearQuery()
            ->where(self::fields_DOMAIN, $wildcardDomain)
            ->where(self::fields_CERT_TYPE, self::CERT_TYPE_WILDCARD)
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->find()
            ->fetch();
        
        return $this->getCertId() ? $this : null;
    }
}
