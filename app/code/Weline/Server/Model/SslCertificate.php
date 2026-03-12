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
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** SSL 证书模型 - 存储每个域名的证书信息、到期时间、状态等 */
#[Table(comment: 'SSL证书表')]
#[Index(name: 'uk_domain', columns: ['domain'], type: 'UNIQUE')]
#[Index(name: 'idx_website', columns: ['website_id'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_expires', columns: ['expires_at'])]
class SslCertificate extends Model
{
    public const schema_table = 'weline_server_ssl_certificate';
    public const schema_primary_key = 'cert_id';
    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: '证书ID')]
    public const schema_fields_ID = 'cert_id';
    #[Col('varchar', 255, nullable: false, comment: '主域名')]
    public const schema_fields_DOMAIN = 'domain';
    #[Col('varchar', 20, default: 'exact', comment: '证书类型')]
    public const schema_fields_CERT_TYPE = 'cert_type';
    #[Col('int', 11, default: 0, comment: '关联网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 500, comment: '证书文件路径')]
    public const schema_fields_CERT_PATH = 'cert_path';
    #[Col('varchar', 500, comment: '私钥文件路径')]
    public const schema_fields_KEY_PATH = 'key_path';
    #[Col('varchar', 500, comment: '证书链路径')]
    public const schema_fields_CHAIN_PATH = 'chain_path';
    #[Col('varchar', 100, default: "Let's Encrypt", comment: '颁发机构')]
    public const schema_fields_ISSUER = 'issuer';
    #[Col('varchar', 30, default: 'letsencrypt', comment: '证书申请服务商')]
    public const schema_fields_PROVIDER = 'provider';
    #[Col('datetime', comment: '颁发时间')]
    public const schema_fields_ISSUED_AT = 'issued_at';
    #[Col('datetime', comment: '到期时间')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col('varchar', 20, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', 1, default: 1, comment: '自动续签')]
    public const schema_fields_AUTO_RENEW = 'auto_renew';
    #[Col('int', 1, default: 0, comment: '启用HTTPS')]
    public const schema_fields_HTTPS_ENABLED = 'https_enabled';
    #[Col('datetime', comment: '最后续签时间')]
    public const schema_fields_LAST_RENEW_AT = 'last_renew_at';
    #[Col('text', comment: '续签错误信息')]
    public const schema_fields_RENEW_ERROR = 'renew_error';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_ERROR = 'error';
    public const CERT_TYPE_EXACT = 'exact';
    public const CERT_TYPE_WILDCARD = 'wildcard';
    /**
     * 保存前自动更新时间戳，并校验必填字段 domain（NOT NULL）
     */
    public function save_before(): void
    {
        parent::save_before();
        
        $now = \date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        
        if (!$this->getData(self::schema_fields_ID)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
            $domain = \strtolower(\trim((string) $this->getData(self::schema_fields_DOMAIN)));
            if ($domain === '') {
                throw new \InvalidArgumentException(
                    'SslCertificate 新建记录时 domain 不能为空，请先 setDomain() 或检查调用方是否传入有效域名。'
                );
            }
        } else {
            // 更新时校验 uk_domain：若目标 domain 已被其他行占用则提前抛错，避免 SQLSTATE[23505]
            $domain = \strtolower(\trim((string) $this->getData(self::schema_fields_DOMAIN)));
            if ($domain !== '') {
                $currentId = (int) $this->getData(self::schema_fields_ID);
                $fresh = \Weline\Framework\Manager\ObjectManager::getInstance(static::class, [], false);
                $rows = $fresh->clearQuery()
                    ->fields(self::schema_fields_ID)
                    ->where(self::schema_fields_DOMAIN, $domain)
                    ->where(self::schema_fields_ID, $currentId, '!=')
                    ->limit(1)
                    ->select()
                    ->fetchArray();
                if (\count($rows) > 0) {
                    $otherId = (int) ($rows[0][self::schema_fields_ID] ?? 0);
                    throw new \InvalidArgumentException(
                        __('域名 %{1} 已被其他证书记录使用（cert_id=%{2}），不能重复保存。请通过证书管理合并或删除重复记录。', [$domain, $otherId])
                    );
                }
            }
        }
    }
    
    // =============== Getter/Setter 方法 ===============
    
    public function getCertId(): int
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
    
    public function setCertType(string $certType): self
    {
        $this->setData(self::schema_fields_CERT_TYPE, $certType);
        return $this;
    }
    
    public function getCertType(): string
    {
        return (string) ($this->getData(self::schema_fields_CERT_TYPE) ?: self::CERT_TYPE_EXACT);
    }
    
    public function isWildcard(): bool
    {
        return $this->getCertType() === self::CERT_TYPE_WILDCARD;
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
    
    public function setCertPath(string $path): self
    {
        $this->setData(self::schema_fields_CERT_PATH, $path);
        return $this;
    }
    
    public function getCertPath(): string
    {
        return (string) $this->getData(self::schema_fields_CERT_PATH);
    }
    
    public function setKeyPath(string $path): self
    {
        $this->setData(self::schema_fields_KEY_PATH, $path);
        return $this;
    }
    
    public function getKeyPath(): string
    {
        return (string) $this->getData(self::schema_fields_KEY_PATH);
    }
    
    public function setChainPath(string $path): self
    {
        $this->setData(self::schema_fields_CHAIN_PATH, $path);
        return $this;
    }
    
    public function getChainPath(): string
    {
        return (string) $this->getData(self::schema_fields_CHAIN_PATH);
    }
    
    public function setIssuer(string $issuer): self
    {
        $this->setData(self::schema_fields_ISSUER, $issuer);
        return $this;
    }
    
    public function getIssuer(): string
    {
        return (string) $this->getData(self::schema_fields_ISSUER);
    }
    
    public function setProvider(string $provider): self
    {
        $this->setData(self::schema_fields_PROVIDER, \strtolower(\trim($provider)));
        return $this;
    }
    
    public function getProvider(): string
    {
        return (string) $this->getData(self::schema_fields_PROVIDER);
    }
    
    public function setIssuedAt(string $datetime): self
    {
        $this->setData(self::schema_fields_ISSUED_AT, $datetime);
        return $this;
    }
    
    public function getIssuedAt(): string
    {
        return (string) $this->getData(self::schema_fields_ISSUED_AT);
    }
    
    public function setExpiresAt(string $datetime): self
    {
        $this->setData(self::schema_fields_EXPIRES_AT, $datetime);
        return $this;
    }
    
    public function getExpiresAt(): string
    {
        return (string) $this->getData(self::schema_fields_EXPIRES_AT);
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
    
    public function setAutoRenew(bool $autoRenew): self
    {
        $this->setData(self::schema_fields_AUTO_RENEW, $autoRenew ? 1 : 0);
        return $this;
    }
    
    public function isAutoRenew(): bool
    {
        return (bool) $this->getData(self::schema_fields_AUTO_RENEW);
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
    
    public function setLastRenewAt(string $datetime): self
    {
        $this->setData(self::schema_fields_LAST_RENEW_AT, $datetime);
        return $this;
    }
    
    public function getLastRenewAt(): string
    {
        return (string) $this->getData(self::schema_fields_LAST_RENEW_AT);
    }
    
    public function setRenewError(string $error): self
    {
        $this->setData(self::schema_fields_RENEW_ERROR, $error);
        return $this;
    }
    
    public function getRenewError(): string
    {
        return (string) $this->getData(self::schema_fields_RENEW_ERROR);
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
            ->where(self::schema_fields_DOMAIN, \strtolower(\trim($domain)))
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
            ->where(self::schema_fields_HTTPS_ENABLED, 1)
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
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
            ->where(self::schema_fields_AUTO_RENEW, 1)
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->where(self::schema_fields_EXPIRES_AT, $warningDate, '<=')
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
            ->where(self::schema_fields_DOMAIN, $domain)
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
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
                ->where(self::schema_fields_DOMAIN, $wildcardDomain)
                ->where(self::schema_fields_CERT_TYPE, self::CERT_TYPE_WILDCARD)
                ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
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
            ->where(self::schema_fields_DOMAIN, $wildcardDomain)
            ->where(self::schema_fields_CERT_TYPE, self::CERT_TYPE_WILDCARD)
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->find()
            ->fetch();
        
        return $this->getCertId() ? $this : null;
    }
}
