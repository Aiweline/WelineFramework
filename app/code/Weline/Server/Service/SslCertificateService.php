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
 * - 开发环境（本地域名）自签证书自动生成
 * - 生产环境 （线上域名没有证书时，自签证书会立即生效，等待线上正式证书下发会动态替换正式证书） Let's Encrypt 自动申请
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
    public const ISSUER_LITESSL = 'Sectigo';
    public const ISSUER_UNKNOWN = 'Unknown';
    
    /**
     * 证书申请提供商（三种来源：自签 / Let's Encrypt / LiteSSL）
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
     * 上一次 ACME 请求失败时的错误详情（供创建订单等步骤返回给前端）
     */
    protected string $lastAcmeError = '';
    
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
     * 等待指定秒数（WLS 下用 SchedulerSystem 不阻塞 Worker，否则用原生 sleep）
     * 当 Framework 未加载导致 SchedulerSystem 不存在时回退到 sleep，避免 Class not found。
     */
    private function waitSeconds(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        if (\class_exists(\Weline\Framework\Runtime\SchedulerSystem::class, false)) {
            \Weline\Framework\Runtime\SchedulerSystem::sleep($seconds);
        } else {
            \sleep($seconds);
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
        if ($provider === 'array' || $provider === '') {
            return self::PROVIDER_LETS_ENCRYPT;
        }
        return match ($provider) {
            'letsencrypt', 'let\'s encrypt', 'le' => self::PROVIDER_LETS_ENCRYPT,
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
     * 从完整域名提取根域（去掉第一段子域标签）
     *
     * 例：www.example.com → example.com，api.store.example.com → store.example.com
     * 对于只有两段的域名（example.com）返回自身（不再截断）。
     */
    protected function extractRootDomain(string $domain): string
    {
        $parts = \explode('.', \strtolower(\trim($domain)));
        if (\count($parts) <= 2) {
            return \implode('.', $parts);
        }
        \array_shift($parts);
        return \implode('.', $parts);
    }

    /**
     * 检查子域名是否被泛域名证书覆盖；若是则直接复制泛域证书到该子域记录并写入磁盘，跳过 ACME 申请。
     *
     * @return array|null 若复制成功则返回 ensureCertificate 兼容的结果数组；否则返回 null 表示继续原流程
     */
    public function applyWildcardToSubdomainIfExists(string $domain, int $websiteId = 0): ?array
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '' || \str_starts_with($domain, '*.')) {
            return null;
        }

        $parts = \explode('.', $domain);
        if (\count($parts) < 3) {
            return null;
        }

        $rootDomain = $this->extractRootDomain($domain);
        $wildcardCert = ObjectManager::getInstance(SslCertificate::class, [], false)
            ->findWildcardByRoot($rootDomain);

        if ($wildcardCert === null) {
            return null;
        }

        $certPem  = $wildcardCert->getCertPem();
        $keyPem   = $wildcardCert->getKeyPem();
        if ($certPem === '' || $keyPem === '') {
            return null;
        }

        $now = \date('Y-m-d H:i:s');
        $expiresAt = $wildcardCert->getExpiresAt();
        if ($expiresAt !== '' && \strtotime($expiresAt) < \time()) {
            w_log_info(__('[SslCertificateService] 泛域名 *.%{1} 已过期，子域 %{2} 不使用泛域证书', [$rootDomain, $domain]));
            return null;
        }

        $subCert = ObjectManager::getInstance(SslCertificate::class, [], false);
        $subCert->clearQuery()->loadByDomain($domain);

        if (!$subCert->getCertId()) {
            $subCert->setDomain($domain);
        }

        $subCert->setCertPem($certPem)
            ->setKeyPem($keyPem)
            ->setChainPem($wildcardCert->getChainPem())
            ->setCsrPem($wildcardCert->getCsrPem())
            ->setIssuer($wildcardCert->getIssuer())
            ->setProvider($wildcardCert->getProvider())
            ->setIssuedAt($wildcardCert->getIssuedAt())
            ->setExpiresAt($expiresAt)
            ->setStatus(SslCertificate::STATUS_ACTIVE)
            ->setAutoRenew(true)
            ->setHttpsEnabled(true)
            ->setCertType(SslCertificate::CERT_TYPE_EXACT)
            ->setUpdatedAt($now);

        if (!$subCert->getCertId()) {
            $subCert->setCreatedAt($now);
        }
        if ($websiteId > 0) {
            $subCert->setWebsiteId($websiteId);
        }

        $subCert->save();

        $this->restoreCertificateFilesFromData($subCert->getData());

        $certDir = $this->getCertificateDir($domain);
        w_log_info(__(
            '[SslCertificateService] 子域名 %{1} 已被泛域名 *.%{2} 覆盖，直接写入泛域证书，跳过 ACME 申请',
            [$domain, $rootDomain]
        ));

        return [
            'success'     => true,
            'message'     => __('子域名 %{1} 已被泛域名 *.%{2} 覆盖，直接使用泛域证书', [$domain, $rootDomain]),
            'cert_path'   => $certDir . 'fullchain.pem',
            'key_path'    => $certDir . 'privkey.pem',
            'issuer'      => $wildcardCert->getIssuer(),
            'expires_at'  => $expiresAt,
            'is_new'      => false,
            'ssl_enabled' => true,
        ];
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
        // 0.0.0.0 是"监听所有网卡"的绑定地址，不是真实域名，归一化为 localhost
        if ($domain === '0.0.0.0') {
            $domain = 'localhost';
        }

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

        // 0.5 泛域名覆盖检查：若存在有效泛域证书则直接复制给子域，跳过后续流程
        $wildcardResult = $this->applyWildcardToSubdomainIfExists($domain, $websiteId);
        if ($wildcardResult !== null) {
            return $wildcardResult;
        }
        
        $certDir = $this->getCertificateDir($domain);
        $certPath = $certDir . 'fullchain.pem';
        $keyPath = $certDir . 'privkey.pem';
        
        // 1. 检查本地证书文件是否存在且未过期，若有则入库后直接使用（避免每次重新申请）
        if ($this->isCertificateValid($certPath) && \is_file($keyPath)) {
            $this->syncCertificateRecordFromFiles($domain, $certPath, $keyPath, $websiteId, true);
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
        
        // 2. 判断使用自签证书还是 ACME（Let's Encrypt / LiteSSL）
        // 只看域名本身：本地域名/IP、或解析到回环地址 → 自签
        // 线上域名即使在 dev 环境也用 ACME 申请真证书
        $useSelfsigned = $this->isLocalDomain($domain) 
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
            
            // 保存主文件
            if (!$certPem || !\file_put_contents($certPath, $certPem)) {
                return ['success' => false, 'message' => __('保存证书文件失败')];
            }
            if (!$keyPem || !\file_put_contents($keyPath, $keyPem)) {
                return ['success' => false, 'message' => __('保存私钥文件失败')];
            }
            
            // 补全所有文件：cert.pem、chain.pem、csr.pem、domain.key
            @\file_put_contents($certDir . 'cert.pem', $certPem);
            @\file_put_contents($certDir . 'chain.pem', $certPem);
            @\file_put_contents($certDir . 'domain.key', $keyPem);
            $csrPem = '';
            if (\openssl_csr_export($csr, $csrPem)) {
                @\file_put_contents($certDir . 'csr.pem', $csrPem);
            }
            
            @\chmod($certPath, 0644);
            @\chmod($keyPath, 0600);
            
            $saved = $this->updateCertificateRecord(
                $domain,
                $certPath,
                $keyPath,
                self::ISSUER_SELF_SIGNED,
                $validDays,
                $websiteId,
                self::PROVIDER_SELF_SIGNED
            );
            if (!$saved) {
                return ['success' => false, 'message' => __('自签证书文件已生成，但写入证书管理失败，请检查日志并重试')];
            }
            
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
    ): bool {
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
            $chainPath = \dirname($certPath) . DS . 'chain.pem';
            $certContents = $this->readCertificateContents($certPath, $keyPath, $chainPath);

            $cert->setDomain($domain)
                ->setWebsiteId($websiteId)
                ->setCertPath($certPath)
                ->setKeyPath($keyPath)
                ->setChainPath(\is_file($chainPath) ? $chainPath : '')
                ->setCertPem($certContents['cert_pem'])
                ->setKeyPem($certContents['key_pem'])
                ->setChainPem($certContents['chain_pem'])
                ->setCsrPem($certContents['csr_pem'])
                ->setCertType($certType)
                ->setIssuer($issuer)
                ->setProvider($provider)
                ->setIssuedAt(\date('Y-m-d H:i:s'))
                ->setExpiresAt($expiresAt)
                ->setStatus(SslCertificate::STATUS_ACTIVE)
                ->setHttpsEnabled(true)
                ->setAutoRenew($provider !== self::PROVIDER_SELF_SIGNED);

            // 避免 uk_domain 冲突：若该域名已被其他行占用，合并到该行并更新该行
            $cert = $this->resolveDuplicateDomainCert($cert);
            $cert->setDomain($domain);
            $cert->save();

            // 触发事件通知其他模块（使用事件机制解耦）
            if ($isRenewal) {
                $this->dispatchCertificateRenewedEvent(
                    $domain,
                    $cert->getCertId(),
                    $oldExpiresAt,
                    $expiresAt
                );
            } else {
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

            // 泛域名证书更新后同步 PEM 到子域记录
            if ($certType === SslCertificate::CERT_TYPE_WILDCARD) {
                $this->syncWildcardToSubdomains($domain);
            }
            return true;
            
        } catch (\Throwable $e) {
            w_log_error('[SslCertificateService] ' . __('更新证书记录失败：%{1}', [$e->getMessage()]));
            return false;
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
     * 触发证书删除事件
     *
     * 通知其他模块（如 Websites）清除域名池的 HTTPS 状态和可建站状态
     */
    public function dispatchCertificateDeletedEvent(string $domain, ?int $certId = null, string $reason = ''): void
    {
        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);

            $data = [
                'domain' => $domain,
                'cert_id' => $certId,
                'reason' => $reason,
            ];
            $eventsManager->dispatch('Weline_Server::domain::certificate_deleted', $data);
        } catch (\Throwable $e) {
            w_log_error('[SslCertificateService] ' . __('证书删除事件调度失败：%{1}', [$e->getMessage()]));
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
     * 获取证书存储目录（域名统一小写，与删除/扫描等逻辑一致，避免同域多写法导致“证书丢失”误判）
     */
    public function getCertificateDir(string $domain): string
    {
        $domain = \strtolower(\trim($domain));
        $dir = $this->certBaseDir . $domain . DS;
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * 解析时尝试的证书目录名列表（仅用于探测，不创建目录）
     * 同一证书可能存于根域或 www 子域目录，需多变体查找避免误报“证书文件丢失”。
     *
     * @return list<string> 目录名（小写，不含路径），如 ['qipaisaas.com', 'www.qipaisaas.com', '*.qipaisaas.com']
     */
    protected function getCertificateDirCandidates(string $domain): array
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return [];
        }
        $root = $this->extractRootDomain($domain);
        $candidates = [$domain];
        if ($root !== $domain) {
            $candidates[] = $root;
        }
        if ($root !== '' && 'www.' . $root !== $domain) {
            $candidates[] = 'www.' . $root;
        }
        $wildcard = '*.' . $root;
        if ($root !== '' && $wildcard !== $domain) {
            $candidates[] = $wildcard;
        }
        return \array_values(\array_unique($candidates));
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
                $cert->clearData(true);
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
            $certContents = $this->readCertificateContents($certPath, $keyPath, $chainPath);

            $cert->setDomain($domain)
                ->setWebsiteId($websiteId)
                ->setCertType($certType)
                ->setCertPath($certPath)
                ->setKeyPath($keyPath)
                ->setChainPath($chainPath)
                ->setCertPem($certContents['cert_pem'])
                ->setKeyPem($certContents['key_pem'])
                ->setChainPem($certContents['chain_pem'])
                ->setIssuer($isSelfSigned ? self::ISSUER_SELF_SIGNED : ($issuer !== '' ? $issuer : $this->getIssuerByProvider($provider)))
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

            $cert = $this->resolveDuplicateDomainCert($cert);
            // 保存前再次设置 domain，避免 resolveDuplicateDomainCert 返回的模型因 getData() 未含 domain 导致 INSERT 违反 NOT NULL
            $cert->setDomain($domain);
            $cert->save();
            return $cert;
        } catch (\Throwable $e) {
            w_log_error('[SslCertificateService] ' . __('同步证书记录失败：%{1}', [$e->getMessage()]));
            return null;
        }
    }

    /**
     * 避免 uk_domain 冲突：若已有其他行占用当前 domain，将当前数据合并到该行并返回该行供 save；否则返回原 cert。
     * 合并后显式设置 domain 等必填字段，避免 _model_fields_data 未含 domain 导致 INSERT 违反 NOT NULL。
     */
    private function resolveDuplicateDomainCert(SslCertificate $cert): SslCertificate
    {
        $domain = $cert->getDomain();
        if ($domain === '') {
            return $cert;
        }
        $currentId = $cert->getCertId();
        $existing = $this->certModel->clearQuery()->loadByDomain($domain);
        $existingId = $existing->getCertId();
        if ($existingId > 0 && $existingId !== $currentId) {
            $existing->setData($cert->getData());
            $existing->setData(SslCertificate::schema_fields_ID, $existingId);
            $existing->setDomain($cert->getDomain());
            $existing->setCertType($cert->getCertType());
            $existing->setProvider($cert->getProvider() ?: self::PROVIDER_SELF_SIGNED);
            $existing->setStatus($cert->getStatus());
            $existing->setWebsiteId($cert->getWebsiteId());
            return $existing;
        }
        return $cert;
    }

    /**
     * 根据 provider 返回对应的默认 issuer 显示名。
     * 仅当证书文件无法解析出 issuer 时使用。
     */
    public function getIssuerByProvider(string $provider): string
    {
        return match ($this->normalizeAcmeProvider($provider)) {
            self::PROVIDER_LETS_ENCRYPT => self::ISSUER_LETS_ENCRYPT,
            self::PROVIDER_LITESSL => self::ISSUER_LITESSL,
            self::PROVIDER_SELF_SIGNED => self::ISSUER_SELF_SIGNED,
            default => self::ISSUER_UNKNOWN,
        };
    }

    /**
     * 基于证书文件中的实际 issuer 推断 provider。
     * issuer 不为空时以 issuer 为准（证书文件是真实来源）；
     * issuer 为空时保留 DB 中已有的有效 provider。
     * 均无法判定时返回 self_signed（最安全的默认值）。
     */
    protected function inferProviderByIssuer(string $provider, string $issuer): string
    {
        $issuerLower = \strtolower(\trim($issuer));

        if ($issuerLower !== '') {
            if (\str_contains($issuerLower, 'self') || \str_contains($issuerLower, 'weline')) {
                return self::PROVIDER_SELF_SIGNED;
            }
            if (\str_contains($issuerLower, 'let') && \str_contains($issuerLower, 'encrypt')) {
                return self::PROVIDER_LETS_ENCRYPT;
            }
            if (\str_contains($issuerLower, 'sectigo') || \str_contains($issuerLower, 'litessl')) {
                return self::PROVIDER_LITESSL;
            }
            // ISRG（Internet Security Research Group）是 Let's Encrypt 的母组织
            if (\str_contains($issuerLower, 'isrg')) {
                return self::PROVIDER_LETS_ENCRYPT;
            }
        }

        $normalizedProvider = $this->normalizeAcmeProvider($provider);
        if ($this->isSupportedProvider($normalizedProvider)) {
            return $normalizedProvider;
        }

        return self::PROVIDER_SELF_SIGNED;
    }
    
    /**
     * 获取所有证书目录映射（用于 SNI）
     * 
     * 注意：PHP 的 SNI_server_certs 需要精确的域名键匹配，不会自动处理泛域名匹配。
     * 因此，对于泛域名证书（*.example.com），我们同时生成：
     * 1. 根域名映射（example.com）- 根域名可以使用泛域名证书
     * 2. 保留泛域名键（*.example.com）- 用于 fallback
     * 
     * 当证书文件不存在时：先按路径探测 → 再从证书管理（DB 含 PEM）恢复磁盘；localhost/127.0.0.1 互查等价记录。
     * 暂无法从磁盘/DB 恢复且未过期时：不再弹系统通知、不把证书记录标为 ERROR（证书任务会自行恢复，避免误报）。
     * 已过期则仍发续签提示。
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
        $missingCerts = [];  // 记录证书文件缺失的域名
        $expiredCerts = [];  // 记录已过期的证书
        
        foreach ($certificates as $cert) {
            $domain = (string)($cert[SslCertificate::schema_fields_DOMAIN] ?? '');

            // 0.0.0.0 只是"监听所有网卡"的绑定地址，不是真实域名，跳过
            if ($domain === '0.0.0.0') {
                continue;
            }

            $certPath = (string)($cert[SslCertificate::schema_fields_CERT_PATH] ?? '');
            $keyPath = (string)($cert[SslCertificate::schema_fields_KEY_PATH] ?? '');
            $certType = (string)($cert[SslCertificate::schema_fields_CERT_TYPE] ?? SslCertificate::CERT_TYPE_EXACT);
            $certId = (int)($cert[SslCertificate::schema_fields_ID] ?? 0);
            $expiresAt = (string)($cert[SslCertificate::schema_fields_EXPIRES_AT] ?? '');

            // 检查证书文件是否存在；若 DB 路径为空/失效，先尝试从标准目录自动探测并回写路径
            if ($certPath === '' || $keyPath === '' || !\is_file($certPath) || !\is_file($keyPath)) {
                [$resolvedCertPath, $resolvedKeyPath] = $this->resolveCertificateFilePaths($domain, $certPath, $keyPath);
                if ($resolvedCertPath !== '' && $resolvedKeyPath !== '') {
                    $certPath = $resolvedCertPath;
                    $keyPath = $resolvedKeyPath;
                    $cert[SslCertificate::schema_fields_CERT_PATH] = $certPath;
                    $cert[SslCertificate::schema_fields_KEY_PATH] = $keyPath;

                    // 路径探测成功后同步回 DB，避免后续请求重复误判
                    try {
                        $certModel = \Weline\Framework\Manager\ObjectManager::getInstance(SslCertificate::class, [], false);
                        $certModel->load($certId);
                        if ($certModel->getCertId()) {
                            $certModel->setCertPath($certPath)
                                ->setKeyPath($keyPath)
                                ->setStatus(SslCertificate::STATUS_ACTIVE)
                                ->setRenewError('')
                                ->save();
                        }
                    } catch (\Throwable $e) {
                        w_log_warning('[SslCertificateService] 自动回写证书路径失败: ' . $e->getMessage());
                    }
                }
            }

            // 标准目录探测后仍不可用时，再从证书管理（整行 PEM / 等价 localhost 记录）恢复
            if ($certPath === '' || $keyPath === '' || !\is_file($certPath) || !\is_file($keyPath)) {
                $isExpired = $expiresAt !== '' && \strtotime($expiresAt) < \time();
                if (!$isExpired && $this->tryRestoreCertificateFromManagement($certId, $domain, $cert)) {
                    $restoredDir = $this->getCertificateDir((string) $domain);
                    $certPath = $restoredDir . 'fullchain.pem';
                    $keyPath = $restoredDir . 'privkey.pem';
                    $cert[SslCertificate::schema_fields_CERT_PATH] = $certPath;
                    $cert[SslCertificate::schema_fields_KEY_PATH] = $keyPath;
                    $cert[SslCertificate::schema_fields_CHAIN_PATH] = \is_file($restoredDir . 'chain.pem')
                        ? $restoredDir . 'chain.pem'
                        : '';
                } elseif ($isExpired) {
                    $expiredCerts[] = [
                        'domain' => $domain,
                        'expires_at' => $expiresAt,
                        'cert_id' => $certId,
                    ];
                    continue;
                } else {
                    $missingCerts[] = [
                        'domain' => $domain,
                        'expires_at' => $expiresAt,
                        'cert_id' => $certId,
                        'cert_path' => $certPath,
                        'key_path' => $keyPath,
                    ];
                    continue;
                }
            }

            $certData = [
                'cert' => $certPath,
                'key' => $keyPath,
                'chain' => $cert[SslCertificate::schema_fields_CHAIN_PATH] ?? '',
                'cert_type' => $certType,
                'force_https' => (int) ($cert[SslCertificate::schema_fields_FORCE_HTTPS] ?? 1),
                'force_root_to_www' => (int) ($cert[SslCertificate::schema_fields_FORCE_ROOT_TO_WWW] ?? 0),
            ];
            
            $this->appendCertificateMapEntries($map, (string) $domain, $certType, $certData);
        }
        
        // 发出证书缺失通知
        if (!empty($missingCerts)) {
            $this->notifyMissingCertificates($missingCerts);
        }
        
        // 发出证书过期通知
        if (!empty($expiredCerts)) {
            $this->notifyExpiredCertificates($expiredCerts);
        }
        
        return $map;
    }

    /**
     * 解析证书文件路径（优先使用现有路径，其次探测标准目录下常见文件名）。
     *
     * @return array{0:string,1:string} [certPath,keyPath]
     */
    protected function resolveCertificateFilePaths(string $domain, string $certPath, string $keyPath): array
    {
        if ($certPath !== '' && $keyPath !== '' && \is_file($certPath) && \is_file($keyPath)) {
            return [$certPath, $keyPath];
        }

        $fileCandidates = [
            ['cert' => 'fullchain.pem', 'key' => 'privkey.pem'],
            ['cert' => 'cert.pem', 'key' => 'key.pem'],
            ['cert' => 'ssl.crt', 'key' => 'ssl.key'],
            ['cert' => 'server.crt', 'key' => 'server.key'],
            ['cert' => 'certificate.crt', 'key' => 'private.key'],
        ];

        // 尝试 DB 中的 domain 以及根域、www、泛域目录，避免证书在另一变体目录下被误判为丢失
        $dirCandidates = $this->getCertificateDirCandidates($domain);
        foreach ($dirCandidates as $dirName) {
            $certDir = $this->certBaseDir . $dirName . DS;
            foreach ($fileCandidates as $candidate) {
                $candidateCertPath = $certDir . $candidate['cert'];
                $candidateKeyPath = $certDir . $candidate['key'];
                if (\is_file($candidateCertPath) && \is_file($candidateKeyPath)) {
                    return [$candidateCertPath, $candidateKeyPath];
                }
            }
        }

        return ['', ''];
    }
    
    /**
     * 证书文件当前不可用（探测与 PEM 恢复均未命中）。
     * 不弹系统通知、不标 ERROR：多由同步/任务时序导致，证书会由定时任务或下次加载自行恢复。
     *
     * @param array $missingCerts 缺失的证书列表
     */
    protected function notifyMissingCertificates(array $missingCerts): void
    {
        foreach ($missingCerts as $cert) {
            $domain = $cert['domain'];
            $expiresAt = $cert['expires_at'];
            $certPath = $cert['cert_path'];
            w_log_debug(
                '[SslCertificateService] 证书文件暂未就绪（等待自动恢复） domain=' . $domain
                . ' cert_path=' . $certPath . ' expires_at=' . ($expiresAt ?: '未知'),
            );
        }
    }
    
    /**
     * 通知证书已过期且文件不存在
     * 
     * @param array $expiredCerts 过期的证书列表
     */
    protected function notifyExpiredCertificates(array $expiredCerts): void
    {
        foreach ($expiredCerts as $cert) {
            $domain = $cert['domain'];
            $expiresAt = $cert['expires_at'];
            
            $title = __('域名 %{1} 的证书已过期', [$domain]);
            $content = __('过期时间：%{1}。请续签证书以恢复 HTTPS 服务。', [$expiresAt ?: '未知']);
            
            // 发送系统通知
            w_msg('ssl_cert_expired', 'error', $title, $content, [
                'priority' => 9,
                'icon' => 'ri-shield-keyhole-line',
                'metadata' => [
                    'domain' => $domain,
                    'cert_id' => $cert['cert_id'],
                    'expires_at' => $expiresAt,
                    'action' => 'renew',
                ],
            ]);
            
            w_log_error('[SslCertificateService] ' . $title . ' - ' . $content);
            
            // 更新证书记录状态为过期
            try {
                $certModel = \Weline\Framework\Manager\ObjectManager::getInstance(SslCertificate::class, [], false);
                $certModel->load($cert['cert_id']);
                if ($certModel->getCertId()) {
                    $certModel->setStatus(SslCertificate::STATUS_EXPIRED)
                        ->setRenewError(__('证书已过期'))
                        ->save();
                }
            } catch (\Throwable $e) {
                w_log_error('[SslCertificateService] 更新证书记录状态失败: ' . $e->getMessage());
            }
        }
    }

    protected function appendCertificateMapEntries(array &$map, string $domain, string $certType, array $certData): void
    {
        $map[$domain] = $certData;

        if ($certType !== SslCertificate::CERT_TYPE_WILDCARD || !\str_starts_with($domain, '*.')) {
            return;
        }

        $rootDomain = \substr($domain, 2);
        if ($rootDomain !== '' && !isset($map[$rootDomain])) {
            $map[$rootDomain] = $certData;
        }

        try {
            $poolModel = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Websites\Model\DomainPool::class,
                [],
                false
            );
            $subdomains = $poolModel->clearQuery()
                ->where(\Weline\Websites\Model\DomainPool::schema_fields_ROOT_DOMAIN, $rootDomain)
                ->where(\Weline\Websites\Model\DomainPool::schema_fields_STATUS, \Weline\Websites\Model\DomainPool::STATUS_ACTIVE)
                ->select()
                ->fetchArray();

            foreach ($subdomains as $row) {
                $subdomain = (string) ($row[\Weline\Websites\Model\DomainPool::schema_fields_DOMAIN] ?? '');
                if ($subdomain !== '' && !isset($map[$subdomain])) {
                    $map[$subdomain] = $certData;
                }
            }
        } catch (\Throwable $e) {
            w_log_debug('[SslCertificateService] 获取 DomainPool 子域名失败: ' . $e->getMessage());
        }
    }

    protected function readCertificateContents(string $certPath, string $keyPath, string $chainPath = ''): array
    {
        $certPem = \is_file($certPath) ? (string) @\file_get_contents($certPath) : '';
        $keyPem = \is_file($keyPath) ? (string) @\file_get_contents($keyPath) : '';
        $chainPem = ($chainPath !== '' && \is_file($chainPath)) ? (string) @\file_get_contents($chainPath) : '';

        if ($chainPem === '' && $certPem !== '') {
            $chainPem = $this->extractChainFromFullchain($certPem);
        }

        $csrPath = \dirname($certPath) . DS . 'csr.pem';
        $csrPem = \is_file($csrPath) ? (string) @\file_get_contents($csrPath) : '';

        return [
            'cert_pem' => $certPem,
            'key_pem' => $keyPem,
            'chain_pem' => $chainPem,
            'csr_pem' => $csrPem,
        ];
    }

    /**
     * 从 fullchain PEM 中提取中间证书链（去掉第一张叶子证书，保留后续所有证书）。
     * Let's Encrypt 的 fullchain.pem 通常包含：叶子证书 + R3/E1 中间证书。
     * 浏览器需要中间证书链才能验证信任路径。
     */
    protected function extractChainFromFullchain(string $fullchainPem): string
    {
        $certs = [];
        $offset = 0;
        while (($start = \strpos($fullchainPem, '-----BEGIN CERTIFICATE-----', $offset)) !== false) {
            $end = \strpos($fullchainPem, '-----END CERTIFICATE-----', $start);
            if ($end === false) {
                break;
            }
            $end += \strlen('-----END CERTIFICATE-----');
            $certs[] = \trim(\substr($fullchainPem, $start, $end - $start));
            $offset = $end;
        }

        if (\count($certs) <= 1) {
            return '';
        }

        // 去掉第一张（叶子证书），其余为中间证书链
        \array_shift($certs);
        return \implode("\n", $certs);
    }

    /**
     * 从 fullchain PEM 中提取叶子证书（第一张证书）。
     */
    protected function extractLeafCertFromFullchain(string $fullchainPem): string
    {
        $start = \strpos($fullchainPem, '-----BEGIN CERTIFICATE-----');
        if ($start === false) {
            return '';
        }
        $end = \strpos($fullchainPem, '-----END CERTIFICATE-----', $start);
        if ($end === false) {
            return '';
        }
        $end += \strlen('-----END CERTIFICATE-----');
        return \trim(\substr($fullchainPem, $start, $end - $start));
    }

    protected function restoreCertificateFilesFromData(array $cert): bool
    {
        $domain = \strtolower(\trim((string) ($cert[SslCertificate::schema_fields_DOMAIN] ?? '')));
        $certPem = (string) ($cert[SslCertificate::schema_fields_CERT_PEM] ?? '');
        $keyPem = (string) ($cert[SslCertificate::schema_fields_KEY_PEM] ?? '');
        $chainPem = (string) ($cert[SslCertificate::schema_fields_CHAIN_PEM] ?? '');
        $csrPem = (string) ($cert[SslCertificate::schema_fields_CSR_PEM] ?? '');
        if ($domain === '' || $certPem === '' || $keyPem === '') {
            return false;
        }

        if ($chainPem === '') {
            $chainPem = $this->extractChainFromFullchain($certPem);
        }

        $certDir = $this->getCertificateDir($domain);

        // 1. fullchain.pem（核心：SSL 握手用）
        if (@\file_put_contents($certDir . 'fullchain.pem', $certPem) === false) {
            return false;
        }
        // 2. privkey.pem（核心：SSL 握手用）
        if (@\file_put_contents($certDir . 'privkey.pem', $keyPem) === false) {
            return false;
        }
        // 3. chain.pem（中间证书链，浏览器验证需要）
        if ($chainPem !== '') {
            @\file_put_contents($certDir . 'chain.pem', $chainPem);
        }
        // 4. cert.pem（叶子证书）
        $leafPem = $this->extractLeafCertFromFullchain($certPem);
        if ($leafPem !== '') {
            @\file_put_contents($certDir . 'cert.pem', $leafPem);
        }
        // 5. domain.key（原始域名密钥，与 privkey.pem 内容相同）
        @\file_put_contents($certDir . 'domain.key', $keyPem);
        // 6. csr.pem（证书签名请求）
        if ($csrPem !== '') {
            @\file_put_contents($certDir . 'csr.pem', $csrPem);
        }

        @\chmod($certDir . 'fullchain.pem', 0644);
        @\chmod($certDir . 'privkey.pem', 0600);
        @\chmod($certDir . 'domain.key', 0600);

        $certPath = $certDir . 'fullchain.pem';
        $keyPath = $certDir . 'privkey.pem';
        $chainPath = $certDir . 'chain.pem';

        try {
            $certId = (int) ($cert[SslCertificate::schema_fields_ID] ?? 0);
            if ($certId > 0) {
                $certModel = ObjectManager::getInstance(SslCertificate::class, [], false);
                $certModel->load($certId);
                if ($certModel->getCertId()) {
                    $certModel->setCertPath($certPath)
                        ->setKeyPath($keyPath)
                        ->setChainPath($chainPem !== '' ? $chainPath : '')
                        ->setStatus(SslCertificate::STATUS_ACTIVE)
                        ->setRenewError('')
                        ->save();
                    // 如果 DB 中 chain_pem 为空但从 fullchain 提取到了中间证书链，回填到 DB
                    $dbChain = (string) ($cert[SslCertificate::schema_fields_CHAIN_PEM] ?? '');
                    if ($dbChain === '' && $chainPem !== '') {
                        $certModel->setChainPem($chainPem)->save();
                    }
                }
            }
        } catch (\Throwable $e) {
            w_log_error('[SslCertificateService] 恢复证书文件后更新记录失败：' . $e->getMessage());
        }

        return true;
    }

    /**
     * 从证书管理（DB）尽量写回磁盘：列表行 PEM → 按 cert_id 全字段加载 → localhost/127.0.0.1/::1 等价域互查 PEM。
     */
    protected function tryRestoreCertificateFromManagement(int $certId, string $domain, array $certRow): bool
    {
        if ($this->restoreCertificateFilesFromData($certRow)) {
            return true;
        }
        if ($certId > 0) {
            try {
                $m = ObjectManager::getInstance(SslCertificate::class, [], false);
                $m->load($certId);
                if ($m->getCertId() && $this->restoreCertificateFilesFromData($m->getData())) {
                    return true;
                }
            } catch (\Throwable $e) {
                w_log_warning('[SslCertificateService] 按 cert_id 加载 PEM 后恢复失败: ' . $e->getMessage());
            }
        }
        foreach ($this->getLoopbackEquivalentDomains($domain) as $alt) {
            try {
                $m = ObjectManager::getInstance(SslCertificate::class, [], false);
                $altModel = $m->clearQuery()->loadByDomain($alt);
                if (!$altModel->getCertId()) {
                    continue;
                }
                if ($altModel->getCertPem() === '' || $altModel->getKeyPem() === '') {
                    continue;
                }
                $data = $altModel->getData();
                $data[SslCertificate::schema_fields_DOMAIN] = \strtolower(\trim($domain));
                $data[SslCertificate::schema_fields_ID] = $certId > 0 ? $certId : $altModel->getCertId();
                if ($this->restoreCertificateFilesFromData($data)) {
                    w_log_info(__('[SslCertificateService] 已从等价域 %{1} 的 PEM 恢复到 %{2}', [$alt, $domain]));

                    return true;
                }
            } catch (\Throwable $e) {
                w_log_warning('[SslCertificateService] 等价域 ' . $alt . ' 恢复证书失败: ' . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    protected function getLoopbackEquivalentDomains(string $domain): array
    {
        $d = \strtolower(\trim($domain));

        return match ($d) {
            'localhost' => ['127.0.0.1', '::1'],
            '127.0.0.1' => ['localhost', '::1'],
            '::1' => ['localhost', '127.0.0.1'],
            default => [],
        };
    }

    /**
     * 从数据库恢复指定域名的证书文件到磁盘（供 WLS Worker 动态加载调用）。
     *
     * 查找该域名在证书管理表中的记录，若有有效 PEM 数据则恢复全部 6 个文件到 app/etc/ssl/{domain}/。
     */
    public function restoreCertificateFromDb(string $domain): bool
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return false;
        }

        try {
            $certModel = ObjectManager::getInstance(SslCertificate::class, [], false);
            $cert = $certModel->clearQuery()->loadByDomain($domain);
            if (!$cert->getCertId()) {
                return false;
            }

            $ok = $this->tryRestoreCertificateFromManagement($cert->getCertId(), $domain, $cert->getData());
            if ($ok) {
                w_log_info(__('[SslCertificateService] 已从数据库恢复证书到磁盘：%{1}', [$domain]));
            }

            return $ok;
        } catch (\Throwable $e) {
            w_log_error(__('[SslCertificateService] 从数据库恢复证书失败：%{1} - %{2}', [$domain, $e->getMessage()]));
            return false;
        }
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
            if ($domain === '' || $domain === '0.0.0.0') {
                $result['skipped']++;
                continue;
            }

            $targetDir = \dirname(Env::path_ENV_FILE) . DS . 'ssl' . DS . $domain . DS;
            if (!\is_dir($targetDir)) {
                @\mkdir($targetDir, 0755, true);
            }
            $targetCert = $targetDir . 'fullchain.pem';
            $targetKey = $targetDir . 'privkey.pem';
            $targetExistsBefore = \is_file($targetCert) && \is_file($targetKey);

            if ($sourceCert === '' || $sourceKey === '' || !\is_file($sourceCert) || !\is_file($sourceKey)) {
                if ($this->restoreCertificateFilesFromData($row)) {
                    $result[$targetExistsBefore ? 'updated' : 'written']++;
                } else {
                    $result['errors'][] = __('证书源文件不存在且无法从证书管理恢复：%{1}', [$domain]);
                }
                continue;
            }

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
        $defaultDomain = (string)(Env::get('wls.host') ?? 'localhost');
        if ($defaultDomain === '127.0.0.1' || $defaultDomain === '::1' || $defaultDomain === '0.0.0.0') {
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
    
    public const CHALLENGE_HTTP01 = 'http01';
    public const CHALLENGE_DNS01 = 'dns01';
    public const CHALLENGE_AUTO = 'auto';

    /**
     * 为域名申请证书
     *
     * @param string $domain 域名
     * @param string $webroot Webroot 路径（用于 HTTP-01 验证）
     * @param string $email 联系邮箱
     * @param int $websiteId 关联的网站 ID
     * @param string $provider 证书提供商
     * @param string $challengeStrategy 验证策略: auto|http01|dns01。auto 时若端口非80则自动用 dns01
     * @param int $poolId 域名池 ID（DNS-01 时用于解析 DNS 账户）
     * @param int $domainId 根域名 ID（DNS-01 时用于解析 DNS 账户）
     * @param callable|null $onProgress 进度回调 function(string $message, array $extra=[])，用于 SSE 等实时展示
     * @return array ['success' => bool, 'message' => string, 'cert' => SslCertificate|null]
     */
    public function requestCertificate(
        string $domain,
        string $webroot,
        string $email,
        int $websiteId = 0,
        string $provider = self::PROVIDER_LETS_ENCRYPT,
        string $challengeStrategy = self::CHALLENGE_AUTO,
        int $poolId = 0,
        int $domainId = 0,
        ?\Closure $onProgress = null
    ): array
    {
        try {
            // 0. 泛域名覆盖检查：若有效泛域证书已覆盖该子域，直接复制跳过 ACME
            $wildcardResult = $this->applyWildcardToSubdomainIfExists($domain, $websiteId);
            if ($wildcardResult !== null) {
                $onProgress?->call($this, __('子域名 %{1} 已被泛域名覆盖，跳过申请', [$domain]));
                return $wildcardResult;
            }

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
            $normalizedDomain = \strtolower(\trim($domain));
            if ($normalizedDomain === '') {
                return ['success' => false, 'message' => __('域名不能为空'), 'cert' => null];
            }
            $cert = $this->certModel->clearQuery()->loadByDomain($normalizedDomain);
            $loadedDomain = \strtolower(\trim((string) $cert->getDomain()));
            if (!$cert->getCertId() || $loadedDomain !== $normalizedDomain) {
                $cert = ObjectManager::getInstance(SslCertificate::class);
                $cert->clearData(true);
                $certType = \str_starts_with($normalizedDomain, '*.') 
                    ? SslCertificate::CERT_TYPE_WILDCARD 
                    : SslCertificate::CERT_TYPE_EXACT;
                $cert->setDomain($normalizedDomain)
                    ->setWebsiteId($websiteId)
                    ->setCertType($certType)
                    ->setProvider($provider)
                    ->setStatus(SslCertificate::STATUS_PENDING)
                    ->setAutoRenew(true);
            } else {
                $cert->setDomain($normalizedDomain)
                    ->setProvider($provider);
            }
            
            // 3. 设置证书路径
            $certDir = $this->getCertificateDir($normalizedDomain);
            $certPath = $certDir . 'fullchain.pem';
            $keyPath = $certDir . 'privkey.pem';
            $chainPath = $certDir . 'chain.pem';

            // 3.5 若证书目录已有未过期证书，跳过申请，直接同步记录并返回
            if ($this->isCertificateValid($certPath) && \is_file($keyPath)) {
                if ($onProgress) {
                    $onProgress((string) __('已存在未过期证书，跳过申请'), ['step' => 'skip_acme']);
                    $onProgress((string) __('证书存储位置：%{1}', [$certDir]), ['cert_dir' => $certDir]);
                    $onProgress((string) __('正在同步证书管理记录…'), ['step' => 'sync_record']);
                }
                $synced = $this->syncCertificateRecordFromFiles($normalizedDomain, $certPath, $keyPath, $websiteId, true, $provider);
                if ($synced !== null) {
                    if ($onProgress) {
                        $onProgress((string) __('证书管理记录已同步，cert_id=%{1}', [$synced->getCertId()]), ['cert_id' => $synced->getCertId()]);
                    }
                    // 重新生成证书映射文件（确保泛域名证书展开后子域名能正确匹配）
                    $this->regenerateCertificateMap();
                    return ['success' => true, 'message' => __('已存在未过期证书，已跳过申请并更新记录'), 'cert' => $synced];
                }
            }

            $cert->setCertPath($certPath)
                ->setKeyPath($keyPath)
                ->setChainPath($chainPath);

            // 4. 使用 ACME 协议申请证书
            $result = $this->performAcmeChallenge($normalizedDomain, $webroot, $email, $certDir, $challengeStrategy, $poolId, $domainId, $onProgress);

            if ($result['success']) {
                if ($onProgress) {
                    $onProgress((string) __('证书已保存到：%{1}', [$certDir]), ['cert_dir' => $certDir, 'cert_path' => $certPath]);
                    $onProgress((string) __('正在保存证书管理记录…'), ['step' => 'save_record']);
                }
                // 更新证书信息
                $certInfo = $this->parseCertificate($certPath);
                $expiresAt = $certInfo['expires_at'] ?? \date('Y-m-d H:i:s', \strtotime('+90 days'));
                $issuer = ((string) ($certInfo['issuer'] ?? '')) !== '' ? (string) $certInfo['issuer'] : $this->getIssuerByProvider($provider);

                // 将 PEM 内容写入证书记录，供 server:ssl:reload 等场景从 DB 恢复证书
                $certContents = $this->readCertificateContents($certPath, $keyPath, $chainPath);
                // ACME 只生成 fullchain.pem + privkey.pem，补全 chain.pem + cert.pem 到磁盘
                if ($certContents['chain_pem'] !== '' && !\is_file($chainPath)) {
                    @\file_put_contents($chainPath, $certContents['chain_pem']);
                }
                $leafCertPath = $certDir . 'cert.pem';
                if (!\is_file($leafCertPath) && $certContents['cert_pem'] !== '') {
                    $leafPem = $this->extractLeafCertFromFullchain($certContents['cert_pem']);
                    if ($leafPem !== '') {
                        @\file_put_contents($leafCertPath, $leafPem);
                    }
                }
                $cert->setIssuedAt($certInfo['issued_at'] ?? \date('Y-m-d H:i:s'))
                    ->setExpiresAt($expiresAt)
                    ->setIssuer($issuer)
                    ->setProvider($provider)
                    ->setStatus(SslCertificate::STATUS_ACTIVE)
                    ->setLastRenewAt(\date('Y-m-d H:i:s'))
                    ->setRenewError('')
                    ->setAutoRenew(true)
                    ->setCertPem($certContents['cert_pem'])
                    ->setKeyPem($certContents['key_pem'])
                    ->setChainPem($certContents['chain_pem'])
                    ->setCsrPem($certContents['csr_pem']);
                $cert = $this->resolveDuplicateDomainCert($cert);
                $cert->setDomain($normalizedDomain)
                    ->setProvider($provider);
                $cert->save();

                if ($onProgress) {
                    $onProgress((string) __('证书管理记录已保存，cert_id=%{1}', [$cert->getCertId()]), ['cert_id' => $cert->getCertId()]);
                }

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

                // 重新生成证书映射文件（确保泛域名证书展开后子域名能正确匹配）
                $this->regenerateCertificateMap();

                return ['success' => true, 'message' => __('证书申请成功'), 'cert' => $cert];
            } else {
                $cert->setStatus(SslCertificate::STATUS_ERROR)
                    ->setRenewError($result['message']);
                $cert = $this->resolveDuplicateDomainCert($cert);
                $cert->setDomain($normalizedDomain);
                $cert->save();
                return ['success' => false, 'message' => $result['message'], 'cert' => $cert];
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'cert' => null];
        }
    }

    /**
     * 手动导入证书（文本或 PFX 解析后内容）
     *
     * @param string $domain 证书域名
     * @param string $fullchainPem fullchain PEM 内容
     * @param string $privateKeyPem 私钥 PEM 内容
     * @param string $chainPem 可选 chain PEM 内容
     * @param int $websiteId 网站 ID
     * @param bool $httpsEnabled 是否启用 HTTPS
     * @param string $provider 证书提供商标记
     * @return array{success: bool, message: string, cert: ?SslCertificate, cert_id?: int}
     */
    public function importManualCertificate(
        string $domain,
        string $fullchainPem,
        string $privateKeyPem,
        string $chainPem = '',
        int $websiteId = 0,
        bool $httpsEnabled = true,
        string $provider = 'manual'
    ): array {
        $domain = \strtolower(\trim($domain));
        $fullchainPem = \trim($fullchainPem);
        $privateKeyPem = \trim($privateKeyPem);
        $chainPem = \trim($chainPem);

        if ($domain === '') {
            return ['success' => false, 'message' => __('域名不能为空'), 'cert' => null];
        }
        if ($fullchainPem === '') {
            return ['success' => false, 'message' => __('证书内容不能为空'), 'cert' => null];
        }
        if ($privateKeyPem === '') {
            return ['success' => false, 'message' => __('私钥内容不能为空'), 'cert' => null];
        }

        $certResource = @\openssl_x509_read($fullchainPem);
        if ($certResource === false) {
            return ['success' => false, 'message' => __('证书内容格式无效，请上传/粘贴 PEM 证书链'), 'cert' => null];
        }
        $keyResource = @\openssl_pkey_get_private($privateKeyPem);
        if ($keyResource === false) {
            return ['success' => false, 'message' => __('私钥内容格式无效，请上传/粘贴 PEM 私钥'), 'cert' => null];
        }

        try {
            $certDir = $this->getCertificateDir($domain);
            $certPath = $certDir . 'fullchain.pem';
            $keyPath = $certDir . 'privkey.pem';
            $chainPath = $certDir . 'chain.pem';

            if (\file_put_contents($certPath, $fullchainPem) === false) {
                return ['success' => false, 'message' => __('写入证书文件失败'), 'cert' => null];
            }
            if (\file_put_contents($keyPath, $privateKeyPem) === false) {
                return ['success' => false, 'message' => __('写入私钥文件失败'), 'cert' => null];
            }
            if ($chainPem !== '') {
                if (\file_put_contents($chainPath, $chainPem) === false) {
                    return ['success' => false, 'message' => __('写入中间证书文件失败'), 'cert' => null];
                }
            }

            $provider = \trim($provider) !== '' ? $provider : 'manual';
            $cert = $this->syncCertificateRecordFromFiles($domain, $certPath, $keyPath, $websiteId, $httpsEnabled, $provider);
            if (!$cert instanceof SslCertificate) {
                return ['success' => false, 'message' => __('证书文件已写入，但同步证书记录失败'), 'cert' => null];
            }

            $certInfo = $this->parseCertificate($certPath);
            $this->dispatchCertificateIssuedEvent(
                $domain,
                $cert->getCertId(),
                $certPath,
                $keyPath,
                (string)($certInfo['issuer'] ?? $cert->getIssuer()),
                (string)($certInfo['expires_at'] ?? $cert->getExpiresAt()),
                $cert->getCertType()
            );

            // 重新生成证书映射文件（确保泛域名证书展开后子域名能正确匹配）
            $this->regenerateCertificateMap();

            return [
                'success' => true,
                'message' => __('证书导入成功'),
                'cert' => $cert,
                'cert_id' => $cert->getCertId(),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => __('证书导入失败：%{1}', [$e->getMessage()]), 'cert' => null];
        }
    }
    
    /**
     * 续签证书
     */
    public function renewCertificate(SslCertificate $cert, string $webroot, string $email): array
    {
        $result = $this->requestCertificate(
            $cert->getDomain(),
            $webroot,
            $email,
            $cert->getWebsiteId(),
            $cert->getProvider() ?: self::PROVIDER_LETS_ENCRYPT
        );

        if (($result['success'] ?? false) && $cert->getCertType() === SslCertificate::CERT_TYPE_WILDCARD) {
            $this->syncWildcardToSubdomains($cert->getDomain());
        }

        return $result;
    }

    /**
     * 泛域名证书续签后，将新 PEM 同步到所有引用该泛域证书的子域记录。
     */
    public function syncWildcardToSubdomains(string $wildcardDomain): void
    {
        $wildcardDomain = \strtolower(\trim($wildcardDomain));
        if (!str_starts_with($wildcardDomain, '*.')) {
            return;
        }

        $rootDomain = \substr($wildcardDomain, 2);

        $wildcardCert = ObjectManager::getInstance(SslCertificate::class, [], false);
        $wildcardCert->clearQuery()->loadByDomain($wildcardDomain);
        if (!$wildcardCert->getCertId() || $wildcardCert->getStatus() !== SslCertificate::STATUS_ACTIVE) {
            return;
        }

        $certPem  = $wildcardCert->getCertPem();
        $keyPem   = $wildcardCert->getKeyPem();
        $chainPem = $wildcardCert->getChainPem();
        $csrPem   = $wildcardCert->getCsrPem();
        if ($certPem === '' || $keyPem === '') {
            return;
        }

        $subCerts = ObjectManager::getInstance(SslCertificate::class, [], false)
            ->clearQuery()
            ->where(SslCertificate::schema_fields_CERT_TYPE, SslCertificate::CERT_TYPE_EXACT)
            ->where(SslCertificate::schema_fields_STATUS, SslCertificate::STATUS_ACTIVE)
            ->select()
            ->fetchArray();

        $now = \date('Y-m-d H:i:s');
        $synced = 0;

        foreach ($subCerts as $row) {
            $subDomain = (string) ($row[SslCertificate::schema_fields_DOMAIN] ?? '');
            if ($subDomain === '' || !\str_ends_with($subDomain, '.' . $rootDomain)) {
                continue;
            }
            $parts = \explode('.', $subDomain);
            if (\count($parts) < 3) {
                continue;
            }

            $subCertModel = ObjectManager::getInstance(SslCertificate::class, [], false);
            $subCertModel->load((int) $row[SslCertificate::schema_fields_ID]);
            if (!$subCertModel->getCertId()) {
                continue;
            }

            $subCertModel->setCertPem($certPem)
                ->setKeyPem($keyPem)
                ->setChainPem($chainPem)
                ->setCsrPem($csrPem)
                ->setIssuer($wildcardCert->getIssuer())
                ->setProvider($wildcardCert->getProvider())
                ->setIssuedAt($wildcardCert->getIssuedAt())
                ->setExpiresAt($wildcardCert->getExpiresAt())
                ->setUpdatedAt($now)
                ->save();

            $this->restoreCertificateFilesFromData($subCertModel->getData());
            $synced++;
        }

        if ($synced > 0) {
            w_log_info(__(
                '[SslCertificateService] 泛域名 %{1} 续签后已同步 PEM 到 %{2} 个子域记录',
                [$wildcardDomain, (string) $synced]
            ));
        }
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
    
    protected function getServerPort(): int
    {
        $config = Env::getInstance()->getConfig('wls');
        if (\is_array($config)) {
            return (int)($config['port'] ?? 80);
        }
        return 80;
    }

    /**
     * 是否可使用 HTTP-01 验证（ACME 需访问 http://domain/.well-known/...）
     * 主端口 80 可直接用；主端口 443 时 WLS 会起 80 重定向监听，也可用 HTTP-01。
     */
    protected function canUseHttp01Challenge(): bool
    {
        $config = Env::getInstance()->getConfig('wls');
        if (!\is_array($config)) {
            return true;
        }
        $port = (int)($config['port'] ?? 80);
        if ($port === 80) {
            return true;
        }
        $redirectPort = (int)($config['http_redirect_port'] ?? 80);
        $redirectEnabled = ($port === 443) && ($redirectPort > 0);
        return $redirectEnabled && $redirectPort === 80;
    }

    /**
     * 执行 ACME 验证并获取证书
     *
     * @param string $challengeStrategy auto|http01|dns01
     * @param int $poolId 域名池 ID（DNS-01 用）
     * @param int $domainId 根域名 ID（DNS-01 用）
     * @param \Closure|null $onProgress 进度回调
     */
    protected function performAcmeChallenge(
        string $domain,
        string $webroot,
        string $email,
        string $certDir,
        string $challengeStrategy = self::CHALLENGE_AUTO,
        int $poolId = 0,
        int $domainId = 0,
        ?\Closure $onProgress = null
    ): array {
        $strategy = $challengeStrategy;
        if ($strategy === self::CHALLENGE_AUTO) {
            if ($this->canUseHttp01Challenge()) {
                $strategy = self::CHALLENGE_HTTP01;
            } else {
                $strategy = self::CHALLENGE_DNS01;
            }
        }
        if ($onProgress) {
            $onProgress(
                $strategy === self::CHALLENGE_DNS01
                    ? (string)__('使用 DNS-01 验证（将自动添加 TXT 记录）')
                    : (string)__('使用 HTTP-01 验证'),
                ['strategy' => $strategy]
            );
        }

        $onProg = static function (string $msg, array $extra = []) use ($onProgress): void {
            if ($onProgress instanceof \Closure) {
                $onProgress($msg, $extra);
            }
        };

        try {
            $onProg(__('正在获取 ACME 目录...'), ['step' => 'directory']);
            $directory = $this->getAcmeDirectory();
            if (!$directory) {
                return ['success' => false, 'message' => __('无法获取 ACME 目录')];
            }

            $onProg(__('正在生成域名密钥...'), ['step' => 'domain_key']);
            $domainKeyPath = $certDir . 'domain.key';
            if (!$this->generateDomainKey($domainKeyPath)) {
                return ['success' => false, 'message' => __('无法生成域名密钥')];
            }

            $onProg(__('正在注册/获取 ACME 账户...'), ['step' => 'account']);
            $accountUrl = $this->registerAccount($directory['newAccount'], $email);
            if (!$accountUrl) {
                return ['success' => false, 'message' => __('账户注册失败')];
            }

            $dnsOrderMaxTries = ($strategy === self::CHALLENGE_DNS01) ? 2 : 1;
            $orderUrl = '';

            for ($orderTry = 1; $orderTry <= $dnsOrderMaxTries; $orderTry++) {
                if ($orderTry > 1) {
                    $onProg(
                        (string)__(
                            '证书机构查询 DNS TXT 超时，已重新创建订单并自动重试（将写入新的 TXT 验证值，与上次不同）...'
                        ),
                        ['step' => 'dns01_retry_order']
                    );
                } else {
                    $onProg(__('正在创建证书订单...'), ['step' => 'order']);
                }
                $this->lastAcmeError = '';
                $orderUrl = $this->createOrder($directory['newOrder'], [$domain], $accountUrl);
                if (!$orderUrl) {
                    $detail = $this->lastAcmeError !== '' ? $this->lastAcmeError : __('CA 未返回订单地址');
                    return ['success' => false, 'message' => __('创建订单失败：%{1}', [$detail])];
                }

                $onProg(__('正在获取授权信息...'), ['step' => 'authorizations']);
                $order = $this->getResource($orderUrl, $accountUrl);
                if (!$order || empty($order['authorizations'])) {
                    return ['success' => false, 'message' => __('获取授权失败')];
                }

                $allChallengesOk = true;
                $lastFailDetail = '';
                foreach ($order['authorizations'] as $authUrl) {
                    $auth = $this->getResource($authUrl, $accountUrl);
                    if (!$auth) {
                        return ['success' => false, 'message' => __('获取授权详情失败')];
                    }

                    $challengeResult = $strategy === self::CHALLENGE_DNS01
                        ? $this->performDns01Challenge($auth, $authUrl, $accountUrl, $domain, $poolId, $domainId, $onProgress)
                        : $this->performHttp01Challenge($auth, $authUrl, $accountUrl, $webroot, $onProgress);

                    if (!($challengeResult['validated'] ?? false)) {
                        $allChallengesOk = false;
                        $lastFailDetail = (string) ($challengeResult['error'] ?? '');
                        $retryTxtTimeout = $strategy === self::CHALLENGE_DNS01
                            && $orderTry < $dnsOrderMaxTries
                            && $this->isAcmeDns01TxtQueryTimeout($lastFailDetail);
                        if ($retryTxtTimeout) {
                            break;
                        }
                        return [
                            'success' => false,
                            'message' => $this->formatAcmeChallengeFailureMessage($lastFailDetail),
                        ];
                    }
                }

                if ($allChallengesOk) {
                    break;
                }
                if ($orderTry >= $dnsOrderMaxTries || !$this->isAcmeDns01TxtQueryTimeout($lastFailDetail)) {
                    return [
                        'success' => false,
                        'message' => $this->formatAcmeChallengeFailureMessage($lastFailDetail),
                    ];
                }
            }

            // 6. 完成订单并获取证书
            $onProg(__('正在生成 CSR...'), ['step' => 'csr', 'progress' => 92]);
            $csrPath = $certDir . 'csr.pem';
            $csr = $this->generateCsr($domain, $domainKeyPath, $csrPath);
            if (!$csr) {
                return ['success' => false, 'message' => __('生成 CSR 失败')];
            }

            $onProg(__('正在提交 CSR 至 CA...'), ['step' => 'finalize', 'progress' => 94]);
            $order = $this->getResource($orderUrl, $accountUrl);
            $certUrl = $this->finalize($order['finalize'], $csr, $accountUrl);
            if (!$certUrl) {
                return ['success' => false, 'message' => __('提交 CSR 失败')];
            }

            $onProg(__('等待 CA 颁发证书...'), ['step' => 'wait_cert', 'progress' => 96]);
            $maxWait = 30;
            $certReady = false;
            for ($i = 0; $i < $maxWait; $i++) {
                $this->waitSeconds(2);
                $order = $this->getResource($orderUrl, $accountUrl);
                if ($order && $order['status'] === 'valid' && !empty($order['certificate'])) {
                    $certReady = true;
                    $certUrl = $order['certificate'];
                    break;
                }
                if ($onProgress && $i > 0 && $i % 3 === 0) {
                    $onProg(__('等待颁发中...（%{1}s）', [$i * 2]), ['progress' => 96]);
                }
            }

            if (!$certReady) {
                return ['success' => false, 'message' => __('等待证书颁发超时')];
            }

            $onProg(__('正在下载证书...'), ['step' => 'download', 'progress' => 98]);
            $certPem = $this->downloadCertificate($certUrl, $accountUrl);
            if (!$certPem) {
                return ['success' => false, 'message' => __('下载证书失败')];
            }

            $onProg(__('正在保存证书到本地...'), ['step' => 'save', 'progress' => 99]);
            $fullchainPath = $certDir . 'fullchain.pem';
            $privkeyPath = $certDir . 'privkey.pem';

            \file_put_contents($fullchainPath, $certPem);
            \copy($domainKeyPath, $privkeyPath);

            $onProg(__('证书已写入：%{1}', [$fullchainPath]), ['step' => 'saved', 'cert_path' => $fullchainPath, 'cert_dir' => $certDir]);
            $onProg(__('证书申请流程已完成'), ['step' => 'done', 'progress' => 100]);
            return ['success' => true, 'message' => __('证书申请成功')];
            
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    protected function performHttp01Challenge(
        array $auth,
        string $authUrl,
        string $accountUrl,
        string $webroot,
        ?\Closure $onProgress = null
    ): array {
        $httpChallenge = null;
        foreach ($auth['challenges'] ?? [] as $challenge) {
            if (($challenge['type'] ?? '') === 'http-01') {
                $httpChallenge = $challenge;
                break;
            }
        }
        if (!$httpChallenge) {
            return ['validated' => false, 'error' => __('未找到 HTTP-01 挑战')];
        }

        $onProg = static function (string $msg, array $extra = []) use ($onProgress): void {
            if ($onProgress instanceof \Closure) {
                $onProgress($msg, $extra);
            }
        };

        $onProg(__('正在创建 HTTP-01 验证文件'), ['progress' => 35]);
        if (!$this->createHttpChallenge($webroot, $httpChallenge['token'], $httpChallenge['token'])) {
            return ['validated' => false, 'error' => __('创建验证文件失败')];
        }

        $onProg(__('正在通知 CA 服务器进行验证'), ['progress' => 50]);
        $this->notifyChallenge($httpChallenge['url'], $accountUrl);

        $onProg(__('正在等待 CA 验证...'), ['progress' => 60]);
        $maxWait = 60;
        $validated = false;
        $lastAuth = null;
        for ($i = 0; $i < $maxWait; $i++) {
            $this->waitSeconds(2);
            $auth = $this->getResource($authUrl, $accountUrl);
            $lastAuth = $auth;
            if ($auth && ($auth['status'] ?? '') === 'valid') {
                $validated = true;
                break;
            }
            if ($auth && ($auth['status'] ?? '') === 'invalid') {
                break;
            }
            if ($i > 0 && $i % 5 === 0) {
                $onProg(__('等待 CA 验证中… (%1秒)', [$i * 2]), ['progress' => 60 + (int) (20 * $i / $maxWait)]);
            }
        }

        $onProg(__('正在清理验证文件'), ['progress' => 90]);
        $this->cleanupHttpChallenge($webroot, $httpChallenge['token']);

        $error = '';
        if (!$validated && $lastAuth && ($lastAuth['status'] ?? '') === 'invalid') {
            foreach ($lastAuth['challenges'] ?? [] as $c) {
                if (($c['type'] ?? '') === 'http-01' && isset($c['error']['detail'])) {
                    $error = (string) $c['error']['detail'];
                    break;
                }
            }
        }

        return ['validated' => $validated, 'error' => $error];
    }

    protected function performDns01Challenge(
        array $auth,
        string $authUrl,
        string $accountUrl,
        string $domain,
        int $poolId,
        int $domainId,
        ?\Closure $onProgress = null
    ): array {
        $dnsChallenge = null;
        foreach ($auth['challenges'] ?? [] as $challenge) {
            if (($challenge['type'] ?? '') === 'dns-01') {
                $dnsChallenge = $challenge;
                break;
            }
        }
        if (!$dnsChallenge) {
            return ['validated' => false, 'error' => __('未找到 DNS-01 挑战')];
        }
        $thumbprint = $this->getAccountKeyThumbprint();
        if ($thumbprint === '') {
            return ['validated' => false, 'error' => __('获取账户指纹失败')];
        }
        $keyAuth = ($dnsChallenge['token'] ?? '') . '.' . $thumbprint;
        $digest = \hash('sha256', $keyAuth, true);
        $challengeValue = \str_replace(['+', '/', '='], ['-', '_', ''], \base64_encode($digest));

        // 与 addAcmeTxtRecord 同源解析 DNS 供应商，不依赖其返回值（线上 w_query/序列化可能丢 dns_provider）
        $dnsProviderCode = '';
        try {
            $probe = w_query('websites', 'getAcmeDnsProviderCode', [
                'domain' => $domain,
                'pool_id' => $poolId,
                'domain_id' => $domainId,
            ]);
            if (\is_array($probe)) {
                $dnsProviderCode = \strtolower(\trim((string)($probe['provider_code'] ?? '')));
            }
        } catch (\Throwable) {
            // 未升级 Websites 时无此 operation，回退至 addResult 中的 dns_provider
        }

        if ($onProgress) {
            $onProgress((string)__('正在通过 DNS 供应商添加 TXT 记录...'), ['step' => 'add_txt']);
        }
        $addResult = w_query('websites', 'addAcmeTxtRecord', [
            'domain' => $domain,
            'challenge_value' => $challengeValue,
            'pool_id' => $poolId,
            'domain_id' => $domainId,
            '_on_progress' => $onProgress,
        ]);
        if (!($addResult['success'] ?? false)) {
            $addErr = (string) ($addResult['message'] ?? __('未知错误'));
            if ($onProgress) {
                $extra = ['step' => 'add_txt_fail'];
                if (isset($addResult['dns_response'])) {
                    $extra['dns_response'] = $addResult['dns_response'];
                }
                $onProgress((string)__('添加 TXT 记录失败：%{1}', [$addErr]), $extra);
            }
            return ['validated' => false, 'error' => $addErr];
        }
        $recordId = (string)($addResult['record_id'] ?? '');
        if ($dnsProviderCode === '' && \is_array($addResult)) {
            $dnsProviderCode = \strtolower(\trim((string)($addResult['dns_provider'] ?? '')));
            if ($dnsProviderCode === '') {
                $dr = $addResult['dns_response'] ?? null;
                if (\is_array($dr) && isset($dr['provider'])) {
                    $dnsProviderCode = \strtolower(\trim((string) $dr['provider']));
                }
            }
        }
        // 先轮询公网 TXT 是否可见，避免 Gname 等未真正生效时白等 3+7 分钟
        $txtFqdn = '_acme-challenge.' . $domain;
        // GName / Cloudflare 等 API 返回成功到公网 TXT 可解析常需更久，90s 易误判失败
        $pollMaxSeconds = \in_array($dnsProviderCode, ['gname', 'cloudflare'], true) ? 180 : 90;
        $pollIntervalSeconds = 10;
        $txtVisible = false;
        if ($onProgress) {
            $onProgress(
                (string)__('检查 TXT 是否在公网生效（本机+公共 DNS，最多 %{1} 秒；GName、Cloudflare 等写入后公网可见常需 1～3 分钟）', [$pollMaxSeconds]),
                ['step' => 'dns_visibility']
            );
        }
        $elapsed = 0;
        while ($elapsed <= $pollMaxSeconds) {
            $txtVisible = $this->isAcmeTxtVisible($txtFqdn, $challengeValue);
            if ($txtVisible) {
                break;
            }
            if ($elapsed >= $pollMaxSeconds) {
                break;
            }
            $wait = \min($pollIntervalSeconds, $pollMaxSeconds - $elapsed);
            $this->waitSeconds($wait);
            $elapsed += $wait;
            if ($onProgress && $elapsed <= $pollMaxSeconds) {
                $onProgress((string)__('检查 TXT 生效中...（已等待 %{1}s / 最多 %{2}s）', [$elapsed, $pollMaxSeconds]), ['progress' => 40]);
            }
        }
        if (!$txtVisible) {
            if ($recordId !== '') {
                w_query('websites', 'removeAcmeTxtRecord', [
                    'domain' => $domain,
                    'record_id' => $recordId,
                    'pool_id' => $poolId,
                    'domain_id' => $domainId,
                ]);
            }
            $err = (string)__('TXT 记录在公网未生效，可能未添加成功或 DNS 传播较慢（如 Gname、Cloudflare）。请确认域名 NS 已指向当前 DNS 服务商后重试。');
            if ($onProgress) {
                $onProgress($err, ['step' => 'dns_visibility_fail']);
            }
            return ['validated' => false, 'error' => $err];
        }
        // 公网已能解析 TXT 即可通知 CA；本系统检测路径与 LE 全球多点查询权威 NS 的路径不同，后者偶发超时不代表 TXT 未写入
        if ($onProgress) {
            $onProgress((string)__('TXT 已在公网生效，正在通知 CA 验证...'), ['step' => 'notify_ca']);
            $onProgress(
                (string)__(
                    '说明：本系统与证书机构使用不同 DNS 路径。证书机构从全球多点直连您域名的权威 DNS；若 GName 等对 CA 查询响应慢，仍可能报「查询超时」，与「记录已生效」不矛盾，可稍后重试或换更快 DNS 托管。'
                ),
                ['step' => 'notify_ca_hint']
            );
        }
        $this->notifyChallenge($dnsChallenge['url'] ?? '', $accountUrl);

        // CA 查询 TXT 可能较慢（如 Gname），等待轮次调长以减少 query timed out（约 14 分钟）
        $maxWait = 420;
        $maxWaitMinutes = (int) ($maxWait * 2 / 60);
        if ($onProgress) {
            $onProgress((string)__('等待 CA 验证 DNS 记录（最多 %{1} 分钟）...', [$maxWaitMinutes]), ['step' => 'wait_validation']);
        }
        $validated = false;
        $lastAuth = null;
        for ($i = 0; $i < $maxWait; $i++) {
            $this->waitSeconds(2);
            if ($onProgress && $i > 0 && $i % 5 === 0) {
                $onProgress((string)__('等待 CA 验证中...（%{1}s）', [$i * 2]), ['progress' => (int) \min(90, 50 + $i)]);
            }
            $auth = $this->getResource($authUrl, $accountUrl);
            $lastAuth = $auth;
            if ($auth && ($auth['status'] ?? '') === 'valid') {
                $validated = true;
                break;
            }
            if ($auth && ($auth['status'] ?? '') === 'invalid') {
                break;
            }
        }

        if ($recordId !== '') {
            if ($onProgress) {
                $onProgress(
                    $validated
                        ? (string)__('CA 验证完成，正在清理 TXT 记录...')
                        : (string)__('CA 验证未通过，正在清理临时 TXT 记录...'),
                    ['step' => 'cleanup']
                );
            }
            w_query('websites', 'removeAcmeTxtRecord', [
                'domain' => $domain,
                'record_id' => $recordId,
                'pool_id' => $poolId,
                'domain_id' => $domainId,
            ]);
        }

        $error = '';
        if (!$validated && $lastAuth && ($lastAuth['status'] ?? '') === 'invalid') {
            foreach ($lastAuth['challenges'] ?? [] as $c) {
                if (($c['type'] ?? '') === 'dns-01' && isset($c['error']['detail'])) {
                    $error = (string) $c['error']['detail'];
                    break;
                }
            }
        }

        return ['validated' => $validated, 'error' => $error];
    }

    private function isAcmeDns01TxtQueryTimeout(string $detail): bool
    {
        $d = \strtolower($detail);
        if (!\str_contains($d, 'txt')) {
            return false;
        }
        return \str_contains($d, 'looking up txt')
            && (\str_contains($d, 'timed out') || \str_contains($d, 'timeout') || \str_contains($d, 'query timed out'));
    }

    private function formatAcmeChallengeFailureMessage(string $detail): string
    {
        $msg = $detail !== '' ? __('域名验证失败：%{1}', [$detail]) : __('域名验证失败');
        $detailLower = \strtolower($detail);
        if (\str_contains($detailLower, 'txt record') || \str_contains($detailLower, 'no txt')) {
            $msg .= ' ' . __('（DNS 传播可能需要更长时间，请 2–5 分钟后重试）');
        } elseif ($this->isAcmeDns01TxtQueryTimeout($detail)) {
            $msg .= ' ' . __(
                '（DNS-01：CA 从全球多点查您权威 DNS 上的 TXT。若同款流程在把域名 NS 换到 Cloudflare 后即可签发，基本可认定原 DNS 托管（如注册商自带解析）对 TXT 的全球查询不稳定或未正确对外服务；建议长期用 Cloudflare 等做 DNS 托管。）'
            );
        } elseif (\str_contains($detailLower, 'looking up a for') || \str_contains($detailLower, 'looking up aaaa for') || (\str_contains($detailLower, 'query timed out') && !\str_contains($detailLower, 'txt'))) {
            $msg .= ' ' . __('（HTTP 验证场景：CA 查询 A/AAAA 超时或未生效；若站点经 CDN，请改用 DNS-01 或保证源站可达。）');
        }
        return $msg;
    }

    protected function getAccountKeyThumbprint(): string
    {
        $key = \openssl_pkey_get_private(\file_get_contents($this->accountKeyPath));
        if (!$key) {
            return '';
        }
        $details = \openssl_pkey_get_details($key);
        if (!$details || !isset($details['rsa']['n'])) {
            return '';
        }
        $jwk = [
            'e' => $this->base64UrlEncode($details['rsa']['e']),
            'kty' => 'RSA',
            'n' => $this->base64UrlEncode($details['rsa']['n']),
        ];
        $thumbprint = \hash('sha256', \json_encode($jwk, \JSON_UNESCAPED_SLASHES), true);
        return \str_replace(['+', '/', '='], ['-', '_', ''], \base64_encode($thumbprint));
    }

    /**
     * 检查 ACME TXT 是否在公网可见：先本机解析，再 Google/Cloudflare DoH（绕过本机缓存，常比单用 dns_get_record 早看到 GName 等）
     */
    protected function isAcmeTxtVisible(string $txtFqdn, string $expectedValue): bool
    {
        if ($this->acmeTxtMatchesDnsGetRecord($txtFqdn, $expectedValue)) {
            return true;
        }
        return $this->isAcmeTxtVisibleViaPublicDoh($txtFqdn, $expectedValue);
    }

    private function acmeTxtMatchesDnsGetRecord(string $txtFqdn, string $expectedValue): bool
    {
        $records = @\dns_get_record($txtFqdn, \DNS_TXT);
        if (!\is_array($records)) {
            return false;
        }
        foreach ($records as $r) {
            $txt = $r['txt'] ?? null;
            if ($txt === $expectedValue) {
                return true;
            }
            if (\is_array($txt)) {
                foreach ($txt as $t) {
                    if ($t === $expectedValue) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * @see https://developers.google.com/speed/public-dns/docs/doh-json
     */
    private function isAcmeTxtVisibleViaPublicDoh(string $txtFqdn, string $expectedValue): bool
    {
        if (!\function_exists('curl_init')) {
            return false;
        }
        $qname = \rtrim(\strtolower($txtFqdn), '.') . '.';
        $enc = \rawurlencode($qname);
        $endpoints = [
            ['url' => 'https://dns.google/resolve?name=' . $enc . '&type=TXT', 'accept' => ''],
            ['url' => 'https://cloudflare-dns.com/dns-query?name=' . $enc . '&type=TXT', 'accept' => 'application/dns-json'],
        ];
        foreach ($endpoints as $ep) {
            $ch = \curl_init($ep['url']);
            if ($ch === false) {
                continue;
            }
            $headers = ['User-Agent: Weline-Server/1.0 ACME-TXT-Check'];
            if ($ep['accept'] !== '') {
                $headers[] = 'Accept: ' . $ep['accept'];
            }
            \curl_setopt_array($ch, [
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_TIMEOUT => 6,
                \CURLOPT_CONNECTTIMEOUT => 3,
                \CURLOPT_HTTPHEADER => $headers,
                \CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $raw = \curl_exec($ch);
            \curl_close($ch);
            if (!\is_string($raw) || $raw === '') {
                continue;
            }
            $json = \json_decode($raw, true);
            if (!\is_array($json) || (int) ($json['Status'] ?? -1) !== 0) {
                continue;
            }
            foreach ($json['Answer'] ?? [] as $a) {
                if ((int) ($a['type'] ?? 0) !== 16) {
                    continue;
                }
                $data = (string) ($a['data'] ?? '');
                if ($this->acmeTxtDataMatchesExpected($data, $expectedValue)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function acmeTxtDataMatchesExpected(string $data, string $expectedValue): bool
    {
        $data = \trim($data);
        if ($data === $expectedValue) {
            return true;
        }
        if (\str_starts_with($data, '"') && \str_ends_with($data, '"') && \strlen($data) >= 2) {
            if (\substr($data, 1, -1) === $expectedValue) {
                return true;
            }
        }
        if (\preg_match_all('/"((?:\\\\.|[^"\\\\])*)"/', $data, $m)) {
            foreach ($m[1] as $seg) {
                if (\stripcslashes((string) $seg) === $expectedValue) {
                    return true;
                }
            }
        }
        return false;
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
     * 失败时会将 CA 返回的错误信息写入 $this->lastAcmeError
     */
    protected function createOrder(string $url, array $domains, string $accountUrl): ?string
    {
        $identifiers = [];
        foreach ($domains as $domain) {
            $identifiers[] = ['type' => 'dns', 'value' => $domain];
        }

        $payload = ['identifiers' => $identifiers];
        $response = $this->signedRequest($url, $payload, $accountUrl);

        $location = $response['headers']['location'] ?? null;
        if ($location !== null && $location !== '') {
            return $location;
        }

        $this->lastAcmeError = $this->extractAcmeErrorFromResponse($response);
        return null;
    }

    /**
     * 从 ACME 响应中解析错误信息（支持 JWS 格式的 payload）
     */
    protected function extractAcmeErrorFromResponse(array $response): string
    {
        $body = $response['body'] ?? null;
        if (!\is_array($body)) {
            $raw = $response['raw'] ?? '';
            return \trim((string) $raw) !== '' ? \substr((string) $raw, 0, 200) : '';
        }
        if (isset($body['detail']) && \is_string($body['detail'])) {
            return $body['detail'];
        }
        if (isset($body['payload']) && \is_string($body['payload'])) {
            $decoded = $this->base64UrlDecode($body['payload']);
            if ($decoded !== '') {
                $inner = \json_decode($decoded, true);
                if (\is_array($inner) && isset($inner['detail']) && \is_string($inner['detail'])) {
                    return $inner['detail'];
                }
                if (\is_array($inner) && isset($inner['type']) && \is_string($inner['type'])) {
                    return $inner['type'];
                }
            }
        }
        if (isset($body['type']) && \is_string($body['type'])) {
            return $body['type'];
        }
        return '';
    }

    /**
     * Base64 URL 解码
     */
    protected function base64UrlDecode(string $data): string
    {
        $data = \strtr($data, '-_', '+/');
        $decoded = \base64_decode($data, true);
        return $decoded !== false ? $decoded : '';
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
            'issuer' => $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? '',
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
     * 从证书管理中重载证书文件并刷新 WLS 证书映射。
     *
     * @param string|null $domain 可选，只处理指定域名
     * @param bool $clearNoPem 若为 true，对缺少 PEM 的证书记录执行删除而非跳过
     * @return array{processed:int,reloaded:int,expired:int,skipped:int,deleted:int,errors:array<int,string>,domains:array<int,string>,deleted_domains:array<int,string>}
     */
    public function reloadManagedCertificates(?string $domain = null, bool $clearNoPem = false): array
    {
        $result = [
            'processed' => 0,
            'reloaded' => 0,
            'expired' => 0,
            'skipped' => 0,
            'deleted' => 0,
            'errors' => [],
            'domains' => [],
            'deleted_domains' => [],
        ];

        $query = $this->certModel->clearQuery();
        if ($domain !== null && \trim($domain) !== '') {
            $query->where(SslCertificate::schema_fields_DOMAIN, \strtolower(\trim($domain)));
        } else {
            $query->where(SslCertificate::schema_fields_HTTPS_ENABLED, 1)
                ->where(
                    SslCertificate::schema_fields_STATUS,
                    [
                        SslCertificate::STATUS_ACTIVE,
                        SslCertificate::STATUS_EXPIRED,
                        SslCertificate::STATUS_ERROR,
                    ],
                    'IN'
                );
        }

        $certificates = $query->select()->fetchArray();
        if ($certificates === []) {
            $result['errors'][] = $domain
                ? (string) __('未找到域名 %{1} 的证书记录', [$domain])
                : (string) __('证书管理中没有可重载的证书记录');
            return $result;
        }

        $expiredCerts = [];
        foreach ($certificates as $cert) {
            $result['processed']++;
            $certDomain = \strtolower(\trim((string) ($cert[SslCertificate::schema_fields_DOMAIN] ?? '')));
            if ($certDomain === '') {
                $result['skipped']++;
                $result['errors'][] = (string) __('存在空域名证书记录，已跳过');
                continue;
            }

            $expiresAt = (string) ($cert[SslCertificate::schema_fields_EXPIRES_AT] ?? '');
            $isExpired = $expiresAt !== '' && \strtotime($expiresAt) < \time();
            if ($isExpired) {
                $result['expired']++;
                $expiredCerts[] = [
                    'domain' => $certDomain,
                    'expires_at' => $expiresAt,
                    'cert_id' => (int) ($cert[SslCertificate::schema_fields_ID] ?? 0),
                ];
                continue;
            }

            $certPem = (string) ($cert[SslCertificate::schema_fields_CERT_PEM] ?? '');
            $keyPem = (string) ($cert[SslCertificate::schema_fields_KEY_PEM] ?? '');
            if ($certPem === '' || $keyPem === '') {
                if ($clearNoPem) {
                    $this->clearDomainCertificate($certDomain, $cert, $result);
                } else {
                    $result['skipped']++;
                    $result['errors'][] = (string) __('域名 %{1} 的证书管理记录缺少 PEM 内容，无法重载（使用 --clear 可清除并重置）', [$certDomain]);
                }
                continue;
            }

            if ($this->restoreCertificateFilesFromData($cert)) {
                $result['reloaded']++;
                $result['domains'][] = $certDomain;
            } else {
                if ($clearNoPem) {
                    $this->clearDomainCertificate($certDomain, $cert, $result);
                } else {
                    $result['skipped']++;
                    $result['errors'][] = (string) __('域名 %{1} 的证书文件恢复失败（使用 --clear 可清除并重置）', [$certDomain]);
                }
            }
        }

        if ($expiredCerts !== []) {
            $this->notifyExpiredCertificates($expiredCerts);
        }

        if ($result['reloaded'] > 0 || $result['deleted'] > 0) {
            $this->clearServerCache();
        }

        return $result;
    }
    
    /**
     * 清理服务器缓存
     */
    /**
     * 清除指定域名的证书：删除 DB 记录 + 清除磁盘证书目录，使该域名回到"无证书"状态。
     *
     * @param array<string, mixed> $cert 证书行数据
     * @param array<string, mixed> &$result reloadManagedCertificates 的结果数组（引用修改）
     */
    private const PROTECTED_LOCAL_DOMAINS = ['localhost', '127.0.0.1', '::1'];

    protected function clearDomainCertificate(string $domain, array $cert, array &$result): void
    {
        if (\in_array(\strtolower($domain), self::PROTECTED_LOCAL_DOMAINS, true)) {
            $result['skipped'] = ($result['skipped'] ?? 0) + 1;
            $result['errors'][] = (string) __('本地域名 %{1} 的证书受保护，跳过清除', [$domain]);
            return;
        }

        $certId = (int) ($cert[SslCertificate::schema_fields_ID] ?? 0);

        // 1. 删除 DB 记录
        if ($certId > 0) {
            try {
                $toDelete = ObjectManager::getInstance(SslCertificate::class, [], false);
                $toDelete->load($certId);
                if ($toDelete->getCertId()) {
                    $toDelete->delete()->fetch();
                }
            } catch (\Throwable $e) {
                $result['errors'][] = (string) __('域名 %{1} 删除 DB 记录失败：%{2}', [$domain, $e->getMessage()]);
            }
        }

        // 2. 清除磁盘证书目录（app/etc/ssl/{domain}/）
        $certDir = $this->certBaseDir . \strtolower($domain) . DS;
        if (\is_dir($certDir)) {
            $files = @\scandir($certDir);
            if ($files !== false) {
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }
                    @\unlink($certDir . $file);
                }
            }
            @\rmdir($certDir);
        }

        // 3. 通知其他模块清除关联状态
        $this->dispatchCertificateDeletedEvent($domain, $certId, (string) __('server:ssl:reload --clear 清理'));

        $result['deleted']++;
        $result['deleted_domains'][] = $domain;
    }

    protected function clearServerCache(): void
    {
        // 重新生成证书映射文件（含泛域名展开）+ 自动通知 Worker 热重载
        $this->regenerateCertificateMap();
        
        // 清除实例配置中指向不存在证书的 ssl_cert/ssl_key/ssl_domain，避免 server:start 加载失效路径
        $this->clearInvalidSslPathsFromInstanceConfigs();
    }

    /**
     * 清理无效 SSL 配置：清除实例配置中失效证书路径，并重新生成证书映射。
     * 在检测到「证书文件不存在」时立即调用，避免反复报错。
     */
    public function cleanupInvalidSslConfigAndMap(): void
    {
        $this->clearInvalidSslPathsFromInstanceConfigs();
        $this->regenerateCertificateMap();
    }

    /**
     * 清除实例配置文件中指向不存在证书的 ssl_cert/ssl_key/ssl_domain。
     * 证书重载或删除后，若实例配置仍引用已失效路径，server:start 会报「证书文件不存在」。
     *
     * 必须清理两个目录：
     * - var/server/config/  loadSavedInstanceConfig 从此加载，getServerConfig 的 ssl_cert 来源
     * - var/server/instances/  Master 运行时实例文件，也含 ssl_cert/ssl_key
     */
    protected function clearInvalidSslPathsFromInstanceConfigs(): void
    {
        $dirsToClear = [
            Env::VAR_DIR . 'server' . DS . 'config' . DS,
            Env::VAR_DIR . 'server' . DS . 'instances' . DS,
        ];
        $clearModifier = static function (array $data): array {
            $sslCert = \trim((string) ($data['ssl_cert'] ?? ''));
            $sslKey = \trim((string) ($data['ssl_key'] ?? ''));
            if ($sslCert === '' && $sslKey === '') {
                return $data;
            }
            $certExists = $sslCert !== '' && \is_file($sslCert);
            $keyExists = $sslKey !== '' && \is_file($sslKey);
            if ($certExists && $keyExists) {
                return $data;
            }
            $data['ssl_cert'] = '';
            $data['ssl_key'] = '';
            $data['ssl_domain'] = '';
            return $data;
        };
        foreach ($dirsToClear as $dir) {
            if (!\is_dir($dir)) {
                continue;
            }
            $files = \glob($dir . '*.json');
            if ($files === false) {
                continue;
            }
            foreach ($files as $file) {
                ServerInstanceManager::atomicUpdateJsonStatic((string) $file, $clearModifier);
            }
        }
    }
    
    /**
     * 重新生成证书映射文件
     * 
     * 在证书签发、续签、启用/禁用后调用，确保证书映射文件包含最新的域名映射
     * 特别是处理泛域名证书的展开，使子域名能够正确匹配证书
     */
    public function regenerateCertificateMap(): void
    {
        $mapFile = Env::VAR_DIR . 'server' . DS . 'ssl_certificate_map.json';
        
        // 确保目录存在
        $mapDir = \dirname($mapFile);
        if (!\is_dir($mapDir)) {
            @\mkdir($mapDir, 0755, true);
        }
        
        // 获取证书映射（包含泛域名展开）
        $map = $this->getCertificateMap();
        
        // 保存映射文件
        \file_put_contents($mapFile, \json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        w_log_debug('[SslCertificateService] 证书映射文件已重新生成，包含 ' . \count($map) . ' 个域名');

        // 通知所有 Worker 热重载 SNI 证书映射（无需重启即可生效新证书）
        MasterProcess::sendSslCertReloadCommand('default');
    }
}
