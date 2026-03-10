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
     * 证书申请提供商
     */
    public const PROVIDER_LETS_ENCRYPT = 'letsencrypt';
    public const PROVIDER_LITESSL = 'litessl';
    public const PROVIDER_SELF_SIGNED = 'self_signed';
    
    /**
     * Let's Encrypt ACME 目录
     */
    protected const ACME_DIRECTORY_PROD = 'https://acme-v02.api.letsencrypt.org/directory';
    protected const ACME_DIRECTORY_STAGING = 'https://acme-staging-v02.api.letsencrypt.org/directory';
    
    /**
     * LiteSSL ACME 目录（Sectigo DV）
     */
    protected const ACME_DIRECTORY_LITESSL_PROD = 'https://acme.sectigo.com/v2/DV';
    
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
     * ACME 提供商
     */
    protected string $acmeProvider = self::PROVIDER_LETS_ENCRYPT;
    
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
    
    /**
     * 判断缓存：本地域名 [domain => bool]
     */
    protected array $localDomainCache = [];
    
    /**
     * 判断缓存：解析到回环 [domain => bool]
     */
    protected array $loopbackResolveCache = [];
    
    /**
     * 判断缓存：回环/内网 IP [ip => bool]
     */
    protected array $loopbackIpCache = [];
    
    /**
     * 判断缓存：需要自签证书 [host => bool]
     */
    protected array $needsSelfSignedCache = [];
    
    /**
     * DNS 解析缓存 [domain => ip[]]
     */
    protected array $dnsResolveCache = [];
    
    /**
     * SAN 条目缓存 [domain => ['dns' => [], 'ip' => []]]
     */
    protected array $sanEntriesCache = [];
    
    /**
     * 证书匹配缓存 [certPath:host => bool]
     */
    protected array $certMatchCache = [];
    
    /**
     * 证书解析缓存 [certPath => parsed_cert_array|false]
     */
    protected array $certParseCache = [];
    
    public function __construct()
    {
        $this->certBaseDir = \dirname(Env::path_ENV_FILE) . DS . 'ssl' . DS;
        $this->accountKeyPath = $this->certBaseDir . 'account.key';
        $this->updateAcmeDirectory();
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
        $this->updateAcmeDirectory();
        return $this;
    }
    
    /**
     * 设置 ACME 提供商
     */
    public function setAcmeProvider(string $provider): self
    {
        $this->acmeProvider = $this->normalizeAcmeProvider($provider);
        $this->updateAcmeDirectory();
        return $this;
    }
    
    /**
     * 获取 ACME 提供商
     */
    public function getAcmeProvider(): string
    {
        return $this->acmeProvider;
    }
    
    /**
     * 更新 ACME 目录
     */
    protected function updateAcmeDirectory(): void
    {
        $directory = $this->resolveAcmeDirectory($this->acmeProvider, $this->staging);
        if ($directory !== null) {
            if (!isset($this->acmeDirectory) || $this->acmeDirectory !== $directory) {
                $this->directoryCache = null;
            }
            $this->acmeDirectory = $directory;
        }
    }
    
    /**
     * 解析 ACME 目录
     */
    protected function resolveAcmeDirectory(string $provider, bool $staging): ?string
    {
        return match ($provider) {
            self::PROVIDER_LETS_ENCRYPT => $staging ? self::ACME_DIRECTORY_STAGING : self::ACME_DIRECTORY_PROD,
            self::PROVIDER_LITESSL => $staging ? null : self::ACME_DIRECTORY_LITESSL_PROD,
            default => null,
        };
    }
    
    /**
     * 规范化 ACME 提供商
     */
    protected function normalizeAcmeProvider(string $provider): string
    {
        $provider = \strtolower(\trim($provider));
        return match ($provider) {
            '', 'letsencrypt', 'let\'s encrypt', 'le' => self::PROVIDER_LETS_ENCRYPT,
            'litessl', 'lite-ssl', 'lite_ssl' => self::PROVIDER_LITESSL,
            'self-signed', 'self_signed', 'selfsigned' => self::PROVIDER_SELF_SIGNED,
            default => $provider,
        };
    }
    
    /**
     * 判断是否支持的 ACME 提供商
     */
    protected function isSupportedProvider(string $provider): bool
    {
        return \in_array($provider, [self::PROVIDER_LETS_ENCRYPT, self::PROVIDER_LITESSL, self::PROVIDER_SELF_SIGNED], true);
    }
    
    /**
     * 检查是否为开发环境
     */
    public function isDevelopmentEnvironment(): bool
    {
        $deployMode = Env::system('deploy') ?? 'prod';
        return \in_array($deployMode, ['dev', 'development', 'local'], true);
    }
    
    /**
     * 申请/启用 HTTPS 前环境检查（仅 Windows）
     * 在 no-SSL 环境下申请证书前调用，若当前为 Windows 且未安装 event 扩展，
     * 返回提示信息：申请证书后无法启动 HTTPS，需先安装 event 扩展。
     *
     * @return string|null 需要提示时返回文案，否则返回 null
     */
    public function getHttpsReadinessWarning(): ?string
    {
        if (!\defined('IS_WIN') || !IS_WIN) {
            return null;
        }
        if (\extension_loaded('event')) {
            return null;
        }
        return __('当前为 Windows 且未安装 PHP event 扩展。申请证书后若要启用 HTTPS，需先安装 event 扩展，否则无法启动 HTTPS 服务。请先安装 event 后再申请证书。下载：%{1}', ['https://windows.php.net/downloads/pecl/releases/event/']);
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
        
        // 缓存命中
        if (isset($this->localDomainCache[$domain])) {
            return $this->localDomainCache[$domain];
        }
        
        // localhost 或 IP 地址
        if ($domain === 'localhost' || \filter_var($domain, FILTER_VALIDATE_IP)) {
            return $this->localDomainCache[$domain] = true;
        }
        
        // 本地开发常用后缀
        $localSuffixes = ['.local', '.test', '.dev', '.localhost', '.example'];
        foreach ($localSuffixes as $suffix) {
            if (\str_ends_with($domain, $suffix)) {
                return $this->localDomainCache[$domain] = true;
            }
        }
        
        return $this->localDomainCache[$domain] = false;
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
        
        // 缓存命中
        if (isset($this->loopbackResolveCache[$domain])) {
            return $this->loopbackResolveCache[$domain];
        }
        
        // 如果已经是 IP 地址，直接检查
        if (\filter_var($domain, FILTER_VALIDATE_IP)) {
            return $this->loopbackResolveCache[$domain] = $this->isLoopbackIp($domain);
        }
        
        // 解析域名获取 IP（使用缓存）
        $ips = $this->resolveDomainIps($domain);
        
        if (empty($ips)) {
            // 解析失败，域名无法公网访问，使用自签证书
            return $this->loopbackResolveCache[$domain] = true;
        }
        
        // 检查所有解析的 IP 是否有本地地址
        foreach ($ips as $ip) {
            if ($this->isLoopbackIp($ip)) {
                return $this->loopbackResolveCache[$domain] = true;
            }
        }
        
        return $this->loopbackResolveCache[$domain] = false;
    }
    
    /**
     * 检查 IP 是否为本地回环地址或私有地址
     * 
     * @param string $ip IP 地址
     * @return bool
     */
    protected function isLoopbackIp(string $ip): bool
    {
        // 缓存命中
        if (isset($this->loopbackIpCache[$ip])) {
            return $this->loopbackIpCache[$ip];
        }
        
        // IPv4 回环地址: 127.0.0.0/8
        if (\str_starts_with($ip, '127.')) {
            return $this->loopbackIpCache[$ip] = true;
        }
        
        // IPv6 回环地址
        if ($ip === '::1') {
            return $this->loopbackIpCache[$ip] = true;
        }
        
        // 私有地址范围（Let's Encrypt 也无法验证）
        // 10.0.0.0/8
        if (\str_starts_with($ip, '10.')) {
            return $this->loopbackIpCache[$ip] = true;
        }
        
        // 172.16.0.0/12
        if (\preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip)) {
            return $this->loopbackIpCache[$ip] = true;
        }
        
        // 192.168.0.0/16
        if (\str_starts_with($ip, '192.168.')) {
            return $this->loopbackIpCache[$ip] = true;
        }
        
        // 169.254.0.0/16 (链路本地)
        if (\str_starts_with($ip, '169.254.')) {
            return $this->loopbackIpCache[$ip] = true;
        }
        
        return $this->loopbackIpCache[$ip] = false;
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
        // 0. 若后台已禁用该域名的 HTTPS，直接返回「不使用 SSL」
        $cert = $this->certModel->clearQuery()->loadByDomain($domain);
        if ($cert->getCertId() && !$cert->getHttpsEnabled()) {
            return [
                'success' => true,
                'message' => __('HTTPS 已在此域名禁用'),
                'cert_path' => '',
                'key_path' => '',
                'issuer' => '',
                'expires_at' => '',
                'is_new' => false,
                'ssl_enabled' => false,
            ];
        }
        
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
                'ssl_enabled' => true,
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
        // 使用缓存的证书解析
        $cert = $this->getParsedCertificateRaw($certPath);
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
     * 获取用于自签证书的 OpenSSL 配置
     * 
     * 自动判断域名是否为本地/内网环境，并生成包含正确 SAN 的配置：
     * - localhost/127.0.0.1 → DNS:localhost + IP:127.0.0.1
     * - 内网 IP（10.x, 172.16-31.x, 192.168.x）→ IP:x.x.x.x
     * - 本地域名（*.local, *.test 等）→ DNS:domain + 解析的 IP
     * - 解析到内网/回环的公网域名 → DNS:domain + IP:解析地址
     */
    protected function getOpensslConfigForSelfSigned(string $domain): array
    {
        $opensslConfig = $this->getOpensslConfig();
        $domain = \strtolower(\trim($domain));
        
        // 判断是否需要本地/内网 SAN 配置
        $needLocalSan = $this->isLocalDomain($domain) || $this->resolvesToLoopback($domain);
        if (!$needLocalSan) {
            return $opensslConfig;
        }
        
        // 收集 SAN 条目
        $sanEntries = $this->collectSanEntries($domain);
        if (empty($sanEntries['dns']) && empty($sanEntries['ip'])) {
            return $opensslConfig;
        }
        
        // 生成 SAN 配置文件（按域名哈希命名，避免冲突；始终覆盖以保证配置格式更新后生效，如 macOS/LibreSSL 兼容）
        $configHash = \md5($domain . \serialize($sanEntries));
        $sanConfigPath = $this->certBaseDir . "openssl_san_{$configHash}.cnf";
        $sanConfig = $this->buildSanOpenSslConfig($domain, $sanEntries);
        @\file_put_contents($sanConfigPath, $sanConfig);
        
        if (\is_file($sanConfigPath)) {
            $opensslConfig['config'] = $sanConfigPath;
        }
        return $opensslConfig;
    }
    
    /**
     * 收集域名的 SAN 条目（DNS 和 IP）
     * 
     * @param string $domain 域名或 IP
     * @return array ['dns' => [...], 'ip' => [...]]
     */
    protected function collectSanEntries(string $domain): array
    {
        $domain = \strtolower(\trim($domain));
        
        // 缓存命中
        if (isset($this->sanEntriesCache[$domain])) {
            return $this->sanEntriesCache[$domain];
        }
        
        $dns = [];
        $ip = [];
        
        // 1. 处理 IP 地址
        if (\filter_var($domain, FILTER_VALIDATE_IP)) {
            $ip[] = $domain;
            // 127.0.0.1 同时加 localhost
            if ($domain === '127.0.0.1') {
                $dns[] = 'localhost';
            }
            return $this->sanEntriesCache[$domain] = ['dns' => $dns, 'ip' => $ip];
        }
        
        // 2. 处理域名
        $dns[] = $domain;
        
        // localhost 特殊处理：同时包含 127.0.0.1 和 ::1
        if ($domain === 'localhost') {
            $ip[] = '127.0.0.1';
            $ip[] = '::1';
            return $this->sanEntriesCache[$domain] = ['dns' => $dns, 'ip' => $ip];
        }
        
        // 3. 解析域名获取 IP
        $resolvedIps = $this->resolveDomainIps($domain);
        foreach ($resolvedIps as $resolvedIp) {
            // 只添加本地/内网 IP 到 SAN（公网 IP 不需要）
            if ($this->isLoopbackIp($resolvedIp)) {
                $ip[] = $resolvedIp;
            }
        }
        
        // 如果域名是 *.localhost 类型，也加上 127.0.0.1
        if (\str_ends_with($domain, '.localhost') && !\in_array('127.0.0.1', $ip, true)) {
            $ip[] = '127.0.0.1';
        }
        
        return $this->sanEntriesCache[$domain] = ['dns' => \array_unique($dns), 'ip' => \array_unique($ip)];
    }
    
    /**
     * 解析域名获取所有 IP 地址
     */
    protected function resolveDomainIps(string $domain): array
    {
        $domain = \strtolower(\trim($domain));
        
        // 缓存命中
        if (isset($this->dnsResolveCache[$domain])) {
            return $this->dnsResolveCache[$domain];
        }
        
        $ips = @\gethostbynamel($domain);
        if ($ips) {
            return $this->dnsResolveCache[$domain] = $ips;
        }
        // 尝试单个解析
        $ip = @\gethostbyname($domain);
        if ($ip !== $domain) {
            return $this->dnsResolveCache[$domain] = [$ip];
        }
        return $this->dnsResolveCache[$domain] = [];
    }
    
    /**
     * 生成带 SAN 的 OpenSSL 配置文件内容
     */
    protected function buildSanOpenSslConfig(string $domain, array $sanEntries): string
    {
        $altNames = [];
        $idx = 1;
        foreach ($sanEntries['dns'] as $dns) {
            $altNames[] = "DNS.{$idx} = {$dns}";
            $idx++;
        }
        $idx = 1;
        foreach ($sanEntries['ip'] as $ipAddr) {
            $altNames[] = "IP.{$idx} = {$ipAddr}";
            $idx++;
        }
        $altNamesStr = \implode("\n", $altNames);
        
        // 分离 CSR 与 x509 扩展：macOS/LibreSSL 在 openssl_csr_new 时加载 req_extensions，
        // 若 v3_req 含仅证书适用的扩展（如 basicConstraints/keyUsage 等）会报 "Error loading extension section v3_req"。
        // req_extensions 仅保留 subjectAltName；x509_extensions 用于签发时使用完整扩展。
        return <<<CNF
[ req ]
default_bits = 2048
default_md = sha256
distinguished_name = dn
req_extensions = v3_req
x509_extensions = v3_ca

[ dn ]
CN = {$domain}

[ v3_req ]
subjectAltName = @alt_names

[ v3_ca ]
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer
basicConstraints = CA:true
keyUsage = critical, digitalSignature, keyEncipherment
subjectAltName = @alt_names

[ alt_names ]
{$altNamesStr}
CNF;
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
            
            // 获取 OpenSSL 配置（localhost 时含 SAN，便于浏览器认可）
            $opensslConfig = $this->getOpensslConfigForSelfSigned($domain);
            
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
            $this->updateCertificateRecord(
                $domain,
                $certPath,
                $keyPath,
                self::ISSUER_SELF_SIGNED,
                $validDays,
                $websiteId,
                self::PROVIDER_SELF_SIGNED
            );
            
            return [
                'success' => true,
                'message' => __('自签证书生成成功'),
                'cert_path' => $certPath,
                'key_path' => $keyPath,
                'issuer' => self::ISSUER_SELF_SIGNED,
                'expires_at' => \date('Y-m-d H:i:s', \strtotime("+{$validDays} days")),
                'is_new' => true,
                'ssl_enabled' => true,
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
        int $websiteId = 0,
        string $provider = self::PROVIDER_SELF_SIGNED
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
                ->setProvider($provider)
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
            w_log_error('[SslCertificateService] ' . __('更新证书记录失败：%{1}', [$e->getMessage()]));
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
            
            $data = [
                'domain' => $domain,
                'cert_id' => $certId,
                'cert_path' => $certPath,
                'key_path' => $keyPath,
                'issuer' => $issuer,
                'expires_at' => $expiresAt,
                'cert_type' => $certType,
            ];
            $eventsManager->dispatch('Weline_Server::domain::certificate_issued', $data);
        } catch (\Throwable $e) {
            // 事件调度失败不影响主流程
            w_log_error('[SslCertificateService] ' . __('证书签发事件调度失败：%{1}', [$e->getMessage()]));
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
            
            $data = [
                'domain' => $domain,
                'cert_id' => $certId,
                'reason' => $reason,
            ];
            $eventsManager->dispatch('Weline_Server::domain::certificate_disabled', $data);
        } catch (\Throwable $e) {
            w_log_error('[SslCertificateService] ' . __('证书禁用事件调度失败：%{1}', [$e->getMessage()]));
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
            
            $data = [
                'domain' => $domain,
                'cert_id' => $certId,
                'old_expires_at' => $oldExpiresAt,
                'new_expires_at' => $newExpiresAt,
            ];
            $eventsManager->dispatch('Weline_Server::domain::certificate_renewed', $data);
        } catch (\Throwable $e) {
            w_log_error('[SslCertificateService] ' . __('证书更新事件调度失败：%{1}', [$e->getMessage()]));
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
            w_log_error('[SslCertificateService] ' . __('请求域名列表失败：%{1}', [$e->getMessage()]));
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
     * 将当前正在使用的证书路径同步到证书表（框架级兜底）。
     *
     * 适用于以下场景：
     * - 启动参数直接指定 ssl_cert/ssl_key
     * - 启动时自动检测到 app/etc/ssl 目录证书
     * - 本地开发证书已存在但尚未入库
     */
    public function syncCertificateRecordFromFiles(
        string $domain,
        string $certPath,
        string $keyPath,
        int $websiteId = 0,
        bool $httpsEnabled = true,
        string $provider = ''
    ): ?SslCertificate {
        $domain = \strtolower(\trim($domain));
        if ($domain === '' || !\is_file($certPath) || !\is_file($keyPath)) {
            return null;
        }

        try {
            $cert = $this->certModel->clearQuery()->loadByDomain($domain);
            if (!$cert->getCertId()) {
                $cert = ObjectManager::getInstance(SslCertificate::class);
            }

            $parsed = $this->parseCertificate($certPath);
            $issuer = (string)($parsed['issuer'] ?? '');
            $issuedAt = (string)($parsed['issued_at'] ?? '');
            $expiresAt = (string)($parsed['expires_at'] ?? '');

            $provider = $this->inferProviderByIssuer(
                $provider !== '' ? $provider : (string)$cert->getProvider(),
                $issuer
            );
            $isSelfSigned = ($provider === self::PROVIDER_SELF_SIGNED);

            $certType = \str_starts_with($domain, '*.')
                ? SslCertificate::CERT_TYPE_WILDCARD
                : SslCertificate::CERT_TYPE_EXACT;

            $status = SslCertificate::STATUS_ACTIVE;
            if ($expiresAt !== '' && \strtotime($expiresAt) < \time()) {
                $status = SslCertificate::STATUS_EXPIRED;
            }

            $chainPath = '';
            $candidateChainPath = \dirname($certPath) . DS . 'chain.pem';
            if (\is_file($candidateChainPath)) {
                $chainPath = $candidateChainPath;
            }

            $cert->setDomain($domain)
                ->setWebsiteId($websiteId)
                ->setCertType($certType)
                ->setCertPath($certPath)
                ->setKeyPath($keyPath)
                ->setChainPath($chainPath)
                ->setIssuer($issuer !== '' ? $issuer : ($isSelfSigned ? self::ISSUER_SELF_SIGNED : "Let's Encrypt"))
                ->setProvider($provider)
                ->setStatus($status)
                ->setHttpsEnabled($httpsEnabled)
                ->setAutoRenew(!$isSelfSigned);

            if ($issuedAt !== '') {
                $cert->setIssuedAt($issuedAt);
            }
            if ($expiresAt !== '') {
                $cert->setExpiresAt($expiresAt);
            }
            if ($status === SslCertificate::STATUS_ACTIVE) {
                $cert->setRenewError('');
            }

            $cert->save();
            return $cert;
        } catch (\Throwable $e) {
            w_log_error('[SslCertificateService] ' . __('同步证书记录失败：%{1}', [$e->getMessage()]));
            return null;
        }
    }

    /**
     * 基于 issuer 推断 provider（优先保留已知 provider）。
     */
    protected function inferProviderByIssuer(string $provider, string $issuer): string
    {
        $normalizedProvider = $this->normalizeAcmeProvider($provider);
        if ($this->isSupportedProvider($normalizedProvider)) {
            return $normalizedProvider;
        }

        $issuerLower = \strtolower(\trim($issuer));
        if ($issuerLower === '') {
            return self::PROVIDER_LETS_ENCRYPT;
        }
        if (\str_contains($issuerLower, 'self')) {
            return self::PROVIDER_SELF_SIGNED;
        }
        if (\str_contains($issuerLower, 'let') && \str_contains($issuerLower, 'encrypt')) {
            return self::PROVIDER_LETS_ENCRYPT;
        }
        if (\str_contains($issuerLower, 'sectigo') || \str_contains($issuerLower, 'litessl')) {
            return self::PROVIDER_LITESSL;
        }
        return self::PROVIDER_LETS_ENCRYPT;
    }
    
    /**
     * 获取所有证书目录映射（用于 SNI）
     * 
     * @return array [domain => [cert => path, key => path], ...]
     */
    public function getCertificateMap(): array
    {
        $this->reconcileCertificateFiles();

        $certificates = $this->certModel->clearQuery()
            ->where(SslCertificate::schema_fields_STATUS, SslCertificate::STATUS_ACTIVE)
            ->where(SslCertificate::schema_fields_HTTPS_ENABLED, 1)
            ->select()
            ->fetchArray();
        
        $map = [];
        foreach ($certificates as $cert) {
            $domain = $cert[SslCertificate::schema_fields_DOMAIN];
            $certPath = $cert[SslCertificate::schema_fields_CERT_PATH];
            $keyPath = $cert[SslCertificate::schema_fields_KEY_PATH];
            
            if (\is_file($certPath) && \is_file($keyPath)) {
                $map[$domain] = [
                    'cert' => $certPath,
                    'key' => $keyPath,
                    'chain' => $cert[SslCertificate::schema_fields_CHAIN_PATH] ?? '',
                ];
            }
        }
        
        return $map;
    }

    /**
     * 将数据库中的证书文件同步到 app/etc/ssl/{domain}/ 目录。
     *
     * @return array{written:int,updated:int,skipped:int,errors:array}
     */
    public function reconcileCertificateFiles(): array
    {
        $result = [
            'written' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $certificates = $this->certModel->clearQuery()
            ->where(SslCertificate::schema_fields_STATUS, SslCertificate::STATUS_ACTIVE)
            ->where(SslCertificate::schema_fields_HTTPS_ENABLED, 1)
            ->select()
            ->fetchArray();

        foreach ($certificates as $row) {
            $domain = (string)($row[SslCertificate::schema_fields_DOMAIN] ?? '');
            $sourceCert = (string)($row[SslCertificate::schema_fields_CERT_PATH] ?? '');
            $sourceKey = (string)($row[SslCertificate::schema_fields_KEY_PATH] ?? '');
            if ($domain === '' || $sourceCert === '' || $sourceKey === '') {
                $result['skipped']++;
                continue;
            }
            if (!\is_file($sourceCert) || !\is_file($sourceKey)) {
                $result['errors'][] = __('证书源文件不存在：%{1}', [$domain]);
                continue;
            }

            $targetDir = \dirname(Env::path_ENV_FILE) . DS . 'ssl' . DS . $domain . DS;
            if (!\is_dir($targetDir)) {
                @\mkdir($targetDir, 0755, true);
            }
            $targetCert = $targetDir . 'fullchain.pem';
            $targetKey = $targetDir . 'privkey.pem';
            $targetExistsBefore = \is_file($targetCert) && \is_file($targetKey);

            try {
                $copiedCert = $this->copyIfChanged($sourceCert, $targetCert);
                $copiedKey = $this->copyIfChanged($sourceKey, $targetKey);
                if ($copiedCert || $copiedKey) {
                    $result[$targetExistsBefore ? 'updated' : 'written']++;
                    @\chmod($targetCert, 0644);
                    @\chmod($targetKey, 0600);
                } else {
                    $result['skipped']++;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = __('同步证书失败 %{1}: %{2}', [$domain, $e->getMessage()]);
            }
        }

        return $result;
    }

    /**
     * 扫描证书目录并自动入库（页面/命令可复用）。
     *
     * 扫描范围：
     * - app/etc/ssl/{domain}/
     * - app/etc/ 下兼容旧格式（cert.pem/key.pem 等）
     *
     * @return array{synced:int, skipped:int}
     */
    public function syncCertificatesFromStorage(): array
    {
        $etcDir = \dirname(Env::path_ENV_FILE) . DS;
        $sslDir = $etcDir . 'ssl' . DS;
        $synced = 0;
        $skipped = 0;

        $certFormats = [
            ['cert' => 'fullchain.pem', 'key' => 'privkey.pem'],
            ['cert' => 'cert.pem', 'key' => 'key.pem'],
            ['cert' => 'ssl.crt', 'key' => 'ssl.key'],
            ['cert' => 'ssl.pem', 'key' => 'ssl.key'],
            ['cert' => 'server.crt', 'key' => 'server.key'],
            ['cert' => 'certificate.crt', 'key' => 'private.key'],
        ];

        if (\is_dir($sslDir)) {
            $domains = @\scandir($sslDir) ?: [];
            foreach ($domains as $domain) {
                if ($domain === '.' || $domain === '..') {
                    continue;
                }
                $domainDir = $sslDir . $domain . DS;
                if (!\is_dir($domainDir)) {
                    continue;
                }
                $matched = false;
                foreach ($certFormats as $format) {
                    $certPath = $domainDir . $format['cert'];
                    $keyPath = $domainDir . $format['key'];
                    if (\is_file($certPath) && \is_file($keyPath)) {
                        $matched = true;
                        if ($this->syncCertificateRecordFromFiles($domain, $certPath, $keyPath) instanceof SslCertificate) {
                            $synced++;
                        } else {
                            $skipped++;
                        }
                        break;
                    }
                }
                if (!$matched) {
                    $skipped++;
                }
            }
        }

        // 兼容旧格式：app/etc 下直接放证书
        $defaultDomain = (string)(Env::get('server.host') ?? 'localhost');
        if ($defaultDomain === '127.0.0.1' || $defaultDomain === '::1') {
            $defaultDomain = 'localhost';
        }
        $defaultDomain = \strtolower(\trim($defaultDomain));
        foreach ($certFormats as $format) {
            $certPath = $etcDir . $format['cert'];
            $keyPath = $etcDir . $format['key'];
            if (\is_file($certPath) && \is_file($keyPath)) {
                if ($this->syncCertificateRecordFromFiles($defaultDomain, $certPath, $keyPath) instanceof SslCertificate) {
                    $synced++;
                } else {
                    $skipped++;
                }
                break;
            }
        }

        return ['synced' => $synced, 'skipped' => $skipped];
    }

    private function copyIfChanged(string $source, string $target): bool
    {
        if (\is_file($target)) {
            $sourceHash = @\sha1_file($source);
            $targetHash = @\sha1_file($target);
            if ($sourceHash !== false && $sourceHash === $targetHash) {
                return false;
            }
        }
        return (bool)@\copy($source, $target);
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
    public function requestCertificate(
        string $domain,
        string $webroot,
        string $email,
        int $websiteId = 0,
        string $provider = self::PROVIDER_LETS_ENCRYPT
    ): array
    {
        try {
            $provider = $this->normalizeAcmeProvider($provider);
            if (!\in_array($provider, [self::PROVIDER_LETS_ENCRYPT, self::PROVIDER_LITESSL], true)) {
                return ['success' => false, 'message' => __('不支持的证书提供商：%{provider}', ['provider' => $provider]), 'cert' => null];
            }
            
            if ($provider === self::PROVIDER_LITESSL && $this->staging) {
                return ['success' => false, 'message' => __('LiteSSL 暂不支持测试环境'), 'cert' => null];
            }
            
            $this->setAcmeProvider($provider);
            if ($this->resolveAcmeDirectory($this->acmeProvider, $this->staging) === null) {
                return ['success' => false, 'message' => __('无法获取证书提供商的 ACME 目录'), 'cert' => null];
            }
            
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
                    ->setProvider($provider)
                    ->setStatus(SslCertificate::STATUS_PENDING)
                    ->setAutoRenew(true);
            } else {
                $cert->setProvider($provider);
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
            $cert->getWebsiteId(),
            $cert->getProvider() ?: self::PROVIDER_LETS_ENCRYPT
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
     * httpRequest 返回 ['headers' => ..., 'body' => <ACME目录JSON>, 'raw' => ...]
     */
    protected function getAcmeDirectory(): ?array
    {
        if ($this->directoryCache !== null) {
            return $this->directoryCache;
        }
        if (empty($this->acmeDirectory)) {
            return null;
        }

        $response = $this->httpRequest($this->acmeDirectory);
        $body = \is_array($response) ? ($response['body'] ?? null) : null;
        if ($body !== null && isset($body['newAccount'])) {
            $this->directoryCache = $body;
            return $body;
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
     * 获取解析后的证书（带缓存）
     * 
     * @param string $certPath 证书路径
     * @return array|false 解析后的证书数组，失败返回 false
     */
    protected function getParsedCertificateRaw(string $certPath): array|false
    {
        // 缓存命中
        if (isset($this->certParseCache[$certPath])) {
            return $this->certParseCache[$certPath];
        }
        
        if (!\is_file($certPath)) {
            return $this->certParseCache[$certPath] = false;
        }
        
        $certData = @\file_get_contents($certPath);
        if (!$certData) {
            return $this->certParseCache[$certPath] = false;
        }
        
        $cert = @\openssl_x509_parse($certData);
        return $this->certParseCache[$certPath] = ($cert ?: false);
    }
    
    /**
     * 解析证书信息
     */
    public function parseCertificate(string $certPath): array
    {
        $cert = $this->getParsedCertificateRaw($certPath);
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
     * 检查证书是否适用于给定 host（CN 或 SAN 匹配）
     * 
     * 智能匹配规则：
     * - 直接匹配 CN 或 SAN
     * - localhost 与 127.0.0.1 视为等价
     * - 内网 IP 需要证书中明确包含该 IP
     */
    public function certificateMatchesHost(string $certPath, string $host): bool
    {
        $host = \strtolower(\trim($host));
        $cacheKey = $certPath . ':' . $host;
        
        // 缓存命中
        if (isset($this->certMatchCache[$cacheKey])) {
            return $this->certMatchCache[$cacheKey];
        }
        
        // 获取解析后的证书（使用缓存）
        $cert = $this->getParsedCertificateRaw($certPath);
        if (!$cert) {
            return $this->certMatchCache[$cacheKey] = false;
        }
        
        $cn = \strtolower(\trim($cert['subject']['CN'] ?? ''));
        $san = $cert['extensions']['subjectAltName'] ?? '';
        
        // 1. 直接匹配 CN
        if ($cn === $host) {
            return $this->certMatchCache[$cacheKey] = true;
        }
        
        // 2. localhost/127.0.0.1 互等价
        $localhostEquivalents = ['localhost', '127.0.0.1'];
        if (\in_array($host, $localhostEquivalents, true) && \in_array($cn, $localhostEquivalents, true)) {
            return $this->certMatchCache[$cacheKey] = true;
        }
        
        // 3. 解析 SAN 进行匹配
        if ($san !== '') {
            // 标准化 SAN 字符串用于搜索
            $sanLower = \strtolower($san);
            
            // 直接匹配
            if (\str_contains($sanLower, 'dns:' . $host) || 
                \str_contains($sanLower, 'dns: ' . $host) ||
                \str_contains($sanLower, 'ip address:' . $host) ||
                \str_contains($sanLower, 'ip address: ' . $host)) {
                return $this->certMatchCache[$cacheKey] = true;
            }
            
            // localhost/127.0.0.1 等价匹配
            if (\in_array($host, $localhostEquivalents, true)) {
                foreach ($localhostEquivalents as $equiv) {
                    if (\str_contains($sanLower, $equiv)) {
                        return $this->certMatchCache[$cacheKey] = true;
                    }
                }
            }
        }
        
        // 4. 内网 IP 必须证书中明确包含，不做通配
        return $this->certMatchCache[$cacheKey] = false;
    }
    
    /**
     * 检查 host 是否为本地或内网地址（需要自签证书）
     */
    public function needsSelfSignedCertificate(string $host): bool
    {
        $host = \strtolower(\trim($host));
        
        // 缓存命中
        if (isset($this->needsSelfSignedCache[$host])) {
            return $this->needsSelfSignedCache[$host];
        }
        
        // IP 地址：检查是否为回环/内网
        if (\filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->needsSelfSignedCache[$host] = $this->isLoopbackIp($host);
        }
        
        // 域名：检查是否为本地域名或解析到内网
        return $this->needsSelfSignedCache[$host] = ($this->isLocalDomain($host) || $this->resolvesToLoopback($host));
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
        \curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        \curl_setopt($ch, CURLOPT_USERAGENT, 'Weline-Server/1.0 ACME-Client');

        if ($method === 'POST') {
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            \curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/jose+json']);
        }

        $response = \curl_exec($ch);
        $curlError = $response === false ? \curl_error($ch) : '';
        $httpCode = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) \curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        \curl_close($ch);

        if ($response === false) {
            w_log_warning(__('ACME HTTP 请求失败: url=%{1}, error=%{2}', [$url, $curlError]), [], 'ssl_cert');
            return ['headers' => [], 'body' => null, 'raw' => '', 'error' => $curlError];
        }

        $headerStr = \substr((string) $response, 0, $headerSize);
        $bodyStr = \substr((string) $response, $headerSize);
        
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
            
            // 禁用时：触发服务器重启以使配置实时生效
            $restartTriggered = !$enabled && $this->triggerServerRestartForDomain($domain);
            
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
            
            $message = $enabled ? __('HTTPS 已启用') : __('HTTPS 已禁用');
            if (!$enabled && $restartTriggered) {
                $message = __('HTTPS 已禁用，服务器正在重启以使配置生效');
            }
            return [
                'success' => true,
                'message' => $message,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * 禁用 HTTPS 后触发服务器重启，使配置实时生效
     */
    protected function triggerServerRestartForDomain(string $domain): bool
    {
        $instanceNames = $this->findInstancesUsingDomain($domain);
        if (empty($instanceNames)) {
            return false;
        }
        try {
            $ipcGateway = ObjectManager::getInstance(\Weline\Server\Service\Control\IpcControlGateway::class);
            $controlMessage = \Weline\Server\IPC\ControlMessage::class;
            $anyOk = false;
            foreach ($instanceNames as $instanceName) {
                $res = $ipcGateway->command($instanceName, $controlMessage::ACTION_STOP, '', [], 8.0);
                if ($res['success'] ?? false) {
                    $this->scheduleServerStart($instanceName);
                    $anyOk = true;
                }
            }
            return $anyOk;
        } catch (\Throwable $e) {
            Env::log_warning('ssl_cert_toggle', 'SslCertificateService::triggerServerRestartForDomain failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 查找使用指定域名的 WLS 实例
     */
    protected function findInstancesUsingDomain(string $domain): array
    {
        $domain = \strtolower(\trim($domain));
        $instanceDir = Env::VAR_DIR . 'server' . DS . 'instances' . DS;
        if (!\is_dir($instanceDir)) {
            return [];
        }
        $found = [];
        $files = @\glob($instanceDir . '*.json');
        if (!$files) {
            return [];
        }
        foreach ($files as $file) {
            $data = @\json_decode((string)\file_get_contents($file), true);
            if (!\is_array($data)) {
                continue;
            }
            $instDomain = \strtolower(\trim((string)($data['ssl_domain'] ?? '')));
            $sslCert = (string)($data['ssl_cert'] ?? '');
            $matches = $instDomain === $domain
                || ($domain === 'localhost' && ($instDomain === 'localhost' || \str_contains($sslCert, 'localhost')))
                || ($domain === '127.0.0.1' && ($instDomain === '127.0.0.1' || \str_contains($sslCert, 'localhost')));
            if ($matches) {
                $found[] = \basename($file, '.json');
            }
        }
        return $found;
    }
    
    /**
     * 延迟 4 秒后后台启动服务器（等待旧进程完全退出）
     */
    protected function scheduleServerStart(string $instanceName): void
    {
        $bp = \defined('BP') ? BP : \dirname(__DIR__, 4) . DS;
        $php = \defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $w = $bp . 'bin' . DS . 'w';
        $tmp = \sys_get_temp_dir() . DS . 'weline_ssl_restart_' . \getmypid() . '_' . \uniqid() . '.php';
        $code = '<?php sleep(4); passthru(' . \var_export($php . ' ' . \escapeshellarg($w) . ' server:start ' . \escapeshellarg($instanceName) . ' -d', true) . ');';
        if (@\file_put_contents($tmp, $code) && \is_file($tmp)) {
            \register_shutdown_function(static fn () => @\unlink($tmp));
            $pid = \Weline\Framework\System\Process\Processer::create($php . ' ' . \escapeshellarg($tmp), false);
            if ($pid <= 0) {
                @\unlink($tmp);
            }
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
        
        // 通过 IPC 控制通道通知 Master 执行缓存重载（含 SSL 证书缓存刷新）
        $masterClass = MasterProcess::class;
        if (\is_callable([$masterClass, 'sendCacheClearCommand'])) {
            \call_user_func([$masterClass, 'sendCacheClearCommand'], 'default');
        }
    }
}
