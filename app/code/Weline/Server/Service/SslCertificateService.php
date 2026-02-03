<?php
declare(strict_types=1);

/**
 * Weline Server - SSL 证书管理服务
 * 
 * 提供 Let's Encrypt 自动申请、续签证书功能
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Model\SslCertificate;

/**
 * SSL 证书管理服务
 * 
 * 支持：
 * - 开发环境自签证书自动生成
 * - 生产环境 Let's Encrypt 自动申请
 * - 证书自动续签
 * - 多域名证书管理
 * - SNI 证书匹配
 */
class SslCertificateService
{
    /**
     * 证书颁发者标识
     */
    public const ISSUER_SELF_SIGNED = 'Weline Self-Signed';
    public const ISSUER_LETS_ENCRYPT = "Let's Encrypt";
    
    /**
     * Let's Encrypt ACME 目录
     */
    protected const ACME_DIRECTORY_PROD = 'https://acme-v02.api.letsencrypt.org/directory';
    protected const ACME_DIRECTORY_STAGING = 'https://acme-staging-v02.api.letsencrypt.org/directory';
    
    /**
     * 证书存储基础目录
     */
    protected string $certBaseDir;
    
    /**
     * 账户密钥路径
     */
    protected string $accountKeyPath;
    
    /**
     * ACME 目录 URL
     */
    protected string $acmeDirectory;
    
    /**
     * ACME 目录缓存
     */
    protected ?array $directoryCache = null;
    
    /**
     * 是否使用测试环境
     */
    protected bool $staging = false;
    
    /**
     * 证书模型
     */
    protected SslCertificate $certModel;
    
    public function __construct()
    {
        $this->certBaseDir = \dirname(Env::path_ENV_FILE) . DS . 'ssl' . DS;
        $this->accountKeyPath = $this->certBaseDir . 'account.key';
        $this->acmeDirectory = self::ACME_DIRECTORY_PROD;
        $this->certModel = ObjectManager::getInstance(SslCertificate::class);
        
        // 确保目录存在
        if (!\is_dir($this->certBaseDir)) {
            @\mkdir($this->certBaseDir, 0755, true);
        }
    }
    
    /**
     * 设置是否使用测试环境
     */
    public function setStaging(bool $staging): self
    {
        $this->staging = $staging;
        $this->acmeDirectory = $staging ? self::ACME_DIRECTORY_STAGING : self::ACME_DIRECTORY_PROD;
        return $this;
    }
    
    /**
     * 检查是否为开发环境
     */
    public function isDevelopmentEnvironment(): bool
    {
        $deployMode = Env::get('deploy', 'prod');
        return \in_array($deployMode, ['dev', 'development', 'local'], true);
    }
    
    /**
     * 检查域名是否为本地开发域名（需要自签证书）
     * 
     * 本地域名包括：
     * - localhost
     * - *.local
     * - *.test
     * - *.dev (非真实域名)
     * - 127.0.0.1
     * - IP 地址
     */
    public function isLocalDomain(string $domain): bool
    {
        $domain = \strtolower(\trim($domain));
        
        // localhost 或 IP 地址
        if ($domain === 'localhost' || \filter_var($domain, FILTER_VALIDATE_IP)) {
            return true;
        }
        
        // 本地开发常用后缀
        $localSuffixes = ['.local', '.test', '.dev', '.localhost', '.example'];
        foreach ($localSuffixes as $suffix) {
            if (\str_ends_with($domain, $suffix)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查域名是否解析到本地回环地址
     * 
     * 即使在生产环境，如果域名解析到 127.0.0.1 或其他本地地址，
     * Let's Encrypt 无法验证，需要使用自签证书
     * 
     * @param string $domain 域名
     * @return bool
     */
    public function resolvesToLoopback(string $domain): bool
    {
        $domain = \strtolower(\trim($domain));
        
        // 如果已经是 IP 地址，直接检查
        if (\filter_var($domain, FILTER_VALIDATE_IP)) {
            return $this->isLoopbackIp($domain);
        }
        
        // 解析域名获取 IP
        $ips = @\gethostbynamel($domain);
        
        if (!$ips) {
            // 无法解析域名，可能是本地 hosts 配置
            // 尝试单个解析
            $ip = @\gethostbyname($domain);
            if ($ip === $domain) {
                // 解析失败，域名无法公网访问，使用自签证书
                return true;
            }
            $ips = [$ip];
        }
        
        // 检查所有解析的 IP 是否都是本地地址
        foreach ($ips as $ip) {
            if ($this->isLoopbackIp($ip)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查 IP 是否为本地回环地址或私有地址
     * 
     * @param string $ip IP 地址
     * @return bool
     */
    protected function isLoopbackIp(string $ip): bool
    {
        // IPv4 回环地址: 127.0.0.0/8
        if (\str_starts_with($ip, '127.')) {
            return true;
        }
        
        // IPv6 回环地址
        if ($ip === '::1') {
            return true;
        }
        
        // 私有地址范围（Let's Encrypt 也无法验证）
        // 10.0.0.0/8
        if (\str_starts_with($ip, '10.')) {
            return true;
        }
        
        // 172.16.0.0/12
        if (\preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip)) {
            return true;
        }
        
        // 192.168.0.0/16
        if (\str_starts_with($ip, '192.168.')) {
            return true;
        }
        
        // 169.254.0.0/16 (链路本地)
        if (\str_starts_with($ip, '169.254.')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 为域名自动获取或生成证书
     * 
     * 逻辑：
     * 1. 如果证书已存在且有效，保持不变
     * 2. 开发环境/本地域名：生成自签证书
     * 3. 生产环境/公网域名：申请 Let's Encrypt 证书
     * 
     * @param string $domain 域名
     * @param string $webroot Webroot 路径（Let's Encrypt 需要）
     * @param string $email 邮箱（Let's Encrypt 需要）
     * @param int $websiteId 网站 ID
     * @return array ['success' => bool, 'message' => string, 'cert_path' => string, 'key_path' => string]
     */
    public function ensureCertificate(string $domain, string $webroot = '', string $email = '', int $websiteId = 0): array
    {
        $certDir = $this->getCertificateDir($domain);
        $certPath = $certDir . 'fullchain.pem';
        $keyPath = $certDir . 'privkey.pem';
        
        // 1. 检查证书是否已存在且有效
        if ($this->isCertificateValid($certPath)) {
            $certInfo = $this->parseCertificate($certPath);
            return [
                'success' => true,
                'message' => __('使用已有证书'),
                'cert_path' => $certPath,
                'key_path' => $keyPath,
                'issuer' => $certInfo['issuer'] ?? 'Unknown',
                'expires_at' => $certInfo['expires_at'] ?? '',
                'is_new' => false,
            ];
        }
        
        // 2. 判断使用自签证书还是 Let's Encrypt
        // 条件：开发环境 OR 本地域名格式 OR 域名解析到本地/私有地址
        $useSelfsigned = $this->isDevelopmentEnvironment() 
            || $this->isLocalDomain($domain) 
            || $this->resolvesToLoopback($domain);
        
        if ($useSelfsigned) {
            // 开发环境、本地域名、或解析到本地地址：生成自签证书
            return $this->generateSelfSignedCertificate($domain, $websiteId);
        } else {
            // 生产环境且域名解析到公网 IP：申请 Let's Encrypt 证书
            if (empty($webroot)) {
                $webroot = \defined('PUB') ? PUB : '';
            }
            if (empty($email)) {
                $email = Env::get('admin_email', 'admin@' . $domain);
            }
            return $this->requestCertificate($domain, $webroot, $email, $websiteId);
        }
    }
    
    /**
     * 检查证书是否有效
     */
    public function isCertificateValid(string $certPath): bool
    {
        if (!\is_file($certPath)) {
            return false;
        }
        
        $certData = @\file_get_contents($certPath);
        if (!$certData) {
            return false;
        }
        
        $cert = @\openssl_x509_parse($certData);
        if (!$cert) {
            return false;
        }
        
        // 检查是否过期（提前 7 天视为无效，需续签）
        $expiresAt = $cert['validTo_time_t'] ?? 0;
        return $expiresAt > (\time() + 7 * 24 * 3600);
    }
    
    /**
     * 获取 OpenSSL 配置
     * 
     * 在 Windows 上需要显式指定配置文件路径
     */
    protected function getOpensslConfig(): array
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ];
        
        // Windows 需要指定配置文件
        if (IS_WIN) {
            // 尝试多个常见的配置文件路径
            $possiblePaths = [
                \getenv('OPENSSL_CONF'),
                \dirname(PHP_BINARY) . DS . 'extras' . DS . 'ssl' . DS . 'openssl.cnf',
                \dirname(PHP_BINARY) . DS . 'openssl.cnf',
                'C:\\Program Files\\Common Files\\SSL\\openssl.cnf',
                'C:\\xampp\\apache\\conf\\openssl.cnf',
                'C:\\OpenSSL-Win64\\openssl.cnf',
                'C:\\OpenSSL-Win32\\openssl.cnf',
            ];
            
            foreach ($possiblePaths as $path) {
                if ($path && \is_file($path)) {
                    $config['config'] = $path;
                    break;
                }
            }
            
            // 如果找不到配置文件，创建一个最小配置
            if (!isset($config['config'])) {
                $tempConfig = $this->certBaseDir . 'openssl.cnf';
                if (!\is_file($tempConfig)) {
                    $minimalConfig = <<<'CNF'
[ req ]
default_bits = 2048
default_md = sha256
distinguished_name = dn
x509_extensions = v3_ca

[ dn ]
CN = localhost

[ v3_ca ]
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer
basicConstraints = critical, CA:true
keyUsage = critical, digitalSignature, cRLSign, keyCertSign
CNF;
                    @\file_put_contents($tempConfig, $minimalConfig);
                }
                if (\is_file($tempConfig)) {
                    $config['config'] = $tempConfig;
                }
            }
        }
        
        return $config;
    }
    
    /**
     * 生成自签证书（用于开发环境）
     * 
     * @param string $domain 域名
     * @param int $websiteId 网站 ID
     * @param int $validDays 有效天数（默认 365 天）
     * @return array
     */
    public function generateSelfSignedCertificate(string $domain, int $websiteId = 0, int $validDays = 365): array
    {
        try {
            $certDir = $this->getCertificateDir($domain);
            $certPath = $certDir . 'fullchain.pem';
            $keyPath = $certDir . 'privkey.pem';
            
            // 获取 OpenSSL 配置（处理 Windows 兼容性）
            $opensslConfig = $this->getOpensslConfig();
            
            // 生成私钥
            $privateKey = \openssl_pkey_new($opensslConfig);
            
            if (!$privateKey) {
                // 输出详细错误
                $errors = [];
                while ($msg = \openssl_error_string()) {
                    $errors[] = $msg;
                }
                $errorMsg = __('生成私钥失败');
                if ($errors) {
                    $errorMsg .= ': ' . \implode(', ', $errors);
                }
                return ['success' => false, 'message' => $errorMsg];
            }
            
            // 证书主体信息
            $dn = [
                'countryName' => 'CN',
                'stateOrProvinceName' => 'Development',
                'localityName' => 'Local',
                'organizationName' => 'Weline Framework',
                'organizationalUnitName' => 'Development',
                'commonName' => $domain,
                'emailAddress' => 'dev@' . $domain,
            ];
            
            // 生成 CSR（使用相同的配置）
            $csr = \openssl_csr_new($dn, $privateKey, $opensslConfig);
            if (!$csr) {
                return ['success' => false, 'message' => __('生成 CSR 失败')];
            }
            
            // 自签证书（使用相同的配置）
            $cert = \openssl_csr_sign($csr, null, $privateKey, $validDays, $opensslConfig);
            if (!$cert) {
                return ['success' => false, 'message' => __('签发证书失败')];
            }
            
            // 导出证书和私钥（Windows 需要传递配置）
            \openssl_x509_export($cert, $certPem);
            
            $exportConfig = [];
            if (isset($opensslConfig['config'])) {
                $exportConfig['config'] = $opensslConfig['config'];
            }
            
            if (!\openssl_pkey_export($privateKey, $keyPem, null, $exportConfig)) {
                $errors = [];
                while ($msg = \openssl_error_string()) {
                    $errors[] = $msg;
                }
                return ['success' => false, 'message' => __('导出私钥失败') . ': ' . \implode(', ', $errors)];
            }
            
            // 保存文件
            if (!$certPem || !\file_put_contents($certPath, $certPem)) {
                return ['success' => false, 'message' => __('保存证书文件失败')];
            }
            if (!$keyPem || !\file_put_contents($keyPath, $keyPem)) {
                return ['success' => false, 'message' => __('保存私钥文件失败')];
            }
            
            // 设置文件权限
            @\chmod($certPath, 0644);
            @\chmod($keyPath, 0600);
            
            // 更新数据库记录
            $this->updateCertificateRecord($domain, $certPath, $keyPath, self::ISSUER_SELF_SIGNED, $validDays, $websiteId);
            
            return [
                'success' => true,
                'message' => __('自签证书生成成功'),
                'cert_path' => $certPath,
                'key_path' => $keyPath,
                'issuer' => self::ISSUER_SELF_SIGNED,
                'expires_at' => \date('Y-m-d H:i:s', \strtotime("+{$validDays} days")),
                'is_new' => true,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * 更新证书数据库记录
     * 
     * 证书签发成功后：
     * 1. 写入/更新 SslCertificate 记录
     * 2. 触发事件通知其他模块同步 HTTPS 状态
     */
    protected function updateCertificateRecord(
        string $domain,
        string $certPath,
        string $keyPath,
        string $issuer,
        int $validDays,
        int $websiteId = 0
    ): void {
        try {
            $cert = $this->certModel->clearQuery()->loadByDomain($domain);
            $isRenewal = $cert->getCertId() > 0;
            $oldExpiresAt = $isRenewal ? $cert->getExpiresAt() : null;
            
            if (!$cert->getCertId()) {
                $cert = ObjectManager::getInstance(SslCertificate::class);
            }
            
            // 判断证书类型
            $certType = \str_starts_with($domain, '*.') 
                ? SslCertificate::CERT_TYPE_WILDCARD 
                : SslCertificate::CERT_TYPE_EXACT;
            
            $expiresAt = \date('Y-m-d H:i:s', \strtotime("+{$validDays} days"));
            
            $cert->setDomain($domain)
                ->setWebsiteId($websiteId)
                ->setCertPath($certPath)
                ->setKeyPath($keyPath)
                ->setCertType($certType)
                ->setIssuer($issuer)
                ->setIssuedAt(\date('Y-m-d H:i:s'))
                ->setExpiresAt($expiresAt)
                ->setStatus(SslCertificate::STATUS_ACTIVE)
                ->setHttpsEnabled(true)
                ->setAutoRenew($issuer !== self::ISSUER_SELF_SIGNED) // 自签证书不自动续签
                ->save();
            
            // 触发事件通知其他模块（使用事件机制解耦）
            if ($isRenewal) {
                // 证书续签
                $this->dispatchCertificateRenewedEvent(
                    $domain,
                    $cert->getCertId(),
                    $oldExpiresAt,
                    $expiresAt
                );
            } else {
                // 新证书签发
                $this->dispatchCertificateIssuedEvent(
                    $domain,
                    $cert->getCertId(),
                    $certPath,
                    $keyPath,
                    $issuer,
                    $expiresAt,
                    $certType
                );
            }
            
        } catch (\Throwable $e) {
            // 数据库更新失败不影响证书生成
            \error_log('[SslCertificateService] ' . __('更新证书记录失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 触发证书签发完成事件
     * 
     * 使用事件机制通知其他模块证书已签发，解耦模块间依赖
     * 
     * @param string $domain 域名
     * @param int $certId 证书 ID
     * @param string $certPath 证书路径
     * @param string $keyPath 私钥路径
     * @param string $issuer 颁发者
     * @param string $expiresAt 过期时间
     * @param string $certType 证书类型
     */
    protected function dispatchCertificateIssuedEvent(
        string $domain,
        int $certId,
        string $certPath,
        string $keyPath,
        string $issuer,
        string $expiresAt,
        string $certType
    ): void {
        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            
            $eventsManager->dispatch('Weline_Server::domain::certificate_issued', [
                'domain' => $domain,
                'cert_id' => $certId,
                'cert_path' => $certPath,
                'key_path' => $keyPath,
                'issuer' => $issuer,
                'expires_at' => $expiresAt,
                'cert_type' => $certType,
            ]);
        } catch (\Throwable $e) {
            // 事件调度失败不影响主流程
            \error_log('[SslCertificateService] ' . __('证书签发事件调度失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 触发证书禁用事件
     * 
     * 使用事件机制通知其他模块 HTTPS 已禁用
     * 
     * @param string $domain 域名
     * @param int|null $certId 证书 ID
     * @param string $reason 禁用原因
     */
    public function dispatchCertificateDisabledEvent(string $domain, ?int $certId = null, string $reason = ''): void
    {
        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            
            $eventsManager->dispatch('Weline_Server::domain::certificate_disabled', [
                'domain' => $domain,
                'cert_id' => $certId,
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            \error_log('[SslCertificateService] ' . __('证书禁用事件调度失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 触发证书更新事件
     * 
     * @param string $domain 域名
     * @param int $certId 证书 ID
     * @param string|null $oldExpiresAt 旧过期时间
     * @param string $newExpiresAt 新过期时间
     */
    protected function dispatchCertificateRenewedEvent(
        string $domain,
        int $certId,
        ?string $oldExpiresAt,
        string $newExpiresAt
    ): void {
        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            
            $eventsManager->dispatch('Weline_Server::domain::certificate_renewed', [
                'domain' => $domain,
                'cert_id' => $certId,
                'old_expires_at' => $oldExpiresAt,
                'new_expires_at' => $newExpiresAt,
            ]);
        } catch (\Throwable $e) {
            \error_log('[SslCertificateService] ' . __('证书更新事件调度失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 请求域名列表（通过事件获取）
     * 
     * 触发集成事件，让其他模块提供域名数据
     * 
     * @param array $filter 过滤条件
     * @return array 域名列表
     */
    public function requestDomainList(array $filter = []): array
    {
        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            
            $eventData = [
                'filter' => $filter,
                'domains' => [],
            ];
            
            $eventsManager->dispatch('Weline_Server::integration::domain_list_requested', $eventData);
            
            // 获取事件中填充的域名数据
            return $eventData['domains'] ?? [];
        } catch (\Throwable $e) {
            \error_log('[SslCertificateService] ' . __('请求域名列表失败：%{1}', [$e->getMessage()]));
            return [];
        }
    }
    
    /**
     * @deprecated 使用事件机制替代直接类调用
     * 禁用域名的 HTTPS（证书失效或删除时调用）
     */
    public function disableHttpsForDomain(string $domain): void
    {
        $this->dispatchCertificateDisabledEvent($domain, null, __('手动禁用'));
    }
    
    /**
     * 获取证书存储目录
     */
    public function getCertificateDir(string $domain): string
    {
        $dir = $this->certBaseDir . $domain . DS;
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        return $dir;
    }
    
    /**
     * 获取所有证书目录映射（用于 SNI）
     * 
     * @return array [domain => [cert => path, key => path], ...]
     */
    public function getCertificateMap(): array
    {
        $certificates = $this->certModel->clearQuery()
            ->where(SslCertificate::fields_STATUS, SslCertificate::STATUS_ACTIVE)
            ->where(SslCertificate::fields_HTTPS_ENABLED, 1)
            ->select()
            ->fetchArray();
        
        $map = [];
        foreach ($certificates as $cert) {
            $domain = $cert[SslCertificate::fields_DOMAIN];
            $certPath = $cert[SslCertificate::fields_CERT_PATH];
            $keyPath = $cert[SslCertificate::fields_KEY_PATH];
            
            if (\is_file($certPath) && \is_file($keyPath)) {
                $map[$domain] = [
                    'cert' => $certPath,
                    'key' => $keyPath,
                    'chain' => $cert[SslCertificate::fields_CHAIN_PATH] ?? '',
                ];
            }
        }
        
        return $map;
    }
    
    /**
     * 为域名申请证书
     * 
     * @param string $domain 域名
     * @param string $webroot Webroot 路径（用于 HTTP-01 验证）
     * @param string $email 联系邮箱
     * @param int $websiteId 关联的网站 ID
     * @return array ['success' => bool, 'message' => string, 'cert' => SslCertificate|null]
     */
    public function requestCertificate(string $domain, string $webroot, string $email, int $websiteId = 0): array
    {
        try {
            // 1. 确保账户密钥存在
            if (!$this->ensureAccountKey()) {
                return ['success' => false, 'message' => __('无法创建账户密钥'), 'cert' => null];
            }
            
            // 2. 创建或获取证书记录
            $cert = $this->certModel->clearQuery()->loadByDomain($domain);
            if (!$cert->getCertId()) {
                $cert = ObjectManager::getInstance(SslCertificate::class);
                $certType = \str_starts_with($domain, '*.') 
                    ? SslCertificate::CERT_TYPE_WILDCARD 
                    : SslCertificate::CERT_TYPE_EXACT;
                $cert->setDomain($domain)
                    ->setWebsiteId($websiteId)
                    ->setCertType($certType)
                    ->setStatus(SslCertificate::STATUS_PENDING)
                    ->setAutoRenew(true);
            }
            
            // 3. 设置证书路径
            $certDir = $this->getCertificateDir($domain);
            $certPath = $certDir . 'fullchain.pem';
            $keyPath = $certDir . 'privkey.pem';
            $chainPath = $certDir . 'chain.pem';
            
            $cert->setCertPath($certPath)
                ->setKeyPath($keyPath)
                ->setChainPath($chainPath);
            
            // 4. 使用 ACME 协议申请证书
            $result = $this->performAcmeChallenge($domain, $webroot, $email, $certDir);
            
            if ($result['success']) {
                // 更新证书信息
                $certInfo = $this->parseCertificate($certPath);
                $expiresAt = $certInfo['expires_at'] ?? \date('Y-m-d H:i:s', \strtotime('+90 days'));
                $issuer = $certInfo['issuer'] ?? "Let's Encrypt";
                
                $cert->setIssuedAt($certInfo['issued_at'] ?? \date('Y-m-d H:i:s'))
                    ->setExpiresAt($expiresAt)
                    ->setIssuer($issuer)
                    ->setStatus(SslCertificate::STATUS_ACTIVE)
                    ->setLastRenewAt(\date('Y-m-d H:i:s'))
                    ->setRenewError('')
                    ->save();
                
                // 触发证书签发事件（通过事件解耦模块间依赖）
                $this->dispatchCertificateIssuedEvent(
                    $domain,
                    $cert->getCertId(),
                    $certPath,
                    $keyPath,
                    $issuer,
                    $expiresAt,
                    $cert->getCertType()
                );
                
                return ['success' => true, 'message' => __('证书申请成功'), 'cert' => $cert];
            } else {
                $cert->setStatus(SslCertificate::STATUS_ERROR)
                    ->setRenewError($result['message'])
                    ->save();
                
                return ['success' => false, 'message' => $result['message'], 'cert' => $cert];
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'cert' => null];
        }
    }
    
    /**
     * 续签证书
     */
    public function renewCertificate(SslCertificate $cert, string $webroot, string $email): array
    {
        return $this->requestCertificate(
            $cert->getDomain(),
            $webroot,
            $email,
            $cert->getWebsiteId()
        );
    }
    
    /**
     * 续签所有即将过期的证书
     * 
     * @param string $webroot Webroot 路径
     * @param string $email 联系邮箱
     * @param int $days 提前多少天续签
     * @return array 续签结果
     */
    public function renewExpiringCertificates(string $webroot, string $email, int $days = 30): array
    {
        $certificates = $this->certModel->getCertificatesNeedRenew($days);
        $results = [];
        
        foreach ($certificates as $certData) {
            $cert = ObjectManager::getInstance(SslCertificate::class);
            $cert->setData($certData);
            
            $result = $this->renewCertificate($cert, $webroot, $email);
            $results[$cert->getDomain()] = $result;
        }
        
        return $results;
    }
    
    /**
     * 确保账户密钥存在
     */
    protected function ensureAccountKey(): bool
    {
        if (\is_file($this->accountKeyPath)) {
            return true;
        }
        
        // 生成 RSA 账户密钥
        $config = [
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        
        $key = \openssl_pkey_new($config);
        if (!$key) {
            return false;
        }
        
        \openssl_pkey_export($key, $privateKey);
        
        return (bool) \file_put_contents($this->accountKeyPath, $privateKey);
    }
    
    /**
     * 执行 ACME 验证并获取证书
     */
    protected function performAcmeChallenge(string $domain, string $webroot, string $email, string $certDir): array
    {
        // 这里实现简化版的 ACME 协议
        // 生产环境建议使用成熟的 ACME 库如 kelunik/acme
        
        try {
            // 1. 获取 ACME 目录
            $directory = $this->getAcmeDirectory();
            if (!$directory) {
                return ['success' => false, 'message' => __('无法获取 ACME 目录')];
            }
            
            // 2. 生成域名密钥
            $domainKeyPath = $certDir . 'domain.key';
            if (!$this->generateDomainKey($domainKeyPath)) {
                return ['success' => false, 'message' => __('无法生成域名密钥')];
            }
            
            // 3. 注册/获取账户
            $accountUrl = $this->registerAccount($directory['newAccount'], $email);
            if (!$accountUrl) {
                return ['success' => false, 'message' => __('账户注册失败')];
            }
            
            // 4. 创建订单
            $orderUrl = $this->createOrder($directory['newOrder'], [$domain], $accountUrl);
            if (!$orderUrl) {
                return ['success' => false, 'message' => __('创建订单失败')];
            }
            
            // 5. 获取授权并完成验证
            $order = $this->getResource($orderUrl, $accountUrl);
            if (!$order || empty($order['authorizations'])) {
                return ['success' => false, 'message' => __('获取授权失败')];
            }
            
            foreach ($order['authorizations'] as $authUrl) {
                $auth = $this->getResource($authUrl, $accountUrl);
                if (!$auth) {
                    return ['success' => false, 'message' => __('获取授权详情失败')];
                }
                
                // 查找 HTTP-01 验证
                $httpChallenge = null;
                foreach ($auth['challenges'] as $challenge) {
                    if ($challenge['type'] === 'http-01') {
                        $httpChallenge = $challenge;
                        break;
                    }
                }
                
                if (!$httpChallenge) {
                    return ['success' => false, 'message' => __('未找到 HTTP-01 验证方式')];
                }
                
                // 创建验证文件
                if (!$this->createHttpChallenge($webroot, $httpChallenge['token'], $httpChallenge['token'])) {
                    return ['success' => false, 'message' => __('创建验证文件失败')];
                }
                
                // 通知 CA 开始验证
                $this->notifyChallenge($httpChallenge['url'], $accountUrl);
                
                // 等待验证完成
                $maxWait = 60;
                $validated = false;
                for ($i = 0; $i < $maxWait; $i++) {
                    \sleep(2);
                    $auth = $this->getResource($authUrl, $accountUrl);
                    if ($auth && $auth['status'] === 'valid') {
                        $validated = true;
                        break;
                    }
                    if ($auth && $auth['status'] === 'invalid') {
                        break;
                    }
                }
                
                // 清理验证文件
                $this->cleanupHttpChallenge($webroot, $httpChallenge['token']);
                
                if (!$validated) {
                    return ['success' => false, 'message' => __('域名验证失败')];
                }
            }
            
            // 6. 完成订单并获取证书
            $csrPath = $certDir . 'csr.pem';
            $csr = $this->generateCsr($domain, $domainKeyPath, $csrPath);
            if (!$csr) {
                return ['success' => false, 'message' => __('生成 CSR 失败')];
            }
            
            // 提交 CSR
            $order = $this->getResource($orderUrl, $accountUrl);
            $certUrl = $this->finalize($order['finalize'], $csr, $accountUrl);
            if (!$certUrl) {
                return ['success' => false, 'message' => __('提交 CSR 失败')];
            }
            
            // 等待证书颁发
            $maxWait = 30;
            $certReady = false;
            for ($i = 0; $i < $maxWait; $i++) {
                \sleep(2);
                $order = $this->getResource($orderUrl, $accountUrl);
                if ($order && $order['status'] === 'valid' && !empty($order['certificate'])) {
                    $certReady = true;
                    $certUrl = $order['certificate'];
                    break;
                }
            }
            
            if (!$certReady) {
                return ['success' => false, 'message' => __('等待证书颁发超时')];
            }
            
            // 下载证书
            $certPem = $this->downloadCertificate($certUrl, $accountUrl);
            if (!$certPem) {
                return ['success' => false, 'message' => __('下载证书失败')];
            }
            
            // 保存证书
            $fullchainPath = $certDir . 'fullchain.pem';
            $privkeyPath = $certDir . 'privkey.pem';
            
            \file_put_contents($fullchainPath, $certPem);
            \copy($domainKeyPath, $privkeyPath);
            
            return ['success' => true, 'message' => __('证书申请成功')];
            
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * 获取 ACME 目录
     */
    protected function getAcmeDirectory(): ?array
    {
        if ($this->directoryCache !== null) {
            return $this->directoryCache;
        }
        
        $response = $this->httpRequest($this->acmeDirectory);
        if ($response && isset($response['newAccount'])) {
            $this->directoryCache = $response;
            return $response;
        }
        
        return null;
    }
    
    /**
     * 生成域名密钥
     */
    protected function generateDomainKey(string $path): bool
    {
        if (\is_file($path)) {
            return true;
        }
        
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        
        $key = \openssl_pkey_new($config);
        if (!$key) {
            return false;
        }
        
        \openssl_pkey_export($key, $privateKey);
        
        return (bool) \file_put_contents($path, $privateKey);
    }
    
    /**
     * 注册账户
     */
    protected function registerAccount(string $url, string $email): ?string
    {
        $payload = [
            'termsOfServiceAgreed' => true,
            'contact' => ["mailto:{$email}"],
        ];
        
        $response = $this->signedRequest($url, $payload);
        
        return $response['headers']['location'] ?? null;
    }
    
    /**
     * 创建订单
     */
    protected function createOrder(string $url, array $domains, string $accountUrl): ?string
    {
        $identifiers = [];
        foreach ($domains as $domain) {
            $identifiers[] = ['type' => 'dns', 'value' => $domain];
        }
        
        $payload = ['identifiers' => $identifiers];
        $response = $this->signedRequest($url, $payload, $accountUrl);
        
        return $response['headers']['location'] ?? null;
    }
    
    /**
     * 获取资源
     */
    protected function getResource(string $url, string $accountUrl): ?array
    {
        $response = $this->signedRequest($url, '', $accountUrl);
        return $response['body'] ?? null;
    }
    
    /**
     * 创建 HTTP 验证文件
     */
    protected function createHttpChallenge(string $webroot, string $token, string $keyAuth): bool
    {
        $challengeDir = $webroot . DS . '.well-known' . DS . 'acme-challenge' . DS;
        if (!\is_dir($challengeDir)) {
            @\mkdir($challengeDir, 0755, true);
        }
        
        $thumbprint = $this->getAccountThumbprint();
        $content = $token . '.' . $thumbprint;
        
        return (bool) \file_put_contents($challengeDir . $token, $content);
    }
    
    /**
     * 清理 HTTP 验证文件
     */
    protected function cleanupHttpChallenge(string $webroot, string $token): void
    {
        $file = $webroot . DS . '.well-known' . DS . 'acme-challenge' . DS . $token;
        if (\is_file($file)) {
            @\unlink($file);
        }
    }
    
    /**
     * 通知验证
     */
    protected function notifyChallenge(string $url, string $accountUrl): void
    {
        $this->signedRequest($url, new \stdClass(), $accountUrl);
    }
    
    /**
     * 生成 CSR
     */
    protected function generateCsr(string $domain, string $keyPath, string $csrPath): ?string
    {
        $key = \openssl_pkey_get_private(\file_get_contents($keyPath));
        if (!$key) {
            return null;
        }
        
        $dn = ['commonName' => $domain];
        $csr = \openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        if (!$csr) {
            return null;
        }
        
        \openssl_csr_export($csr, $csrPem);
        \file_put_contents($csrPath, $csrPem);
        
        // 转换为 DER 格式
        $csrDer = $this->csrToDer($csrPem);
        return $this->base64UrlEncode($csrDer);
    }
    
    /**
     * 完成订单
     */
    protected function finalize(string $url, string $csr, string $accountUrl): ?string
    {
        $payload = ['csr' => $csr];
        $response = $this->signedRequest($url, $payload, $accountUrl);
        
        return $response['body']['certificate'] ?? null;
    }
    
    /**
     * 下载证书
     */
    protected function downloadCertificate(string $url, string $accountUrl): ?string
    {
        $response = $this->signedRequest($url, '', $accountUrl);
        return $response['raw'] ?? null;
    }
    
    /**
     * 解析证书信息
     */
    public function parseCertificate(string $certPath): array
    {
        if (!\is_file($certPath)) {
            return [];
        }
        
        $certData = \file_get_contents($certPath);
        $cert = \openssl_x509_parse($certData);
        
        if (!$cert) {
            return [];
        }
        
        return [
            'issued_at' => \date('Y-m-d H:i:s', $cert['validFrom_time_t'] ?? \time()),
            'expires_at' => \date('Y-m-d H:i:s', $cert['validTo_time_t'] ?? \strtotime('+90 days')),
            'issuer' => $cert['issuer']['O'] ?? "Let's Encrypt",
            'subject' => $cert['subject']['CN'] ?? '',
        ];
    }
    
    /**
     * 获取账户密钥指纹
     */
    protected function getAccountThumbprint(): string
    {
        $key = \openssl_pkey_get_private(\file_get_contents($this->accountKeyPath));
        $details = \openssl_pkey_get_details($key);
        
        $jwk = [
            'e' => $this->base64UrlEncode($details['rsa']['e']),
            'kty' => 'RSA',
            'n' => $this->base64UrlEncode($details['rsa']['n']),
        ];
        
        return $this->base64UrlEncode(\hash('sha256', \json_encode($jwk), true));
    }
    
    /**
     * 签名请求
     */
    protected function signedRequest(string $url, $payload, ?string $kid = null): array
    {
        $key = \openssl_pkey_get_private(\file_get_contents($this->accountKeyPath));
        $details = \openssl_pkey_get_details($key);
        
        $nonce = $this->getNonce();
        
        $header = [
            'alg' => 'RS256',
            'nonce' => $nonce,
            'url' => $url,
        ];
        
        if ($kid) {
            $header['kid'] = $kid;
        } else {
            $header['jwk'] = [
                'e' => $this->base64UrlEncode($details['rsa']['e']),
                'kty' => 'RSA',
                'n' => $this->base64UrlEncode($details['rsa']['n']),
            ];
        }
        
        $protected = $this->base64UrlEncode(\json_encode($header));
        $payloadB64 = $payload === '' ? '' : $this->base64UrlEncode(\json_encode($payload));
        
        \openssl_sign($protected . '.' . $payloadB64, $signature, $key, OPENSSL_ALGO_SHA256);
        
        $body = \json_encode([
            'protected' => $protected,
            'payload' => $payloadB64,
            'signature' => $this->base64UrlEncode($signature),
        ]);
        
        return $this->httpRequest($url, 'POST', $body);
    }
    
    /**
     * 获取 Nonce
     */
    protected function getNonce(): string
    {
        $directory = $this->getAcmeDirectory();
        if (!$directory || empty($directory['newNonce'])) {
            return '';
        }
        
        $ch = \curl_init($directory['newNonce']);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_HEADER, true);
        \curl_setopt($ch, CURLOPT_NOBODY, true);
        $response = \curl_exec($ch);
        \curl_close($ch);
        
        if (\preg_match('/replay-nonce:\s*(\S+)/i', $response, $matches)) {
            return $matches[1];
        }
        
        return '';
    }
    
    /**
     * HTTP 请求
     */
    protected function httpRequest(string $url, string $method = 'GET', ?string $body = null): array
    {
        $ch = \curl_init($url);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_HEADER, true);
        
        if ($method === 'POST') {
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            \curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/jose+json']);
        }
        
        $response = \curl_exec($ch);
        $headerSize = \curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        \curl_close($ch);
        
        $headerStr = \substr($response, 0, $headerSize);
        $bodyStr = \substr($response, $headerSize);
        
        // 解析响应头
        $headers = [];
        foreach (\explode("\r\n", $headerStr) as $line) {
            if (\strpos($line, ':') !== false) {
                [$key, $value] = \explode(':', $line, 2);
                $headers[\strtolower(\trim($key))] = \trim($value);
            }
        }
        
        return [
            'headers' => $headers,
            'body' => \json_decode($bodyStr, true),
            'raw' => $bodyStr,
        ];
    }
    
    /**
     * Base64 URL 编码
     */
    protected function base64UrlEncode(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * CSR 转 DER 格式
     */
    protected function csrToDer(string $pem): string
    {
        $pem = \preg_replace('/-----(BEGIN|END) CERTIFICATE REQUEST-----/', '', $pem);
        return \base64_decode(\str_replace(["\r", "\n", ' '], '', $pem));
    }
    
    /**
     * 切换域名 HTTPS 状态
     * 
     * 同时通过事件同步更新所有关联模块的 HTTPS 状态
     */
    public function toggleHttps(string $domain, bool $enabled): array
    {
        try {
            $cert = $this->certModel->clearQuery()->loadByDomain($domain);
            
            if (!$cert->getCertId()) {
                return ['success' => false, 'message' => __('未找到域名证书：%{1}', [$domain])];
            }
            
            if ($enabled) {
                // 启用 HTTPS 前检查证书文件
                if (!$cert->certificateFilesExist()) {
                    return ['success' => false, 'message' => __('证书文件不存在，无法启用 HTTPS')];
                }
                if ($cert->isExpired()) {
                    return ['success' => false, 'message' => __('证书已过期，请先续签')];
                }
            }
            
            $cert->setHttpsEnabled($enabled)->save();
            
            // 通过事件同步 HTTPS 状态（解耦模块间依赖）
            if ($enabled) {
                $this->dispatchCertificateIssuedEvent(
                    $domain,
                    $cert->getCertId(),
                    $cert->getCertPath(),
                    $cert->getKeyPath(),
                    $cert->getIssuer(),
                    $cert->getExpiresAt(),
                    $cert->getCertType()
                );
            } else {
                $this->dispatchCertificateDisabledEvent($domain, $cert->getCertId(), __('用户手动禁用'));
            }
            
            // 清理服务器缓存，确保配置立即生效
            $this->clearServerCache();
            
            return [
                'success' => true,
                'message' => $enabled ? __('HTTPS 已启用') : __('HTTPS 已禁用'),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * 清理服务器缓存
     */
    protected function clearServerCache(): void
    {
        // 删除证书映射缓存
        $cacheFile = Env::VAR_DIR . 'cache' . DS . 'ssl_certificate_map.json';
        if (\is_file($cacheFile)) {
            @\unlink($cacheFile);
        }
        
        // 通知服务器重新加载证书配置
        $reloadFlagFile = Env::VAR_DIR . 'server' . DS . 'ssl_reload_flag';
        @\file_put_contents($reloadFlagFile, \time());
    }
}
