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
use Weline\Framework\Database\Schema\SchemaMigrationExecutor;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Server\Api\Tls\AcmeDnsTxtPollPolicyProviderInterface;
use Weline\Server\Model\SslCertificate;
use Weline\Server\Service\Edge\CertificateMaterialUpdateCoordinator;
use Weline\Server\Service\Edge\Gateway\GatewayAcmeChallengePublisher;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Edge\Gateway\ProjectAcmeHttp01ChallengeStore;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxChildProcessProbe;

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
    public const ISSUER_LOCAL_CA = 'Weline Local Development CA';
    public const ISSUER_LETS_ENCRYPT = "Let's Encrypt";
    public const ISSUER_LITESSL = 'Sectigo';
    public const ISSUER_UNKNOWN = 'Unknown';
    
    /**
     * 证书申请提供商（三种来源：自签 / Let's Encrypt / LiteSSL）
     */
    public const PROVIDER_LETS_ENCRYPT = 'letsencrypt';
    public const PROVIDER_LITESSL = 'litessl';
    public const PROVIDER_SELF_SIGNED = 'self_signed';
    public const PROVIDER_LOCAL_CA = 'local_ca';

    private const LOCAL_CA_DIRNAME = '_local_ca';
    private const LOCAL_CA_CERT_FILENAME = 'rootCA.pem';
    private const LOCAL_CA_KEY_FILENAME = 'rootCA.key';
    private const LOCAL_CA_SERIAL_FILENAME = 'serial.txt';
    private const GLOBAL_LOCAL_CA_VENDOR_DIR = 'Weline';
    private const GLOBAL_LOCAL_CA_APP_DIR = 'WLS';

    /**
     * ACME 申请进行中锁文件（位于 app/etc/ssl/{domain}/），用于避免颁发过程中 sync/页面同步覆盖证书记录
     */
    public const SSL_ISSUANCE_LOCK_FILENAME = '.ssl_issuing';

    /** 锁文件超过此时长视为进程异常退出残留，允许自动清除（秒） */
    protected const SSL_ISSUANCE_LOCK_STALE_SECONDS = 7200;
    
    /**
     * Let's Encrypt ACME 目录
     */
    protected const ACME_DIRECTORY_PROD = 'https://acme-v02.api.letsencrypt.org/directory';
    protected const ACME_DIRECTORY_STAGING = 'https://acme-staging-v02.api.letsencrypt.org/directory';
    protected const GATEWAY_ACME_PUBLISH_TIMEOUT_SECONDS = 15.0;
    protected const GATEWAY_ACME_PUBLISH_INITIAL_RETRY_MICROSECONDS = 250_000;
    protected const GATEWAY_ACME_PUBLISH_MAX_RETRY_MICROSECONDS = 4_000_000;
    private const MAX_CERTIFICATE_MATERIAL_BYTES = 1_048_576;
    private const MAX_LEGACY_CERTIFICATE_MAP_ENTRIES = 4096;
    private const MAX_ACME_CHALLENGE_STATE_BYTES = 1024;
    private const MAX_CERTIFICATE_SOURCE_DIRECTORIES = 1024;
    private const MAX_CERTIFICATE_DIRECTORY_ENTRIES = 32;
    private const MAX_INSTANCE_JSON_FILES = 512;
    private const MAX_TRUST_COMMAND_OUTPUT_BYTES = 262_144;
    private const MAX_TRUST_COMMAND_TIMEOUT_MS = 30_000;
    private const MAX_ACME_HTTP_HEADER_BYTES = 65_536;
    private const MAX_ACME_HTTP_RESPONSE_BYTES = 4_194_304;
    
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
     * Cache local CA trust results by certificate fingerprint for the current
     * process. Importing a root CA can trigger OS credential prompts, so one CA
     * must never attempt trust import once per generated leaf certificate.
     *
     * @var array<string,array{success:bool,trusted:bool,message:string}>
     */
    protected array $localCaTrustResultCache = [];
    
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
    protected ?SslCertificate $certModel = null;

    /**
     * 证书表首次启动兜底只需每进程执行一次。
     */
    protected static bool $certificateStorageReady = false;

    /** @var array<string,int> */
    private static array $heldLocalCaStateLocks = [];

    /**
     * Definitive SQLite corruption is non-transient for this PHP process.
     * Caching the reason prevents repeated table probes and bootstrap writes.
     */
    protected static ?string $certificateStorageCorruptionReason = null;
    
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

    private ?ProjectAcmeHttp01ChallengeStore $acmeHttp01Store = null;
    
    /**
     * SAN 条目缓存 [domain => ['dns' => [], 'ip' => []]]
     */
    protected array $sanEntriesCache = [];
    
    /**
     * 证书解析缓存。路径内容可能被续签原子替换，因此必须以内容摘要校验缓存。
     *
     * @var array<string,array{digest:string,parsed:array|false}>
     */
    protected array $certParseCache = [];
    
    public function __construct(bool $deferCertificateStorage = false)
    {
        $etcDirectory = \realpath(\dirname(Env::path_ENV_FILE));
        if (!\is_string($etcDirectory) || $etcDirectory === '') {
            throw new \RuntimeException('Unable to resolve the project certificate parent directory.');
        }
        $this->certBaseDir = \rtrim($etcDirectory, '/\\') . DS . 'ssl' . DS;
        $this->ensureCertificateBaseDirectory();
        $this->accountKeyPath = $this->certBaseDir . 'account.key';
        $this->updateAcmeDirectory();
        if (!$deferCertificateStorage) {
            $this->certificateModel();
            $this->ensureCertificateStorageReady();
        }
        
    }

    private static function sameFilesystemPath(string $left, string $right): bool
    {
        $normalize = static function (string $path): string {
            $path = \rtrim(\str_replace('\\', '/', $path), '/');
            return \PHP_OS_FAMILY === 'Windows' ? \strtolower($path) : $path;
        };

        return \hash_equals($normalize($left), $normalize($right));
    }

    private static function filesystemPathIsRoot(string $path): bool
    {
        $path = \str_replace('\\', '/', \trim($path));
        if (\preg_match('#\A/+\z#D', $path) === 1) {
            return true;
        }
        $path = \rtrim($path, '/');
        return \preg_match('/\A[A-Za-z]:\z/D', $path) === 1
            || \preg_match('#\A//(?![?.](?:/|\z))[^/]+(?:/[^/]+)?\z#D', $path) === 1
            || \preg_match('#\A//[?.]/[A-Za-z]:\z#Di', $path) === 1
            || \preg_match('#\A//[?.]/UNC(?:/[^/]+(?:/[^/]+)?)?\z#Di', $path) === 1
            || \preg_match(
                '#\A//[?.]/Volume\{[0-9A-Fa-f-]+\}\z#Di',
                $path,
            ) === 1;
    }

    private static function regularFileIdentityMatches(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
            if ((int)($left[$field] ?? -1) !== (int)($right[$field] ?? -2)) {
                return false;
            }
        }
        return true;
    }

    private function ensureCertificateBaseDirectory(): string
    {
        $base = \rtrim($this->certBaseDir, '/\\');
        if ($base === '' || \str_contains($base, "\0")) {
            throw new \RuntimeException('Project certificate directory path is unsafe.');
        }
        $status = @\lstat($base);
        if (!\is_array($status)) {
            if (\file_exists($base) || \is_link($base)) {
                throw new \RuntimeException('Project certificate directory is indeterminate.');
            }
            if (!@\mkdir($base, 0700, false)) {
                throw new \RuntimeException('Unable to create the project certificate directory.');
            }
            $status = @\lstat($base);
        }
        $real = \realpath($base);
        if (!\is_array($status)
            || (((int)($status['mode'] ?? 0)) & 0170000) !== 0040000
            || \is_link($base)
            || !\is_string($real)
            || !self::sameFilesystemPath($base, $real)
        ) {
            throw new \RuntimeException(
                'Project certificate directory must be a canonical no-follow directory.',
            );
        }
        $this->certBaseDir = \rtrim($real, '/\\') . DS;
        return $this->certBaseDir;
    }

    private function certificateDirectoryForSegment(string $segment, bool $create): ?string
    {
        if ($segment === ''
            || \strlen($segment) > 255
            || \str_contains($segment, "\0")
            || \str_contains($segment, '/')
            || \str_contains($segment, '\\')
            || $segment === '.'
            || $segment === '..'
        ) {
            throw new \RuntimeException('Certificate storage segment is unsafe.');
        }
        $base = $this->ensureCertificateBaseDirectory();
        $path = \rtrim($base, '/\\') . DS . $segment;
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException('Certificate domain directory is indeterminate.');
            }
            if (!$create) {
                return null;
            }
            if (!@\mkdir($path, 0700, false)) {
                throw new \RuntimeException('Unable to create the certificate domain directory.');
            }
            $status = @\lstat($path);
        }
        $real = \realpath($path);
        if (!\is_array($status)
            || (((int)($status['mode'] ?? 0)) & 0170000) !== 0040000
            || \is_link($path)
            || !\is_string($real)
            || !self::sameFilesystemPath($path, $real)
            || !\str_starts_with(
                \PHP_OS_FAMILY === 'Windows' ? \strtolower($real . DS) : $real . DS,
                \PHP_OS_FAMILY === 'Windows' ? \strtolower($base) : $base,
            )
        ) {
            throw new \RuntimeException(
                'Certificate domain directory must be canonical and inside app/etc/ssl.',
            );
        }
        return \rtrim($real, '/\\') . DS;
    }

    /** @return list<string> */
    private function boundedDirectoryEntries(string $directory, int $maximum, string $label): array
    {
        if ($maximum < 1 || $maximum > self::MAX_CERTIFICATE_SOURCE_DIRECTORIES) {
            throw new \InvalidArgumentException('Certificate directory bound is invalid.');
        }
        $status = @\lstat(\rtrim($directory, '/\\'));
        if (!\is_array($status)
            || (((int)($status['mode'] ?? 0)) & 0170000) !== 0040000
            || \is_link(\rtrim($directory, '/\\'))
        ) {
            throw new \RuntimeException($label . ' is not a safe directory.');
        }
        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to enumerate ' . $label . '.');
        }
        $entries = [];
        try {
            while (($entry = \readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (\count($entries) >= $maximum) {
                    throw new \RuntimeException($label . ' exceeds its bounded entry limit.');
                }
                $entries[] = $entry;
            }
        } finally {
            @\closedir($handle);
        }
        $after = @\lstat(\rtrim($directory, '/\\'));
        if (!\is_array($after)
            || (int)($status['dev'] ?? -1) !== (int)($after['dev'] ?? -2)
            || (int)($status['ino'] ?? -1) !== (int)($after['ino'] ?? -2)
        ) {
            throw new \RuntimeException($label . ' changed while it was enumerated.');
        }
        \sort($entries, SORT_STRING);
        return $entries;
    }

    private static function readRegularFileNoFollow(
        string $path,
        int $maximumBytes = self::MAX_CERTIFICATE_MATERIAL_BYTES,
        bool $allowEmpty = false,
        bool $private = false,
    ): ?string {
        if ($path === ''
            || \str_contains($path, "\0")
            || $maximumBytes < 1
            || \is_link($path)
        ) {
            return null;
        }
        $real = \realpath($path);
        $before = @\lstat($path);
        if (!\is_string($real)
            || !\is_array($before)
            || !self::sameFilesystemPath($path, $real)
            || (((int)($before['mode'] ?? 0)) & 0170000) !== 0100000
            || (int)($before['nlink'] ?? 0) !== 1
            || (int)($before['size'] ?? -1) < ($allowEmpty ? 0 : 1)
            || (int)($before['size'] ?? -1) > $maximumBytes
            || (\PHP_OS_FAMILY !== 'Windows'
                && $private
                && (((int)($before['mode'] ?? 0)) & 0077) !== 0)
        ) {
            return null;
        }
        $handle = @\fopen($real, 'rb');
        if (!\is_resource($handle)) {
            return null;
        }
        try {
            $opened = @\fstat($handle);
            if (!\is_array($opened) || !self::regularFileIdentityMatches($before, $opened)) {
                return null;
            }
            $contents = @\stream_get_contents($handle, $maximumBytes + 1);
            $after = @\fstat($handle);
        } finally {
            @\fclose($handle);
        }
        $latest = @\lstat($real);
        if (!\is_string($contents)
            || \strlen($contents) > $maximumBytes
            || (!$allowEmpty && $contents === '')
            || !\is_array($after)
            || !\is_array($latest)
            || !self::regularFileIdentityMatches($opened, $after)
            || !self::regularFileIdentityMatches($after, $latest)
            || (int)($latest['size'] ?? -1) !== \strlen($contents)
        ) {
            return null;
        }
        return $contents;
    }

    private static function readPrivateKeyFileNoFollow(string $path): ?string
    {
        return self::readRegularFileNoFollow(
            $path,
            self::MAX_CERTIFICATE_MATERIAL_BYTES,
            false,
            true,
        );
    }

    private function writeCertificateFileAtomically(
        string $path,
        string $contents,
        int $mode,
    ): void {
        if ($contents === '' || \strlen($contents) > self::MAX_CERTIFICATE_MATERIAL_BYTES) {
            throw new \RuntimeException('Certificate material content is empty or oversized.');
        }
        $base = $this->ensureCertificateBaseDirectory();
        $parent = \dirname($path);
        $parentReal = \realpath($parent);
        if (!\is_string($parentReal)
            || !self::sameFilesystemPath($parent, $parentReal)
            || (!self::sameFilesystemPath(\rtrim($parentReal, '/\\'), \rtrim($base, '/\\'))
                && !\str_starts_with(
                    \PHP_OS_FAMILY === 'Windows'
                        ? \strtolower(\rtrim($parentReal, '/\\') . DS)
                        : \rtrim($parentReal, '/\\') . DS,
                    \PHP_OS_FAMILY === 'Windows' ? \strtolower($base) : $base,
                ))
        ) {
            throw new \RuntimeException('Certificate material target is outside app/etc/ssl.');
        }
        $this->writeLockedSslStateAtomically(
            $path,
            $contents,
            $mode,
            self::MAX_CERTIFICATE_MATERIAL_BYTES,
            'project certificate material',
            function (string $candidate) use ($path): void {
                $this->assertCertificateStateContents($path, $candidate);
            },
        );
    }

    private function writeLocalCaStateAtomically(
        string $path,
        string $contents,
        int $mode,
    ): void {
        if ($contents === ''
            || \strlen($contents) > self::MAX_CERTIFICATE_MATERIAL_BYTES
            || \str_contains($path, "\0")
        ) {
            throw new \RuntimeException('Local CA state content or path is invalid.');
        }
        $parent = \dirname($path);
        $parentReal = \realpath($parent);
        $parentStatus = @\lstat($parent);
        if (!\is_string($parentReal)
            || !\is_array($parentStatus)
            || \is_link($parent)
            || ((((int)($parentStatus['mode'] ?? 0)) & 0170000) !== 0040000)
            || !self::sameFilesystemPath($parent, $parentReal)
            || self::filesystemPathIsRoot($parentReal)
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$parentStatus['mode']) & 0077) !== 0)
            )
        ) {
            throw new \RuntimeException('Local CA state directory is unsafe.');
        }
        $allowed = [];
        foreach ([
            $this->getLocalCaDir(),
            $this->getGlobalLocalCaDir(false),
        ] as $directory) {
            $real = $directory !== '' ? \realpath($directory) : false;
            if (\is_string($real)) {
                $allowed[] = $real;
            }
        }
        $authorized = false;
        foreach ($allowed as $directory) {
            if (self::sameFilesystemPath($parentReal, $directory)) {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            throw new \RuntimeException('Local CA state target is outside an authorized directory.');
        }
        $this->withLocalCaStateDirectoryLock(
            $parentReal,
            function () use ($path, $contents, $mode): void {
                $this->writeLockedSslStateAtomically(
                    $path,
                    $contents,
                    $mode,
                    self::MAX_CERTIFICATE_MATERIAL_BYTES,
                    'local certificate authority state',
                    function (string $candidate) use ($path): void {
                        $this->assertLocalCaStateContents($path, $candidate);
                    },
                );
            },
        );
    }

    /** @template TResult @param \Closure():TResult $operation @return TResult */
    private function withLocalCaStateDirectoryLock(
        string $directory,
        \Closure $operation,
    ): mixed {
        return (new ProjectCertificateGenerationStore())->withCertificateLifecycleLock(
            function () use ($directory, $operation): mixed {
                $canonical = \realpath($directory);
                $status = @\lstat($directory);
                if (!\is_string($canonical)
                    || !\is_array($status)
                    || \is_link($directory)
                    || !self::sameFilesystemPath($directory, $canonical)
                    || self::filesystemPathIsRoot($canonical)
                    || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
                ) {
                    throw new \RuntimeException('Local CA lock directory is unsafe.');
                }
                $lockPath = \rtrim($canonical, '/\\') . DS
                    . '.wls-local-ca-state.lock';
                if ((self::$heldLocalCaStateLocks[$lockPath] ?? 0) > 0) {
                    self::$heldLocalCaStateLocks[$lockPath]++;
                    try {
                        return $operation();
                    } finally {
                        self::$heldLocalCaStateLocks[$lockPath]--;
                    }
                }
                return GatewayProjectStateFilesystem::withExclusiveLock(
                    $lockPath,
                    function () use ($lockPath, $operation): mixed {
                        self::$heldLocalCaStateLocks[$lockPath] = 1;
                        try {
                            return $operation();
                        } finally {
                            unset(self::$heldLocalCaStateLocks[$lockPath]);
                        }
                    },
                );
            },
        );
    }

    /** @param \Closure(string):void $validateContents */
    private function writeLockedSslStateAtomically(
        string $path,
        string $contents,
        int $mode,
        int $maximumBytes,
        string $label,
        \Closure $validateContents,
    ): void {
        if ($path === ''
            || \str_contains($path, "\0")
            || $contents === ''
            || \strlen($contents) > $maximumBytes
            || !\in_array($mode, [0600, 0644], true)
            || $label === ''
        ) {
            throw new \RuntimeException('SSL state publication boundary is invalid.');
        }
        $validateContents($contents);
        (new ProjectCertificateGenerationStore())->withCertificateLifecycleLock(
            function () use (
                $path,
                $contents,
                $mode,
                $maximumBytes,
                $label,
                $validateContents,
            ): void {
                GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                    $path,
                    $maximumBytes,
                    $label,
                    function (string $current) use (
                        $path,
                        $mode,
                        $label,
                        $validateContents,
                    ): void {
                        $this->assertSslStateTargetMode($path, $mode, $label);
                        $validateContents($current);
                    },
                );
                GatewayProjectStateFilesystem::atomicWrite($path, $contents, $mode);
                $published = GatewayProjectStateFilesystem::read(
                    $path,
                    $maximumBytes,
                    'published ' . $label,
                );
                $this->assertSslStateTargetMode($path, $mode, $label);
                $validateContents($published);
                if (!\hash_equals(
                    \hash('sha256', $contents),
                    \hash('sha256', $published),
                )) {
                    throw new \RuntimeException(
                        'Published ' . $label . ' digest does not match the requested state.',
                    );
                }
            },
        );
    }

    private function assertSslStateTargetMode(
        string $path,
        int $mode,
        string $label,
    ): void {
        $status = @\lstat($path);
        if (!\is_array($status)
            || \is_link($path)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($status['nlink'] ?? 0) !== 1
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$status['mode']) & 0777) !== $mode))
        ) {
            throw new \RuntimeException($label . ' target permissions or identity are unsafe.');
        }
    }

    private function assertCertificateStateContents(string $path, string $contents): void
    {
        $leaf = \basename(\str_replace('\\', '/', $path));
        if (\in_array($leaf, ['fullchain.pem', 'cert.pem'], true)) {
            $domain = self::logicalDomainFromStorageSegment(
                \basename(\dirname(\str_replace('\\', '/', $path))),
            );
            if ($domain === '' || !self::certificatePemIsValidForName($contents, $domain)) {
                throw new \RuntimeException(
                    'Certificate material is invalid or does not cover its storage domain.',
                );
            }
            return;
        }
        if ($leaf === 'chain.pem') {
            if (!self::certificateBundlePemIsValid($contents, false)) {
                throw new \RuntimeException('Certificate chain material is malformed.');
            }
            return;
        }
        if (\in_array($leaf, ['privkey.pem', 'domain.key', 'account.key'], true)) {
            $this->assertPrivateKeyPem($contents);
            return;
        }
        if ($leaf === 'csr.pem') {
            $this->assertCertificateSigningRequest($path, $contents);
            return;
        }
        if ($leaf === self::SSL_ISSUANCE_LOCK_FILENAME) {
            if (\preg_match(
                '/\A[1-9][0-9]{0,18}\n[0-9]{4}-[0-9]{2}-[0-9]{2}T'
                    . '[0-9]{2}:[0-9]{2}:[0-9]{2}(?:[+-][0-9]{2}:[0-9]{2}|Z)\z/D',
                $contents,
            ) !== 1) {
                throw new \RuntimeException('SSL issuance marker is malformed.');
            }
            return;
        }
        if (\preg_match('/\Aopenssl(?:_san_[a-f0-9]{32})?\.cnf\z/D', $leaf) === 1) {
            $this->assertOpenSslConfiguration($contents);
            return;
        }
        throw new \RuntimeException('Certificate material target leaf is not managed.');
    }

    private function assertLocalCaStateContents(string $path, string $contents): void
    {
        $leaf = \basename(\str_replace('\\', '/', $path));
        if ($leaf === self::LOCAL_CA_CERT_FILENAME) {
            $certificates = $this->extractPemCertificates($contents);
            $parsed = \count($certificates) === 1
                ? $this->parseCertificatePem($certificates[0])
                : false;
            $now = \time();
            if (!self::certificateBundlePemIsValid($contents, false)
                || !\is_array($parsed)
                || (int)($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > $now
                || (int)($parsed['validTo_time_t'] ?? 0) <= $now
                || !$this->isCertificateAuthorityPem($certificates[0])
                || !$this->isCertificateSelfSignedPem($certificates[0])
            ) {
                throw new \RuntimeException('Local CA certificate state is invalid.');
            }
            return;
        }
        if ($leaf === self::LOCAL_CA_KEY_FILENAME) {
            $this->assertPrivateKeyPem($contents);
            return;
        }
        if ($leaf === self::LOCAL_CA_SERIAL_FILENAME) {
            if (\preg_match('/\A[1-9][0-9]{0,17}\z/D', $contents) !== 1) {
                throw new \RuntimeException('Local CA serial state is malformed.');
            }
            return;
        }
        if (\preg_match(
            '/\Aopenssl_(?:local_ca|leaf_[a-f0-9]{32})\.cnf\z/D',
            $leaf,
        ) === 1) {
            $this->assertOpenSslConfiguration($contents);
            return;
        }
        throw new \RuntimeException('Local CA state target leaf is not managed.');
    }

    private function assertPrivateKeyPem(string $contents): void
    {
        $pattern = '/-----BEGIN ((?:RSA |EC )?PRIVATE KEY)-----.*?'
            . '-----END \\1-----/s';
        $count = \preg_match_all($pattern, $contents, $matches);
        $residual = \preg_replace($pattern, '', $contents);
        $privateKeyPem = \is_array($matches[0] ?? null)
            ? (string)($matches[0][0] ?? '')
            : '';
        if ($count !== 1
            || !\is_string($residual)
            || \trim($residual) !== ''
            || \substr_count($privateKeyPem, '-----BEGIN ') !== 1
            || \substr_count($privateKeyPem, '-----END ') !== 1
            || @\openssl_pkey_get_private($privateKeyPem) === false
        ) {
            throw new \RuntimeException('Private key state is malformed.');
        }
    }

    private function assertCertificateSigningRequest(string $path, string $contents): void
    {
        $pattern = '/-----BEGIN CERTIFICATE REQUEST-----.*?'
            . '-----END CERTIFICATE REQUEST-----/s';
        $count = \preg_match_all($pattern, $contents, $matches);
        $residual = \preg_replace($pattern, '', $contents);
        $csrPem = \is_array($matches[0] ?? null)
            ? (string)($matches[0][0] ?? '')
            : '';
        if ($count !== 1
            || !\is_string($residual)
            || \trim($residual) !== ''
            || \substr_count(
                $csrPem,
                '-----BEGIN CERTIFICATE REQUEST-----',
            ) !== 1
            || \substr_count(
                $csrPem,
                '-----END CERTIFICATE REQUEST-----',
            ) !== 1
        ) {
            throw new \RuntimeException('Certificate signing request is malformed.');
        }
        $subject = @\openssl_csr_get_subject($csrPem, false);
        $domain = self::logicalDomainFromStorageSegment(
            \basename(\dirname(\str_replace('\\', '/', $path))),
        );
        $commonName = \is_array($subject) && \is_string($subject['commonName'] ?? null)
            ? (string)$subject['commonName']
            : (\is_array($subject) && \is_string($subject['CN'] ?? null)
                ? (string)$subject['CN']
                : '');
        try {
            $commonName = self::normalizeCertificateStorageDomain($commonName);
        } catch (\Throwable) {
            $commonName = '';
        }
        if ($domain === '' || $commonName === '' || !\hash_equals($domain, $commonName)) {
            throw new \RuntimeException(
                'Certificate signing request does not match its storage domain.',
            );
        }
    }

    private function assertOpenSslConfiguration(string $contents): void
    {
        if (\str_contains($contents, "\0")
            || \preg_match('/^\s*\[\s*req\s*\]\s*$/mi', $contents) !== 1
        ) {
            throw new \RuntimeException('OpenSSL configuration state is malformed.');
        }
    }

    private function removeCertificateLeafSafely(
        string $directory,
        string $leaf,
        ?array $expectedDirectoryStatus = null,
        ?array $expectedLeafIdentity = null,
    ): bool {
        if ($leaf === ''
            || \str_contains($leaf, "\0")
            || \str_contains($leaf, '/')
            || \str_contains($leaf, '\\')
            || $leaf === '.'
            || $leaf === '..'
        ) {
            throw new \RuntimeException('Certificate leaf name is unsafe.');
        }
        $directory = \rtrim($directory, '/\\');
        $directoryBefore = @\lstat($directory);
        $directoryReal = \realpath($directory);
        if (!\is_array($directoryBefore)
            || (((int)($directoryBefore['mode'] ?? 0)) & 0170000) !== 0040000
            || \is_link($directory)
            || !\is_string($directoryReal)
            || !self::sameFilesystemPath($directory, $directoryReal)
            || (\is_array($expectedDirectoryStatus)
                && ((int)($expectedDirectoryStatus['dev'] ?? -1)
                        !== (int)($directoryBefore['dev'] ?? -2)
                    || (int)($expectedDirectoryStatus['ino'] ?? -1)
                        !== (int)($directoryBefore['ino'] ?? -2)))
        ) {
            throw new \RuntimeException('Certificate directory changed before leaf removal.');
        }
        $path = $directory . DS . $leaf;
        $leafStatus = @\lstat($path);
        if (!\is_array($leafStatus)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException('Certificate leaf is indeterminate.');
            }
            if (\is_array($expectedLeafIdentity)) {
                throw new \RuntimeException(
                    'Certificate leaf identity changed after deletion preflight.',
                );
            }
            return false;
        }
        $type = ((int)($leafStatus['mode'] ?? 0)) & 0170000;
        if ($type !== 0100000 && $type !== 0120000) {
            throw new \RuntimeException('Certificate leaf is not a regular file or link.');
        }
        if ($type === 0100000 && (int)($leafStatus['nlink'] ?? 0) !== 1) {
            throw new \RuntimeException('Hard-linked certificate leaves cannot be removed.');
        }
        $observedIdentity = $this->certificateRemovalLeafIdentity(
            $path,
            $leafStatus,
        );
        if (\is_array($expectedLeafIdentity)
            && !\hash_equals(
                \hash('sha256', GatewayClient::canonicalJson($expectedLeafIdentity)),
                \hash('sha256', GatewayClient::canonicalJson($observedIdentity)),
            )
        ) {
            throw new \RuntimeException(
                'Certificate leaf identity changed after deletion preflight.',
            );
        }
        $immediatelyBefore = @\lstat($path);
        if (!\is_array($immediatelyBefore)
            || !$this->certificateRemovalStatusMatches(
                $leafStatus,
                $immediatelyBefore,
            )
        ) {
            throw new \RuntimeException(
                'Certificate leaf changed immediately before removal.',
            );
        }
        if (!@\unlink($path)) {
            throw new \RuntimeException('Unable to remove certificate leaf safely.');
        }
        GatewayProjectStateFilesystem::syncDirectory($directory);
        $directoryAfter = @\lstat($directory);
        if (!\is_array($directoryAfter)
            || (int)($directoryBefore['dev'] ?? -1) !== (int)($directoryAfter['dev'] ?? -2)
            || (int)($directoryBefore['ino'] ?? -1) !== (int)($directoryAfter['ino'] ?? -2)
        ) {
            throw new \RuntimeException('Certificate directory changed during leaf removal.');
        }
        return true;
    }

    private function removeCertificateStateLeafSafely(
        string $directory,
        string $leaf,
    ): bool {
        if (!\in_array($leaf, ['chain.pem', 'csr.pem'], true)) {
            throw new \InvalidArgumentException(
                'Optional certificate state leaf is not managed.',
            );
        }
        return (new ProjectCertificateGenerationStore())->withCertificateLifecycleLock(
            function () use ($directory, $leaf): bool {
                $path = \rtrim($directory, '/\\') . DS . $leaf;
                $mode = $leaf === 'chain.pem' ? 0644 : 0600;
                GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                    $path,
                    self::MAX_CERTIFICATE_MATERIAL_BYTES,
                    'optional certificate state',
                    function (string $candidate) use ($path, $mode): void {
                        $this->assertSslStateTargetMode(
                            $path,
                            $mode,
                            'optional certificate state',
                        );
                        $this->assertCertificateStateContents($path, $candidate);
                    },
                );
                return $this->removeCertificateLeafSafely($directory, $leaf);
            },
        );
    }

    /** @return array<string,int|string> */
    private function certificateRemovalLeafIdentity(string $path, array $status): array
    {
        $type = ((int)($status['mode'] ?? 0)) & 0170000;
        $identity = [];
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
            $identity[$field] = (int)($status[$field] ?? -1);
        }
        if ($type === 0100000) {
            $contents = self::readRegularFileNoFollow(
                $path,
                self::MAX_CERTIFICATE_MATERIAL_BYTES,
                true,
                false,
            );
            if (!\is_string($contents)) {
                throw new \RuntimeException(
                    'Certificate leaf could not be read without following links.',
                );
            }
            $identity['content_sha256'] = \hash('sha256', $contents);
        } elseif ($type === 0120000) {
            $target = @\readlink($path);
            if (!\is_string($target) || $target === '' || \str_contains($target, "\0")) {
                throw new \RuntimeException('Certificate symbolic-link identity is invalid.');
            }
            $identity['link_target_sha256'] = \hash('sha256', $target);
        } else {
            throw new \RuntimeException('Certificate leaf type is not removable.');
        }
        $after = @\lstat($path);
        if (!\is_array($after)
            || !$this->certificateRemovalStatusMatches($status, $after)
        ) {
            throw new \RuntimeException(
                'Certificate leaf changed while its deletion identity was captured.',
            );
        }
        return $identity;
    }

    private function certificateRemovalStatusMatches(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
            if ((int)($left[$field] ?? -1) !== (int)($right[$field] ?? -2)) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    private function boundedJsonFiles(string $directory, string $label): array
    {
        if (!\is_dir($directory)) {
            return [];
        }
        $files = [];
        foreach ($this->boundedDirectoryEntries(
            $directory,
            self::MAX_INSTANCE_JSON_FILES,
            $label,
        ) as $entry) {
            if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\.json\z/D', $entry) !== 1) {
                continue;
            }
            $path = \rtrim($directory, '/\\') . DS . $entry;
            if (self::readRegularFileNoFollow($path) === null) {
                throw new \RuntimeException($label . ' contains an unsafe JSON file.');
            }
            $files[] = $path;
        }
        return $files;
    }

    protected function certificateModel(): SslCertificate
    {
        return $this->certModel ??= ObjectManager::getInstance(SslCertificate::class);
    }

    /**
     * Remove SNI entries whose leaf certificate does not cover the map key or
     * whose private key is unrelated. This runs at worker startup/reload, not
     * on the handshake hot path.
     *
     * @param array<string,mixed> $map
     * @return array<string,array<string,mixed>>
     */
    public static function sanitizeSniCertificateMap(array $map): array
    {
        $sanitized = [];
        foreach ($map as $mappedName => $pair) {
            if (!\is_string($mappedName) || !\is_array($pair)) {
                continue;
            }
            $mappedName = self::normalizeSniName($mappedName, true);
            $cert = \trim((string)($pair['local_cert'] ?? ''));
            $key = \trim((string)($pair['local_pk'] ?? ''));
            if ($mappedName === '' || $cert === '' || $key === ''
                || !self::sniCertificatePairIsValid($cert, $key, $mappedName)
            ) {
                continue;
            }
            $sanitized[$mappedName] = \array_replace($pair, [
                'local_cert' => $cert,
                'local_pk' => $key,
            ]);
        }

        return $sanitized;
    }

    public static function sniCertificateCoversName(string $certificatePath, string $name): bool
    {
        $name = self::normalizeSniName($name, true);
        if ($name === '') {
            return false;
        }
        foreach (self::sniCertificateDnsNames($certificatePath) as $certificateName) {
            if (\str_starts_with($name, '*.')) {
                if ($certificateName === $name) {
                    return true;
                }
                continue;
            }
            if (self::sniHostnameMatchesPattern($name, $certificateName)) {
                return true;
            }
        }

        return false;
    }

    public static function sniCertificatePairIsValid(
        string $certificatePath,
        string $keyPath,
        string $name,
    ): bool {
        return $certificatePath !== ''
            && $keyPath !== ''
            && self::readRegularFileNoFollow($certificatePath) !== null
            && self::readPrivateKeyFileNoFollow($keyPath) !== null
            && self::sniCertificateCoversName($certificatePath, $name)
            && self::sniCertificateMatchesPrivateKey($certificatePath, $keyPath, $name);
    }

    public static function sniHostnameMatchesPattern(string $hostname, string $pattern): bool
    {
        $hostname = self::normalizeSniName($hostname, false);
        $pattern = self::normalizeSniName($pattern, true);
        if ($hostname === '' || $pattern === '') {
            return false;
        }
        if (!\str_starts_with($pattern, '*.')) {
            return \hash_equals($pattern, $hostname);
        }
        $root = \substr($pattern, 2);
        if ($root === '' || !\str_ends_with($hostname, '.' . $root)) {
            return false;
        }

        return \substr_count($hostname, '.') === \substr_count($root, '.') + 1;
    }

    /**
     * Select from an already sanitized map without parsing certificates during
     * a TLS handshake.
     *
     * @param array<string,array<string,mixed>> $map
     * @return array<string,mixed>
     */
    public static function selectSniCertificatePair(
        ?string $sniHost,
        array $map,
        string $defaultCert,
        string $defaultKey,
    ): array {
        $fallback = ['local_cert' => $defaultCert, 'local_pk' => $defaultKey];
        $hostname = self::normalizeSniName((string)$sniHost, false);
        if ($hostname === '') {
            return $fallback;
        }
        $exact = $map[$hostname] ?? null;
        if (self::usableSniPair($exact)) {
            return $exact;
        }
        foreach ($map as $mappedName => $pair) {
            if (!\is_string($mappedName) || !\str_starts_with($mappedName, '*.')) {
                continue;
            }
            if (self::sniHostnameMatchesPattern($hostname, $mappedName) && self::usableSniPair($pair)) {
                return $pair;
            }
        }

        return $fallback;
    }

    /** @return list<string> */
    private static function sniCertificateDnsNames(string $certificatePath): array
    {
        static $cache = [];

        $realPath = \realpath($certificatePath);
        $pem = self::readRegularFileNoFollow($certificatePath);
        if (!\is_string($realPath) || $pem === null) {
            return [];
        }
        if ($pem === '' || !\preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $match)) {
            return [];
        }
        $cacheKey = $realPath . ':' . \hash('sha256', $match[0]);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        $certificate = @\openssl_x509_read($match[0]);
        $parsed = $certificate !== false ? @\openssl_x509_parse($certificate, false) : false;
        if (!\is_array($parsed)) {
            return $cache[$cacheKey] = [];
        }
        $names = [];
        $subjectAltName = $parsed['extensions']['subjectAltName'] ?? '';
        if (\is_string($subjectAltName)) {
            foreach (\preg_split('/,\s*/', $subjectAltName) ?: [] as $segment) {
                if (\str_starts_with($segment, 'DNS:')) {
                    $normalized = self::normalizeSniName(\substr($segment, 4), true);
                    if ($normalized !== '') {
                        $names[] = $normalized;
                    }
                }
            }
        }
        if ($names === []) {
            $commonName = $parsed['subject']['CN'] ?? '';
            if (\is_string($commonName)) {
                $normalized = self::normalizeSniName($commonName, true);
                if ($normalized !== '') {
                    $names[] = $normalized;
                }
            }
        }

        return $cache[$cacheKey] = \array_values(\array_unique($names));
    }

    private static function sniCertificateMatchesPrivateKey(
        string $certificatePath,
        string $keyPath,
        string $name,
    ): bool {
        $certificatePem = self::readRegularFileNoFollow($certificatePath);
        $privateKeyPem = self::readPrivateKeyFileNoFollow($keyPath);
        if ($certificatePem === null || $privateKeyPem === null) {
            return false;
        }
        return self::certificatePemPairIsValidForName(
            $certificatePem,
            $privateKeyPem,
            $name,
        );
    }

    private static function certificatePemPairIsValidForName(
        string $certificatePem,
        string $privateKeyPem,
        string $name,
    ): bool {
        if (\strlen($privateKeyPem) > self::MAX_CERTIFICATE_MATERIAL_BYTES
            || !self::certificatePemIsValidForName($certificatePem, $name)
        ) {
            return false;
        }
        if (!\preg_match(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            $certificatePem,
            $leafMatch,
        )) {
            return false;
        }
        $leafPem = (string)$leafMatch[0];
        $certificate = @\openssl_x509_read($leafPem);
        $privateKey = @\openssl_pkey_get_private($privateKeyPem);
        if ($certificate === false || $privateKey === false) {
            return false;
        }
        if (!@\openssl_x509_check_private_key($certificate, $privateKey)) {
            return false;
        }
        return true;
    }

    private static function certificatePemIsValidForName(
        string $certificatePem,
        string $name,
    ): bool {
        if (\strlen($certificatePem) > self::MAX_CERTIFICATE_MATERIAL_BYTES
            || !self::certificateBundlePemIsValid($certificatePem, false)
            || !\preg_match(
                '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
                $certificatePem,
                $leafMatch,
            )
        ) {
            return false;
        }
        $certificate = @\openssl_x509_read((string)$leafMatch[0]);
        if ($certificate === false) {
            return false;
        }
        if ($name === '') {
            return true;
        }
        $parsed = @\openssl_x509_parse($certificate, false);
        $now = \time();
        if (!\is_array($parsed)
            || (int)($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > $now
            || (int)($parsed['validTo_time_t'] ?? 0) <= $now
        ) {
            return false;
        }
        $requestedIp = \filter_var($name, FILTER_VALIDATE_IP) !== false
            ? @\inet_pton($name)
            : false;
        $requestedDns = $requestedIp === false ? self::normalizeSniName($name, true) : '';
        if ($requestedIp === false && $requestedDns === '') {
            return false;
        }
        $subjectAltName = \trim((string)($parsed['extensions']['subjectAltName'] ?? ''));
        if ($subjectAltName !== '') {
            foreach (\preg_split('/,\s*/', $subjectAltName) ?: [] as $entry) {
                if ($requestedIp !== false
                    && \preg_match('/\AIP(?: Address)?:\s*(.+)\z/i', $entry, $match) === 1
                ) {
                    $candidate = @\inet_pton(\trim((string)$match[1]));
                    if (\is_string($candidate) && \hash_equals($requestedIp, $candidate)) {
                        return true;
                    }
                    continue;
                }
                if ($requestedDns === '' || !\str_starts_with(\strtoupper($entry), 'DNS:')) {
                    continue;
                }
                $pattern = self::normalizeSniName(\substr($entry, 4), true);
                if ($pattern === '') {
                    continue;
                }
                if (\str_starts_with($requestedDns, '*.')) {
                    if (\hash_equals($requestedDns, $pattern)) {
                        return true;
                    }
                } elseif (self::sniHostnameMatchesPattern($requestedDns, $pattern)) {
                    return true;
                }
            }
            return false;
        }
        if ($requestedDns === '') {
            return false;
        }
        $commonName = $parsed['subject']['CN'] ?? '';
        $pattern = \is_string($commonName) ? self::normalizeSniName($commonName, true) : '';
        if ($pattern === '') {
            return false;
        }
        return \str_starts_with($requestedDns, '*.')
            ? \hash_equals($requestedDns, $pattern)
            : self::sniHostnameMatchesPattern($requestedDns, $pattern);
    }

    private static function certificateBundlePemIsValid(
        string $certificatePem,
        bool $allowEmpty,
    ): bool {
        if ($certificatePem === '') {
            return $allowEmpty;
        }
        if (\strlen($certificatePem) > self::MAX_CERTIFICATE_MATERIAL_BYTES) {
            return false;
        }
        $pattern = '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s';
        $count = \preg_match_all($pattern, $certificatePem, $matches);
        if (!\is_int($count) || $count < 1 || $count > 16) {
            return false;
        }
        $residual = \preg_replace($pattern, '', $certificatePem);
        if (!\is_string($residual) || \trim($residual) !== '') {
            return false;
        }
        foreach ((array)($matches[0] ?? []) as $pem) {
            if (!\is_string($pem) || @\openssl_x509_read($pem) === false) {
                return false;
            }
        }
        return true;
    }

    private static function certificateBundleFileIsValid(
        string $path,
        bool $allowEmpty = false,
    ): bool {
        $pem = self::readRegularFileNoFollow(
            $path,
            self::MAX_CERTIFICATE_MATERIAL_BYTES,
            $allowEmpty,
        );
        return $pem !== null && self::certificateBundlePemIsValid($pem, $allowEmpty);
    }

    private static function certificateFilePairIsValidForName(
        string $certificatePath,
        string $privateKeyPath,
        string $name,
    ): bool {
        $certificatePem = self::readRegularFileNoFollow($certificatePath);
        $privateKeyPem = self::readPrivateKeyFileNoFollow($privateKeyPath);
        return $certificatePem !== null
            && $privateKeyPem !== null
            && self::certificatePemPairIsValidForName(
                $certificatePem,
                $privateKeyPem,
                $name,
            );
    }

    private static function normalizeSniName(string $name, bool $allowWildcard): string
    {
        $name = \strtolower(\rtrim(\trim($name), '.'));
        if ($name === '' || \filter_var($name, \FILTER_VALIDATE_IP)) {
            return '';
        }
        $prefix = '';
        if (\str_starts_with($name, '*.')) {
            if (!$allowWildcard) {
                return '';
            }
            $prefix = '*.';
            $name = \substr($name, 2);
        }
        if ($name === '' || \strlen($name) > 253
            || !\preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $name)
        ) {
            return '';
        }

        return $prefix . $name;
    }

    private static function usableSniPair(mixed $pair): bool
    {
        return \is_array($pair)
            && \trim((string)($pair['local_cert'] ?? '')) !== ''
            && \trim((string)($pair['local_pk'] ?? '')) !== '';
    }

    /**
     * WLS 首次启动可能早于 setup:upgrade 触达 Server 模块表。
     * SSL 证书准备是 server:start 的前置链路，必须能在缺表时用声明式 schema
     * 原子创建本模块证书表；已有表则零写入，避免影响正常升级和已有证书。
     */
    public function ensureCertificateStorageReady(): void
    {
        if (self::$certificateStorageReady) {
            return;
        }
        if (self::$certificateStorageCorruptionReason !== null) {
            throw new \RuntimeException((string) __(
                'WLS SSL 证书数据库已在本次启动中熔断：%{1}',
                [self::$certificateStorageCorruptionReason]
            ));
        }

        $connector = null;
        $tableName = '';
        $isDefinitiveCorruption = static function (\Throwable $error): bool {
            return \preg_match(
                '/database disk image is malformed|file is not a database|not a database|SQLITE_CORRUPT|SQLITE_NOTADB/i',
                $error->getMessage()
            ) === 1;
        };

        try {
            $certificateModel = $this->certificateModel();
            $connector = $certificateModel->getConnection()->getConnector();
            $tableName = $certificateModel->getTable();
            if ($connector->tableExist($tableName)) {
                self::$certificateStorageReady = true;
                return;
            }

            $schema = ObjectManager::getInstance(SchemaParser::class)->parse(SslCertificate::class);
            if ($schema === null) {
                throw new \RuntimeException('Unable to build schema for ' . SslCertificate::class . ' during WLS SSL bootstrap.');
            }

            ObjectManager::getInstance(SchemaMigrationExecutor::class)
                ->createBootstrapTable($connector, $schema);
            self::$certificateStorageReady = true;
            w_log_info('[SslCertificateService] SSL certificate storage table created during WLS startup bootstrap: ' . $tableName);
        } catch (\Throwable $error) {
            $failure = $error;
            if (!$isDefinitiveCorruption($failure) && $connector !== null && $tableName !== '') {
                try {
                    if ($connector->tableExist($tableName)) {
                        self::$certificateStorageReady = true;
                        return;
                    }
                } catch (\Throwable $probeError) {
                    $failure = $probeError;
                }
            }

            if ($isDefinitiveCorruption($failure)) {
                self::$certificateStorageCorruptionReason = \trim($failure->getMessage());
                w_log_error((string) __(
                    '[SslCertificateService] SQLite 证书存储已损坏，本次启动已熔断后续探测和写入：%{1}',
                    [self::$certificateStorageCorruptionReason]
                ));
                throw new \RuntimeException((string) __(
                    'WLS SSL 证书数据库已损坏，无法安全生成或恢复证书：%{1}',
                    [self::$certificateStorageCorruptionReason]
                ), 0, $failure);
            }

            w_log_error('[SslCertificateService] SSL certificate storage bootstrap failed: ' . $failure->getMessage());
            throw $failure;
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
        \Weline\Framework\Runtime\SchedulerSystem::sleep($seconds);
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
            'local-ca', 'local_ca', 'localca', 'dev-ca', 'dev_ca' => self::PROVIDER_LOCAL_CA,
            default => $provider,
        };
    }
    
    /**
     * 判断是否支持的 ACME 提供商
     */
    protected function isSupportedProvider(string $provider): bool
    {
        return \in_array($provider, [
            self::PROVIDER_LETS_ENCRYPT,
            self::PROVIDER_LITESSL,
            self::PROVIDER_SELF_SIGNED,
            self::PROVIDER_LOCAL_CA,
        ], true);
    }

    protected function isLocalManagedProvider(string $provider): bool
    {
        $provider = $this->normalizeAcmeProvider($provider);

        return \in_array($provider, [self::PROVIDER_SELF_SIGNED, self::PROVIDER_LOCAL_CA], true);
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
        
        // 与 isLocalDomain 对齐：*.test / *.local 等开发后缀不走阻塞式 DNS。
        // 否则 Windows 上 gethostbynamel 可能因上游解析超时卡住很久，拖死 server:start 的 SSL 准备阶段。
        if ($this->isLocalDomain($domain)) {
            return $this->loopbackResolveCache[$domain] = true;
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
        return (new ProjectCertificateGenerationStore())->withCertificateLifecycleLock(
            fn (): ?array => $this->applyWildcardToSubdomainIfExistsLocked(
                $domain,
                $websiteId,
            ),
        );
    }

    private function applyWildcardToSubdomainIfExistsLocked(
        string $domain,
        int $websiteId = 0,
    ): ?array
    {
        try {
            $domain = self::normalizeCertificateStorageDomain($domain);
        } catch (\Throwable) {
            return null;
        }
        $this->assertCertificateMutationNotBlockedByRetirement($domain);
        if (\str_starts_with($domain, '*.')) {
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

        $isLocalCaWildcard = $this->shouldUseTrustedLocalCertificateAuthority($domain)
            && $this->normalizeAcmeProvider((string) $wildcardCert->getProvider()) === self::PROVIDER_LOCAL_CA;
        if ($this->shouldUseTrustedLocalCertificateAuthority($domain) && !$isLocalCaWildcard) {
            return null;
        }

        $certPem  = $wildcardCert->getCertPem();
        $keyPem   = $wildcardCert->getKeyPem();
        if ($certPem === ''
            || $keyPem === ''
            || \strlen($certPem) > self::MAX_CERTIFICATE_MATERIAL_BYTES
            || \strlen($keyPem) > self::MAX_CERTIFICATE_MATERIAL_BYTES
            || !self::certificatePemPairIsValidForName($certPem, $keyPem, $domain)
        ) {
            return null;
        }

        $now = \date('Y-m-d H:i:s');
        $expiresAt = $wildcardCert->getExpiresAt();
        if ($expiresAt !== '' && \strtotime($expiresAt) < \time()) {
            w_log_info(__('[SslCertificateService] 泛域名 *.%{1} 已过期，子域 %{2} 不使用泛域证书', [$rootDomain, $domain]));
            return null;
        }

        if ($isLocalCaWildcard) {
            $this->restoreCertificateFilesFromData($wildcardCert->getData());
            $wildcardCertPath = $this->getCertificateDir((string) $wildcardCert->getDomain()) . 'fullchain.pem';
            if (!$this->localCaCertificateCoversRequiredSan($domain, $wildcardCertPath)) {
                return null;
            }
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
        $subCert->setWebsiteId($websiteId);

        $stagedData = $subCert->getData();
        $stagedData[SslCertificate::schema_fields_ID] = 0;
        if (!$this->restoreCertificateFilesFromData($stagedData)) {
            return null;
        }

        $certDir = $this->getCertificateDir($domain);
        $subCert->setCertPath($certDir . 'fullchain.pem')
            ->setKeyPath($certDir . 'privkey.pem')
            ->setChainPath(\trim((string)$subCert->getChainPem()) !== ''
                ? $certDir . 'chain.pem'
                : '')
            ->save();
        w_log_info(__(
            '[SslCertificateService] 子域名 %{1} 已被泛域名 *.%{2} 覆盖，直接写入泛域证书，跳过 ACME 申请',
            [$domain, $rootDomain]
        ));

        $this->regenerateCertificateMap();

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
    /**
     * 快速探测：本地是否已经存在可直接复用的证书文件（不触发任何签发）。
     *
     * 仅用于"是否要给用户打印『正在准备 SSL 证书...』"这类 UX 判定，
     * 真正的复用校验仍由 {@see ensureCertificate()} 自身完成。
     */
    public function hasValidLocalCertificate(string $domain): bool
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '0.0.0.0') {
            $domain = 'localhost';
        }
        if ($domain === '') {
            return false;
        }

        $certDir = $this->getCertificateDir($domain);
        $certPath = $certDir . 'fullchain.pem';
        $keyPath = $certDir . 'privkey.pem';

        if (!$this->canReuseCertificateForDomain($certPath, $keyPath, $domain)) {
            return false;
        }

        // 若属于本地 CA 签发场景，但既有 fullchain 无法提取 CA 证书（老旧 bundle/自签漂移），
        // ensureCertificate 会主动重签——此时不能宣称"已有证书可复用"。
        if ($this->shouldUseTrustedLocalCertificateAuthority($domain)
            && !$this->prepareExistingLocalCaCertificateForReuse($certPath)) {
            return false;
        }

        return true;
    }

    /**
     * 统一启动探针与实际复用分支的密钥、有效期和域名覆盖校验。
     */
    protected function canReuseCertificateForDomain(
        string $certPath,
        string $keyPath,
        string $domain,
    ): bool {
        return $this->canReuseConfiguredCertificate($certPath, $keyPath)
            && $this->certificateMatchesHost($certPath, $domain);
    }

    public function ensureCertificate(string $domain, string $webroot = '', string $email = '', int $websiteId = 0): array
    {
        // 0.0.0.0 是"监听所有网卡"的绑定地址，不是真实域名，归一化为 localhost
        if ($domain === '0.0.0.0') {
            $domain = 'localhost';
        }

        // 0. 若后台已禁用该域名的 HTTPS，直接返回「不使用 SSL」
        $cert = $this->certificateModel()->clearQuery()->loadByDomain($domain);
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

        if ($this->shouldUseTrustedLocalCertificateAuthority($domain)
            && self::readRegularFileNoFollow($certPath) !== null
            && self::readPrivateKeyFileNoFollow($keyPath) !== null
            && !$this->prepareExistingLocalCaCertificateForReuse($certPath)) {
            $localCaResult = $this->generateLocalCaSignedCertificate($domain, $websiteId);
            if ($localCaResult['success'] ?? false) {
                return $localCaResult;
            }
        }
        
        // 1. 仅复用未过期、密钥配对且覆盖当前域名的证书。
        if ($this->canReuseCertificateForDomain($certPath, $keyPath, $domain)) {
            // 启动复用路径只做入库/映射，不重复触发「从 bundle 恢复 CA + Windows 信任探测」，
            // 避免 certutil 慢调用把“使用已有证书”也拖成十几秒。
            $this->syncCertificateRecordFromFiles($domain, $certPath, $keyPath, $websiteId, true, '', false);
            $this->regenerateCertificateMap();
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
        $useSelfsigned = $this->shouldUseTrustedLocalCertificateAuthority($domain);
        
        if ($useSelfsigned) {
            // 开发环境、本地域名、或解析到本地地址：生成自签证书
            $localCaResult = $this->generateLocalCaSignedCertificate($domain, $websiteId);
            if ($localCaResult['success'] ?? false) {
                return $localCaResult;
            }

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
        
        // 检查尚未生效或已进入续签窗口的证书。
        $now = \time();
        $validFrom = (int)($cert['validFrom_time_t'] ?? PHP_INT_MAX);
        $expiresAt = $cert['validTo_time_t'] ?? 0;
        return $validFrom <= $now && $expiresAt > ($now + 7 * 24 * 3600);
    }

    /**
     * 检查已配置证书是否可被复用。
     *
     * 校验规则：
     * - 证书文件存在且未过期（保留 7 天续期窗口）
     * - 可选 key 文件存在且为有效私钥
     * - 私钥与证书公钥匹配
     */
    public function canReuseConfiguredCertificate(string $certPath, string $keyPath = ''): bool
    {
        $certPath = \trim($certPath);
        if ($certPath === '' || self::readRegularFileNoFollow($certPath) === null) {
            return false;
        }

        if (!$this->isCertificateValid($certPath)) {
            return false;
        }

        if ($keyPath === '') {
            return true;
        }

        $keyPath = \trim($keyPath);
        if ($keyPath === '') {
            return false;
        }

        $privateKeyPem = self::readPrivateKeyFileNoFollow($keyPath);
        if ($privateKeyPem === null) {
            return false;
        }

        $privateKey = @\openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            return false;
        }

        $certPem = self::readRegularFileNoFollow($certPath);
        if ($certPem === null) {
            return false;
        }

        $certResource = @\openssl_x509_read($certPem);
        if ($certResource === false) {
            return false;
        }

        $publicFromCert = @\openssl_pkey_get_public($certResource);
        if ($publicFromCert === false) {
            return false;
        }

        $privateDetails = @\openssl_pkey_get_details($privateKey);
        $certPublicDetails = @\openssl_pkey_get_details($publicFromCert);
        if (!\is_array($privateDetails) || !\is_array($certPublicDetails)) {
            return false;
        }

        if ((int) ($privateDetails['type'] ?? -1) !== (int) ($certPublicDetails['type'] ?? -1)) {
            return false;
        }

        $privatePublicKey = (string) ($privateDetails['key'] ?? '');
        $certPublicKey = (string) ($certPublicDetails['key'] ?? '');

        return $privatePublicKey !== '' && $privatePublicKey === $certPublicKey;
    }

    /**
     * 证书管理器视角：该主机名是否已有 **ACTIVE** 且覆盖该名的证书，且 PEM 或磁盘文件存在、未进入续期窗口（与 {@see isCertificateValid} 同为提前 7 天）。
     * 供域名池维护入队 / 健康扫描使用，**不**依赖域名池上的 https_status 字段。
     */
    public function isManagedCertificateHealthyForHostname(string $hostname): bool
    {
        $hostname = \strtolower(\trim($hostname));
        if ($hostname === '') {
            return false;
        }
        $certProbe = ObjectManager::getInstance(SslCertificate::class, [], false);
        $cert = $certProbe->findCertificateForDomain($hostname);
        if ($cert === null || (int) $cert->getCertId() <= 0) {
            return false;
        }
        if ($cert->getStatus() !== SslCertificate::STATUS_ACTIVE) {
            return false;
        }
        if (!$cert->coversHostname($hostname)) {
            return false;
        }
        $certPem = $cert->getCertPem();
        $keyPem = $cert->getKeyPem();
        if ($certPem !== '' && $keyPem !== '') {
            return $this->isPemCertificateValidWithRenewalMargin($certPem);
        }
        $certPath = $cert->getCertPath();
        $keyPath = $cert->getKeyPath();
        if ($certPath === ''
            || $keyPath === ''
            || self::readRegularFileNoFollow($certPath) === null
            || self::readPrivateKeyFileNoFollow($keyPath) === null
        ) {
            return false;
        }
        if (!$this->certificateMatchesHost($certPath, $hostname)) {
            return false;
        }

        return $this->isCertificateValid($certPath);
    }

    /** @param non-empty-string $certPem PEM 证书链（取第一张叶子即可） */
    private function isPemCertificateValidWithRenewalMargin(string $certPem): bool
    {
        $certPem = \trim($certPem);
        if ($certPem === '') {
            return false;
        }
        $margin = 7 * 24 * 3600;
        $first = $certPem;
        if (\preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $certPem, $m)) {
            $first = $m[0];
        }
        $res = @\openssl_x509_read($first);
        if ($res === false) {
            return false;
        }
        $parsed = @\openssl_x509_parse($res, false);
        if (!\is_array($parsed)) {
            return false;
        }
        $to = (int) ($parsed['validTo_time_t'] ?? 0);

        return $to > (\time() + $margin);
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
                $path = \is_string($path) ? \trim($path) : '';
                if ($path !== ''
                    && self::readRegularFileNoFollow(
                        $path,
                        self::MAX_CERTIFICATE_MATERIAL_BYTES,
                        true,
                    ) !== null
                ) {
                    $config['config'] = (string)\realpath($path);
                    break;
                }
            }
            
            // 如果找不到配置文件，创建一个最小配置
            if (!isset($config['config'])) {
                $tempConfig = $this->certBaseDir . 'openssl.cnf';
                if (self::readRegularFileNoFollow(
                    $tempConfig,
                    self::MAX_CERTIFICATE_MATERIAL_BYTES,
                    false,
                    true,
                ) === null) {
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
                    try {
                        $this->writeCertificateFileAtomically($tempConfig, $minimalConfig, 0600);
                    } catch (\Throwable) {
                        // The caller will continue without an explicit config
                        // and OpenSSL will report its own bounded error.
                    }
                }
                if (self::readRegularFileNoFollow(
                    $tempConfig,
                    self::MAX_CERTIFICATE_MATERIAL_BYTES,
                    false,
                    true,
                ) !== null) {
                    $config['config'] = $tempConfig;
                }
            }
        }
        
        return $config;
    }

    protected function shouldUseTrustedLocalCertificateAuthority(string $domain): bool
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return false;
        }

        return $this->isLocalDomain($domain) || $this->resolvesToLoopback($domain);
    }

    protected function getLocalCaDir(): string
    {
        $dir = Env::VAR_DIR . 'server' . DS . self::LOCAL_CA_DIRNAME . DS;
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0700, true);
        }
        $trimmed = \rtrim($dir, '/\\');
        $status = @\lstat($trimmed);
        $real = \realpath($trimmed);
        if (!\is_array($status)
            || \is_link($trimmed)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            || !\is_string($real)
            || !self::sameFilesystemPath($trimmed, $real)
            || self::filesystemPathIsRoot($real)
            || (\PHP_OS_FAMILY !== 'Windows'
                && (!@\chmod($real, 0700)
                    || (((int)(@\fileperms($real) ?: 0)) & 0777) !== 0700))
        ) {
            throw new \RuntimeException('Project local CA directory is unsafe.');
        }
        return \rtrim($real, '/\\') . DS;
    }

    protected function getLocalCaCertPath(): string
    {
        return $this->getLocalCaDir() . self::LOCAL_CA_CERT_FILENAME;
    }

    protected function getLocalCaKeyPath(): string
    {
        return $this->getLocalCaDir() . self::LOCAL_CA_KEY_FILENAME;
    }

    protected function getLocalCaSerialPath(): string
    {
        return $this->getLocalCaDir() . self::LOCAL_CA_SERIAL_FILENAME;
    }

    protected function getGlobalLocalCaDir(bool $create = true): string
    {
        $configured = '';
        try {
            $value = Env::get('wls.ssl.local_ca_dir');
            if (\is_string($value)) {
                $configured = \trim($value);
            }
        } catch (\Throwable) {
            $configured = '';
        }

        if ($configured !== '') {
            $dir = \rtrim($configured, "\\/") . DS;
        } else {
            $baseDir = $this->resolveUserStateBaseDir();
            if ($baseDir === '') {
                return '';
            }
            $dir = \rtrim($baseDir, "\\/") . DS
                . self::GLOBAL_LOCAL_CA_VENDOR_DIR . DS
                . self::GLOBAL_LOCAL_CA_APP_DIR . DS
                . self::LOCAL_CA_DIRNAME . DS;
        }

        if ($create && !\is_dir($dir)) {
            @\mkdir($dir, 0700, true);
        }
        if (!\is_dir($dir)) {
            return $create ? '' : $dir;
        }
        $trimmed = \rtrim($dir, '/\\');
        $status = @\lstat($trimmed);
        $real = \realpath($trimmed);
        if (!\is_array($status)
            || \is_link($trimmed)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            || !\is_string($real)
            || !self::sameFilesystemPath($trimmed, $real)
            || self::filesystemPathIsRoot($real)
            || (\PHP_OS_FAMILY !== 'Windows'
                && (!@\chmod($real, 0700)
                    || (((int)(@\fileperms($real) ?: 0)) & 0777) !== 0700))
        ) {
            return '';
        }
        return \rtrim($real, '/\\') . DS;
    }

    protected function resolveUserStateBaseDir(): string
    {
        $candidates = [];
        if ($this->getOsFamily() === 'Windows') {
            $candidates = [
                (string) \getenv('LOCALAPPDATA'),
                (string) \getenv('APPDATA'),
                (string) \getenv('USERPROFILE'),
            ];
        } else {
            $home = (string) \getenv('HOME');
            $candidates = [
                (string) \getenv('XDG_STATE_HOME'),
                $home !== '' ? $home . DS . '.local' . DS . 'state' : '',
                $home,
            ];
        }

        foreach ($candidates as $candidate) {
            $candidate = \rtrim(\trim($candidate), '/\\');
            if ($candidate === '' || \str_contains($candidate, "\0")) {
                continue;
            }
            $status = @\lstat($candidate);
            $real = \realpath($candidate);
            if (\is_array($status)
                && ((((int)($status['mode'] ?? 0)) & 0170000) === 0040000)
                && !\is_link($candidate)
                && \is_string($real)
                && self::sameFilesystemPath($candidate, $real)
                && !self::filesystemPathIsRoot($real)
            ) {
                return $real;
            }
        }

        return '';
    }

    protected function getGlobalLocalCaCertPath(): string
    {
        $dir = $this->getGlobalLocalCaDir();

        return $dir !== '' ? $dir . self::LOCAL_CA_CERT_FILENAME : '';
    }

    protected function getGlobalLocalCaKeyPath(): string
    {
        $dir = $this->getGlobalLocalCaDir();

        return $dir !== '' ? $dir . self::LOCAL_CA_KEY_FILENAME : '';
    }

    protected function getGlobalLocalCaSerialPath(): string
    {
        $dir = $this->getGlobalLocalCaDir();

        return $dir !== '' ? $dir . self::LOCAL_CA_SERIAL_FILENAME : '';
    }

    protected function canUseLocalCertificateAuthorityPair(string $certPath, string $keyPath): bool
    {
        return self::readRegularFileNoFollow($certPath) !== null
            && self::readPrivateKeyFileNoFollow($keyPath) !== null
            && $this->isCertificateValid($certPath)
            && $this->isCertificateSelfSigned($certPath)
            && $this->isCertificateAuthority($certPath)
            && $this->canReuseConfiguredCertificate($certPath, $keyPath);
    }

    protected function certificateFilesHaveSameFingerprint(string $leftPath, string $rightPath): bool
    {
        $left = $this->getCertificateSha1Fingerprint($leftPath);
        $right = $this->getCertificateSha1Fingerprint($rightPath);

        return $left !== '' && $right !== '' && $left === $right;
    }

    protected function localAndGlobalCertificateAuthorityMatch(): bool
    {
        $localCertPath = $this->getLocalCaCertPath();
        $globalCertPath = $this->getGlobalLocalCaCertPath();
        if ($globalCertPath === ''
            || self::readRegularFileNoFollow($localCertPath) === null
            || self::readRegularFileNoFollow($globalCertPath) === null
        ) {
            return false;
        }

        return $this->certificateFilesHaveSameFingerprint($localCertPath, $globalCertPath);
    }

    protected function syncGlobalLocalCertificateAuthority(string $certPath, string $keyPath): bool
    {
        if (!$this->canUseLocalCertificateAuthorityPair($certPath, $keyPath)) {
            return false;
        }

        $globalCertPath = $this->getGlobalLocalCaCertPath();
        $globalKeyPath = $this->getGlobalLocalCaKeyPath();
        if ($globalCertPath === '' || $globalKeyPath === '') {
            return false;
        }

        return $this->withLocalCaStateDirectoryLock(
            \dirname($globalCertPath),
            fn (): bool => $this->syncGlobalLocalCertificateAuthorityLocked(
                $certPath,
                $keyPath,
                $globalCertPath,
                $globalKeyPath,
            ),
        );
    }

    private function syncGlobalLocalCertificateAuthorityLocked(
        string $certPath,
        string $keyPath,
        string $globalCertPath,
        string $globalKeyPath,
    ): bool {
        if ($this->canUseLocalCertificateAuthorityPair($globalCertPath, $globalKeyPath)) {
            return $this->certificateFilesHaveSameFingerprint($certPath, $globalCertPath);
        }

        $certPem = self::readRegularFileNoFollow($certPath);
        $keyPem = self::readPrivateKeyFileNoFollow($keyPath);
        try {
            if ($certPem === null || $keyPem === null) {
                throw new \RuntimeException('Local CA source changed before global publication.');
            }
            $this->writeLocalCaStateAtomically($globalCertPath, $certPem, 0644);
            $this->writeLocalCaStateAtomically($globalKeyPath, $keyPem, 0600);
        } catch (\Throwable) {
            w_log_warning(__('[SslCertificateService] Failed to persist reusable global local CA files to %{1}', [\dirname($globalCertPath)]), [], 'ssl_cert');
            return false;
        }

        $localSerialPath = $this->getLocalCaSerialPath();
        $globalSerialPath = $this->getGlobalLocalCaSerialPath();
        $serial = self::readRegularFileNoFollow($localSerialPath, 64, true, true);
        if ($globalSerialPath !== '' && \is_string($serial) && $serial !== '') {
            try {
                $this->writeLocalCaStateAtomically($globalSerialPath, $serial, 0600);
            } catch (\Throwable) {
                return false;
            }
        }
        if (!$this->canUseLocalCertificateAuthorityPair(
            $globalCertPath,
            $globalKeyPath,
        )
            || !$this->certificateFilesHaveSameFingerprint(
                $certPath,
                $globalCertPath,
            )
        ) {
            return false;
        }
        w_log_info(__('[SslCertificateService] Local CA is now reusable across projects from %{1}', [\dirname($globalCertPath)]));

        return true;
    }

    protected function restoreProjectLocalCertificateAuthorityFromGlobal(): ?array
    {
        $globalCertPath = $this->getGlobalLocalCaCertPath();
        $globalKeyPath = $this->getGlobalLocalCaKeyPath();
        if ($globalCertPath === '' || $globalKeyPath === '') {
            return null;
        }

        $certPath = $this->getLocalCaCertPath();
        $keyPath = $this->getLocalCaKeyPath();
        return $this->withLocalCaStateDirectoryLock(
            \dirname($globalCertPath),
            fn (): ?array => $this->withLocalCaStateDirectoryLock(
                \dirname($certPath),
                fn (): ?array => $this->restoreProjectLocalCertificateAuthorityFromGlobalLocked(
                    $globalCertPath,
                    $globalKeyPath,
                    $certPath,
                    $keyPath,
                ),
            ),
        );
    }

    private function restoreProjectLocalCertificateAuthorityFromGlobalLocked(
        string $globalCertPath,
        string $globalKeyPath,
        string $certPath,
        string $keyPath,
    ): ?array {
        if (!$this->canUseLocalCertificateAuthorityPair($globalCertPath, $globalKeyPath)) {
            return null;
        }

        $certPem = self::readRegularFileNoFollow($globalCertPath);
        $keyPem = self::readPrivateKeyFileNoFollow($globalKeyPath);
        try {
            if ($certPem === null || $keyPem === null) {
                throw new \RuntimeException('Global local CA source changed before restore.');
            }
            $this->writeLocalCaStateAtomically($certPath, $certPem, 0644);
            $this->writeLocalCaStateAtomically($keyPath, $keyPem, 0600);
        } catch (\Throwable) {
            w_log_warning(__('[SslCertificateService] Failed to restore project local CA files from global reusable CA'), [], 'ssl_cert');
            return null;
        }

        $globalSerialPath = $this->getGlobalLocalCaSerialPath();
        $serial = $globalSerialPath !== ''
            ? self::readRegularFileNoFollow($globalSerialPath, 64, true, true)
            : null;
        if (\is_string($serial) && $serial !== '') {
            try {
                $this->writeLocalCaStateAtomically(
                    $this->getLocalCaSerialPath(),
                    $serial,
                    0600,
                );
            } catch (\Throwable) {
                return null;
            }
        }
        if (!$this->canUseLocalCertificateAuthorityPair($certPath, $keyPath)
            || !$this->certificateFilesHaveSameFingerprint(
                $globalCertPath,
                $certPath,
            )
        ) {
            return null;
        }
        w_log_info(__('[SslCertificateService] Reusing global Weline local CA for this project'));

        return [
            'success' => true,
            'cert_path' => $certPath,
            'key_path' => $keyPath,
            'is_new' => false,
            'reused_global' => true,
        ];
    }

    protected function buildLocalCaOpenSslConfig(): string
    {
        return <<<CNF
[ req ]
default_bits = 2048
default_md = sha256
distinguished_name = dn
x509_extensions = v3_ca

[ dn ]
CN = Weline Local Development CA

[ v3_ca ]
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer
basicConstraints = critical, CA:true
keyUsage = critical, digitalSignature, cRLSign, keyCertSign
CNF;
    }

    protected function buildServerLeafOpenSslConfig(
        string $domain,
        array $sanEntries,
        string $caIssuersUri = '',
        string $crlDistributionUri = ''
    ): string
    {
        $altNames = [];
        $dnsIndex = 1;
        foreach ($sanEntries['dns'] as $dns) {
            $altNames[] = "DNS.{$dnsIndex} = {$dns}";
            $dnsIndex++;
        }

        $ipIndex = 1;
        foreach ($sanEntries['ip'] as $ipAddr) {
            $altNames[] = "IP.{$ipIndex} = {$ipAddr}";
            $ipIndex++;
        }

        $altNamesStr = \implode("\n", $altNames);
        $authorityInfoAccess = $caIssuersUri !== ''
            ? "\nauthorityInfoAccess = caIssuers;URI:{$caIssuersUri}"
            : '';
        $crlDistributionPoints = $crlDistributionUri !== ''
            ? "\ncrlDistributionPoints = URI:{$crlDistributionUri}"
            : '';

        return <<<CNF
[ req ]
default_bits = 2048
default_md = sha256
distinguished_name = dn
req_extensions = v3_req
x509_extensions = v3_leaf

[ dn ]
CN = {$domain}

[ v3_req ]
subjectAltName = @alt_names

[ v3_leaf ]
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer
basicConstraints = critical, CA:false
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names{$authorityInfoAccess}{$crlDistributionPoints}

[ alt_names ]
{$altNamesStr}
CNF;
    }

    protected function getOpensslConfigForLocalCaLeaf(string $domain): array
    {
        $opensslConfig = $this->getOpensslConfig();
        // 本地 CA 叶子证书改用 ECDSA P-256：
        //   Windows + PHP 某些构建下 `openssl_pkey_new(RSA 2048)` 每次 5~30s，
        //   多域名站点叠加会把 server:start 的 SSL 准备阶段拖到长时间"看起来卡住"。
        //   ECC P-256 同等安全强度下，密钥生成在毫秒级；本地/内网场景完全够用，
        //   且 CA 证书仍保留原 RSA 2048（不改信任链），仅叶子密钥加速。
        //   env.php: ['wls']['ssl']['local_leaf_key_type'] 允许改回 'rsa' 兜底。
        $leafKeyType = 'ec';
        try {
            $configured = Env::get('wls.ssl.local_leaf_key_type');
            if (\is_string($configured) && $configured !== '') {
                $leafKeyType = \strtolower($configured);
            }
        } catch (\Throwable) {
            // 读 env 失败不影响默认 ECC 路径
        }

        if ($leafKeyType === 'ec' && \defined('OPENSSL_KEYTYPE_EC')) {
            $opensslConfig['private_key_type'] = \OPENSSL_KEYTYPE_EC;
            $opensslConfig['curve_name'] = 'prime256v1';
            unset($opensslConfig['private_key_bits']);
        }

        $sanEntries = $this->collectSanEntries($domain);
        if (empty($sanEntries['dns']) && empty($sanEntries['ip'])) {
            return $opensslConfig;
        }

        $configHash = \md5('local-ca-leaf:' . $leafKeyType . ':' . $domain . \serialize($sanEntries));
        $configPath = $this->getLocalCaDir() . "openssl_leaf_{$configHash}.cnf";
        try {
            $this->writeLocalCaStateAtomically(
                $configPath,
                $this->buildServerLeafOpenSslConfig($domain, $sanEntries),
                0600,
            );
        } catch (\Throwable) {
            return $opensslConfig;
        }
        if (self::readRegularFileNoFollow(
            $configPath,
            self::MAX_CERTIFICATE_MATERIAL_BYTES,
            false,
            true,
        ) !== null) {
            $opensslConfig['config'] = $configPath;
        }

        return $opensslConfig;
    }

    protected function prepareExistingLocalCaCertificateForReuse(string $certPath): bool
    {
        $certPem = self::readRegularFileNoFollow($certPath);
        if ($certPem === null) {
            return false;
        }

        if (!$this->isCertificateValid($certPath)
            || $this->isCertificateSelfSigned($certPath)
            || $this->isCertificateAuthority($certPath)) {
            return false;
        }

        $chainPath = \dirname($certPath) . DS . 'chain.pem';
        $chainPem = self::readRegularFileNoFollow(
            $chainPath,
            self::MAX_CERTIFICATE_MATERIAL_BYTES,
            true,
        ) ?? '';

        if ($this->extractLocalCaPemFromCertificateBundle($certPem, $chainPem) === '') {
            return false;
        }

        $domain = self::logicalDomainFromStorageSegment(\basename(\dirname($certPath)));

        return $this->localCaCertificateCoversRequiredSan($domain, $certPath);
    }

    protected function localCaCertificateCoversRequiredSan(string $domain, string $certPath): bool
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '' || self::readRegularFileNoFollow($certPath) === null) {
            return false;
        }

        if (!$this->localCaCertificateIsSignedByCurrentAuthority($certPath)) {
            return false;
        }

        $cert = $this->getParsedCertificateRaw($certPath);
        if (!$cert) {
            return false;
        }

        $requiredSan = $this->collectSanEntries($domain);
        $actualSan = $this->extractCertificateSubjectAltNames((string) ($cert['extensions']['subjectAltName'] ?? ''));

        foreach ($requiredSan['dns'] as $dnsName) {
            if (!$this->certificateMatchesHost($certPath, $dnsName)) {
                return false;
            }
        }

        $actualIpSan = \array_map([$this, 'normalizeIpAddressForComparison'], $actualSan['ip']);
        foreach ($requiredSan['ip'] as $ipAddr) {
            if (!\in_array($this->normalizeIpAddressForComparison($ipAddr), $actualIpSan, true)) {
                return false;
            }
        }

        return true;
    }

    protected function localCaCertificateIsSignedByCurrentAuthority(string $certPath): bool
    {
        $caPath = $this->getLocalCaCertPath();
        if (self::readRegularFileNoFollow($caPath) === null
            || self::readRegularFileNoFollow($certPath) === null
        ) {
            return false;
        }

        $leafPath = \dirname($certPath) . DS . 'cert.pem';
        if (self::readRegularFileNoFollow($leafPath) === null) {
            $leafPath = $certPath;
        }

        if (\function_exists('openssl_x509_verify')) {
            $leafPem = self::readRegularFileNoFollow($leafPath);
            $caPem = self::readRegularFileNoFollow($caPath);
            if ($leafPem === null || $caPem === null) {
                return false;
            }

            $caPublicKey = @\openssl_pkey_get_public($caPem);
            if ($caPublicKey !== false && @\openssl_x509_verify($leafPem, $caPublicKey) === 1) {
                return true;
            }
        }

        return $this->isLocalDevelopmentSslChainCryptographicallyValid($caPath, $leafPath);
    }

    protected function normalizeIpAddressForComparison(string $ip): string
    {
        $ip = \strtolower(\trim($ip));
        if ($ip === '') {
            return '';
        }

        $packed = @\inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }

        $normalized = @\inet_ntop($packed);

        return \is_string($normalized) && $normalized !== '' ? \strtolower($normalized) : $ip;
    }

    protected function ensureLocalCertificateAuthority(): array
    {
        return $this->withLocalCaStateDirectoryLock(
            $this->getLocalCaDir(),
            fn (): array => $this->ensureLocalCertificateAuthorityLocked(),
        );
    }

    private function ensureLocalCertificateAuthorityLocked(): array
    {
        $certPath = $this->getLocalCaCertPath();
        $keyPath = $this->getLocalCaKeyPath();
        if ($this->canUseLocalCertificateAuthorityPair($certPath, $keyPath)) {
            $this->syncGlobalLocalCertificateAuthority($certPath, $keyPath);
            return [
                'success' => true,
                'cert_path' => $certPath,
                'key_path' => $keyPath,
                'is_new' => false,
            ];
        }

        $globalCa = $this->restoreProjectLocalCertificateAuthorityFromGlobal();
        if ($globalCa !== null) {
            return $globalCa;
        }

        if ($this->hasLocalCertificateAuthorityInSystemStore()) {
            w_log_warning(__('[SslCertificateService] 系统信任库已有 Weline Local Development CA，但没有可复用私钥，将生成新的本地 CA；如需跨项目复用，请配置 wls.ssl.local_ca_dir 或复制 rootCA.key'), [], 'ssl_cert');
        }

        // 首次生成 CA（或既有 CA 失效）时 RSA 2048 可能很慢：
        // 向日志明确打点，避免运维看到"准备 SSL 证书..."长时间无输出以为死循环。
        $caGenStart = \hrtime(true);
        w_log_info('[SslCertificateService] local CA not ready, generating new CA (RSA 2048) ...');

        $opensslConfig = $this->getOpensslConfig();
        $configPath = $this->getLocalCaDir() . 'openssl_local_ca.cnf';
        try {
            $this->writeLocalCaStateAtomically(
                $configPath,
                $this->buildLocalCaOpenSslConfig(),
                0600,
            );
        } catch (\Throwable) {
            // OpenSSL can still use its runtime default configuration.
        }
        if (self::readRegularFileNoFollow(
            $configPath,
            self::MAX_CERTIFICATE_MATERIAL_BYTES,
            false,
            true,
        ) !== null) {
            $opensslConfig['config'] = $configPath;
        }

        $privateKey = \openssl_pkey_new($opensslConfig);
        if (!$privateKey) {
            return ['success' => false, 'message' => __('Failed to generate local CA private key')];
        }

        $dn = [
            'countryName' => 'CN',
            'stateOrProvinceName' => 'Development',
            'localityName' => 'Local',
            'organizationName' => 'Weline Framework',
            'organizationalUnitName' => 'Development',
            'commonName' => self::ISSUER_LOCAL_CA,
            'emailAddress' => 'dev@weline.localhost',
        ];

        $csr = \openssl_csr_new($dn, $privateKey, $opensslConfig);
        if (!$csr) {
            return ['success' => false, 'message' => __('Failed to generate local CA CSR')];
        }

        $cert = \openssl_csr_sign($csr, null, $privateKey, 3650, $opensslConfig, 1);
        if (!$cert) {
            return ['success' => false, 'message' => __('Failed to sign local CA certificate')];
        }

        \openssl_x509_export($cert, $certPem);
        if (!\openssl_pkey_export($privateKey, $keyPem, null, isset($opensslConfig['config']) ? ['config' => $opensslConfig['config']] : [])) {
            return ['success' => false, 'message' => __('Failed to export local CA private key')];
        }

        try {
            if (!$certPem || !$keyPem) {
                throw new \RuntimeException('OpenSSL returned empty local CA material.');
            }
            $this->writeLocalCaStateAtomically($certPath, $certPem, 0644);
            $this->writeLocalCaStateAtomically($keyPath, $keyPem, 0600);
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => __('Failed to persist local CA material: %{1}', [
                    $throwable->getMessage(),
                ]),
            ];
        }
        if (!$this->canUseLocalCertificateAuthorityPair($certPath, $keyPath)) {
            return [
                'success' => false,
                'message' => __('Published local CA certificate and key did not verify'),
            ];
        }
        $this->syncGlobalLocalCertificateAuthority($certPath, $keyPath);

        $caMs = (int) \round((\hrtime(true) - $caGenStart) / 1_000_000.0);
        w_log_info(\sprintf('[SslCertificateService] local CA generated in %dms', $caMs));

        return [
            'success' => true,
            'cert_path' => $certPath,
            'key_path' => $keyPath,
            'is_new' => true,
        ];
    }

    protected function normalizeCertificateFingerprint(string $value): string
    {
        return \strtoupper((string) \preg_replace('/[^A-F0-9]/i', '', $value));
    }

    protected function getOsFamily(): string
    {
        return \PHP_OS_FAMILY;
    }

    protected function isRootUser(): bool
    {
        return \function_exists('posix_geteuid') && (int) @\posix_geteuid() === 0;
    }

    /**
     * Execute one fixed argv vector without a command shell.
     *
     * @param list<string> $command
     */
    protected function runTrustCommand(
        array $command,
        ?int &$exitCode = null,
        bool $inheritStdin = false,
    ): string
    {
        $exitCode = 1;
        if (!\function_exists('proc_open')
            || !\array_is_list($command)
            || $command === []
            || \count($command) > 32
        ) {
            return '';
        }
        $argvBytes = 0;
        foreach ($command as $argument) {
            if (!\is_string($argument)
                || $argument === ''
                || \str_contains($argument, "\0")
                || \strlen($argument) > 4096
            ) {
                return '';
            }
            $argvBytes += \strlen($argument);
        }
        if ($argvBytes > 32_768) {
            return '';
        }

        $configuredTimeout = \getenv('WLS_SSL_TRUST_COMMAND_TIMEOUT_MS');
        $timeoutMs = \is_string($configuredTimeout)
            && \preg_match('/\A[0-9]{1,8}\z/D', $configuredTimeout) === 1
                ? (int)$configuredTimeout
                : 5000;
        $timeoutMs = \max(500, \min(self::MAX_TRUST_COMMAND_TIMEOUT_MS, $timeoutMs));
        // Trust mutation is intentionally non-interactive. The shared runner
        // owns an isolated POSIX process group or the signed Windows Job helper,
        // terminates the whole tree, and never calls proc_close before exit is
        // proven. A caller that needs a password receives the manual command.
        unset($inheritStdin);
        $result = GatewayBoundedCommandRunner::run(
            $command,
            $timeoutMs / 1000.0,
            failOnTruncatedOutput: true,
        );
        $exitCode = (int)($result['code'] ?? 125);
        $output = (string)($result['output'] ?? '');
        if ($exitCode === 124) {
            $output = $this->appendBoundedCommandMarker(
                $output,
                'WLS_SSL_TRUST_COMMAND_TIMEOUT after ' . $timeoutMs . 'ms',
            );
            w_log_warning(
                'SSL trust command timed out after {timeout_ms}ms: {command}',
                ['timeout_ms' => $timeoutMs, 'command' => \basename($command[0])],
                'ssl_cert',
            );
        }
        if (\strlen($output) > self::MAX_TRUST_COMMAND_OUTPUT_BYTES) {
            $exitCode = 125;
            $output = $this->appendBoundedCommandMarker(
                \substr($output, 0, self::MAX_TRUST_COMMAND_OUTPUT_BYTES),
                'WLS_SSL_TRUST_COMMAND_OUTPUT_LIMIT',
            );
        }
        return \trim($output);
    }

    private function appendBoundedCommandMarker(string $output, string $marker): string
    {
        $separator = $output === '' ? '' : "\n";
        $suffix = $separator . $marker;
        if (\strlen($suffix) >= self::MAX_TRUST_COMMAND_OUTPUT_BYTES) {
            return \substr($marker, 0, self::MAX_TRUST_COMMAND_OUTPUT_BYTES);
        }
        return \substr(
            $output,
            0,
            self::MAX_TRUST_COMMAND_OUTPUT_BYTES - \strlen($suffix),
        ) . $suffix;
    }

    /** @param list<string> $command */
    protected function runInteractiveTrustCommand(
        array $command,
        ?int &$exitCode = null,
    ): string
    {
        // Automatic trust mutation never inherits a terminal. If elevation is
        // not already authorized, the caller reports the explicit manual path.
        return $this->runTrustCommand($command, $exitCode, false);
    }

    protected function canUseInteractivePrivilegePrompt(): bool
    {
        if (PHP_SAPI !== 'cli' || !\defined('STDIN')) {
            return false;
        }
        if (\function_exists('posix_isatty')) {
            return (bool) @\posix_isatty(STDIN);
        }
        if (\function_exists('stream_isatty')) {
            return (bool) @\stream_isatty(STDIN);
        }

        return true;
    }

    /** @param list<string> $command @return list<string> */
    protected function buildSudoCommand(array $command, string $prompt): array
    {
        $sudo = $this->resolveTrustExecutable('sudo');
        if ($sudo === '') {
            throw new \RuntimeException('The sudo executable is unavailable.');
        }
        unset($prompt);
        return [$sudo, '-n', ...$command];
    }

    protected function commandExists(string $command): bool
    {
        return $this->resolveTrustExecutable($command) !== '';
    }

    protected function resolveTrustExecutable(string $command): string
    {
        $command = \trim($command);
        if ($command === ''
            || \str_contains($command, "\0")
            || \preg_match('/\A[A-Za-z0-9_.+-]+\z/D', $command) !== 1
        ) {
            return '';
        }
        $windowsRoot = \rtrim((string)\getenv('SystemRoot'), '/\\');
        if ($windowsRoot !== ''
            && (\strlen($windowsRoot) > 1024
                || \str_contains($windowsRoot, "\0")
                || \preg_match('/\A[A-Za-z]:[\\\\\/][^\x00]+\z/D', $windowsRoot) !== 1
                || \in_array(
                    '..',
                    \preg_split('#[\\\\/]+#', $windowsRoot) ?: [],
                    true,
                ))
        ) {
            $windowsRoot = '';
        }
        $candidates = match (\strtolower($command)) {
            'security' => ['/usr/bin/security'],
            'openssl' => ['/usr/bin/openssl', '/opt/homebrew/bin/openssl', '/usr/local/bin/openssl'],
            'sudo' => ['/usr/bin/sudo'],
            'install' => ['/usr/bin/install', '/bin/install'],
            'update-ca-certificates' => ['/usr/sbin/update-ca-certificates'],
            'update-ca-trust' => ['/usr/bin/update-ca-trust', '/usr/sbin/update-ca-trust'],
            'certutil', 'certutil.exe' => $windowsRoot === ''
                ? []
                : [$windowsRoot . DIRECTORY_SEPARATOR . 'System32'
                    . DIRECTORY_SEPARATOR . 'certutil.exe'],
            default => [],
        };
        foreach ($candidates as $candidate) {
            $real = \realpath($candidate);
            $status = @\lstat($candidate);
            if (\is_string($real)
                && \is_array($status)
                && !\is_link($candidate)
                && ((((int)($status['mode'] ?? 0)) & 0170000) === 0100000)
                && (\PHP_OS_FAMILY === 'Windows' || \is_executable($real))
            ) {
                return $real;
            }
        }
        return '';
    }

    protected function hasLocalCertificateAuthorityInSystemStore(): bool
    {
        if ($this->getOsFamily() === 'Windows') {
            $certutil = $this->resolveTrustExecutable('certutil.exe');
            if ($certutil === '') {
                return false;
            }
            $output = $this->runTrustCommand(
                [$certutil, '-user', '-store', 'Root', self::ISSUER_LOCAL_CA],
                $exitCode
            );

            return $exitCode === 0
                && $output !== ''
                && !\preg_match('/command\s+FAILED|0x80092004|NOT_FOUND|Cannot\s+find|找不到/i', $output);
        }

        $security = $this->resolveTrustExecutable('security');
        if ($this->getOsFamily() === 'Darwin' && $security !== '') {
            $output = $this->runTrustCommand(
                [$security, 'find-certificate', '-a', '-c', self::ISSUER_LOCAL_CA],
                $exitCode
            );

            return $exitCode === 0 && $output !== '' && !\preg_match('/could not be found|error/i', $output);
        }

        return false;
    }

    protected function isLocalCertificateAuthorityTrustedOnWindows(string $caCertPath): bool
    {
        $certPem = self::readRegularFileNoFollow($caCertPath);
        if ($certPem === null || !\function_exists('openssl_x509_fingerprint')) {
            return false;
        }

        $fingerprint = \openssl_x509_fingerprint($certPem, 'sha1');
        if (!\is_string($fingerprint) || $fingerprint === '') {
            return false;
        }

        // 仅按 SHA1 指纹查询单张证书。全量 `certutil -user -store Root` 会枚举当前用户
        // 信任库中全部根证，证书多时可能卡住数十秒，拖慢每次本地 CA 签发。
        $thumb = $this->normalizeCertificateFingerprint($fingerprint);
        if ($thumb === '') {
            return false;
        }

        $certutil = $this->resolveTrustExecutable('certutil.exe');
        if ($certutil === '') {
            return false;
        }
        $storeOutput = $this->runTrustCommand(
            [$certutil, '-user', '-store', 'Root', $thumb],
            $exitCode
        );
        if ($exitCode !== 0 || $storeOutput === '') {
            return false;
        }

        if (\preg_match('/command\s+FAILED|0x80092004|NOT_FOUND/i', $storeOutput)) {
            return false;
        }

        // certutil output is localized on non-English Windows builds. A zero
        // exit code without explicit NOT_FOUND/FAILED markers is the stable
        // signal here; matching English "Certificate:" caused false negatives
        // and repeated Root-store import prompts.
        return true;
    }

    protected function isLocalCertificateAuthorityTrustedOnMacos(string $caCertPath): bool
    {
        if (self::readRegularFileNoFollow($caCertPath) === null
            || !$this->commandExists('security')
        ) {
            return false;
        }

        if (!$this->isMacosCaRootTrustedForSsl($caCertPath)) {
            return false;
        }

        $leafPath = $this->resolveLocalDevelopmentProbeLeafPath();
        if ($leafPath === '') {
            return true;
        }

        return $this->isLocalDevelopmentSslChainCryptographicallyValid($caCertPath, $leafPath);
    }

    protected function isMacosCaRootTrustedForSsl(string $caCertPath): bool
    {
        $security = $this->resolveTrustExecutable('security');
        if ($security === '') {
            return false;
        }
        $exitCode = 1;
        $output = $this->runTrustCommand(
            [$security, 'verify-cert', '-c', $caCertPath, '-p', 'ssl'],
            $exitCode
        );

        if (\preg_match('/CSSMERR_|failed/i', (string) $output)) {
            return false;
        }

        // macOS may return a non-zero status when Certificate Transparency
        // metadata is unavailable for a local development root, while the
        // actual trust result is still "No error".
        if (\preg_match('/Cert Verify Result:\s*No error/i', (string) $output)) {
            return true;
        }

        return $exitCode === 0 && !\preg_match('/error/i', (string) $output);
    }

    /**
     * 用已签发的本地叶子证书验证整条 SSL 链是否被 macOS 信任。
     * 仅检查 CA 文件或 find-certificate 会误判「已在钥匙串但未设为信任根」。
     */
    protected function isLocalDevelopmentSslChainTrustedOnMacos(): bool
    {
        $caPath = $this->getLocalCaCertPath();
        if (self::readRegularFileNoFollow($caPath) === null
            || !$this->isMacosCaRootTrustedForSsl($caPath)
        ) {
            return false;
        }

        $leafPath = $this->resolveLocalDevelopmentProbeLeafPath();
        if ($leafPath === '') {
            return true;
        }

        return $this->isLocalDevelopmentSslChainCryptographicallyValid($caPath, $leafPath);
    }

    protected function isLocalDevelopmentSslChainCryptographicallyValid(string $caCertPath, string $leafPath): bool
    {
        $openssl = $this->resolveTrustExecutable('openssl');
        if ($openssl === '') {
            return false;
        }

        $exitCode = 1;
        $output = $this->runTrustCommand(
            [$openssl, 'verify', '-CAfile', $caCertPath, $leafPath],
            $exitCode
        );

        return $exitCode === 0 && \str_contains((string) $output, ': OK');
    }

    protected function resolveLocalDevelopmentProbeLeafPath(): string
    {
        $base = $this->ensureCertificateBaseDirectory();
        foreach ($this->boundedDirectoryEntries(
            $base,
            self::MAX_CERTIFICATE_SOURCE_DIRECTORIES,
            'project certificate source directory',
        ) as $entry) {
            $logical = self::logicalDomainFromStorageSegment($entry);
            if ($logical === ''
                || !LocalDomainPolicy::isManagedLocalDomain($logical)
                || LocalDomainPolicy::isManagedWildcardDomain($logical)) {
                continue;
            }
            $directory = $this->certificateDirectoryForSegment($entry, false);
            if ($directory === null) {
                continue;
            }
            $leafPath = $directory . 'fullchain.pem';
            if (self::readRegularFileNoFollow($leafPath) !== null
                && $this->isCertificateValid($leafPath)
            ) {
                return $leafPath;
            }
        }

        $wildcard = LocalDomainPolicy::currentWildcardDomain();
        $wildcardPath = $this->getCertificateDir($wildcard) . 'fullchain.pem';
        if (self::readRegularFileNoFollow($wildcardPath) !== null
            && $this->isCertificateValid($wildcardPath)
        ) {
            return $wildcardPath;
        }

        return '';
    }

    /**
     * 确保本地开发 CA 已写入系统信任库（macOS Keychain / Windows / Linux）。
     *
     * @return array{success:bool,trusted:bool,message:string}
     */
    public function ensureLocalDevelopmentCaTrusted(): array
    {
        $ca = $this->ensureLocalCertificateAuthority();
        if (!($ca['success'] ?? false)) {
            return [
                'success' => false,
                'trusted' => false,
                'message' => (string) ($ca['message'] ?? __('Failed to prepare local CA')),
            ];
        }

        return $this->trustLocalCertificateAuthority((string) $ca['cert_path']);
    }

    protected function isLocalCertificateAuthorityTrustedOnLinux(string $caCertPath): bool
    {
        if (self::readRegularFileNoFollow($caCertPath) === null) {
            return false;
        }
        $openssl = $this->resolveTrustExecutable('openssl');
        if ($openssl === '') {
            return false;
        }

        $verifyCommands = [];
        if (\is_dir('/etc/ssl/certs')) {
            $verifyCommands[] = [$openssl, 'verify', '-CApath', '/etc/ssl/certs', $caCertPath];
        }
        foreach ([
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/cert.pem',
        ] as $bundlePath) {
            if (self::readRegularFileNoFollow(
                $bundlePath,
                16 * self::MAX_CERTIFICATE_MATERIAL_BYTES,
                true,
            ) !== null) {
                $verifyCommands[] = [$openssl, 'verify', '-CAfile', $bundlePath, $caCertPath];
            }
        }

        foreach ($verifyCommands as $command) {
            $exitCode = 1;
            $output = $this->runTrustCommand($command, $exitCode);
            if ($exitCode === 0 && \str_contains($output, ': OK')) {
                return true;
            }
        }

        return false;
    }

    protected function getCertificateSha1Fingerprint(string $certPath): string
    {
        if (!\function_exists('openssl_x509_fingerprint')) {
            return '';
        }

        $certPem = self::readRegularFileNoFollow($certPath);
        if ($certPem === null) {
            return '';
        }

        $fingerprint = \openssl_x509_fingerprint($certPem, 'sha1');

        return \is_string($fingerprint) ? $this->normalizeCertificateFingerprint($fingerprint) : '';
    }

    /**
     * @return array{
     *   dest:string,
     *   install_argv:list<string>,
     *   refresh_argv:list<string>,
     *   manual:string
     * }|null
     */
    protected function resolveLinuxLocalCaInstallPlan(string $caCertPath): ?array
    {
        $install = $this->resolveTrustExecutable('install');
        $updateCaCertificates = $this->resolveTrustExecutable('update-ca-certificates');
        if ($install !== ''
            && $updateCaCertificates !== ''
            && \is_dir('/usr/local/share/ca-certificates')
        ) {
            $dest = '/usr/local/share/ca-certificates/weline-local-development-ca.crt';

            return [
                'dest' => $dest,
                'install_argv' => [$install, '-m', '0644', $caCertPath, $dest],
                'refresh_argv' => [$updateCaCertificates],
                'manual' => 'sudo install -m 0644 ' . \escapeshellarg($caCertPath)
                    . ' ' . \escapeshellarg($dest) . ' && sudo update-ca-certificates',
            ];
        }

        $updateCaTrust = $this->resolveTrustExecutable('update-ca-trust');
        if ($install !== ''
            && $updateCaTrust !== ''
            && \is_dir('/etc/pki/ca-trust/source/anchors')
        ) {
            $dest = '/etc/pki/ca-trust/source/anchors/weline-local-development-ca.crt';

            return [
                'dest' => $dest,
                'install_argv' => [$install, '-m', '0644', $caCertPath, $dest],
                'refresh_argv' => [$updateCaTrust, 'extract'],
                'manual' => 'sudo install -m 0644 ' . \escapeshellarg($caCertPath)
                    . ' ' . \escapeshellarg($dest) . ' && sudo update-ca-trust extract',
            ];
        }

        return null;
    }

    protected function trustLocalCertificateAuthorityOnLinux(string $caCertPath): array
    {
        if ($this->isLocalCertificateAuthorityTrustedOnLinux($caCertPath)) {
            return ['success' => true, 'trusted' => true, 'message' => __('Local CA is already trusted by the Linux system store')];
        }

        $plan = $this->resolveLinuxLocalCaInstallPlan($caCertPath);
        if ($plan === null) {
            return [
                'success' => false,
                'trusted' => false,
                'message' => __('Local CA was generated, but no supported Linux trust tool was found. Import %{1} into the system trust store manually', [$caCertPath]),
            ];
        }

        $installCommand = $plan['install_argv'];
        $refreshCommand = $plan['refresh_argv'];
        if ($this->isRootUser()) {
            // Root still executes the exact binaries directly; no `/bin/sh`
            // composition is permitted in WLS certificate publication paths.
        } elseif ($this->commandExists('sudo')) {
            $installCommand = $this->buildSudoCommand(
                $installCommand,
                '[WLS] sudo password for CA trust: ',
            );
            $refreshCommand = $this->buildSudoCommand(
                $refreshCommand,
                '[WLS] sudo password for CA trust: ',
            );
        } else {
            return [
                'success' => false,
                'trusted' => false,
                'message' => __('Local CA was generated. Run manually: %{1}', [$plan['manual']]),
            ];
        }

        $exitCode = 1;
        $output = $this->runInteractiveTrustCommand($installCommand, $exitCode);
        if ($exitCode === 0) {
            $refreshExitCode = 1;
            $refreshOutput = $this->runInteractiveTrustCommand(
                $refreshCommand,
                $refreshExitCode,
            );
            $output = \trim($output . "\n" . $refreshOutput);
            $exitCode = $refreshExitCode;
        }
        if ($exitCode === 0) {
            $trusted = $this->isLocalCertificateAuthorityTrustedOnLinux($caCertPath)
                || self::readRegularFileNoFollow((string)$plan['dest']) !== null;

            return [
                'success' => true,
                'trusted' => $trusted,
                'message' => $trusted
                    ? __('Local CA imported into Linux system trust store')
                    : __('Local CA install command completed, but trust verification did not confirm it yet'),
            ];
        }

        return [
            'success' => false,
            'trusted' => false,
            'message' => __('Local CA was generated, but automatic Linux trust import failed. Run manually: %{1}. Output: %{2}', [$plan['manual'], \trim($output)]),
        ];
    }

    protected function resolveMacosLoginKeychain(): string
    {
        $home = (string) \getenv('HOME');
        foreach ([
            $home !== '' ? $home . '/Library/Keychains/login.keychain-db' : '',
            $home !== '' ? $home . '/Library/Keychains/login.keychain' : '',
        ] as $candidate) {
            if ($candidate !== '' && \is_file($candidate)) {
                return $candidate;
            }
        }

        return 'login.keychain-db';
    }

    protected function trustLocalCertificateAuthorityOnMacos(string $caCertPath): array
    {
        $security = $this->resolveTrustExecutable('security');
        if ($security === '') {
            return [
                'success' => false,
                'trusted' => false,
                'message' => __('Local CA was generated, but macOS security tool was not found. Import %{1} into Keychain Access manually', [$caCertPath]),
            ];
        }

        $installedInSystemKeychain = $this->isLocalCertificateAuthorityInstalledInMacosSystemKeychain($caCertPath);
        $installedInLoginKeychain = $this->isLocalCertificateAuthorityInstalledInMacosLoginKeychain($caCertPath);
        if ($this->isLocalCertificateAuthorityTrustedOnMacos($caCertPath)
            && ($installedInSystemKeychain || $installedInLoginKeychain)) {
            return ['success' => true, 'trusted' => true, 'message' => __('Local CA is already trusted by macOS Keychain')];
        }

        $output = '';
        $manual = 'sudo /usr/bin/security add-trusted-cert -d -r trustRoot -p ssl -p basic -k /Library/Keychains/System.keychain '
            . \escapeshellarg($caCertPath);

        // Safari / Chrome 主要读取系统钥匙串；优先写入 System，再回退 login。
        if ($this->commandExists('sudo')) {
            $systemCommand = $this->buildSudoCommand(
                [
                    $security,
                    'add-trusted-cert',
                    '-d',
                    '-r',
                    'trustRoot',
                    '-p',
                    'ssl',
                    '-p',
                    'basic',
                    '-k',
                    '/Library/Keychains/System.keychain',
                    $caCertPath,
                ],
                '[WLS] sudo password for macOS CA trust: ',
            );
            $systemExitCode = 1;
            $systemOutput = $this->runInteractiveTrustCommand($systemCommand, $systemExitCode);
            if ($systemExitCode === 0 && $this->isLocalCertificateAuthorityTrustedOnMacos($caCertPath)) {
                return ['success' => true, 'trusted' => true, 'message' => __('Local CA imported into macOS System Keychain')];
            }
            $output = \trim($systemOutput);
        }

        $loginKeychain = $this->resolveMacosLoginKeychain();
        $staleLoginCaOutput = $this->removeStaleLocalCertificateAuthoritiesFromMacosKeychain(
            $loginKeychain,
            $this->getCertificateSha1Fingerprint($caCertPath)
        );
        if ($staleLoginCaOutput !== '') {
            $output = \trim($output . "\n" . $staleLoginCaOutput);
        }

        if (!$installedInLoginKeychain) {
            $loginCommand = [
                $security,
                'add-trusted-cert',
                '-d',
                '-r',
                'trustRoot',
                '-p',
                'ssl',
                '-p',
                'basic',
                '-k',
                $loginKeychain,
                $caCertPath,
            ];
            $loginExitCode = 1;
            $loginOutput = $this->runInteractiveTrustCommand($loginCommand, $loginExitCode);
            if ($loginExitCode === 0 && $this->isLocalCertificateAuthorityTrustedOnMacos($caCertPath)) {
                return ['success' => true, 'trusted' => true, 'message' => __('Local CA imported into macOS login Keychain')];
            }
            $output = \trim($output . "\n" . $loginOutput);
        }

        $hint = !$this->canUseInteractivePrivilegePrompt()
            ? __('macOS System Keychain trust needs an interactive Terminal approval; WLS has already tried the user login Keychain. Run the command below from Terminal if a browser still rejects the certificate.')
            : __('Run the command below to approve Keychain trust.');

        return [
            'success' => false,
            'trusted' => false,
            'message' => __('Local CA was generated, but automatic macOS trust import failed. %{1} Manual command: %{2}. Output: %{3}', [$hint, $manual, \trim($output)]),
        ];
    }

    protected function removeStaleLocalCertificateAuthoritiesFromMacosKeychain(string $keychainPath, string $currentFingerprint): string
    {
        $currentFingerprint = $this->normalizeCertificateFingerprint($currentFingerprint);
        $security = $this->resolveTrustExecutable('security');
        if ($currentFingerprint === '' || $keychainPath === '' || $security === '') {
            return '';
        }

        $output = $this->runTrustCommand(
            [
                $security,
                'find-certificate',
                '-a',
                '-Z',
                '-c',
                self::ISSUER_LOCAL_CA,
                $keychainPath,
            ],
            $exitCode
        );
        if ($exitCode !== 0 || $output === '') {
            return '';
        }

        $messages = [];
        if (\preg_match_all('/SHA-1 hash:\s*([A-F0-9]+)/i', $output, $matches)) {
            foreach ($matches[1] as $fingerprint) {
                $fingerprint = $this->normalizeCertificateFingerprint((string) $fingerprint);
                if ($fingerprint === '' || $fingerprint === $currentFingerprint) {
                    continue;
                }

                $deleteOutput = $this->runTrustCommand(
                    [$security, 'delete-certificate', '-Z', $fingerprint, $keychainPath],
                    $deleteExitCode
                );
                $messages[] = $deleteExitCode === 0
                    ? 'Removed stale Weline Local Development CA from macOS keychain: ' . $fingerprint
                    : 'Failed to remove stale Weline Local Development CA ' . $fingerprint . ': ' . \trim($deleteOutput);
            }
        }

        return \implode("\n", $messages);
    }

    protected function isLocalCertificateAuthorityInstalledInMacosSystemKeychain(string $caCertPath): bool
    {
        $fingerprint = $this->getCertificateSha1Fingerprint($caCertPath);
        $security = $this->resolveTrustExecutable('security');
        if ($fingerprint === '' || $security === '') {
            return false;
        }

        $output = $this->runTrustCommand(
            [
                $security,
                'find-certificate',
                '-a',
                '-Z',
                '-c',
                self::ISSUER_LOCAL_CA,
                '/Library/Keychains/System.keychain',
            ],
            $exitCode
        );
        if ($exitCode !== 0 || $output === '') {
            return false;
        }

        return \str_contains($this->normalizeCertificateFingerprint($output), $fingerprint);
    }

    protected function isLocalCertificateAuthorityInstalledInMacosLoginKeychain(string $caCertPath): bool
    {
        return $this->isLocalCertificateAuthorityInstalledInMacosKeychain(
            $caCertPath,
            $this->resolveMacosLoginKeychain()
        );
    }

    protected function isLocalCertificateAuthorityInstalledInMacosKeychain(string $caCertPath, string $keychainPath): bool
    {
        $fingerprint = $this->getCertificateSha1Fingerprint($caCertPath);
        $security = $this->resolveTrustExecutable('security');
        if ($fingerprint === '' || $keychainPath === '' || $security === '') {
            return false;
        }

        $output = $this->runTrustCommand(
            [
                $security,
                'find-certificate',
                '-a',
                '-Z',
                '-c',
                self::ISSUER_LOCAL_CA,
                $keychainPath,
            ],
            $exitCode
        );
        if ($exitCode !== 0 || $output === '') {
            return false;
        }

        return \str_contains($this->normalizeCertificateFingerprint($output), $fingerprint);
    }

    protected function trustLocalCertificateAuthority(string $caCertPath): array
    {
        if (self::readRegularFileNoFollow($caCertPath) === null) {
            return ['success' => false, 'trusted' => false, 'message' => __('Local CA certificate file is missing: %{1}', [$caCertPath])];
        }

        $cacheKey = $this->getCertificateSha1Fingerprint($caCertPath);
        if ($cacheKey !== '' && isset($this->localCaTrustResultCache[$cacheKey])) {
            return $this->localCaTrustResultCache[$cacheKey];
        }

        $remember = function (array $result) use ($cacheKey): array {
            $normalized = [
                'success' => (bool)($result['success'] ?? false),
                'trusted' => (bool)($result['trusted'] ?? false),
                'message' => (string)($result['message'] ?? ''),
            ];
            if ($cacheKey !== '') {
                $this->localCaTrustResultCache[$cacheKey] = $normalized;
            }

            return $normalized;
        };

        if ($this->getOsFamily() === 'Windows') {
            // Windows 证书信任库可能被组策略、杀软或 certutil 交互卡住。
            // WLS 启动路径默认只负责生成可用证书，绝不阻塞或修改系统信任库；
            // 如确需自动导入本地 CA，可显式设置 WLS_SSL_TRUST_WINDOWS_LOCAL_CA=1。
            if ((string)\getenv('WLS_SSL_TRUST_WINDOWS_LOCAL_CA') !== '1') {
                return $remember([
                    'success' => true,
                    'trusted' => false,
                    'message' => __('Local CA generated; Windows trust-store import skipped during WLS startup. Import %{1} manually or set WLS_SSL_TRUST_WINDOWS_LOCAL_CA=1 to opt in.', [$caCertPath]),
                ]);
            }

            if ($this->isLocalCertificateAuthorityTrustedOnWindows($caCertPath)) {
                return $remember(['success' => true, 'trusted' => true, 'message' => __('Local CA is already trusted by the current Windows user')]);
            }

            $certutil = $this->resolveTrustExecutable('certutil.exe');
            if ($certutil === '') {
                return $remember([
                    'success' => false,
                    'trusted' => false,
                    'message' => __('Failed to find certutil.exe; import %{1} into Current User > Trusted Root Certification Authorities manually', [$caCertPath]),
                ]);
            }

            $this->runTrustCommand(
                [$certutil, '-user', '-addstore', 'Root', $caCertPath],
            );
            if ($this->isLocalCertificateAuthorityTrustedOnWindows($caCertPath)) {
                return $remember(['success' => true, 'trusted' => true, 'message' => __('Local CA imported into Current User Root store')]);
            }

            return $remember([
                'success' => false,
                'trusted' => false,
                'message' => __('Local CA was generated, but automatic trust import failed. Import %{1} into Current User > Trusted Root Certification Authorities manually', [$caCertPath]),
            ]);
        }

        if ($this->getOsFamily() === 'Darwin') {
            return $remember($this->trustLocalCertificateAuthorityOnMacos($caCertPath));
        }

        if ($this->getOsFamily() === 'Linux') {
            return $remember($this->trustLocalCertificateAuthorityOnLinux($caCertPath));
        }

        return $remember([
            'success' => true,
            'trusted' => false,
            'message' => __('Local CA was generated. Trust it manually in your OS/browser with %{1}', [$caCertPath]),
        ]);
    }

    protected function nextLocalCaSerial(): int
    {
        $serialPaths = [$this->getLocalCaSerialPath()];
        $globalSerialPath = $this->getGlobalLocalCaSerialPath();
        if ($globalSerialPath !== '' && $this->localAndGlobalCertificateAuthorityMatch()) {
            \array_unshift($serialPaths, $globalSerialPath);
        }
        $serialPaths = \array_values(\array_unique(\array_filter(
            $serialPaths,
            static fn (string $path): bool => $path !== '',
        )));
        if ($serialPaths === []) {
            throw new \RuntimeException('No local CA serial authority is available.');
        }
        $lockPath = \dirname($serialPaths[0]) . DS . 'serial.lock';
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $lockPath,
            function () use ($serialPaths): int {
                $current = 1000;
                foreach ($serialPaths as $serialPath) {
                    $stored = self::readRegularFileNoFollow(
                        $serialPath,
                        64,
                        true,
                        true,
                    );
                    if ($stored === null || $stored === '') {
                        continue;
                    }
                    $stored = \trim($stored);
                    if (\preg_match('/\A[1-9][0-9]{0,17}\z/D', $stored) !== 1) {
                        throw new \RuntimeException('Local CA serial state is corrupt.');
                    }
                    $current = \max($current, (int)$stored);
                }
                if ($current >= PHP_INT_MAX) {
                    throw new \RuntimeException('Local CA serial authority is exhausted.');
                }
                $next = (string)($current + 1);
                foreach ($serialPaths as $serialPath) {
                    $this->writeLocalCaStateAtomically($serialPath, $next, 0600);
                }
                return $current;
            },
        );
    }

    public function generateLocalCaSignedCertificate(string $domain, int $websiteId = 0, int $validDays = 825): array
    {
        try {
            return (new ProjectCertificateGenerationStore())
                ->withCertificateLifecycleLock(
                    fn (): array => $this->generateLocalCaSignedCertificateLocked(
                        $domain,
                        $websiteId,
                        $validDays,
                    ),
                );
        } catch (\Throwable $throwable) {
            return ['success' => false, 'message' => $throwable->getMessage()];
        }
    }

    private function generateLocalCaSignedCertificateLocked(
        string $domain,
        int $websiteId = 0,
        int $validDays = 825,
    ): array
    {
        try {
            $domain = self::normalizeCertificateStorageDomain($domain);
            if ($domain === '') {
                return ['success' => false, 'message' => __('Domain cannot be empty')];
            }
            $this->assertCertificateMutationNotBlockedByRetirement($domain);

            // 分阶段耗时追踪：帮助诊断 "准备 SSL 证书..." 卡在哪一步。
            // 每步通过 w_log_info 记录毫秒级耗时，复杂度极低但在事故时价值高。
            $trace = static function (string $step, float $startNs) use ($domain): void {
                $ms = (int) \round((\hrtime(true) - $startNs) / 1_000_000.0);
                w_log_info(\sprintf('[SslCertificateService][%s] %s elapsed=%dms', $domain, $step, $ms));
            };

            $tCa = \hrtime(true);
            $ca = $this->ensureLocalCertificateAuthority();
            $trace('ensureLocalCertificateAuthority', $tCa);
            if (!($ca['success'] ?? false)) {
                return $ca;
            }

            $tTrust = \hrtime(true);
            $trust = $this->trustLocalCertificateAuthority((string) $ca['cert_path']);
            $trace('trustLocalCertificateAuthority', $tTrust);

            $certDir = $this->getCertificateDir($domain);
            $certPath = $certDir . 'fullchain.pem';
            $keyPath = $certDir . 'privkey.pem';
            $chainPath = $certDir . 'chain.pem';

            $opensslConfig = $this->getOpensslConfigForLocalCaLeaf($domain);
            $tLeafKey = \hrtime(true);
            $privateKey = \openssl_pkey_new($opensslConfig);
            $trace('openssl_pkey_new(leaf)', $tLeafKey);
            if (!$privateKey) {
                return ['success' => false, 'message' => __('Failed to generate local leaf private key')];
            }

            $dn = [
                'countryName' => 'CN',
                'stateOrProvinceName' => 'Development',
                'localityName' => 'Local',
                'organizationName' => 'Weline Framework',
                'organizationalUnitName' => 'Development',
                'commonName' => $domain,
                'emailAddress' => 'dev@' . $this->normalizeLocalCertificateEmailDomain($domain),
            ];

            $tCsr = \hrtime(true);
            $csr = \openssl_csr_new($dn, $privateKey, $opensslConfig);
            $trace('openssl_csr_new(leaf)', $tCsr);
            if (!$csr) {
                return ['success' => false, 'message' => __('Failed to generate local leaf CSR')];
            }

            $caCertPem = self::readRegularFileNoFollow(
                (string)$ca['cert_path'],
            );
            $caKeyPem = self::readPrivateKeyFileNoFollow(
                (string)$ca['key_path'],
            );
            if ($caCertPem === null || $caKeyPem === null) {
                return [
                    'success' => false,
                    'message' => __('Local CA material is unsafe or unreadable'),
                ];
            }
            $caCert = \openssl_x509_read($caCertPem);
            $caKey = \openssl_pkey_get_private($caKeyPem);
            if (!$caCert || !$caKey) {
                return ['success' => false, 'message' => __('Failed to load local CA certificate or private key')];
            }

            $tSign = \hrtime(true);
            $leafCert = \openssl_csr_sign(
                $csr,
                $caCert,
                $caKey,
                $validDays,
                $opensslConfig,
                $this->nextLocalCaSerial()
            );
            $trace('openssl_csr_sign(leaf)', $tSign);
            if (!$leafCert) {
                return ['success' => false, 'message' => __('Failed to sign local certificate with local CA')];
            }

            \openssl_x509_export($leafCert, $leafCertPem);
            if (!\openssl_pkey_export($privateKey, $keyPem, null, isset($opensslConfig['config']) ? ['config' => $opensslConfig['config']] : [])) {
                return ['success' => false, 'message' => __('Failed to export local certificate private key')];
            }

            $fullchainPem = \rtrim($leafCertPem) . "\n" . \rtrim($caCertPem) . "\n";
            if (!$fullchainPem || !$keyPem) {
                return ['success' => false, 'message' => __('Failed to persist local fullchain certificate')];
            }
            $csrPem = '';
            if (!\openssl_csr_export($csr, $csrPem) || $csrPem === '') {
                return ['success' => false, 'message' => __('Failed to export local certificate CSR')];
            }
            $this->writeCertificateFileAtomically($certPath, $fullchainPem, 0644);
            $this->writeCertificateFileAtomically($keyPath, $keyPem, 0600);
            $this->writeCertificateFileAtomically($certDir . 'cert.pem', $leafCertPem, 0644);
            $this->writeCertificateFileAtomically($chainPath, $caCertPem, 0644);
            $this->writeCertificateFileAtomically($certDir . 'domain.key', $keyPem, 0600);
            $this->writeCertificateFileAtomically($certDir . 'csr.pem', $csrPem, 0600);

            $saved = $this->updateCertificateRecord(
                $domain,
                $certPath,
                $keyPath,
                self::ISSUER_LOCAL_CA,
                $validDays,
                $websiteId,
                self::PROVIDER_LOCAL_CA
            );
            if (!$saved) {
                return ['success' => false, 'message' => __('Local certificate files were generated, but saving the certificate record failed')];
            }

            $message = $trust['trusted'] ?? false
                ? __('Local CA-signed certificate generated and trusted successfully')
                : (($trust['message'] ?? '') !== '' ? (string) $trust['message'] : __('Local CA-signed certificate generated successfully'));

            return [
                'success' => true,
                'message' => $message,
                'cert_path' => $certPath,
                'key_path' => $keyPath,
                'issuer' => self::ISSUER_LOCAL_CA,
                'expires_at' => \date('Y-m-d H:i:s', \strtotime("+{$validDays} days")),
                'is_new' => true,
                'ssl_enabled' => true,
                'trusted' => (bool) ($trust['trusted'] ?? false),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    protected function normalizeLocalCertificateEmailDomain(string $domain): string
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === ''
            || \str_starts_with($domain, '*.')
            || \filter_var($domain, FILTER_VALIDATE_IP)) {
            return 'weline.localhost';
        }

        return $domain;
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
        try {
            $this->writeCertificateFileAtomically($sanConfigPath, $sanConfig, 0600);
        } catch (\Throwable) {
            return $opensslConfig;
        }
        
        if (self::readRegularFileNoFollow(
            $sanConfigPath,
            self::MAX_CERTIFICATE_MATERIAL_BYTES,
            false,
            true,
        ) !== null) {
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
            // 本地 IP 同时覆盖 localhost/loopback 变体，避免浏览器从任一入口访问时证书名不匹配。
            if ($this->isLoopbackIp($domain)) {
                $dns[] = 'localhost';
                if ($domain !== '127.0.0.1') {
                    $ip[] = '127.0.0.1';
                }
                if ($domain !== '::1') {
                    $ip[] = '::1';
                }
            }
            return $this->sanEntriesCache[$domain] = ['dns' => \array_unique($dns), 'ip' => \array_unique($ip)];
        }
        
        // 2. 处理域名
        $dns[] = $domain;
        
        // localhost 特殊处理：同时包含 127.0.0.1 和 ::1
        if ($domain === 'localhost') {
            $ip[] = '127.0.0.1';
            $ip[] = '::1';
            return $this->sanEntriesCache[$domain] = ['dns' => $dns, 'ip' => $ip];
        }
        
        // 3. 本地开发用后缀（*.test / *.local / *.dev 等）：不调用阻塞式 DNS。
        // gethostbynamel/gethostbyname 在 Windows 上可能因 .test 等后缀长时间挂起，
        // 用户看到「正在为 *.weline.test 准备 SSL 证书...」后无进展。
        // 开发域默认按本机 HTTPS 使用，SAN 补全回环地址即可（与 hosts 指向 127.0.0.1 的常见约定一致）。
        if ($this->isLocalDomain($domain)) {
            if (!\in_array('localhost', $dns, true)) {
                $dns[] = 'localhost';
            }
            if (!\in_array('127.0.0.1', $ip, true)) {
                $ip[] = '127.0.0.1';
            }
            if (!\in_array('::1', $ip, true)) {
                $ip[] = '::1';
            }
            return $this->sanEntriesCache[$domain] = ['dns' => \array_unique($dns), 'ip' => \array_unique($ip)];
        }
        
        // 4. 解析域名获取 IP（非本地开发后缀）
        $resolvedIps = $this->resolveDomainIps($domain);
        foreach ($resolvedIps as $resolvedIp) {
            // 只添加本地/内网 IP 到 SAN（公网 IP 不需要）
            if ($this->isLoopbackIp($resolvedIp)) {
                $ip[] = $resolvedIp;
            }
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
x509_extensions = v3_leaf

[ dn ]
CN = {$domain}

[ v3_req ]
subjectAltName = @alt_names

[ v3_leaf ]
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer
basicConstraints = critical, CA:false
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[ alt_names ]
{$altNamesStr}
CNF;
    }
    
    /**
     * 是否为框架托管的本地通配相关域名（*.weline.test / *.weline.localhost 及其单标签子域）
     */
    protected function isWelineLocalWildcardCandidateDomain(string $domain): bool
    {
        return LocalDomainPolicy::isManagedWildcardDomain($domain)
            || LocalDomainPolicy::isManagedSingleLabelSubdomain($domain);
    }

    /**
     * 自签签发前：若证书管理中已有有效的托管本地通配证书，则复用。
     *
     * @return array|null ensureCertificate / generateSelfSignedCertificate 兼容结构，null 表示继续生成
     */
    protected function tryReuseWelineLocalWildcardBeforeSelfSign(string $domain, int $websiteId = 0): ?array
    {
        if (!$this->isWelineLocalWildcardCandidateDomain($domain)) {
            return null;
        }

        $domainLower = \strtolower(\trim($domain));
        $wildcardDomain = LocalDomainPolicy::resolveWildcardDomain($domainLower);
        $rootDomain = LocalDomainPolicy::resolveRootDomain($domainLower);
        if ($wildcardDomain === null || $rootDomain === null) {
            return null;
        }

        // 子域：与 ensureCertificate 一致，优先套用库中的本地通配证书
        if ($domainLower !== $wildcardDomain) {
            return $this->applyWildcardToSubdomainIfExists($domain, $websiteId);
        }

        $wildcardCert = ObjectManager::getInstance(SslCertificate::class, [], false)
            ->findWildcardByRoot($rootDomain);
        if ($wildcardCert === null) {
            return null;
        }

        $certPem = $wildcardCert->getCertPem();
        $keyPem = $wildcardCert->getKeyPem();
        if ($certPem === '' || $keyPem === '') {
            return null;
        }

        $expiresAt = $wildcardCert->getExpiresAt();
        if ($expiresAt !== '' && \strtotime($expiresAt) < \time()) {
            w_log_info(__('[SslCertificateService] 库中的 %{1} 通配证书已过期，将重新签发', [$wildcardDomain]));
            return null;
        }

        if (!$this->restoreCertificateFilesFromData($wildcardCert->getData())) {
            w_log_info(__('[SslCertificateService] 复用 %{1} 通配证书时写回磁盘失败，将尝试重新签发', [$wildcardDomain]));
            return null;
        }

        $certDir = $this->getCertificateDir($wildcardCert->getDomain());
        $certPath = $certDir . 'fullchain.pem';
        $keyPath = $certDir . 'privkey.pem';
        if (self::readRegularFileNoFollow($certPath) === null
            || self::readPrivateKeyFileNoFollow($keyPath) === null
        ) {
            return null;
        }

        $certInfo = $this->parseCertificate($certPath);

        $result = [
            'success'     => true,
            'message'     => __('已复用现有本地通配证书，跳过重复签发'),
            'cert_path'   => $certPath,
            'key_path'    => $keyPath,
            'issuer'      => $wildcardCert->getIssuer() ?: ($certInfo['issuer'] ?? self::ISSUER_SELF_SIGNED),
            'expires_at'  => $expiresAt !== '' ? $expiresAt : ($certInfo['expires_at'] ?? ''),
            'is_new'      => false,
            'ssl_enabled' => true,
        ];
        $result['message'] = __('已复用现有 %{1} 通配证书，跳过重复签发', [$wildcardDomain]);

        return $result;
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
            return (new ProjectCertificateGenerationStore())
                ->withCertificateLifecycleLock(
                    fn (): array => $this->generateSelfSignedCertificateLocked(
                        $domain,
                        $websiteId,
                        $validDays,
                    ),
                );
        } catch (\Throwable $throwable) {
            return ['success' => false, 'message' => $throwable->getMessage()];
        }
    }

    private function generateSelfSignedCertificateLocked(
        string $domain,
        int $websiteId = 0,
        int $validDays = 365,
    ): array
    {
        try {
            $domain = self::normalizeCertificateStorageDomain($domain);
            $this->assertCertificateMutationNotBlockedByRetirement($domain);
            $reuse = $this->tryReuseWelineLocalWildcardBeforeSelfSign($domain, $websiteId);
            if ($reuse !== null) {
                return $reuse;
            }

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
            
            if (!$certPem || !$keyPem) {
                return ['success' => false, 'message' => __('保存证书文件失败')];
            }
            $csrPem = '';
            if (!\openssl_csr_export($csr, $csrPem) || $csrPem === '') {
                return ['success' => false, 'message' => __('导出 CSR 失败')];
            }
            $this->writeCertificateFileAtomically($certPath, $certPem, 0644);
            $this->writeCertificateFileAtomically($keyPath, $keyPem, 0600);
            $this->writeCertificateFileAtomically($certDir . 'cert.pem', $certPem, 0644);
            $this->writeCertificateFileAtomically($certDir . 'chain.pem', $certPem, 0644);
            $this->writeCertificateFileAtomically($certDir . 'domain.key', $keyPem, 0600);
            $this->writeCertificateFileAtomically($certDir . 'csr.pem', $csrPem, 0600);
            
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
            $domain = self::normalizeCertificateStorageDomain($domain);
            $cert = $this->certificateModel()->clearQuery()->loadByDomain($domain);
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
            if (!self::certificatePemPairIsValidForName(
                $certContents['cert_pem'],
                $certContents['key_pem'],
                $domain,
            ) || !self::certificateBundlePemIsValid($certContents['chain_pem'], true)) {
                return false;
            }

            $isLocalManaged = $this->isLocalManagedProvider($provider);
            $this->recoverAndTrustLocalCaFromCertificateBundle(
                $provider,
                $issuer,
                $certContents['cert_pem'],
                $certContents['chain_pem']
            );

            $cert->setDomain($domain)
                ->setWebsiteId($websiteId)
                ->setCertPath($certPath)
                ->setKeyPath($keyPath)
                ->setChainPath(self::certificateBundleFileIsValid($chainPath) ? $chainPath : '')
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
                ->setAutoRenew(!$isLocalManaged);

            // 避免 uk_domain 冲突：若该域名已被其他行占用，合并到该行并更新该行
            $cert = $this->resolveDuplicateDomainCert($cert);
            $cert->setDomain($domain);
            $cert->save();

            // 泛域名证书更新后同步 PEM 到子域记录
            if ($certType === SslCertificate::CERT_TYPE_WILDCARD) {
                $this->syncWildcardToSubdomains($domain);
            }

            // 自签 / 本地 CA 签发路径必须刷新 SNI 映射，否则 Worker 仍用旧的 ssl_certificate_map.json，易出现 unrecognized_name
            $this->regenerateCertificateMap();

            // 事件是已完成发布的通知，不能早于服务清单及
            // 原生 TLS Worker 的确认，否则消费者会观察到尚未服务的证书。
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
        $result = $this->disableManagedCertificate($domain, (string)__('手动禁用'));
        if (($result['success'] ?? false) !== true) {
            throw new \RuntimeException((string)(
                $result['message'] ?? 'Unable to disable the managed certificate.'
            ));
        }
    }
    
    /**
     * app/etc/ssl/ 下的规范目录片段。拒绝路径分隔符和非域名输入；Windows
     * 泛域映射为 `_wildcard_.example.com`，IPv6 映射为固定 32 位十六进制叶子。
     */
    public static function certificateStorageSegmentForFilesystem(string $domain): string
    {
        $domain = self::normalizeCertificateStorageDomain($domain);
        if (\filter_var($domain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = @\inet_pton($domain);
            if (!\is_string($packed)) {
                throw new \InvalidArgumentException('Certificate IPv6 storage identity is invalid.');
            }
            return '_ip6_.' . \bin2hex($packed);
        }
        if (\PHP_OS_FAMILY === 'Windows' && \str_starts_with($domain, '*.')) {
            return '_wildcard_.' . \substr($domain, 2);
        }

        return $domain;
    }

    /**
     * 探测/删除证书目录时可能存在的规范片段（Windows 上映射目录 + 逻辑名，
     * 兼容从 Linux 拷贝的 `*.` 目录；任何片段仍需通过 no-follow 目录校验）。
     *
     * @return list<string>
     */
    public static function certificateStorageSegmentCandidatesForProbe(string $logicalDomain): array
    {
        $logicalDomain = self::normalizeCertificateStorageDomain($logicalDomain);
        $mapped = self::certificateStorageSegmentForFilesystem($logicalDomain);
        if ($mapped === $logicalDomain) {
            return [$mapped];
        }

        return \array_values(\array_unique([$mapped, $logicalDomain]));
    }

    /**
     * 将磁盘目录名还原为证书管理中的逻辑域名（Windows 泛域目录 `_wildcard_.example.com` → `*.example.com`）。
     */
    public static function logicalDomainFromStorageSegment(string $segment): string
    {
        $segment = \strtolower(\trim($segment));
        if ($segment === ''
            || \strlen($segment) > 255
            || \str_contains($segment, "\0")
            || \str_contains($segment, '/')
            || \str_contains($segment, '\\')
            || $segment === '.'
            || $segment === '..'
        ) {
            return '';
        }
        $ip6Prefix = '_ip6_.';
        if (\str_starts_with($segment, $ip6Prefix)) {
            $hex = \substr($segment, \strlen($ip6Prefix));
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $hex) !== 1) {
                return '';
            }
            $packed = @\hex2bin($hex);
            $ip = \is_string($packed) ? @\inet_ntop($packed) : false;
            return \is_string($ip) ? \strtolower($ip) : '';
        }
        $prefix = '_wildcard_.';
        if (\str_starts_with($segment, $prefix)) {
            $segment = '*.' . \substr($segment, \strlen($prefix));
        }
        try {
            return self::normalizeCertificateStorageDomain($segment);
        } catch (\Throwable) {
            return '';
        }
    }

    private static function normalizeCertificateStorageDomain(string $domain): string
    {
        if ($domain === ''
            || \strlen($domain) > 255
            || \str_contains($domain, "\0")
            || \str_contains($domain, '/')
            || \str_contains($domain, '\\')
            || !\hash_equals($domain, \trim($domain))
        ) {
            throw new \InvalidArgumentException('Certificate domain is outside storage bounds.');
        }
        $domain = \strtolower(\rtrim($domain, '.'));
        $wildcard = \str_starts_with($domain, '*.');
        $body = $wildcard ? \substr($domain, 2) : $domain;
        if (!$wildcard && \filter_var($body, FILTER_VALIDATE_IP) !== false) {
            $packed = @\inet_pton($body);
            $normalized = \is_string($packed) ? @\inet_ntop($packed) : false;
            if (!\is_string($normalized)) {
                throw new \InvalidArgumentException('Certificate IP identity is invalid.');
            }
            return \strtolower($normalized);
        }
        if (\function_exists('idn_to_ascii')) {
            $variant = \defined('INTL_IDNA_VARIANT_UTS46')
                ? \constant('INTL_IDNA_VARIANT_UTS46')
                : 0;
            $ascii = @\idn_to_ascii($body, IDNA_DEFAULT, $variant);
            if (!\is_string($ascii) || $ascii === '') {
                throw new \InvalidArgumentException('Certificate domain IDNA conversion failed.');
            }
            $body = \strtolower($ascii);
        } elseif (\preg_match('/[^\x20-\x7e]/', $body) === 1) {
            throw new \InvalidArgumentException(
                'Non-ASCII certificate domains require the Intl extension.',
            );
        }
        if (\strlen($body) > 253
            || \preg_match(
                '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*'
                    . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D',
                $body,
            ) !== 1
        ) {
            throw new \InvalidArgumentException('Certificate domain is not a canonical DNS name.');
        }
        return $wildcard ? '*.' . $body : $body;
    }

    /**
     * 获取证书存储目录（域名统一小写，与删除/扫描等逻辑一致，避免同域多写法导致“证书丢失”误判）
     */
    public function getCertificateDir(string $domain): string
    {
        $segment = self::certificateStorageSegmentForFilesystem($domain);
        return (string)$this->certificateDirectoryForSegment($segment, true);
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
     * 指定域名是否处于 SSL 颁发流程中（存在未过期的锁文件）
     */
    public function isDomainSslIssuanceInProgress(string $domain): bool
    {
        return (new ProjectCertificateGenerationStore())->withCertificateLifecycleLock(
            fn (): bool => $this->isDomainSslIssuanceInProgressUnlocked($domain),
        );
    }

    private function isDomainSslIssuanceInProgressUnlocked(string $domain): bool
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return false;
        }
        foreach (self::certificateStorageSegmentCandidatesForProbe($domain) as $segment) {
            $certDir = $this->certificateDirectoryForSegment($segment, false);
            if ($certDir === null) {
                continue;
            }
            $lockPath = $certDir . self::SSL_ISSUANCE_LOCK_FILENAME;
            $lockStatus = @\lstat($lockPath);
            if (!\is_array($lockStatus)) {
                if (\file_exists($lockPath) || \is_link($lockPath)) {
                    throw new \RuntimeException('SSL issuance lock is indeterminate.');
                }
                continue;
            }
            if ((((int)($lockStatus['mode'] ?? 0)) & 0170000) !== 0100000
                || (int)($lockStatus['nlink'] ?? 0) !== 1
            ) {
                throw new \RuntimeException('SSL issuance lock is not a private regular file.');
            }
            $age = \time() - (int)($lockStatus['mtime'] ?? 0);
            if ($age > self::SSL_ISSUANCE_LOCK_STALE_SECONDS) {
                $this->cleanupSslIssuanceMarkerRecovery($lockPath);
                $this->removeCertificateLeafSafely(
                    $certDir,
                    self::SSL_ISSUANCE_LOCK_FILENAME,
                );
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * 若域名正在申请证书，返回提示文案；否则返回空字符串（供后台/API 拦截写操作）
     */
    public function getSslIssuanceConflictMessage(string $domain): string
    {
        return $this->isDomainSslIssuanceInProgress($domain)
            ? (string) __('该域名正在申请 SSL 证书，请等待证书成功下载并入库后再修改证书管理。')
            : '';
    }

    /**
     * Call only while holding the project certificate lifecycle lock. A
     * projection retirement may be superseded by a newly activated generation,
     * but an explicit disable/delete must publish its ordered event before any
     * writer can replace its PostgreSQL row or certificate leaves.
     */
    private function assertCertificateMutationNotBlockedByRetirement(
        string $domain,
    ): void {
        $intent = (new ProjectCertificateGenerationStore())->retirementIntent($domain);
        if (!\is_array($intent)
            || !\hash_equals('pending', (string)($intent['state'] ?? ''))
            || \hash_equals(
                ProjectCertificateGenerationStore::RETIREMENT_OPERATION_PROJECTION,
                (string)($intent['operation'] ?? ''),
            )
        ) {
            return;
        }
        throw new \RuntimeException(
            'Certificate material mutation is blocked until the explicit '
                . 'retirement event has completed.',
        );
    }

    /**
     * 创建颁发流程锁（目录由 getCertificateDir 确保存在）
     */
    protected function acquireSslIssuanceLock(string $domain): bool
    {
        try {
            return (new ProjectCertificateGenerationStore())->withCertificateLifecycleLock(
                fn (): bool => $this->acquireSslIssuanceLockUnlocked($domain),
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private function acquireSslIssuanceLockUnlocked(string $domain): bool
    {
        try {
            $domain = self::normalizeCertificateStorageDomain($domain);
            $this->assertCertificateMutationNotBlockedByRetirement($domain);
        } catch (\Throwable) {
            return false;
        }
        if ($domain === '') {
            return false;
        }
        $certDir = $this->getCertificateDir($domain);
        $lockPath = $certDir . self::SSL_ISSUANCE_LOCK_FILENAME;
        $lockStatus = @\lstat($lockPath);
        if (\is_array($lockStatus)) {
            if ((((int)($lockStatus['mode'] ?? 0)) & 0170000) !== 0100000
                || (int)($lockStatus['nlink'] ?? 0) !== 1
            ) {
                return false;
            }
            $age = \time() - (int)($lockStatus['mtime'] ?? 0);
            if ($age <= self::SSL_ISSUANCE_LOCK_STALE_SECONDS) {
                return false;
            }
            $this->cleanupSslIssuanceMarkerRecovery($lockPath);
            $this->removeCertificateLeafSafely(
                $certDir,
                self::SSL_ISSUANCE_LOCK_FILENAME,
            );
        } elseif (\file_exists($lockPath) || \is_link($lockPath)) {
            return false;
        }
        $payload = (string)\getmypid() . "\n" . \date('c');
        try {
            $this->writeCertificateFileAtomically($lockPath, $payload, 0600);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 释放颁发流程锁
     */
    protected function releaseSslIssuanceLock(string $domain): void
    {
        (new ProjectCertificateGenerationStore())->withCertificateLifecycleLock(
            function () use ($domain): void {
                $this->releaseSslIssuanceLockUnlocked($domain);
            },
        );
    }

    private function releaseSslIssuanceLockUnlocked(string $domain): void
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return;
        }
        foreach (self::certificateStorageSegmentCandidatesForProbe($domain) as $segment) {
            $certDir = $this->certificateDirectoryForSegment($segment, false);
            if ($certDir !== null) {
                $this->cleanupSslIssuanceMarkerRecovery(
                    $certDir . self::SSL_ISSUANCE_LOCK_FILENAME,
                );
                $this->removeCertificateLeafSafely(
                    $certDir,
                    self::SSL_ISSUANCE_LOCK_FILENAME,
                );
            }
        }
    }

    private function cleanupSslIssuanceMarkerRecovery(string $path): void
    {
        GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
            $path,
            self::MAX_CERTIFICATE_MATERIAL_BYTES,
            'SSL issuance marker',
            function (string $candidate) use ($path): void {
                $this->assertSslStateTargetMode($path, 0600, 'SSL issuance marker');
                $this->assertCertificateStateContents($path, $candidate);
            },
        );
    }

    /**
     * 强制释放指定域名的颁发锁（用于启动流程自愈重试）。
     */
    public function forceReleaseSslIssuanceLock(string $domain): void
    {
        $this->releaseSslIssuanceLock($domain);
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
        string $provider = '',
        bool $recoverAndTrustLocalCa = true
    ): ?SslCertificate {
        return (new ProjectCertificateGenerationStore())->withCertificateLifecycleLock(
            fn (): ?SslCertificate => $this->syncCertificateRecordFromFilesLocked(
                $domain,
                $certPath,
                $keyPath,
                $websiteId,
                $httpsEnabled,
                $provider,
                $recoverAndTrustLocalCa,
            ),
        );
    }

    private function syncCertificateRecordFromFilesLocked(
        string $domain,
        string $certPath,
        string $keyPath,
        int $websiteId = 0,
        bool $httpsEnabled = true,
        string $provider = '',
        bool $recoverAndTrustLocalCa = true,
    ): ?SslCertificate {
        try {
            $domain = self::normalizeCertificateStorageDomain($domain);
        } catch (\Throwable) {
            return null;
        }
        // 颁发过程中禁止用磁盘扫描结果覆盖证书记录，避免「尚未成功下载」时管理器数据被改坏
        if ($this->isDomainSslIssuanceInProgress($domain)) {
            return null;
        }

        try {
            $this->assertCertificateMutationNotBlockedByRetirement($domain);
            $chainPath = '';
            $candidateChainPath = \dirname($certPath) . DS . 'chain.pem';
            if (self::certificateBundleFileIsValid($candidateChainPath)) {
                $chainPath = $candidateChainPath;
            }
            $certContents = $this->readCertificateContents($certPath, $keyPath, $chainPath);
            if (!self::certificatePemPairIsValidForName(
                $certContents['cert_pem'],
                $certContents['key_pem'],
                $domain,
            ) || !self::certificateBundlePemIsValid($certContents['chain_pem'], true)) {
                return null;
            }
            $leafPem = $this->extractLeafCertFromFullchain($certContents['cert_pem']);
            $parsed = $leafPem !== '' ? @\openssl_x509_parse($leafPem) : false;
            if (!\is_array($parsed)) {
                return null;
            }

            $cert = $this->certificateModel()->clearQuery()->loadByDomain($domain);
            if (!$cert->getCertId()) {
                $cert = ObjectManager::getInstance(SslCertificate::class);
                $cert->clearData(true);
            }

            $issuer = (string)($parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? '');
            $issuedAt = \date('Y-m-d H:i:s', (int)$parsed['validFrom_time_t']);
            $expiresAt = \date('Y-m-d H:i:s', (int)$parsed['validTo_time_t']);

            $provider = $this->inferProviderByIssuer(
                $provider !== '' ? $provider : (string)$cert->getProvider(),
                $issuer
            );
            $isLocalManaged = $this->isLocalManagedProvider($provider);

            $certType = \str_starts_with($domain, '*.')
                ? SslCertificate::CERT_TYPE_WILDCARD
                : SslCertificate::CERT_TYPE_EXACT;

            $status = SslCertificate::STATUS_ACTIVE;
            if ($recoverAndTrustLocalCa) {
                $this->recoverAndTrustLocalCaFromCertificateBundle(
                    $provider,
                    $issuer,
                    $certContents['cert_pem'],
                    $certContents['chain_pem']
                );
            }

            $cert->setDomain($domain)
                ->setWebsiteId($websiteId)
                ->setCertType($certType)
                ->setCertPath($certPath)
                ->setKeyPath($keyPath)
                ->setChainPath($chainPath)
                ->setCertPem($certContents['cert_pem'])
                ->setKeyPem($certContents['key_pem'])
                ->setChainPem($certContents['chain_pem'])
                ->setIssuer($issuer !== '' ? $issuer : $this->getIssuerByProvider($provider))
                ->setProvider($provider)
                ->setStatus($status)
                ->setHttpsEnabled($httpsEnabled)
                ->setAutoRenew(!$isLocalManaged);

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
            $cert->setDomain($domain)
                ->setWebsiteId($websiteId)
                ->setCertType($certType)
                ->setCertPath($certPath)
                ->setKeyPath($keyPath)
                ->setChainPath($chainPath)
                ->setCertPem($certContents['cert_pem'])
                ->setKeyPem($certContents['key_pem'])
                ->setChainPem($certContents['chain_pem'])
                ->setIssuer($issuer !== '' ? $issuer : $this->getIssuerByProvider($provider))
                ->setProvider($provider)
                ->setStatus($status)
                ->setHttpsEnabled($httpsEnabled)
                ->setAutoRenew(!$isLocalManaged);
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
        $existing = $this->certificateModel()->clearQuery()->loadByDomain($domain);
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
            self::PROVIDER_LOCAL_CA => self::ISSUER_LOCAL_CA,
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
            if (\str_contains($issuerLower, \strtolower(self::ISSUER_LOCAL_CA))) {
                return self::PROVIDER_LOCAL_CA;
            }
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
     * 1. 保留泛域名键（*.example.com）- 用于 fallback
     * 2. 展开已知的单标签子域；根域必须由显式证书覆盖
     * 
     * 当证书文件不存在时：先按路径探测 → 再从证书管理（DB 含 PEM）恢复磁盘；localhost/127.0.0.1 互查等价记录。
     * 暂无法从磁盘/DB 恢复且未过期时：不再弹系统通知、不把证书记录标为 ERROR（证书任务会自行恢复，避免误报）。
     * 已过期则仍发续签提示。
     * 
     * @param array<int|string,string> $certificateRoots When provided, stale
     *        paths outside the current enrollment are re-resolved locally.
     * @return array [domain => [cert => path, key => path], ...]
     */
    public function getCertificateMap(array $certificateRoots = []): array
    {
        return $this->buildCertificateMap($certificateRoots, false);
    }

    /**
     * Complete project domain facts for WLS Edge Protocol 2.
     *
     * Missing or expired material remains represented by an empty pair so a
     * transient source failure can never be interpreted as route deletion.
     * Wildcard expansion and database discovery fail closed in this mode.
     *
     * @param array<int|string,string> $certificateRoots
     * @return array<string,array<string,mixed>>
     */
    public function getGatewayCertificateMap(array $certificateRoots = []): array
    {
        if ($certificateRoots === []) {
            $certificateRoots = [
                'project_ssl' => $this->ensureCertificateBaseDirectory(),
            ];
        }
        return $this->buildCertificateMap($certificateRoots, true);
    }

    /**
     * Complete project edge-route facts, independently of TLS material.
     *
     * Certificate rows remain the TLS fact source. Active WebsiteDomain rows
     * are the host-routing authority and therefore keep port 80 routable after
     * HTTPS is explicitly disabled. A failed/incomplete authority query is
     * never interpreted as route deletion.
     *
     * @param array<int|string,string> $certificateRoots
     * @return array<string,array<string,mixed>>
     */
    public function getGatewayRouteMap(array $certificateRoots = []): array
    {
        $map = $this->getGatewayCertificateMap($certificateRoots);
        foreach ($map as $domain => &$material) {
            if (!\is_array($material)) {
                throw new \RuntimeException(
                    'Gateway certificate route material is malformed: ' . (string)$domain,
                );
            }
            $hasCertificate = \trim((string)($material['cert'] ?? '')) !== ''
                && \trim((string)($material['key'] ?? '')) !== '';
            $material['certificate_state'] = $hasCertificate ? 'active' : 'pending';
        }
        unset($material);

        $authority = $this->requestGatewayRouteDomainFacts();
        if (($authority['complete'] ?? false) !== true) {
            if (($authority['present'] ?? false) !== true) {
                // Weline_Server does not require Weline_Websites. In projects
                // without a route-authority provider, the bounded certificate
                // map plus durable project certificate tombstones remains the
                // complete legacy domain authority. A revoked/deleted final DB
                // row must still publish one HTTP-only desired route and an
                // empty TLS serving set instead of looking like transient loss.
                return $this->mergeGatewayDisabledRouteTombstones($map, true);
            }
            // Once HTTP-only routes exist, a certificate-only fallback cannot
            // distinguish an intentional removal from a transient authority
            // failure. Publishing that partial map would destructively retire
            // otherwise healthy port-80 routes, so desired-state construction
            // must always fail closed.
            throw new \RuntimeException(
                'Project routable-domain authority is unavailable; refusing a partial gateway route set.',
            );
        }
        foreach ((array)($authority['domains'] ?? []) as $fact) {
            if (!\is_array($fact) || \array_is_list($fact)) {
                throw new \RuntimeException('Project routable-domain authority returned a malformed fact.');
            }
            $domain = $this->normalizeGatewayFactDomain(
                (string)($fact['domain'] ?? ''),
                false,
            );
            $httpsEnabled = $fact['https_enabled'] ?? null;
            if (!\is_bool($httpsEnabled)) {
                throw new \RuntimeException(
                    'Project routable-domain HTTPS policy is not canonical: ' . $domain,
                );
            }
            if (isset($map[$domain])) {
                // WebsiteDomain is the host-routing authority, while the
                // project certificate row/generation is the HTTPS lifecycle
                // authority. Its derived https_enabled flag is updated only
                // after serving ACK and must not erase a newly issued active
                // certificate during that transaction.
                continue;
            }
            $map[$domain] = [
                'cert' => '',
                'key' => '',
                'chain' => '',
                'cert_type' => SslCertificate::CERT_TYPE_EXACT,
                // A certificate fact that still desires HTTPS is an issuance
                // pending route. An explicitly HTTP-only website is a durable
                // disabled-certificate route and must never redirect to 443.
                'force_https' => $httpsEnabled,
                'force_root_to_www' => false,
                'certificate_state' => $httpsEnabled ? 'pending' : 'disabled',
            ];
        }
        return $this->mergeGatewayDisabledRouteTombstones($map, false);
    }

    /**
     * @param array<string,array<string,mixed>> $map
     * @return array<string,array<string,mixed>>
     */
    private function mergeGatewayDisabledRouteTombstones(
        array $map,
        bool $addMissing,
    ): array
    {
        $disabled = (new ProjectCertificateGenerationStore())->disabledCertificates();
        foreach ($disabled as $domain => $fact) {
            $domain = $this->normalizeGatewayFactDomain((string)$domain, false);
            $existing = \is_array($map[$domain] ?? null) ? $map[$domain] : null;
            if ($existing === null && !$addMissing) {
                continue;
            }
            if ($existing !== null
                && \trim((string)($existing['cert'] ?? '')) !== ''
                && \trim((string)($existing['key'] ?? '')) !== ''
            ) {
                // A valid source fact is an explicit re-enable candidate. The
                // generation store will allocate strictly above this tombstone
                // before the route can become active.
                continue;
            }
            $generation = (int)($fact['generation'] ?? 0);
            $sourceDigest = \strtolower(\trim((string)(
                $fact['source_digest'] ?? ''
            )));
            if ($generation < 1
                || !\hash_equals('disabled', (string)($fact['state'] ?? ''))
                || !\hash_equals($domain, (string)($fact['domain'] ?? ''))
                || !\hash_equals(
                    \hash(
                        'sha256',
                        "wls-disabled-certificate\0" . $domain . "\0" . $generation,
                    ),
                    $sourceDigest,
                )
            ) {
                throw new \RuntimeException(
                    'Project disabled-certificate route authority is inconsistent: ' . $domain,
                );
            }
            $map[$domain] = [
                'cert' => '',
                'key' => '',
                'chain' => '',
                'cert_type' => SslCertificate::CERT_TYPE_EXACT,
                'force_https' => false,
                'force_root_to_www' => false,
                'certificate_state' => 'disabled',
            ];
        }
        if (\count($map) > 256) {
            throw new \RuntimeException(
                'Complete gateway route facts exceed the 256-route protocol limit.',
            );
        }
        \ksort($map, SORT_STRING);
        return $map;
    }

    /**
     * @return array{present:bool,complete:bool,provider:string,domains:list<array<string,mixed>>}
     */
    private function requestGatewayRouteDomainFacts(): array
    {
        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $eventData = [
                'filter' => [
                    'edge_routable_only' => true,
                    'group_by_root' => false,
                ],
                'domains' => [],
                'route_authority_present' => false,
                'route_authority_complete' => false,
                'route_authority_provider' => '',
            ];
            $eventsManager->dispatch(
                'Weline_Server::integration::domain_list_requested',
                $eventData,
            );
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'Unable to read the complete project routable-domain authority.',
                0,
                $throwable,
            );
        }
        if (($eventData['route_authority_complete'] ?? false) !== true) {
            return [
                'present' => ($eventData['route_authority_present'] ?? false) === true,
                'complete' => false,
                'provider' => '',
                'domains' => [],
            ];
        }
        $provider = \trim((string)($eventData['route_authority_provider'] ?? ''));
        $domains = $eventData['domains'] ?? null;
        if ($provider === ''
            || !\is_array($domains)
            || !\array_is_list($domains)
            || \count($domains) > 256
        ) {
            throw new \RuntimeException(
                'Project routable-domain authority completeness envelope is invalid.',
            );
        }
        $seen = [];
        $validated = [];
        foreach ($domains as $fact) {
            if (!\is_array($fact)
                || \array_is_list($fact)
                || !\hash_equals('website_domain', (string)($fact['source'] ?? ''))
            ) {
                throw new \RuntimeException(
                    'Project routable-domain authority returned a non-routing domain fact.',
                );
            }
            $domain = $this->normalizeGatewayFactDomain(
                (string)($fact['domain'] ?? ''),
                false,
            );
            if (isset($seen[$domain])) {
                throw new \RuntimeException(
                    'Project routable-domain authority returned a duplicate domain: ' . $domain,
                );
            }
            $seen[$domain] = true;
            $fact['domain'] = $domain;
            $validated[] = $fact;
        }
        \usort(
            $validated,
            static fn (array $left, array $right): int =>
                (string)$left['domain'] <=> (string)$right['domain'],
        );
        return [
            'present' => true,
            'complete' => true,
            'provider' => $provider,
            'domains' => $validated,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function buildCertificateMap(array $certificateRoots, bool $strictGatewayFacts): array
    {
        if (!$strictGatewayFacts) {
            $this->reconcileCertificateFiles();
        }

        $query = $this->certificateModel()->clearQuery()
            ->where(SslCertificate::schema_fields_HTTPS_ENABLED, 1);
        if (!$strictGatewayFacts) {
            $query->where(SslCertificate::schema_fields_STATUS, SslCertificate::STATUS_ACTIVE);
        } else {
            $query->where(
                SslCertificate::schema_fields_STATUS,
                SslCertificate::STATUS_REVOKED,
                '!=',
            )->limit(257);
        }
        $certificates = $query->select()->fetchIterator();
        if ($strictGatewayFacts) {
            $bounded = [];
            foreach ($certificates as $certificate) {
                $bounded[] = $certificate;
                if (\count($bounded) > 256) {
                    throw new \RuntimeException(
                        'Complete gateway certificate facts exceed the 256-route protocol limit.'
                    );
                }
            }
            // Enforce the route cardinality before certificate activation can
            // create snapshots or advance a project generation.
            $certificates = $bounded;
        }
        
        $map = [];
        $missingCerts = [];  // 记录证书文件缺失的域名
        $expiredCerts = [];  // 记录已过期的证书
        
        foreach ($certificates as $cert) {
            $cert = \is_array($cert) ? $cert : (\method_exists($cert, 'getData') ? $cert->getData() : []);
            $domain = (string)($cert[SslCertificate::schema_fields_DOMAIN] ?? '');
            $recordStatus = (string)($cert[SslCertificate::schema_fields_STATUS] ?? '');
            // HTTPS_ENABLED is the desired-route fact. Only an explicit
            // revocation (or HTTPS disabled by the query) means deletion.
            // Pending/error/expired rows remain present with empty material so
            // first-issue ACME and transient renewal failures cannot remove a
            // route from the complete desired state.
            if ($strictGatewayFacts
                && $recordStatus === SslCertificate::STATUS_REVOKED
            ) {
                continue;
            }

            // 0.0.0.0 只是"监听所有网卡"的绑定地址，不是真实域名，跳过
            if ($domain === '0.0.0.0') {
                continue;
            }
            if ($strictGatewayFacts) {
                $domain = $this->normalizeGatewayFactDomain($domain, true);
            } else {
                try {
                    $domain = self::normalizeCertificateStorageDomain($domain);
                } catch (\Throwable) {
                    continue;
                }
            }

            $certPath = (string)($cert[SslCertificate::schema_fields_CERT_PATH] ?? '');
            $keyPath = (string)($cert[SslCertificate::schema_fields_KEY_PATH] ?? '');
            $certType = (string)($cert[SslCertificate::schema_fields_CERT_TYPE] ?? SslCertificate::CERT_TYPE_EXACT);
            $certId = (int)($cert[SslCertificate::schema_fields_ID] ?? 0);
            $expiresAt = (string)($cert[SslCertificate::schema_fields_EXPIRES_AT] ?? '');
            $expiresTimestamp = $expiresAt !== '' ? \strtotime($expiresAt) : false;
            $isExpired = $recordStatus === SslCertificate::STATUS_EXPIRED
                || (\is_int($expiresTimestamp) && $expiresTimestamp < \time());
            $forceHttps = $this->certificatePolicyValue(
                $cert[SslCertificate::schema_fields_FORCE_HTTPS] ?? 1,
                true,
                $strictGatewayFacts,
                'force_https',
                $domain,
            );
            $forceRootToWww = $this->certificatePolicyValue(
                $cert[SslCertificate::schema_fields_FORCE_ROOT_TO_WWW] ?? 0,
                false,
                $strictGatewayFacts,
                'force_root_to_www',
                $domain,
            );

            if ($strictGatewayFacts
                && ($isExpired || $recordStatus !== SslCertificate::STATUS_ACTIVE)
            ) {
                $expiredCerts[] = [
                    'domain' => $domain,
                    'expires_at' => $expiresAt,
                    'cert_id' => $certId,
                ];
                $this->appendCertificateMapEntries($map, $domain, $certType, [
                    'cert' => '',
                    'key' => '',
                    'chain' => '',
                    'cert_type' => $certType,
                    'force_https' => $forceHttps,
                    'force_root_to_www' => $forceRootToWww,
                ], true);
                continue;
            }

            // 检查证书文件是否存在；若 DB 路径为空/失效，先尝试从标准目录自动探测并回写路径
            if ($certPath === ''
                || $keyPath === ''
                || self::readRegularFileNoFollow($certPath) === null
                || self::readPrivateKeyFileNoFollow($keyPath) === null
                || !self::certificateFilePairIsValidForName(
                    $certPath,
                    $keyPath,
                    $domain,
                )
                || ($certificateRoots !== []
                    && !$this->certificatePathsInsideRoots(
                        [$certPath, $keyPath],
                        $certificateRoots,
                    ))
            ) {
                [$resolvedCertPath, $resolvedKeyPath] = $this->resolveCertificateFilePaths(
                    $domain,
                    $certPath,
                    $keyPath,
                    $certificateRoots,
                );
                if ($resolvedCertPath !== '' && $resolvedKeyPath !== '') {
                    $certPath = $resolvedCertPath;
                    $keyPath = $resolvedKeyPath;
                    $cert[SslCertificate::schema_fields_CERT_PATH] = $certPath;
                    $cert[SslCertificate::schema_fields_KEY_PATH] = $keyPath;
                    $resolvedChainPath = \dirname($certPath) . DS . 'chain.pem';
                    $cert[SslCertificate::schema_fields_CHAIN_PATH]
                        = self::certificateBundleFileIsValid($resolvedChainPath)
                            ? $resolvedChainPath
                            : '';

                    if (!$strictGatewayFacts) {
                        // Legacy interactive discovery may reconcile the DB.
                        // Agent desired-state reads are observation-only.
                        try {
                            $certModel = \Weline\Framework\Manager\ObjectManager::getInstance(SslCertificate::class, [], false);
                            $certModel->load($certId);
                            if ($certModel->getCertId()) {
                                $certModel->setCertPath($certPath)
                                    ->setKeyPath($keyPath)
                                    ->setChainPath((string)$cert[SslCertificate::schema_fields_CHAIN_PATH])
                                    ->setStatus(SslCertificate::STATUS_ACTIVE)
                                    ->setRenewError('')
                                    ->save();
                            }
                        } catch (\Throwable $e) {
                            w_log_warning('[SslCertificateService] 自动回写证书路径失败: ' . $e->getMessage());
                        }
                    }
                }
            }

            // 标准目录探测后仍不可用时，再从证书管理（整行 PEM / 等价 localhost 记录）恢复
            if ($certPath === ''
                || $keyPath === ''
                || self::readRegularFileNoFollow($certPath) === null
                || self::readPrivateKeyFileNoFollow($keyPath) === null
                || !self::certificateFilePairIsValidForName(
                    $certPath,
                    $keyPath,
                    $domain,
                )
            ) {
                if (!$strictGatewayFacts
                    && !$isExpired
                    && $this->tryRestoreCertificateFromManagement($certId, $domain, $cert)
                ) {
                    $restoredDir = $this->getCertificateDir((string) $domain);
                    $certPath = $restoredDir . 'fullchain.pem';
                    $keyPath = $restoredDir . 'privkey.pem';
                    $cert[SslCertificate::schema_fields_CERT_PATH] = $certPath;
                    $cert[SslCertificate::schema_fields_KEY_PATH] = $keyPath;
                    $cert[SslCertificate::schema_fields_CHAIN_PATH]
                        = self::certificateBundleFileIsValid($restoredDir . 'chain.pem')
                            ? $restoredDir . 'chain.pem'
                            : '';
                } elseif ($isExpired) {
                    $expiredCerts[] = [
                        'domain' => $domain,
                        'expires_at' => $expiresAt,
                        'cert_id' => $certId,
                    ];
                    if (!$strictGatewayFacts) {
                        continue;
                    }
                } else {
                    $missingCerts[] = [
                        'domain' => $domain,
                        'expires_at' => $expiresAt,
                        'cert_id' => $certId,
                        'cert_path' => $certPath,
                        'key_path' => $keyPath,
                    ];
                    if (!$strictGatewayFacts) {
                        continue;
                    }
                }
                if ($strictGatewayFacts) {
                    $this->appendCertificateMapEntries($map, $domain, $certType, [
                        'cert' => '',
                        'key' => '',
                        'chain' => '',
                        'cert_type' => $certType,
                        'force_https' => $forceHttps,
                        'force_root_to_www' => $forceRootToWww,
                    ], true);
                    continue;
                }
            }

            $chainPath = (string)($cert[SslCertificate::schema_fields_CHAIN_PATH] ?? '');
            if ($chainPath !== '' && (!self::certificateBundleFileIsValid($chainPath)
                || ($certificateRoots !== []
                    && !$this->certificatePathsInsideRoots([$chainPath], $certificateRoots)))
            ) {
                $localChain = \dirname($certPath) . DS . 'chain.pem';
                $chainPath = self::certificateBundleFileIsValid($localChain)
                    && ($certificateRoots === []
                        || $this->certificatePathsInsideRoots([$localChain], $certificateRoots))
                        ? $localChain
                        : '';
            }
            $certData = [
                'cert' => $certPath,
                'key' => $keyPath,
                'chain' => $chainPath,
                'cert_type' => $certType,
                'force_https' => $forceHttps,
                'force_root_to_www' => $forceRootToWww,
            ];
            
            $this->appendCertificateMapEntries(
                $map,
                (string)$domain,
                $certType,
                $certData,
                $strictGatewayFacts,
            );
        }
        
        // 发出证书缺失通知
        if (!$strictGatewayFacts && !empty($missingCerts)) {
            $this->notifyMissingCertificates($missingCerts);
        }
        
        // 发出证书过期通知
        if (!$strictGatewayFacts && !empty($expiredCerts)) {
            $this->notifyExpiredCertificates($expiredCerts);
        }

        // 兼容 SNI map（非 Edge 严格事实）需要精确键：浏览器 https://127.0.0.1
        // 的 ClientHello SNI=127.0.0.1。Gateway 严格事实不得自动制造 IP 路由——
        // 可能撞上已 tombstone/disabled 的 127.0.0.1 证书记录并阻断启动。
        // WLS 2.0 Worker 对 localhost↔127.0.0.1/::1 在 routeForHost 侧做回退。
        if (!$strictGatewayFacts) {
            $this->expandLoopbackCertificateMapAliases($map, false);
        }

        return $map;
    }

    /**
     * Ensure localhost / 127.0.0.1 / ::1 share one SNI map entry when any of them
     * (or a covering leaf) is already published.
     *
     * @param array<string,array<string,mixed>> $map
     */
    private function expandLoopbackCertificateMapAliases(array &$map, bool $strictGatewayFacts): void
    {
        $aliases = self::PROTECTED_LOCAL_DOMAINS;
        $sourceKey = null;
        $source = null;

        foreach ($aliases as $key) {
            if (!isset($map[$key]) || !\is_array($map[$key])) {
                continue;
            }
            $candidate = $map[$key];
            $sourceKey = $key;
            $source = $candidate;
            if (\trim((string)($candidate['cert'] ?? '')) !== ''
                && \trim((string)($candidate['key'] ?? '')) !== ''
            ) {
                break;
            }
        }

        if ($source === null) {
            foreach ($map as $domain => $entry) {
                if (!\is_array($entry)) {
                    continue;
                }
                $certPath = \trim((string)($entry['cert'] ?? ''));
                $keyPath = \trim((string)($entry['key'] ?? ''));
                if ($certPath === '' || $keyPath === '') {
                    continue;
                }
                if ($this->certificateMatchesHost($certPath, '127.0.0.1')
                    || $this->certificateMatchesHost($certPath, 'localhost')
                    || $this->certificateMatchesHost($certPath, '::1')
                ) {
                    $sourceKey = (string)$domain;
                    $source = $entry;
                    break;
                }
            }
        }

        if ($source === null || $sourceKey === null) {
            return;
        }

        foreach ($aliases as $alias) {
            if ($alias === $sourceKey) {
                continue;
            }
            if (!isset($map[$alias]) || !\is_array($map[$alias])) {
                $map[$alias] = $source;
                continue;
            }
            if ($this->certificateMapEntryEquivalent((array)$map[$alias], $source)) {
                continue;
            }
            if ($strictGatewayFacts) {
                throw new \RuntimeException(
                    'Loopback certificate map aliases conflict for domain: ' . $alias
                );
            }
        }
    }

    /**
     * 解析证书文件路径（优先使用现有路径，其次探测标准目录下常见文件名）。
     *
     * @return array{0:string,1:string} [certPath,keyPath]
     */
    protected function resolveCertificateFilePaths(
        string $domain,
        string $certPath,
        string $keyPath,
        array $certificateRoots = [],
    ): array
    {
        if ($certPath !== ''
            && $keyPath !== ''
            && self::certificateFilePairIsValidForName($certPath, $keyPath, $domain)
            && ($certificateRoots === []
                || $this->certificatePathsInsideRoots(
                    [$certPath, $keyPath],
                    $certificateRoots,
                ))
        ) {
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
            foreach (self::certificateStorageSegmentCandidatesForProbe($dirName) as $segment) {
                $certDir = $this->certificateDirectoryForSegment($segment, false);
                if ($certDir === null) {
                    continue;
                }
                foreach ($fileCandidates as $candidate) {
                    $candidateCertPath = $certDir . $candidate['cert'];
                    $candidateKeyPath = $certDir . $candidate['key'];
                    if (self::certificateFilePairIsValidForName(
                        $candidateCertPath,
                        $candidateKeyPath,
                        $domain,
                    )
                        && ($certificateRoots === []
                            || $this->certificatePathsInsideRoots(
                                [$candidateCertPath, $candidateKeyPath],
                                $certificateRoots,
                            ))
                    ) {
                        return [$candidateCertPath, $candidateKeyPath];
                    }
                }
            }
        }

        return ['', ''];
    }

    /**
     * @param list<string> $paths
     * @param array<int|string,string> $roots
     */
    private function certificatePathsInsideRoots(array $paths, array $roots): bool
    {
        $canonicalRoots = [];
        foreach ($roots as $root) {
            $root = (string)$root;
            if ($root === '' || \str_contains($root, "\0")) {
                continue;
            }
            $canonical = \realpath($root);
            $status = @\lstat($root);
            if (!\is_string($canonical)
                || $canonical === ''
                || !\is_array($status)
                || (((int)($status['mode'] ?? 0)) & 0170000) !== 0040000
                || \is_link($root)
                || !self::sameFilesystemPath($root, $canonical)
                || self::filesystemPathIsRoot($canonical)
            ) {
                continue;
            }
            $canonicalRoots[] = \str_replace('\\', '/', \rtrim($canonical, '/\\'));
        }
        if ($canonicalRoots === []) {
            return false;
        }
        foreach ($paths as $path) {
            $real = \realpath($path);
            $status = @\lstat($path);
            if (!\is_string($real)
                || !\is_array($status)
                || (((int)($status['mode'] ?? 0)) & 0170000) !== 0100000
                || (int)($status['nlink'] ?? 0) !== 1
                || \is_link($path)
                || !self::sameFilesystemPath($path, $real)
            ) {
                return false;
            }
            $real = \str_replace('\\', '/', \rtrim($real, '/\\'));
            $matched = false;
            foreach ($canonicalRoots as $root) {
                $candidate = \PHP_OS_FAMILY === 'Windows' ? \strtolower($real) : $real;
                $allowed = \PHP_OS_FAMILY === 'Windows' ? \strtolower($root) : $root;
                if ($candidate === $allowed || \str_starts_with($candidate, $allowed . '/')) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }
        return true;
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

    private function certificatePolicyValue(
        mixed $value,
        bool $default,
        bool $strictGatewayFacts,
        string $field,
        string $domain,
    ): int|bool {
        if (!$strictGatewayFacts) {
            return (int)$value;
        }
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (\is_string($value) && ($value === '0' || $value === '1')) {
            return $value === '1';
        }
        if ($value === null) {
            return $default;
        }
        throw new \RuntimeException(
            'Certificate policy ' . $field . ' is not a canonical boolean: ' . $domain,
        );
    }

    protected function appendCertificateMapEntries(
        array &$map,
        string $domain,
        string $certType,
        array $certData,
        bool $strictGatewayFacts = false,
    ): void
    {
        if ($strictGatewayFacts && isset($map[$domain])) {
            if (!$this->certificateMapEntryEquivalent((array)$map[$domain], $certData)) {
                throw new \RuntimeException(
                    'Complete gateway certificate facts contain a conflicting domain: ' . $domain
                );
            }
        } else {
            $map[$domain] = $certData;
        }

        if ($certType !== SslCertificate::CERT_TYPE_WILDCARD || !\str_starts_with($domain, '*.')) {
            return;
        }

        // Edge Protocol 2 and the Controller route matcher understand a
        // wildcard SNI route directly. DomainPool expansion is legacy-only: it
        // couples desired-state reads to another module, can exceed the 256
        // route bound, and can manufacture conflicts with explicit exact rows.
        if ($strictGatewayFacts) {
            return;
        }

        $rootDomain = \substr($domain, 2);

        try {
            $subdomains = w_query('websites', 'getDomainPoolList', [
                'status' => 'active',
                'root_domain' => $rootDomain,
                'limit' => 2000,
            ]);
            if (!\is_array($subdomains) || \count($subdomains) > 2000) {
                if ($strictGatewayFacts) {
                    throw new \RuntimeException(
                        'Wildcard DomainPool returned an invalid or oversized result.'
                    );
                }
                $subdomains = [];
            }

            $seenSubdomains = [];
            foreach ($subdomains as $row) {
                if (!\is_array($row)) {
                    if ($strictGatewayFacts) {
                        throw new \RuntimeException(
                            'Wildcard DomainPool returned a malformed domain row.'
                        );
                    }
                    continue;
                }
                $rawSubdomain = $row['domain'] ?? null;
                if ($strictGatewayFacts && !\is_string($rawSubdomain)) {
                    throw new \RuntimeException(
                        'Wildcard DomainPool row has no canonical domain string.'
                    );
                }
                $subdomain = (string)$rawSubdomain;
                if ($strictGatewayFacts) {
                    $subdomain = $this->normalizeGatewayFactDomain($subdomain, false);
                    if (isset($seenSubdomains[$subdomain])) {
                        throw new \RuntimeException(
                            'Wildcard DomainPool contains a duplicate normalized domain.'
                        );
                    }
                    $seenSubdomains[$subdomain] = true;
                    if (isset($map[$subdomain])) {
                        if (!$this->certificateMapEntryEquivalent(
                            (array)$map[$subdomain],
                            $certData,
                        )) {
                            throw new \RuntimeException(
                                'Wildcard expansion conflicts with an explicit project domain: '
                                    . $subdomain
                            );
                        }
                        continue;
                    }
                }
                if ($subdomain !== '' && !isset($map[$subdomain])) {
                    $map[$subdomain] = $certData;
                }
            }
        } catch (\Throwable $e) {
            if ($strictGatewayFacts) {
                throw new \RuntimeException(
                    'Unable to build the complete wildcard project domain set.',
                    0,
                    $e,
                );
            }
            w_log_debug('[SslCertificateService] 获取 DomainPool 子域名失败: ' . $e->getMessage());
        }
    }

    private function normalizeGatewayFactDomain(string $domain, bool $allowWildcard): string
    {
        if ($domain === ''
            || \strlen($domain) > 255
            || \str_contains($domain, "\0")
            || !\hash_equals($domain, \trim($domain))
        ) {
            throw new \RuntimeException('Gateway certificate fact domain is malformed.');
        }
        $domain = \strtolower(\rtrim($domain, '.'));
        $wildcard = \str_starts_with($domain, '*.');
        if ($wildcard && !$allowWildcard) {
            throw new \RuntimeException('Wildcard expansion returned a wildcard domain.');
        }
        $body = $wildcard ? \substr($domain, 2) : $domain;
        if (\function_exists('idn_to_ascii')) {
            $variant = \defined('INTL_IDNA_VARIANT_UTS46')
                ? \constant('INTL_IDNA_VARIANT_UTS46')
                : 0;
            $ascii = @\idn_to_ascii($body, IDNA_DEFAULT, $variant);
            if (!\is_string($ascii) || $ascii === '') {
                throw new \RuntimeException('Gateway certificate fact IDNA conversion failed.');
            }
            $body = \strtolower($ascii);
        }
        // Local loopback fact keys (certificate map / website domain) are valid
        // without a public TLD; the FQDN regex below requires at least one dot.
        if ($body === 'localhost') {
            return $wildcard ? '*.' . $body : $body;
        }
        if (\filter_var($body, FILTER_VALIDATE_IP) !== false) {
            if (!$wildcard && (
                \str_starts_with($body, '127.')
                || $body === '::1'
            )) {
                $packed = @\inet_pton($body);
                $canonical = \is_string($packed) ? @\inet_ntop($packed) : false;
                if (\is_string($canonical) && $canonical !== '') {
                    return \strtolower($canonical);
                }
            }
            throw new \RuntimeException('Gateway certificate fact domain is outside protocol bounds.');
        }
        if (\strlen($body) > 253
            || \preg_match(
                '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)'
                    . '(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))+\z/D',
                $body,
            ) !== 1
        ) {
            throw new \RuntimeException('Gateway certificate fact domain is outside protocol bounds.');
        }
        return $wildcard ? '*.' . $body : $body;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function certificateMapEntryEquivalent(array $left, array $right): bool
    {
        foreach (['cert', 'key', 'chain', 'force_https', 'force_root_to_www'] as $field) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
                return false;
            }
        }
        return true;
    }

    protected function readCertificateContents(string $certPath, string $keyPath, string $chainPath = ''): array
    {
        $certPem = self::readRegularFileNoFollow($certPath) ?? '';
        $keyPem = self::readPrivateKeyFileNoFollow($keyPath) ?? '';
        $chainPem = $chainPath !== ''
            ? (self::readRegularFileNoFollow($chainPath, self::MAX_CERTIFICATE_MATERIAL_BYTES, true) ?? '')
            : '';

        if ($chainPem === '' && $certPem !== '') {
            $chainPem = $this->extractChainFromFullchain($certPem);
        }

        $csrPath = \dirname($certPath) . DS . 'csr.pem';
        $csrPem = self::readRegularFileNoFollow(
            $csrPath,
            self::MAX_CERTIFICATE_MATERIAL_BYTES,
            true,
        ) ?? '';

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

    /**
     * @return list<string>
     */
    protected function extractPemCertificates(string $pem): array
    {
        if (!\preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $matches)) {
            return [];
        }

        return \array_map(static fn (string $cert): string => \trim($cert), $matches[0]);
    }

    protected function parseCertificatePem(string $certPem): array|false
    {
        $certResource = @\openssl_x509_read($certPem);
        if ($certResource === false) {
            return false;
        }

        $parsed = @\openssl_x509_parse($certResource, false);

        return \is_array($parsed) ? $parsed : false;
    }

    protected function normalizeCertificateNameForComparison(array $name): string
    {
        \ksort($name);
        $parts = [];
        foreach ($name as $key => $value) {
            if (\is_array($value)) {
                \sort($value);
                $value = \implode(',', \array_map('strval', $value));
            }
            $parts[] = \strtolower((string) $key) . '=' . \strtolower(\trim((string) $value));
        }

        return \implode(';', $parts);
    }

    protected function isCertificateAuthorityPem(string $certPem): bool
    {
        $parsed = $this->parseCertificatePem($certPem);
        if (!$parsed) {
            return false;
        }

        $basicConstraints = (string) ($parsed['extensions']['basicConstraints'] ?? '');
        $keyUsage = (string) ($parsed['extensions']['keyUsage'] ?? '');

        return (bool) \preg_match('/(?:^|[,\s])CA\s*:\s*TRUE(?:$|[,\s])/i', $basicConstraints)
            && (
                \stripos($keyUsage, 'Certificate Sign') !== false
                || \stripos($keyUsage, 'keyCertSign') !== false
                || \stripos($keyUsage, 'CRL Sign') !== false
                || \stripos($keyUsage, 'cRLSign') !== false
            );
    }

    protected function isCertificateSelfSignedPem(string $certPem): bool
    {
        $parsed = $this->parseCertificatePem($certPem);
        if (!$parsed) {
            return false;
        }

        $subject = $parsed['subject'] ?? [];
        $issuer = $parsed['issuer'] ?? [];
        if (!\is_array($subject) || !\is_array($issuer)) {
            return false;
        }

        if ($this->normalizeCertificateNameForComparison($subject) !== $this->normalizeCertificateNameForComparison($issuer)) {
            return false;
        }

        $certResource = @\openssl_x509_read($certPem);
        if ($certResource === false) {
            return false;
        }

        $publicKey = @\openssl_pkey_get_public($certResource);
        if ($publicKey === false) {
            return false;
        }

        return @\openssl_x509_verify($certResource, $publicKey) === 1;
    }

    protected function isCertificateSelfSigned(string $certPath): bool
    {
        $certPem = self::readRegularFileNoFollow($certPath);
        if ($certPem === null) {
            return false;
        }
        $certs = $this->extractPemCertificates($certPem);
        if ($certs === []) {
            return false;
        }

        return $this->isCertificateSelfSignedPem($certs[0]);
    }

    protected function isCertificateAuthority(string $certPath): bool
    {
        $certPem = self::readRegularFileNoFollow($certPath);
        if ($certPem === null) {
            return false;
        }
        $certs = $this->extractPemCertificates($certPem);
        if ($certs === []) {
            return false;
        }

        return $this->isCertificateAuthorityPem($certs[0]);
    }

    protected function extractLocalCaPemFromCertificateBundle(string $fullchainPem, string $chainPem = ''): string
    {
        $candidates = \array_merge(
            $this->extractPemCertificates($chainPem),
            $this->extractPemCertificates($fullchainPem)
        );

        foreach ($candidates as $candidatePem) {
            if ($this->isCertificateAuthorityPem($candidatePem) && $this->isCertificateSelfSignedPem($candidatePem)) {
                return \trim($candidatePem) . "\n";
            }
        }

        return '';
    }

    protected function recoverAndTrustLocalCaFromCertificateBundle(
        string $provider,
        string $issuer,
        string $fullchainPem,
        string $chainPem = ''
    ): void {
        $provider = $this->normalizeAcmeProvider($provider);
        if ($provider !== self::PROVIDER_LOCAL_CA
            && !\str_contains(\strtolower($issuer), \strtolower(self::ISSUER_LOCAL_CA))) {
            return;
        }

        $localCaPem = $this->extractLocalCaPemFromCertificateBundle($fullchainPem, $chainPem);
        if ($localCaPem === '') {
            return;
        }

        $caCertPath = $this->getLocalCaCertPath();
        $existingPem = self::readRegularFileNoFollow(
            $caCertPath,
            self::MAX_CERTIFICATE_MATERIAL_BYTES,
            true,
        ) ?? '';
        if (\trim($existingPem) === \trim($localCaPem)) {
            return;
        }

        try {
            $this->writeLocalCaStateAtomically($caCertPath, $localCaPem, 0644);
        } catch (\Throwable $throwable) {
            w_log_warning(__('[SslCertificateService] Failed to recover local CA certificate to %{1}', [$caCertPath]), [], 'ssl_cert');
            return;
        }
        $this->trustLocalCertificateAuthority($caCertPath);
    }

    protected function restoreCertificateFilesFromData(array $cert): bool
    {
        try {
            $domain = self::normalizeCertificateStorageDomain(
                (string)($cert[SslCertificate::schema_fields_DOMAIN] ?? ''),
            );
        } catch (\Throwable) {
            return false;
        }
        $certPem = (string) ($cert[SslCertificate::schema_fields_CERT_PEM] ?? '');
        $keyPem = (string) ($cert[SslCertificate::schema_fields_KEY_PEM] ?? '');
        $chainPem = (string) ($cert[SslCertificate::schema_fields_CHAIN_PEM] ?? '');
        $csrPem = (string) ($cert[SslCertificate::schema_fields_CSR_PEM] ?? '');
        if ($certPem === ''
            || $keyPem === ''
            || \strlen($certPem) > self::MAX_CERTIFICATE_MATERIAL_BYTES
            || \strlen($keyPem) > self::MAX_CERTIFICATE_MATERIAL_BYTES
            || \strlen($chainPem) > self::MAX_CERTIFICATE_MATERIAL_BYTES
            || \strlen($csrPem) > self::MAX_CERTIFICATE_MATERIAL_BYTES
        ) {
            return false;
        }

        $leafPem = $this->extractLeafCertFromFullchain($certPem);
        if ($leafPem === ''
            || !self::certificatePemPairIsValidForName($certPem, $keyPem, $domain)
        ) {
            return false;
        }

        if ($chainPem === '') {
            $chainPem = $this->extractChainFromFullchain($certPem);
        }
        if (!self::certificateBundlePemIsValid($chainPem, true)) {
            return false;
        }

        try {
            $certDir = $this->getCertificateDir($domain);
            $this->writeCertificateFileAtomically(
                $certDir . 'fullchain.pem',
                $certPem,
                0644,
            );
            $this->writeCertificateFileAtomically($certDir . 'privkey.pem', $keyPem, 0600);
            $this->writeCertificateFileAtomically($certDir . 'cert.pem', $leafPem, 0644);
            $this->writeCertificateFileAtomically($certDir . 'domain.key', $keyPem, 0600);
            if ($chainPem !== '') {
                $this->writeCertificateFileAtomically($certDir . 'chain.pem', $chainPem, 0644);
            } else {
                $this->removeCertificateStateLeafSafely($certDir, 'chain.pem');
            }
            if ($csrPem !== '') {
                $this->writeCertificateFileAtomically($certDir . 'csr.pem', $csrPem, 0600);
            } else {
                $this->removeCertificateStateLeafSafely($certDir, 'csr.pem');
            }
        } catch (\Throwable $throwable) {
            w_log_error(
                '[SslCertificateService] 证书文件安全发布失败：' . $throwable->getMessage(),
            );
            return false;
        }

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
            return false;
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
        return (new ProjectCertificateGenerationStore())->withCertificateLifecycleLock(
            fn (): array => $this->reconcileCertificateFilesLocked(),
        );
    }

    /** @return array{written:int,updated:int,skipped:int,errors:array} */
    private function reconcileCertificateFilesLocked(): array
    {
        $result = [
            'written' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $certificates = $this->certificateModel()->clearQuery()
            ->where(SslCertificate::schema_fields_STATUS, SslCertificate::STATUS_ACTIVE)
            ->where(SslCertificate::schema_fields_HTTPS_ENABLED, 1)
            ->select()
            ->fetchIterator();

        foreach ($certificates as $row) {
            $row = \is_array($row) ? $row : (\method_exists($row, 'getData') ? $row->getData() : []);
            $domain = (string)($row[SslCertificate::schema_fields_DOMAIN] ?? '');
            $sourceCert = (string)($row[SslCertificate::schema_fields_CERT_PATH] ?? '');
            $sourceKey = (string)($row[SslCertificate::schema_fields_KEY_PATH] ?? '');
            if ($domain === '' || $domain === '0.0.0.0') {
                $result['skipped']++;
                continue;
            }

            try {
                $domain = self::normalizeCertificateStorageDomain($domain);
                $this->assertCertificateMutationNotBlockedByRetirement($domain);
                if ($this->isDomainSslIssuanceInProgressUnlocked($domain)) {
                    $result['skipped']++;
                    continue;
                }
            } catch (\Throwable $throwable) {
                $result['errors'][] = __('证书状态正在变更，已跳过同步 %{1}: %{2}', [
                    $domain,
                    $throwable->getMessage(),
                ]);
                continue;
            }

            try {
                $targetDir = $this->getCertificateDir($domain);
            } catch (\Throwable $throwable) {
                $result['errors'][] = __('证书目录无效 %{1}: %{2}', [$domain, $throwable->getMessage()]);
                continue;
            }
            $targetCert = $targetDir . 'fullchain.pem';
            $targetKey = $targetDir . 'privkey.pem';
            $targetExistsBefore = self::readRegularFileNoFollow($targetCert) !== null
                && self::readPrivateKeyFileNoFollow($targetKey) !== null;

            if ($sourceCert === ''
                || $sourceKey === ''
                || self::readRegularFileNoFollow($sourceCert) === null
                || self::readPrivateKeyFileNoFollow($sourceKey) === null
                || !self::certificateFilePairIsValidForName(
                    $sourceCert,
                    $sourceKey,
                    $domain,
                )
            ) {
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
                if (!self::certificateFilePairIsValidForName(
                    $targetCert,
                    $targetKey,
                    $domain,
                )) {
                    throw new \RuntimeException(
                        'Certificate target pair failed validation after reconciliation.',
                    );
                }
                if ($copiedCert || $copiedKey) {
                    $result[$targetExistsBefore ? 'updated' : 'written']++;
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
        $sslDir = $this->ensureCertificateBaseDirectory();
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

        foreach ($this->boundedDirectoryEntries(
            $sslDir,
            self::MAX_CERTIFICATE_SOURCE_DIRECTORIES,
            'project certificate source directory',
        ) as $dirName) {
            $entryStatus = @\lstat($sslDir . $dirName);
            $entryType = \is_array($entryStatus)
                ? (((int)($entryStatus['mode'] ?? 0)) & 0170000)
                : 0;
            if ($entryType === 0100000) {
                continue;
            }
            if ($entryType !== 0040000) {
                throw new \RuntimeException(
                    'Certificate source root contains a link or special entry: ' . $dirName,
                );
            }
            $domainDir = $this->certificateDirectoryForSegment($dirName, false);
            if ($domainDir === null) {
                continue;
            }
            $logicalDomain = self::logicalDomainFromStorageSegment($dirName);
            if ($logicalDomain === '') {
                continue;
            }
            if ($this->isDomainSslIssuanceInProgress($logicalDomain)) {
                $skipped++;
                continue;
            }
            $matched = false;
            foreach ($certFormats as $format) {
                $certPath = $domainDir . $format['cert'];
                $keyPath = $domainDir . $format['key'];
                if (self::readRegularFileNoFollow($certPath) !== null
                    && self::readPrivateKeyFileNoFollow($keyPath) !== null
                ) {
                    $matched = true;
                    if ($this->syncCertificateRecordFromFiles($logicalDomain, $certPath, $keyPath) instanceof SslCertificate) {
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

        // 兼容旧格式：app/etc 下直接放证书
        $defaultDomain = (string)(Env::get('wls.host') ?? 'localhost');
        if ($defaultDomain === '127.0.0.1' || $defaultDomain === '::1' || $defaultDomain === '0.0.0.0') {
            $defaultDomain = 'localhost';
        }
        $defaultDomain = \strtolower(\trim($defaultDomain));
        foreach ($certFormats as $format) {
            $certPath = $etcDir . $format['cert'];
            $keyPath = $etcDir . $format['key'];
            if (self::readRegularFileNoFollow($certPath) !== null
                && self::readPrivateKeyFileNoFollow($keyPath) !== null
            ) {
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
        $private = \in_array(\basename($target), ['privkey.pem', 'domain.key', 'account.key'], true);
        $sourceContents = $private
            ? self::readPrivateKeyFileNoFollow($source)
            : self::readRegularFileNoFollow($source);
        if ($sourceContents === null) {
            throw new \RuntimeException('Certificate copy source is unsafe or unavailable.');
        }
        $targetContents = $private
            ? self::readPrivateKeyFileNoFollow($target)
            : self::readRegularFileNoFollow($target);
        if ($targetContents !== null
            && \hash_equals(\hash('sha256', $sourceContents), \hash('sha256', $targetContents))
        ) {
            return false;
        }
        $this->writeCertificateFileAtomically(
            $target,
            $sourceContents,
            $private ? 0600 : 0644,
        );
        return true;
    }
    
    public const CHALLENGE_HTTP01 = 'http01';
    public const CHALLENGE_DNS01 = 'dns01';
    public const CHALLENGE_AUTO = 'auto';

    /**
     * Webroot 占位符：使用 WLS 虚拟 HTTP-01 校验（校验码存 generated/acme-http01，由 worker 直接响应）
     */
    public const WEBROOT_WLS_VIRTUAL = '__wls_virtual__';

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
     * @param bool $forceReissue true 时即使本地已有未过期证书也强制执行 ACME
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
        bool $forceReissue = false,
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
            if ($provider === self::PROVIDER_LOCAL_CA) {
                return $this->generateLocalCaSignedCertificate($domain, $websiteId);
            }
            if ($provider === self::PROVIDER_SELF_SIGNED) {
                return $this->generateSelfSignedCertificate($domain, $websiteId);
            }
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
            
            // 2. 准备内存中的证书记录（成功下载并校验写入磁盘后才 save 入库，避免颁发中途污染证书管理器）
            $normalizedDomain = \strtolower(\trim($domain));
            if ($normalizedDomain === '') {
                return ['success' => false, 'message' => __('域名不能为空'), 'cert' => null];
            }
            $cert = $this->certificateModel()->clearQuery()->loadByDomain($normalizedDomain);
            $loadedDomain = \strtolower(\trim((string) $cert->getDomain()));
            $hadPersistedRow = $cert->getCertId() > 0 && $loadedDomain === $normalizedDomain;
            $priorCertId = $hadPersistedRow ? (int) $cert->getCertId() : 0;
            $priorStatus = $hadPersistedRow ? (string) $cert->getStatus() : '';
            if (!$hadPersistedRow) {
                $cert = ObjectManager::getInstance(SslCertificate::class);
                $cert->clearData(true);
                $certType = \str_starts_with($normalizedDomain, '*.')
                    ? SslCertificate::CERT_TYPE_WILDCARD
                    : SslCertificate::CERT_TYPE_EXACT;
                $cert->setDomain($normalizedDomain)
                    ->setWebsiteId($websiteId)
                    ->setCertType($certType)
                    ->setProvider($provider)
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
            if (!$forceReissue
                && $this->isCertificateValid($certPath)
                && self::readPrivateKeyFileNoFollow($keyPath) !== null
            ) {
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

            if (!$this->acquireSslIssuanceLock($normalizedDomain)) {
                return [
                    'success' => false,
                    'message' => __('该域名正在申请证书中，请等待当前流程结束后再试。'),
                    'cert' => null,
                ];
            }
            try {
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
                    if (!self::certificatePemPairIsValidForName(
                        $certContents['cert_pem'],
                        $certContents['key_pem'],
                        $normalizedDomain,
                    )) {
                        return [
                            'success' => false,
                            'message' => __('证书落盘后的稳定校验失败，已拒绝替换当前有效证书'),
                            'cert' => $cert,
                        ];
                    }
                    // ACME 只生成 fullchain.pem + privkey.pem，补全 chain.pem + cert.pem 到磁盘
                    if ($certContents['chain_pem'] !== ''
                        && !self::certificateBundleFileIsValid($chainPath)
                    ) {
                        $this->writeCertificateFileAtomically(
                            $chainPath,
                            $certContents['chain_pem'],
                            0644,
                        );
                    }
                    $leafCertPath = $certDir . 'cert.pem';
                    if (self::readRegularFileNoFollow($leafCertPath) === null
                        && $certContents['cert_pem'] !== ''
                    ) {
                        $leafPem = $this->extractLeafCertFromFullchain($certContents['cert_pem']);
                        if ($leafPem !== '') {
                            $this->writeCertificateFileAtomically($leafCertPath, $leafPem, 0644);
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
                        ->setCertPath($certPath)
                        ->setKeyPath($keyPath)
                        ->setChainPath($chainPath)
                        ->setIssuedAt($certInfo['issued_at'] ?? \date('Y-m-d H:i:s'))
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
                    $cert->save();

                    if ($onProgress) {
                        $onProgress((string) __('证书管理记录已保存，cert_id=%{1}', [$cert->getCertId()]), ['cert_id' => $cert->getCertId()]);
                    }

                    // 重新生成证书映射文件（确保泛域名证书展开后子域名能正确匹配）
                    $this->regenerateCertificateMap();

                    // 只在完整服务清单及原生 Worker 确认后发送通知。
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
                }

                // 失败：正在使用的 active 记录不写库，避免续签/重申请失败把线上证书条目标成 error
                if ($priorCertId > 0 && $priorStatus === SslCertificate::STATUS_ACTIVE) {
                    $unchanged = $this->certificateModel()->clearQuery()->load($priorCertId);
                    $unchangedCert = ($unchanged->getCertId() === $priorCertId) ? $unchanged : null;
                    return [
                        'success' => false,
                        'message' => $result['message'],
                        'cert' => $unchangedCert,
                    ];
                }
                // 首次申请（库中无行）：失败不落库
                if ($priorCertId === 0) {
                    return ['success' => false, 'message' => $result['message'], 'cert' => null];
                }
                $cert->setStatus(SslCertificate::STATUS_ERROR)
                    ->setRenewError($result['message']);
                $cert = $this->resolveDuplicateDomainCert($cert);
                $cert->setDomain($normalizedDomain);
                $cert->save();
                return ['success' => false, 'message' => $result['message'], 'cert' => $cert];
            } finally {
                $this->releaseSslIssuanceLock($normalizedDomain);
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
        try {
            $domain = self::normalizeCertificateStorageDomain($domain);
        } catch (\Throwable) {
            return ['success' => false, 'message' => __('域名格式无效'), 'cert' => null];
        }
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
        if (\strlen($fullchainPem) > self::MAX_CERTIFICATE_MATERIAL_BYTES
            || \strlen($privateKeyPem) > self::MAX_CERTIFICATE_MATERIAL_BYTES
            || \strlen($chainPem) > self::MAX_CERTIFICATE_MATERIAL_BYTES
        ) {
            return ['success' => false, 'message' => __('证书材料超出安全大小限制'), 'cert' => null];
        }
        if (!self::certificatePemPairIsValidForName($fullchainPem, $privateKeyPem, $domain)
            || !self::certificateBundlePemIsValid($chainPem, true)
        ) {
            return [
                'success' => false,
                'message' => __('证书必须处于有效期内、覆盖目标域名并与私钥匹配'),
                'cert' => null,
            ];
        }

        try {
            $prepared = (new ProjectCertificateGenerationStore())
                ->withCertificateLifecycleLock(
                fn (): array => $this->importManualCertificateLocked(
                    $domain,
                    $fullchainPem,
                    $privateKeyPem,
                    $chainPem,
                    $websiteId,
                    $httpsEnabled,
                    $provider,
                ),
            );
            if (($prepared['success'] ?? false) !== true
                || !\hash_equals('prepared', (string)($prepared['phase'] ?? ''))
            ) {
                return $prepared;
            }
            return $this->completeManualCertificateImport($prepared);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('证书导入失败：%{1}', [$e->getMessage()]),
                'cert' => null,
            ];
        }
    }

    /** @return array{success:bool,message:string,cert:?SslCertificate,cert_id?:int} */
    private function importManualCertificateLocked(
        string $domain,
        string $fullchainPem,
        string $privateKeyPem,
        string $chainPem,
        int $websiteId,
        bool $httpsEnabled,
        string $provider,
    ): array {
        $this->assertCertificateMutationNotBlockedByRetirement($domain);
        $issuingMsg = $this->getSslIssuanceConflictMessage($domain);
        if ($issuingMsg !== '') {
            return ['success' => false, 'message' => $issuingMsg, 'cert' => null];
        }
        try {
            $certDir = $this->getCertificateDir($domain);
            $certPath = $certDir . 'fullchain.pem';
            $keyPath = $certDir . 'privkey.pem';
            $chainPath = $certDir . 'chain.pem';

            $this->writeCertificateFileAtomically($certPath, $fullchainPem, 0644);
            $this->writeCertificateFileAtomically($keyPath, $privateKeyPem, 0600);
            if ($chainPem !== '') {
                $this->writeCertificateFileAtomically($chainPath, $chainPem, 0644);
            } else {
                $this->removeCertificateStateLeafSafely($certDir, 'chain.pem');
            }

            $provider = \trim($provider) !== '' ? $provider : 'manual';
            $cert = $this->syncCertificateRecordFromFiles($domain, $certPath, $keyPath, $websiteId, $httpsEnabled, $provider);
            if (!$cert instanceof SslCertificate) {
                return ['success' => false, 'message' => __('证书文件已写入，但同步证书记录失败'), 'cert' => null];
            }

            $certInfo = $this->parseCertificate($certPath);
            return [
                'success' => true,
                'phase' => 'prepared',
                'message' => __('证书材料已安全写入，等待服务发布'),
                'cert' => $cert,
                'cert_id' => $cert->getCertId(),
                'domain' => $domain,
                'cert_path' => $certPath,
                'key_path' => $keyPath,
                'issuer' => (string)($certInfo['issuer'] ?? $cert->getIssuer()),
                'expires_at' => (string)($certInfo['expires_at'] ?? $cert->getExpiresAt()),
                'cert_type' => (string)$cert->getCertType(),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => __('证书导入失败：%{1}', [$e->getMessage()]), 'cert' => null];
        }
    }

    /** @param array<string,mixed> $prepared */
    private function completeManualCertificateImport(array $prepared): array
    {
        try {
            // File import is material publication, not re-enable authority.
            // A tombstoned domain therefore remains blocked here until the
            // independent explicit HTTPS-enable lifecycle issues its intent.
            $this->regenerateCertificateMap();
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'phase' => 'serving_publication',
                'message' => (string)__(
                    '证书材料已导入，但服务发布失败；已禁用的域名必须单独显式启用 HTTPS：%{1}',
                    [$throwable->getMessage()],
                ),
                'cert' => $prepared['cert'] ?? null,
                'cert_id' => (int)($prepared['cert_id'] ?? 0),
            ];
        }

        $this->dispatchCertificateIssuedEvent(
            (string)$prepared['domain'],
            (int)$prepared['cert_id'],
            (string)$prepared['cert_path'],
            (string)$prepared['key_path'],
            (string)$prepared['issuer'],
            (string)$prepared['expires_at'],
            (string)$prepared['cert_type'],
        );
        return [
            'success' => true,
            'phase' => 'complete',
            'message' => __('证书导入成功'),
            'cert' => $prepared['cert'] ?? null,
            'cert_id' => (int)$prepared['cert_id'],
        ];
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
        try {
            $wildcardDomain = self::normalizeCertificateStorageDomain($wildcardDomain);
        } catch (\Throwable) {
            return;
        }
        if (!\str_starts_with($wildcardDomain, '*.')) {
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
        if ($certPem === ''
            || $keyPem === ''
            || \strlen($certPem) > self::MAX_CERTIFICATE_MATERIAL_BYTES
            || \strlen($keyPem) > self::MAX_CERTIFICATE_MATERIAL_BYTES
            || \strlen($chainPem) > self::MAX_CERTIFICATE_MATERIAL_BYTES
            || \strlen($csrPem) > self::MAX_CERTIFICATE_MATERIAL_BYTES
            || !self::certificatePemPairIsValidForName(
                $certPem,
                $keyPem,
                $wildcardDomain,
            )
            || !self::certificateBundlePemIsValid($chainPem, true)
        ) {
            return;
        }

        $subCerts = ObjectManager::getInstance(SslCertificate::class, [], false)
            ->clearQuery()
            ->where(SslCertificate::schema_fields_CERT_TYPE, SslCertificate::CERT_TYPE_EXACT)
            ->where(SslCertificate::schema_fields_STATUS, SslCertificate::STATUS_ACTIVE)
            ->select()
            ->fetchIterator();

        $now = \date('Y-m-d H:i:s');
        $synced = 0;

        foreach ($subCerts as $row) {
            $row = \is_array($row) ? $row : (\method_exists($row, 'getData') ? $row->getData() : []);
            try {
                $subDomain = self::normalizeCertificateStorageDomain(
                    (string)($row[SslCertificate::schema_fields_DOMAIN] ?? ''),
                );
            } catch (\Throwable) {
                continue;
            }
            if (!\str_ends_with($subDomain, '.' . $rootDomain)) {
                continue;
            }
            $parts = \explode('.', $subDomain);
            if (\count($parts) < 3) {
                continue;
            }

            if (!self::certificatePemPairIsValidForName($certPem, $keyPem, $subDomain)) {
                continue;
            }
            try {
                $didSync = (new ProjectCertificateGenerationStore())
                    ->withCertificateLifecycleLock(function () use (
                        $row,
                        $subDomain,
                        $certPem,
                        $keyPem,
                        $chainPem,
                        $csrPem,
                        $wildcardCert,
                        $wildcardDomain,
                        $now,
                    ): bool {
                        $this->assertCertificateMutationNotBlockedByRetirement(
                            $wildcardDomain,
                        );
                        $this->assertCertificateMutationNotBlockedByRetirement(
                            $subDomain,
                        );
                        $currentWildcard = ObjectManager::getInstance(
                            SslCertificate::class,
                            [],
                            false,
                        );
                        $currentWildcard->load((int)$wildcardCert->getCertId());
                        if (!$currentWildcard->getCertId()
                            || !\hash_equals(
                                $wildcardDomain,
                                self::normalizeCertificateStorageDomain(
                                    $currentWildcard->getDomain(),
                                ),
                            )
                            || !\hash_equals(
                                SslCertificate::STATUS_ACTIVE,
                                $currentWildcard->getStatus(),
                            )
                            || !\hash_equals($certPem, $currentWildcard->getCertPem())
                            || !\hash_equals($keyPem, $currentWildcard->getKeyPem())
                            || !\hash_equals($chainPem, $currentWildcard->getChainPem())
                            || !\hash_equals($csrPem, $currentWildcard->getCsrPem())
                        ) {
                            return false;
                        }
                        $subCertModel = ObjectManager::getInstance(
                            SslCertificate::class,
                            [],
                            false,
                        );
                        $subCertModel->load((int)($row[
                            SslCertificate::schema_fields_ID
                        ] ?? 0));
                        if (!$subCertModel->getCertId()
                            || !\hash_equals(
                                $subDomain,
                                self::normalizeCertificateStorageDomain(
                                    $subCertModel->getDomain(),
                                ),
                            )
                            || !\hash_equals(
                                SslCertificate::STATUS_ACTIVE,
                                $subCertModel->getStatus(),
                            )
                        ) {
                            return false;
                        }
                        $subCertModel->setCertPem($certPem)
                            ->setKeyPem($keyPem)
                            ->setChainPem($chainPem)
                            ->setCsrPem($csrPem)
                            ->setIssuer($currentWildcard->getIssuer())
                            ->setProvider($currentWildcard->getProvider())
                            ->setIssuedAt($currentWildcard->getIssuedAt())
                            ->setExpiresAt($currentWildcard->getExpiresAt())
                            ->setUpdatedAt($now);

                        $stagedData = $subCertModel->getData();
                        $stagedData[SslCertificate::schema_fields_ID] = 0;
                        if (!$this->restoreCertificateFilesFromData($stagedData)) {
                            return false;
                        }
                        $certDir = $this->getCertificateDir($subDomain);
                        $subCertModel->setCertPath($certDir . 'fullchain.pem')
                            ->setKeyPath($certDir . 'privkey.pem')
                            ->setChainPath($chainPem !== '' ? $certDir . 'chain.pem' : '')
                            ->save();
                        return true;
                    });
                if ($didSync) {
                    ++$synced;
                }
            } catch (\Throwable $throwable) {
                w_log_warning(
                    '[SslCertificateService] wildcard subdomain sync deferred for '
                        . $subDomain . ': ' . $throwable->getMessage(),
                );
            }
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
        $certificates = $this->certificateModel()->getCertificatesNeedRenew($days);
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
        if (self::readPrivateKeyFileNoFollow($this->accountKeyPath) !== null) {
            return true;
        }
        if (\file_exists($this->accountKeyPath) || \is_link($this->accountKeyPath)) {
            return false;
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
        try {
            $this->writeCertificateFileAtomically($this->accountKeyPath, $privateKey, 0600);
            return true;
        } catch (\Throwable) {
            return false;
        }
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
            if ($webroot === self::WEBROOT_WLS_VIRTUAL || $this->canUseHttp01Challenge()) {
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
                        : $this->performHttp01Challenge($auth, $authUrl, $accountUrl, $domain, $webroot, $onProgress);

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

            $privateKeyPem = self::readPrivateKeyFileNoFollow($domainKeyPath);
            if ($privateKeyPem === null) {
                return ['success' => false, 'message' => __('域名私钥文件不安全或不可读')];
            }
            if (\strlen($certPem) > self::MAX_CERTIFICATE_MATERIAL_BYTES
                || !self::certificatePemPairIsValidForName($certPem, $privateKeyPem, $domain)
            ) {
                return [
                    'success' => false,
                    'message' => __('CA 返回的证书无效、未覆盖目标域名或与本地私钥不匹配'),
                ];
            }
            $this->writeCertificateFileAtomically($fullchainPath, $certPem, 0644);
            $this->writeCertificateFileAtomically($privkeyPath, $privateKeyPem, 0600);

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
        string $domain,
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

        $useWlsVirtual = $webroot === '' || $webroot === self::WEBROOT_WLS_VIRTUAL;
        $token = $httpChallenge['token'];

        $onProg(__('正在创建 HTTP-01 验证文件'), ['progress' => 35]);
        if ($useWlsVirtual) {
            $thumbprint = $this->getAccountThumbprint();
            $keyAuth = $token . '.' . $thumbprint;
            if (!$this->registerWlsHttp01Challenge($domain, $token, $keyAuth)) {
                return ['validated' => false, 'error' => __('登记 WLS 虚拟校验失败')];
            }
        } else {
            if (!$this->createHttpChallenge($webroot, $token, $token)) {
                return ['validated' => false, 'error' => __('创建验证文件失败')];
            }
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
        if ($useWlsVirtual) {
            $this->cleanupWlsHttp01Challenge($domain);
        } else {
            $this->cleanupHttpChallenge($webroot, $token);
        }

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
        // 轮询 FQDN 须与 Websites addAcmeTxtRecord 写入一致（通配符 *.example.com 为 _acme-challenge.example.com，而非 _acme-challenge.*.example.com）
        $txtFqdn = '_acme-challenge.' . \strtolower(\trim($domain));
        try {
            $fqProbe = w_query('websites', 'getAcmeChallengeTxtFqdn', [
                'domain' => $domain,
                'pool_id' => $poolId,
                'domain_id' => $domainId,
            ]);
            if (\is_array($fqProbe) && !empty($fqProbe['success']) && \trim((string) ($fqProbe['txt_fqdn'] ?? '')) !== '') {
                $txtFqdn = \strtolower(\trim((string) $fqProbe['txt_fqdn']));
            }
        } catch (\Throwable) {
            // 未升级 Websites 或无该 operation 时回退简单拼接
        }
        $txtPoll = $this->getAcmeDnsTxtPollConfig();
        $pollMaxSeconds = (int) ($txtPoll['max_seconds'] ?? 900);
        $pollIntervalSeconds = (int) ($txtPoll['interval_seconds'] ?? 10);
        if ($dnsProviderCode === 'gname' && isset($txtPoll['max_seconds_gname'])) {
            $pollMaxSeconds = (int) $txtPoll['max_seconds_gname'];
        } elseif ($dnsProviderCode === 'cloudflare' && isset($txtPoll['max_seconds_cloudflare'])) {
            $pollMaxSeconds = (int) $txtPoll['max_seconds_cloudflare'];
        }
        $pollMaxSeconds = \max(30, $pollMaxSeconds);
        $pollIntervalSeconds = \max(3, $pollIntervalSeconds);
        $allowPublicDoh = (bool) ($txtPoll['visible_use_public_doh'] ?? false);
        $txtVisible = false;
        if ($onProgress) {
            $onProgress(
                $allowPublicDoh
                    ? (string) __(
                        '检查 TXT 是否已传播（先本机 dns_get_record，必要时公共 DNS；最多 %{1} 秒，间隔 %{2} 秒）',
                        [(string) $pollMaxSeconds, (string) $pollIntervalSeconds]
                    )
                    : (string) __(
                        '检查 TXT 是否已传播（循环 dns_get_record，最多 %{1} 秒，间隔 %{2} 秒；与证书机构查询路径可能不同，宜留足时间）',
                        [(string) $pollMaxSeconds, (string) $pollIntervalSeconds]
                    ),
                ['step' => 'dns_visibility', 'poll_max' => $pollMaxSeconds, 'dns_get_record_only' => !$allowPublicDoh]
            );
        }
        $elapsed = 0;
        while ($elapsed <= $pollMaxSeconds) {
            $txtVisible = $this->isAcmeTxtVisible($txtFqdn, $challengeValue, $allowPublicDoh);
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
            $err = (string)__(
                '在最长 %{1} 秒内 dns_get_record%{2}仍未查到验证 TXT。可能未写入成功、传播未结束或本机解析器缓存滞后；可加大 env websites.acme_dns.txt_poll_max_seconds 后重试。',
                [
                    (string) $pollMaxSeconds,
                    $allowPublicDoh ? (string) __('（及公共 DNS 探测）') : '',
                ]
            );
            if ($onProgress) {
                $onProgress($err, ['step' => 'dns_visibility_fail']);
            }
            return ['validated' => false, 'error' => $err];
        }
        // 本机已能 dns_get_record 到 TXT（及可选 DoH）后再通知 CA；CA 全球路径仍可能不同，后续另有 CA 轮询
        if ($onProgress) {
            $onProgress(
                $allowPublicDoh
                    ? (string) __('TXT 已可解析（本机或公共 DNS 探测），正在通知 CA 验证...')
                    : (string) __('本机 dns_get_record 已能查到验证 TXT，正在通知 CA 验证...'),
                ['step' => 'notify_ca']
            );
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
        $keyPem = self::readPrivateKeyFileNoFollow($this->accountKeyPath);
        $key = \is_string($keyPem) ? \openssl_pkey_get_private($keyPem) : false;
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
     * @return array{
     *   max_seconds: int,
     *   interval_seconds: int,
     *   visible_use_public_doh: bool,
     *   max_seconds_gname?: int,
     *   max_seconds_cloudflare?: int
     * }
     */
    private function getAcmeDnsTxtPollConfig(): array
    {
        $config = [];
        try {
            $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(AcmeDnsTxtPollPolicyProviderInterface::class);
            if ($provider instanceof AcmeDnsTxtPollPolicyProviderInterface) {
                $config = $provider->getPolicy();
            }
        } catch (\Throwable) {
            $config = [];
        }

        // 未配置时默认走 DoH 回退：本机 dns_get_record 在 Windows/企业递归上常滞后于权威，与「CF 已写入但进程查不到」现象一致
        $out = [
            'max_seconds' => (int)($config['max_seconds'] ?? 900),
            'interval_seconds' => (int)($config['interval_seconds'] ?? 10),
            'visible_use_public_doh' => !\array_key_exists('visible_use_public_doh', $config)
                ? true
                : !empty($config['visible_use_public_doh']),
        ];
        if (\array_key_exists('max_seconds_gname', $config)) {
            $out['max_seconds_gname'] = (int)$config['max_seconds_gname'];
        }
        if (\array_key_exists('max_seconds_cloudflare', $config)) {
            $out['max_seconds_cloudflare'] = (int)$config['max_seconds_cloudflare'];
        }

        return $out;
    }

    /**
     * 检查 ACME TXT 是否可见：先 {@see dns_get_record}；$allowPublicDoh 为 true 时再试公共 DoH（更接近 CA 全球递归）。
     */
    protected function isAcmeTxtVisible(string $txtFqdn, string $expectedValue, bool $allowPublicDoh = false): bool
    {
        if ($this->acmeTxtMatchesDnsGetRecord($txtFqdn, $expectedValue)) {
            return true;
        }
        if (!$allowPublicDoh) {
            return false;
        }

        return $this->isAcmeTxtVisibleViaPublicDoh($txtFqdn, $expectedValue);
    }

    private function acmeTxtMatchesDnsGetRecord(string $txtFqdn, string $expectedValue): bool
    {
        if ($this->acmeTxtDnsGetRecordProbe($txtFqdn, $expectedValue)) {
            return true;
        }
        // 部分解析栈对 FQDN 带点/不敏感；再试末尾根点
        if ($txtFqdn !== '' && !\str_ends_with($txtFqdn, '.')) {
            return $this->acmeTxtDnsGetRecordProbe($txtFqdn . '.', $expectedValue);
        }

        return false;
    }

    private function acmeTxtDnsGetRecordProbe(string $txtFqdn, string $expectedValue): bool
    {
        $records = @\dns_get_record($txtFqdn, \DNS_TXT);
        if (!\is_array($records)) {
            return false;
        }
        foreach ($records as $r) {
            $txt = $r['txt'] ?? null;
            $parts = \is_array($txt) ? $txt : [$txt];
            foreach ($parts as $t) {
                if ($t === null || $t === '') {
                    continue;
                }
                // 与 DoH 路径一致：兼容带引号、分段 TXT
                if ($this->acmeTxtDataMatchesExpected((string) $t, $expectedValue)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 公共 DoH（JSON）：与全球递归更接近；多节点任一命中即可。
     *
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
            ['url' => 'https://dns.google/resolve?name=' . $enc . '&type=TXT', 'accept' => 'application/dns-json'],
            ['url' => 'https://cloudflare-dns.com/dns-query?name=' . $enc . '&type=TXT', 'accept' => 'application/dns-json'],
            ['url' => 'https://1.1.1.1/dns-query?name=' . $enc . '&type=TXT', 'accept' => 'application/dns-json'],
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
                \CURLOPT_TIMEOUT => 8,
                \CURLOPT_CONNECTTIMEOUT => 4,
                \CURLOPT_HTTPHEADER => $headers,
                \CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $raw = \curl_exec($ch);
            \curl_close($ch);
            if (!\is_string($raw) || $raw === '') {
                continue;
            }
            $json = \json_decode($raw, true);
            if (!\is_array($json)) {
                continue;
            }
            // Google/CF DoH JSON：Status 0 = NOERROR；部分响应带 Comment 无 Answer（仍传播中）
            if ((int) ($json['Status'] ?? -1) !== 0) {
                continue;
            }
            $answers = $json['Answer'] ?? [];
            if (!\is_array($answers)) {
                continue;
            }
            foreach ($answers as $a) {
                if (!\is_array($a)) {
                    continue;
                }
                $rtype = $a['type'] ?? null;
                if ((int) $rtype !== 16 && (string) $rtype !== '16') {
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
        if (self::readPrivateKeyFileNoFollow($path) !== null) {
            return true;
        }
        if (\file_exists($path) || \is_link($path)) {
            return false;
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
        try {
            $this->writeCertificateFileAtomically($path, $privateKey, 0600);
            return true;
        } catch (\Throwable) {
            return false;
        }
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
        unset($keyAuth);
        if (\preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $token) !== 1) {
            return false;
        }
        $challengeDir = $this->resolveAcmeChallengeDirectory($webroot, true);
        if ($challengeDir === null) {
            return false;
        }
        $thumbprint = $this->getAccountThumbprint();
        $content = $token . '.' . $thumbprint;
        if ($thumbprint === '' || \strlen($content) > 1024) {
            return false;
        }
        try {
            $path = $challengeDir . $token;
            $this->writeLockedSslStateAtomically(
                $path,
                $content,
                0644,
                self::MAX_ACME_CHALLENGE_STATE_BYTES,
                'ACME HTTP-01 challenge state',
                function (string $candidate) use ($path): void {
                    $this->assertAcmeChallengeStateContents($path, $candidate);
                },
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
    
    /**
     * 清理 HTTP 验证文件
     */
    protected function cleanupHttpChallenge(string $webroot, string $token): void
    {
        if (\preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $token) !== 1) {
            return;
        }
        $challengeDir = $this->resolveAcmeChallengeDirectory($webroot, false);
        if ($challengeDir === null) {
            return;
        }
        $file = $challengeDir . $token;
        try {
            (new ProjectCertificateGenerationStore())->withCertificateLifecycleLock(
                function () use ($file): void {
                    GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                        $file,
                        self::MAX_ACME_CHALLENGE_STATE_BYTES,
                        'ACME HTTP-01 challenge state',
                        function (string $candidate) use ($file): void {
                            $this->assertSslStateTargetMode(
                                $file,
                                0644,
                                'ACME HTTP-01 challenge state',
                            );
                            $this->assertAcmeChallengeStateContents($file, $candidate);
                        },
                    );
                    $status = @\lstat($file);
                    if (!\is_array($status)) {
                        if (\file_exists($file) || \is_link($file)) {
                            throw new \RuntimeException(
                                'ACME HTTP-01 challenge target is indeterminate.',
                            );
                        }
                        return;
                    }
                    $this->assertSslStateTargetMode(
                        $file,
                        0644,
                        'ACME HTTP-01 challenge state',
                    );
                    if (!GatewayProjectStateFilesystem::removeRegular(
                        $file,
                        'ACME HTTP-01 challenge state',
                        $status,
                    )) {
                        throw new \RuntimeException(
                            'Unable to remove the ACME HTTP-01 challenge state.',
                        );
                    }
                },
            );
        } catch (\Throwable $throwable) {
            $this->lastAcmeError = $throwable->getMessage();
        }
    }

    private function assertAcmeChallengeStateContents(
        string $path,
        string $contents,
    ): void {
        $token = \basename(\str_replace('\\', '/', $path));
        if (\preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $token) !== 1
            || \preg_match(
                '/\A' . \preg_quote($token, '/') . '\.[A-Za-z0-9_-]{43}\z/D',
                $contents,
            ) !== 1
        ) {
            throw new \RuntimeException('ACME HTTP-01 challenge state is malformed.');
        }
    }

    private function resolveAcmeChallengeDirectory(string $webroot, bool $create): ?string
    {
        if ($webroot === '' || \str_contains($webroot, "\0") || \is_link($webroot)) {
            return null;
        }
        $root = \realpath($webroot);
        $rootStatus = @\lstat($webroot);
        if (!\is_string($root)
            || !\is_array($rootStatus)
            || (((int)($rootStatus['mode'] ?? 0)) & 0170000) !== 0040000
            || !self::sameFilesystemPath($webroot, $root)
            || self::filesystemPathIsRoot($root)
        ) {
            return null;
        }
        $current = $root;
        foreach (['.well-known', 'acme-challenge'] as $segment) {
            $current .= DS . $segment;
            $status = @\lstat($current);
            if (!\is_array($status)) {
                if (!$create
                    || \file_exists($current)
                    || \is_link($current)
                    || !@\mkdir($current, 0755, false)
                ) {
                    return null;
                }
                $status = @\lstat($current);
            }
            $real = \realpath($current);
            if (!\is_array($status)
                || (((int)($status['mode'] ?? 0)) & 0170000) !== 0040000
                || \is_link($current)
                || !\is_string($real)
                || !self::sameFilesystemPath($current, $real)
                || !\str_starts_with(
                    \PHP_OS_FAMILY === 'Windows' ? \strtolower($real . DS) : $real . DS,
                    \PHP_OS_FAMILY === 'Windows'
                        ? \strtolower(\rtrim($root, '/\\') . DS)
                        : \rtrim($root, '/\\') . DS,
                )
            ) {
                return null;
            }
        }
        return \rtrim($current, '/\\') . DS;
    }

    /**
     * 域名转 WLS 虚拟 HTTP-01 存储文件名（与 worker 内规则一致）
     */
    public static function domainToAcmeChallengeFilename(string $domain): string
    {
        return ProjectAcmeHttp01ChallengeStore::projectionFilename($domain);
    }

    /**
     * 登记 WLS 虚拟 HTTP-01 校验（写入 generated/acme-http01，由 worker 响应 /.well-known/acme-challenge/<token>）
     */
    protected function registerWlsHttp01Challenge(string $domain, string $token, string $keyAuth): bool
    {
        try {
            $deadline = $this->gatewayAcmePublishMonotonicNow()
                + self::GATEWAY_ACME_PUBLISH_TIMEOUT_SECONDS;
            $desired = $this->acmeHttp01ChallengeStore()->register(
                $domain,
                $token,
                $keyAuth,
                $deadline,
            );
            return $this->publishGatewayAcmeDesiredBeforeValidation(
                $desired,
                $domain,
                $deadline,
            );
        } catch (\Throwable $throwable) {
            $this->lastAcmeError = $throwable->getMessage();
            return false;
        }
    }

    /**
     * The Agent can report process READY just before its first registration is
     * committed. Keep the project desired state authoritative, but wait a
     * bounded monotonic window for the pending route and exact challenge to be
     * published before the CA is notified. A permanent authorization or
     * control-plane failure still fails closed after the deadline.
     *
     * @param array{generation:int,digest:string,challenges:list<array<string,mixed>>} $desired
     */
    protected function publishGatewayAcmeDesiredBeforeValidation(
        array $desired,
        string $requiredDomain,
        ?float $deadlineMonotonic = null,
    ): bool {
        $deadline = $deadlineMonotonic
            ?? ($this->gatewayAcmePublishMonotonicNow()
                + self::GATEWAY_ACME_PUBLISH_TIMEOUT_SECONDS);
        if (!\is_finite($deadline) || $deadline < 0.0) {
            $this->lastAcmeError = 'Gateway ACME publication deadline is invalid.';
            return false;
        }
        $attempt = 0;
        while (true) {
            if ($attempt > 0 && $this->gatewayAcmePublishMonotonicNow() >= $deadline) {
                break;
            }
            if ($this->publishGatewayAcmeDesired(
                $desired,
                $requiredDomain,
                $deadline,
            )) {
                return true;
            }
            $remainingSeconds = $deadline - $this->gatewayAcmePublishMonotonicNow();
            if ($remainingSeconds <= 0.0) {
                break;
            }
            $this->waitForGatewayAcmePublishRetry($attempt++, $remainingSeconds);
        }

        if ($this->lastAcmeError === '') {
            $this->lastAcmeError = (string)__(
                '网关未在 %{1} 秒内确认 ACME HTTP-01 challenge 发布。',
                [(string)(int)self::GATEWAY_ACME_PUBLISH_TIMEOUT_SECONDS],
            );
            $this->lastAcmeError = \str_replace(
                '%{1}',
                (string)(int)self::GATEWAY_ACME_PUBLISH_TIMEOUT_SECONDS,
                $this->lastAcmeError,
            );
        }
        return false;
    }

    protected function gatewayAcmePublishMonotonicNow(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    protected function waitForGatewayAcmePublishRetry(
        int $attempt,
        float $remainingSeconds,
    ): void
    {
        $multiplier = 1 << \min(4, \max(0, $attempt));
        $remainingMicroseconds = (int)\max(
            0,
            \floor($remainingSeconds * 1_000_000),
        );
        \Weline\Framework\Runtime\SchedulerSystem::usleep(
            \min(
                self::GATEWAY_ACME_PUBLISH_MAX_RETRY_MICROSECONDS,
                self::GATEWAY_ACME_PUBLISH_INITIAL_RETRY_MICROSECONDS * $multiplier,
                $remainingMicroseconds,
            ),
        );
    }

    /**
     * 清理 WLS 虚拟 HTTP-01 校验文件（验证完成后必须删除）
     */
    protected function cleanupWlsHttp01Challenge(string $domain): void
    {
        try {
            $desired = $this->acmeHttp01ChallengeStore()->remove($domain);
            $this->publishGatewayAcmeDesired($desired);
        } catch (\Throwable $throwable) {
            // The project queue remains authoritative. Agent reconciliation
            // retries the desired generation after control-plane recovery.
            $this->lastAcmeError = $throwable->getMessage();
        }
    }

    protected function acmeHttp01ChallengeStore(): ProjectAcmeHttp01ChallengeStore
    {
        return $this->acmeHttp01Store ??= new ProjectAcmeHttp01ChallengeStore();
    }

    /**
     * Synchronously publishes before CA notification when a live project
     * endpoint is owned by the shared gateway. With no gateway endpoint this
     * is pure WLS and the project-local compatibility projection is sufficient.
     *
     * @param array{generation:int,digest:string,challenges:list<array<string,mixed>>} $desired
     */
    protected function publishGatewayAcmeDesired(
        array $desired,
        ?string $requiredDomain = null,
        ?float $deadlineMonotonic = null,
    ): bool {
        return (new GatewayAcmeChallengePublisher())->publish(
            $desired,
            $requiredDomain,
            $deadlineMonotonic,
        );
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
        $keyPem = self::readPrivateKeyFileNoFollow($keyPath);
        $key = \is_string($keyPem) ? \openssl_pkey_get_private($keyPem) : false;
        if (!$key) {
            return null;
        }
        
        $dn = ['commonName' => $domain];
        $csr = \openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        if (!$csr) {
            return null;
        }
        
        \openssl_csr_export($csr, $csrPem);
        try {
            $this->writeCertificateFileAtomically($csrPath, $csrPem, 0600);
        } catch (\Throwable) {
            return null;
        }
        
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
        $certData = self::readRegularFileNoFollow($certPath);
        if ($certData === null) {
            unset($this->certParseCache[$certPath]);
            return false;
        }

        $digest = \hash('sha256', $certData);
        $cached = $this->certParseCache[$certPath] ?? null;
        if (\is_array($cached)
            && ($cached['digest'] ?? '') === $digest
            && \array_key_exists('parsed', $cached)
        ) {
            return $cached['parsed'];
        }
        
        $cert = @\openssl_x509_parse($certData);
        $parsed = $cert ?: false;
        $this->certParseCache[$certPath] = [
            'digest' => $digest,
            'parsed' => $parsed,
        ];

        return $parsed;
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
        if ($host === '') {
            return false;
        }
        
        // 解析缓存按证书内容摘要校验，续签在原路径替换后不会沿用旧 SAN 结果。
        $cert = $this->getParsedCertificateRaw($certPath);
        if (!$cert) {
            return false;
        }
        
        $subjectAltName = (string)($cert['extensions']['subjectAltName'] ?? '');
        $sanEntries = $this->extractCertificateSubjectAltNames($subjectAltName);
        $hasSubjectAltName = \trim($subjectAltName) !== '';
        foreach ($sanEntries['dns'] as $dnsName) {
            if ($this->hostMatchesCertificateName($host, $dnsName)) {
                return true;
            }
        }
        foreach ($sanEntries['ip'] as $ipName) {
            if ($this->hostMatchesCertificateName($host, $ipName)) {
                return true;
            }
        }

        // RFC 6125: SAN 存在时不得回退到冲突 CN；只有无 SAN 的旧证书才校验 CN。
        if ($hasSubjectAltName) {
            return false;
        }

        $cn = \strtolower(\trim($cert['subject']['CN'] ?? ''));
        if ($this->hostMatchesCertificateName($host, $cn)) {
            return true;
        }

        return false;
    }
    
    /**
     * 检查 host 是否为本地或内网地址（需要自签证书）
     */
    /**
     * @return array{dns: list<string>, ip: list<string>}
     */
    protected function extractCertificateSubjectAltNames(string $subjectAltName): array
    {
        $dns = [];
        $ip = [];

        foreach (\explode(',', $subjectAltName) as $entry) {
            $entry = \trim($entry);
            if ($entry === '' || !\str_contains($entry, ':')) {
                continue;
            }

            [$type, $value] = \explode(':', $entry, 2);
            $type = \strtolower(\trim($type));
            $value = \strtolower(\trim($value));
            if ($value === '') {
                continue;
            }

            if ($type === 'dns') {
                $dns[] = $value;
                continue;
            }
            if ($type === 'ip address' || $type === 'ip') {
                $ip[] = $value;
            }
        }

        $result = [
            'dns' => \array_values(\array_unique($dns)),
            'ip' => \array_values(\array_unique($ip)),
        ];

        return $result;
    }

    protected function hostMatchesCertificateName(string $host, string $name): bool
    {
        $host = \strtolower(\trim($host));
        $name = \strtolower(\trim($name));
        if ($host === '' || $name === '') {
            return false;
        }

        $localhostEquivalents = ['localhost', '127.0.0.1', '::1'];
        if (\in_array($host, $localhostEquivalents, true) && \in_array($name, $localhostEquivalents, true)) {
            return true;
        }

        if (\filter_var($host, FILTER_VALIDATE_IP) || \filter_var($name, FILTER_VALIDATE_IP)) {
            return $host === $name;
        }

        if ($host === $name) {
            return true;
        }

        if (!\str_starts_with($name, '*.')) {
            return false;
        }

        $wildcardRoot = \substr($name, 2);
        if ($wildcardRoot === '' || $host === $wildcardRoot) {
            return false;
        }
        if (!\str_ends_with($host, '.' . $wildcardRoot)) {
            return false;
        }

        return \count(\explode('.', $host)) === \count(\explode('.', $wildcardRoot)) + 1;
    }

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
        $keyPem = self::readPrivateKeyFileNoFollow($this->accountKeyPath);
        $key = \is_string($keyPem) ? \openssl_pkey_get_private($keyPem) : false;
        if ($key === false) {
            return '';
        }
        $details = \openssl_pkey_get_details($key);
        if (!\is_array($details) || !\is_array($details['rsa'] ?? null)) {
            return '';
        }
        
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
        $keyPem = self::readPrivateKeyFileNoFollow($this->accountKeyPath);
        $key = \is_string($keyPem) ? \openssl_pkey_get_private($keyPem) : false;
        if ($key === false) {
            throw new \RuntimeException('ACME account key is unavailable or unsafe.');
        }
        $details = \openssl_pkey_get_details($key);
        if (!\is_array($details) || !\is_array($details['rsa'] ?? null)) {
            throw new \RuntimeException('ACME account key details are invalid.');
        }
        
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

        $response = $this->httpRequest(
            (string)$directory['newNonce'],
            'HEAD',
        );
        $nonce = \trim((string)($response['headers']['replay-nonce'] ?? ''));
        return \preg_match('/\A[A-Za-z0-9_-]{1,512}\z/D', $nonce) === 1
            ? $nonce
            : '';
    }
    
    /**
     * HTTP 请求
     */
    protected function httpRequest(string $url, string $method = 'GET', ?string $body = null): array
    {
        $parts = \parse_url($url);
        $authority = \parse_url($this->acmeDirectory);
        $method = \strtoupper(\trim($method));
        if (!\is_array($parts)
            || !\is_array($authority)
            || !\hash_equals('https', \strtolower((string)($parts['scheme'] ?? '')))
            || \trim((string)($parts['host'] ?? '')) === ''
            || !\hash_equals(
                \strtolower((string)($authority['host'] ?? '')),
                \strtolower((string)$parts['host']),
            )
            || (int)($authority['port'] ?? 443) !== (int)($parts['port'] ?? 443)
            || isset($parts['user'])
            || isset($parts['pass'])
            || !\in_array($method, ['GET', 'POST', 'HEAD'], true)
            || ($body !== null
                && \strlen($body) > self::MAX_ACME_HTTP_RESPONSE_BYTES)
        ) {
            return [
                'headers' => [],
                'body' => null,
                'raw' => '',
                'error' => 'ACME request URL, method or body is outside its safety boundary.',
            ];
        }
        $ch = \curl_init($url);
        if ($ch === false) {
            return [
                'headers' => [],
                'body' => null,
                'raw' => '',
                'error' => 'Unable to initialize the ACME HTTP request.',
            ];
        }
        $headers = [];
        $currentHeaders = [];
        $headerBytes = 0;
        $responseBody = '';
        $boundaryExceeded = false;
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_HEADER, false);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        // ACME endpoints are fixed HTTPS authorities. Refuse redirects instead
        // of letting a remote response retarget this host-side client at an
        // unrelated or private HTTPS service.
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        \curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
        \curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        \curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        \curl_setopt($ch, CURLOPT_USERAGENT, 'Weline-Server/1.0 ACME-Client');
        \curl_setopt(
            $ch,
            CURLOPT_HEADERFUNCTION,
            static function ($handle, string $line) use (
                &$headers,
                &$currentHeaders,
                &$headerBytes,
                &$boundaryExceeded,
            ): int {
                unset($handle);
                $length = \strlen($line);
                $headerBytes += $length;
                if ($headerBytes > self::MAX_ACME_HTTP_HEADER_BYTES) {
                    $boundaryExceeded = true;
                    return 0;
                }
                $trimmed = \trim($line);
                if (\preg_match('/\AHTTP\/\S+\s+[1-5][0-9]{2}(?:\s|\z)/D', $trimmed) === 1) {
                    $currentHeaders = [];
                    return $length;
                }
                if ($trimmed === '') {
                    $headers = $currentHeaders;
                    return $length;
                }
                if (\str_contains($line, ':')) {
                    [$name, $value] = \explode(':', $line, 2);
                    $name = \strtolower(\trim($name));
                    if ($name !== '' && \strlen($name) <= 256) {
                        $currentHeaders[$name] = \trim($value);
                    }
                }
                return $length;
            },
        );
        \curl_setopt(
            $ch,
            CURLOPT_WRITEFUNCTION,
            static function ($handle, string $chunk) use (
                &$responseBody,
                &$boundaryExceeded,
            ): int {
                unset($handle);
                $length = \strlen($chunk);
                if (\strlen($responseBody) + $length
                    > self::MAX_ACME_HTTP_RESPONSE_BYTES
                ) {
                    $boundaryExceeded = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return $length;
            },
        );

        if ($method === 'POST') {
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            \curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/jose+json']);
        } elseif ($method === 'HEAD') {
            \curl_setopt($ch, CURLOPT_NOBODY, true);
        }

        $response = \curl_exec($ch);
        $curlError = $response === false ? \curl_error($ch) : '';
        $httpCode = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        \curl_close($ch);

        if ($response === false) {
            if ($boundaryExceeded) {
                $curlError = 'ACME HTTP response exceeded its fixed size boundary.';
            }
            w_log_warning(__('ACME HTTP 请求失败: url=%{1}, error=%{2}', [$url, $curlError]), [], 'ssl_cert');
            return ['headers' => [], 'body' => null, 'raw' => '', 'error' => $curlError];
        }

        return [
            'headers' => $headers,
            'body' => $responseBody === '' ? null : \json_decode($responseBody, true),
            'raw' => $responseBody,
            'http_code' => $httpCode,
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
        if (!$enabled) {
            return $this->disableManagedCertificate(
                $domain,
                (string)__('用户手动禁用'),
            );
        }
        try {
            $domain = self::normalizeCertificateStorageDomain($domain);
            $prepared = (new ProjectCertificateGenerationStore())
                ->withCertificateLifecycleLock(
                fn (): array => $this->enableManagedCertificateLocked($domain),
            );
            if (($prepared['success'] ?? false) !== true
                || !\hash_equals('prepared', (string)($prepared['phase'] ?? ''))
            ) {
                return $prepared;
            }
            return $this->completeManagedCertificateEnable($prepared);
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'phase' => 'enable_preflight',
                'domain' => $domain,
                'message' => $throwable->getMessage(),
            ];
        }
    }

    /** @return array<string,mixed> */
    private function enableManagedCertificateLocked(string $domain): array
    {
        try {
            $domain = self::normalizeCertificateStorageDomain($domain);
            $issuingMsg = $this->getSslIssuanceConflictMessage($domain);
            if ($issuingMsg !== '') {
                return ['success' => false, 'message' => $issuingMsg];
            }

            $generationStore = new ProjectCertificateGenerationStore();
            $pendingRetirement = $generationStore->retirementIntent($domain);
            if (\is_array($pendingRetirement)
                && \hash_equals(
                    'pending',
                    (string)($pendingRetirement['state'] ?? ''),
                )
                && !\hash_equals(
                    ProjectCertificateGenerationStore::RETIREMENT_OPERATION_PROJECTION,
                    (string)($pendingRetirement['operation'] ?? ''),
                )
            ) {
                return [
                    'success' => false,
                    'phase' => 'retirement_pending',
                    'domain' => $domain,
                    'message' => (string)__('上一次证书退役尚未完成代际事件确认，请等待自动恢复后再启用 HTTPS。'),
                ];
            }
            $cert = $this->certificateModel()->clearQuery()->loadByDomain($domain);
            
            if (!$cert->getCertId()) {
                return ['success' => false, 'message' => __('未找到域名证书：%{1}', [$domain])];
            }
            
            // 启用 HTTPS 前必须验证稳定文件、有效期、SAN 和私钥。
            $activePairValid = self::certificateFilePairIsValidForName(
                (string)$cert->getCertPath(),
                (string)$cert->getKeyPath(),
                $domain,
            );
            if (!$activePairValid) {
                $certDir = $this->getCertificateDir(\strtolower(\trim($domain)));
                $certPath = $certDir . 'fullchain.pem';
                $keyPath = $certDir . 'privkey.pem';
                if (self::certificateFilePairIsValidForName(
                    $certPath,
                    $keyPath,
                    $domain,
                )) {
                    $synced = $this->syncCertificateRecordFromFiles(
                        $domain,
                        $certPath,
                        $keyPath,
                        (int)$cert->getWebsiteId(),
                        false,
                        (string)($cert->getProvider() ?: self::PROVIDER_LETS_ENCRYPT),
                    );
                    if ($synced instanceof SslCertificate) {
                        $cert = $synced;
                        $activePairValid = true;
                    }
                }
            }
            if (!$activePairValid) {
                return [
                    'success' => false,
                    'message' => __('证书文件不安全、已过期、未覆盖域名或与私钥不匹配'),
                ];
            }
            
            $cert->setStatus(SslCertificate::STATUS_ACTIVE)
                ->setRenewError('')
                ->setHttpsEnabled(true)
                ->save();

            $certId = (int)$cert->getCertId();
            $reenableIntent = $generationStore->issueExplicitReenableIntent(
                $domain,
                (string)$cert->getCertPath(),
                (string)$cert->getKeyPath(),
            );
            return [
                'success' => true,
                'phase' => 'prepared',
                'domain' => $domain,
                'cert_id' => $certId,
                'cert_path' => (string)$cert->getCertPath(),
                'key_path' => (string)$cert->getKeyPath(),
                'issuer' => (string)$cert->getIssuer(),
                'expires_at' => (string)$cert->getExpiresAt(),
                'cert_type' => (string)$cert->getCertType(),
                'reenable_intent_id' => (string)$reenableIntent['intent_id'],
                'certificate_source_digest' => (string)$reenableIntent['source_digest'],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** @param array<string,mixed> $prepared */
    private function completeManagedCertificateEnable(array $prepared): array
    {
        $domain = (string)$prepared['domain'];
        $certId = (int)$prepared['cert_id'];
        try {
            // Publication runs after releasing the lifecycle lock. Every
            // activation path reacquires it before consuming this exact
            // persistent intent, so disable cannot race an old PEM back in.
            $this->clearServerCache();
        } catch (\Throwable $throwable) {
            $this->recordCertificateLifecycleFailure(
                $certId,
                $domain,
                'enable_serving_publication',
                $throwable,
            );
            return [
                'success' => false,
                'phase' => 'serving_publication',
                'domain' => $domain,
                'cert_id' => $certId,
                'desired_enabled' => true,
                'reenable_intent_id' => (string)($prepared['reenable_intent_id'] ?? ''),
                'message' => (string)__(
                    'HTTPS 已写入启用事实，但服务清单尚未完成确认：%{1}',
                    [$throwable->getMessage()],
                ),
            ];
        }

        $this->dispatchCertificateIssuedEvent(
            $domain,
            $certId,
            (string)$prepared['cert_path'],
            (string)$prepared['key_path'],
            (string)$prepared['issuer'],
            (string)$prepared['expires_at'],
            (string)$prepared['cert_type'],
        );
        return [
            'success' => true,
            'phase' => 'complete',
            'domain' => $domain,
            'cert_id' => $certId,
            'message' => __('HTTPS 已启用'),
        ];
    }
    
    /** @return array<string,mixed> */
    public function disableManagedCertificate(string $domain, string $reason = ''): array
    {
        return $this->transitionCertificateOutOfService($domain, false, $reason);
    }

    /** @return array<string,mixed> */
    public function deleteManagedCertificate(string $domain, string $reason = ''): array
    {
        return $this->transitionCertificateOutOfService($domain, true, $reason);
    }

    /** @return array<string,mixed> */
    private function transitionCertificateOutOfService(
        string $domain,
        bool $delete,
        string $reason,
    ): array {
        $deadlineMonotonic = (\hrtime(true) / 1_000_000_000) + 75.0;
        try {
            $domain = self::normalizeCertificateStorageDomain($domain);
            if (\in_array($domain, self::PROTECTED_LOCAL_DOMAINS, true)) {
                throw new \RuntimeException((string)__(
                    '本地域名 %{1} 的证书受保护，不能禁用或删除',
                    [$domain],
                ));
            }
            $prepared = (new ProjectCertificateGenerationStore())
                ->withCertificateLifecycleLock(
                    fn (): array => $this->transitionCertificateOutOfServiceLocked(
                        $domain,
                        $delete,
                        $reason,
                        $deadlineMonotonic,
                    ),
                    \min(
                        0.25,
                        $this->assertCertificateRetirementBudget(
                            $deadlineMonotonic,
                            0.01,
                        ),
                    ),
                );
            if (($prepared['success'] ?? false) !== true
                || !\hash_equals('prepared', (string)($prepared['phase'] ?? ''))
            ) {
                return $prepared;
            }
            return $this->completeCertificateOutOfService(
                $prepared,
                $deadlineMonotonic,
            );
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'phase' => $delete ? 'delete_preflight' : 'disable_preflight',
                'domain' => $domain,
                'message' => $throwable->getMessage(),
            ];
        }
    }

    /** @return array<string,mixed> */
    private function transitionCertificateOutOfServiceLocked(
        string $domain,
        bool $delete,
        string $reason,
        float $deadlineMonotonic,
    ): array {
        $this->assertCertificateRetirementBudget($deadlineMonotonic, 0.01);
        $issuanceConflict = $this->getSslIssuanceConflictMessage($domain);
        $this->assertCertificateRetirementBudget($deadlineMonotonic, 0.01);
        if ($issuanceConflict !== '') {
            throw new \RuntimeException($issuanceConflict);
        }
        $cert = $this->certificateModel()->clearQuery()->loadByDomain($domain);
        $this->assertCertificateRetirementBudget($deadlineMonotonic, 0.01);
        if (!$cert->getCertId()) {
            throw new \RuntimeException((string)__(
                '未找到域名证书：%{1}',
                [$domain],
            ));
        }
        $certId = (int)$cert->getCertId();
        $operation = $delete
            ? ProjectCertificateGenerationStore::RETIREMENT_OPERATION_DELETE
            : ProjectCertificateGenerationStore::RETIREMENT_OPERATION_DISABLE;
        $generationStore = new ProjectCertificateGenerationStore();

        // The fail-closed tombstone and complete outbox are the first durable
        // write. A crash before the PostgreSQL row update therefore remains
        // replayable and can never leave the old active selector eligible.
        try {
            $retirementIntent = $generationStore->prepareCertificateRetirement(
                $domain,
                $operation,
                $certId,
                $reason,
                $this->certificateRetirementRowDigest($cert),
                $deadlineMonotonic,
            );
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'phase' => 'snapshot_deactivation',
                'domain' => $domain,
                'cert_id' => $certId,
                'endpoint_updates' => 0,
                'desired_revoked' => true,
                'message' => (string)__(
                    '证书退役意图未能安全持久化：%{1}',
                    [$throwable->getMessage()],
                ),
            ];
        }

        try {
            $cert->setHttpsEnabled(false)
                ->setAutoRenew(false)
                ->setStatus(SslCertificate::STATUS_REVOKED)
                ->setRenewError('')
                ->save();
            $retirementIntent = $generationStore->advanceRetirementPhase(
                $retirementIntent,
                ProjectCertificateGenerationStore::RETIREMENT_PHASE_PREPARED,
                ProjectCertificateGenerationStore::RETIREMENT_PHASE_RUNTIME_PENDING,
                \hash(
                    'sha256',
                    GatewayClient::canonicalJson([
                        'certificate_id' => $certId,
                        'row_digest' => $this->certificateRetirementRowDigest($cert),
                        'status' => SslCertificate::STATUS_REVOKED,
                        'https_enabled' => false,
                    ]),
                ),
                $deadlineMonotonic,
            ) ?? $retirementIntent;
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'phase' => 'database_revocation',
                'domain' => $domain,
                'cert_id' => $certId,
                'endpoint_updates' => 0,
                'desired_revoked' => true,
                'message' => (string)__(
                    '证书已进入安全隔离且保留可重放意图，数据库撤销待恢复：%{1}',
                    [$throwable->getMessage()],
                ),
            ];
        }

        return [
            'success' => true,
            'phase' => 'prepared',
            'domain' => $domain,
            'cert_id' => $certId,
            'delete' => $delete,
            'reason' => $reason,
            'retirement_intent' => $retirementIntent,
        ];
    }

    /** @param array<string,mixed> $prepared @return array<string,mixed> */
    private function completeCertificateOutOfService(
        array $prepared,
        float $deadlineMonotonic,
    ): array
    {
        $domain = (string)$prepared['domain'];
        $certId = (int)$prepared['cert_id'];
        $retirementIntent = \is_array($prepared['retirement_intent'] ?? null)
            ? $prepared['retirement_intent']
            : [];
        try {
            return $this->resumeCertificateRetirementIntent(
                $retirementIntent,
                $deadlineMonotonic,
            );
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'phase' => 'retirement_pending',
                'domain' => $domain,
                'cert_id' => $certId,
                'endpoint_updates' => 0,
                'desired_revoked' => true,
                'serving_removed' => false,
                'message' => (string)__('证书已进入失效安全状态，后续退役阶段将自动重放：%{1}', [
                    $throwable->getMessage(),
                ]),
            ];
        }
    }

    /**
     * Resume one exact multi-stage retirement intent. Every irreversible stage
     * records its receipt before the next stage starts, so process death at any
     * boundary is safe to replay.
     *
     * @param array<string,mixed> $expectedIntent
     * @return array<string,mixed>
     */
    private function resumeCertificateRetirementIntent(
        array $expectedIntent,
        float $deadlineMonotonic,
    ): array {
        $store = new ProjectCertificateGenerationStore();
        $domain = self::normalizeCertificateStorageDomain((string)(
            $expectedIntent['domain'] ?? ''
        ));
        $certId = (int)($expectedIntent['certificate_id'] ?? 0);
        for ($step = 0; $step < 12; ++$step) {
            $this->assertCertificateRetirementBudget($deadlineMonotonic, 0.1);
            $intent = $store->retirementIntent($domain, $deadlineMonotonic);
            if (!\is_array($intent)
                || !$this->sameCertificateRetirementIdentity($expectedIntent, $intent)
            ) {
                return $this->certificateRetirementSupersededResult(
                    $domain,
                    $certId,
                    '证书退役意图已变更，旧清理流程已取消。',
                );
            }
            $state = (string)($intent['state'] ?? '');
            if (\hash_equals('superseded', $state)) {
                return $this->certificateRetirementSupersededResult(
                    $domain,
                    $certId,
                    '更高代证书已激活，旧清理流程已取消。',
                );
            }
            if (\hash_equals('completed', $state)) {
                $deleted = \hash_equals(
                    ProjectCertificateGenerationStore::RETIREMENT_OPERATION_DELETE,
                    (string)($intent['operation'] ?? ''),
                );
                return [
                    'success' => true,
                    'phase' => 'complete',
                    'domain' => $domain,
                    'cert_id' => $certId,
                    'endpoint_updates' => 0,
                    'deleted' => $deleted,
                    'message' => $deleted
                        ? (string)__('证书已删除')
                        : (string)__('HTTPS 已禁用'),
                ];
            }
            if (!\hash_equals('pending', $state)) {
                throw new \RuntimeException('Certificate retirement state is invalid.');
            }

            $phase = (string)($intent['phase'] ?? '');
            if (\hash_equals(
                ProjectCertificateGenerationStore::RETIREMENT_PHASE_PREPARED,
                $phase,
            )) {
                $this->withCertificateRetirementLifecycleLock(
                    $store,
                    $deadlineMonotonic,
                    function () use ($store, $intent, $deadlineMonotonic): void {
                        $receipt = $this->commitCertificateRetirementDatabaseFact(
                            $intent,
                        );
                        $store->advanceRetirementPhase(
                            $intent,
                            ProjectCertificateGenerationStore::RETIREMENT_PHASE_PREPARED,
                            ProjectCertificateGenerationStore::RETIREMENT_PHASE_RUNTIME_PENDING,
                            $receipt,
                            $deadlineMonotonic,
                        );
                    },
                );
                continue;
            }
            if (\hash_equals(
                ProjectCertificateGenerationStore::RETIREMENT_PHASE_RUNTIME_PENDING,
                $phase,
            )) {
                (new CertificateMaterialUpdateCoordinator())->notify(
                    $domain,
                    ['wls_revocation_intent' => $intent],
                    '',
                    $deadlineMonotonic,
                );
                continue;
            }
            if (\hash_equals(
                ProjectCertificateGenerationStore::RETIREMENT_PHASE_RUNTIME_RETIRED,
                $phase,
            )) {
                $this->withCertificateRetirementLifecycleLock(
                    $store,
                    $deadlineMonotonic,
                    function () use ($store, $intent, $deadlineMonotonic): void {
                        $receipt = $this->retireLegacyNginxCertificateGeneration(
                            $intent,
                            $deadlineMonotonic,
                        );
                        $store->advanceRetirementPhase(
                            $intent,
                            ProjectCertificateGenerationStore::RETIREMENT_PHASE_RUNTIME_RETIRED,
                            ProjectCertificateGenerationStore::RETIREMENT_PHASE_LEGACY_RETIRED,
                            $receipt,
                            $deadlineMonotonic,
                        );
                    },
                );
                continue;
            }
            if (\hash_equals(
                ProjectCertificateGenerationStore::RETIREMENT_PHASE_LEGACY_RETIRED,
                $phase,
            )) {
                $this->withCertificateRetirementLifecycleLock(
                    $store,
                    $deadlineMonotonic,
                    function () use (
                        $store,
                        $intent,
                        $domain,
                        $deadlineMonotonic,
                    ): void {
                        $endpointUpdates = $this->revokeDomainFromInstanceConfigs(
                            $domain,
                            $deadlineMonotonic,
                        );
                        $store->advanceRetirementPhase(
                            $intent,
                            ProjectCertificateGenerationStore::RETIREMENT_PHASE_LEGACY_RETIRED,
                            ProjectCertificateGenerationStore::RETIREMENT_PHASE_ENDPOINT_RETIRED,
                            \hash(
                                'sha256',
                                GatewayClient::canonicalJson([
                                    'domain' => $domain,
                                    'endpoint_updates' => $endpointUpdates,
                                ]),
                            ),
                            $deadlineMonotonic,
                        );
                    },
                );
                continue;
            }
            if (\hash_equals(
                ProjectCertificateGenerationStore::RETIREMENT_PHASE_ENDPOINT_RETIRED,
                $phase,
            )) {
                $this->withCertificateRetirementLifecycleLock(
                    $store,
                    $deadlineMonotonic,
                    function () use (
                        $store,
                        $intent,
                        $domain,
                        $deadlineMonotonic,
                    ): void {
                        $operation = (string)($intent['operation'] ?? '');
                        $directoryPlan = $operation
                            === ProjectCertificateGenerationStore::RETIREMENT_OPERATION_DELETE
                            ? $this->prepareCertificateDirectoryRemoval(
                                $domain,
                                $deadlineMonotonic,
                            )
                            : [];
                        if ($directoryPlan !== []) {
                            $this->removeCertificateDirectoryPlan(
                                $directoryPlan,
                                $deadlineMonotonic,
                            );
                        }
                        $store->advanceRetirementPhase(
                            $intent,
                            ProjectCertificateGenerationStore::RETIREMENT_PHASE_ENDPOINT_RETIRED,
                            ProjectCertificateGenerationStore::RETIREMENT_PHASE_SOURCE_RETIRED,
                            \hash(
                                'sha256',
                                GatewayClient::canonicalJson([
                                    'domain' => $domain,
                                    'operation' => $operation,
                                    'directories_removed' => \count($directoryPlan),
                                ]),
                            ),
                            $deadlineMonotonic,
                        );
                    },
                );
                continue;
            }
            if (\hash_equals(
                ProjectCertificateGenerationStore::RETIREMENT_PHASE_SOURCE_RETIRED,
                $phase,
            )) {
                $this->withCertificateRetirementLifecycleLock(
                    $store,
                    $deadlineMonotonic,
                    function () use (
                        $store,
                        $intent,
                        $domain,
                        $deadlineMonotonic,
                    ): void {
                        $operation = (string)($intent['operation'] ?? '');
                        $rowResult = 'retained';
                        if ($operation
                            === ProjectCertificateGenerationStore::RETIREMENT_OPERATION_DELETE
                        ) {
                            $rowResult = $this->deleteCertificateRetirementDatabaseRow($intent);
                        }
                        $store->advanceRetirementPhase(
                            $intent,
                            ProjectCertificateGenerationStore::RETIREMENT_PHASE_SOURCE_RETIRED,
                            ProjectCertificateGenerationStore::RETIREMENT_PHASE_DATABASE_RETIRED,
                            \hash(
                                'sha256',
                                GatewayClient::canonicalJson([
                                    'domain' => $domain,
                                    'operation' => $operation,
                                    'row_result' => $rowResult,
                                ]),
                            ),
                            $deadlineMonotonic,
                        );
                    },
                );
                continue;
            }
            if (\hash_equals(
                ProjectCertificateGenerationStore::RETIREMENT_PHASE_DATABASE_RETIRED,
                $phase,
            )) {
                $this->withCertificateRetirementLifecycleLock(
                    $store,
                    $deadlineMonotonic,
                    function () use (
                        $store,
                        $intent,
                        $deadlineMonotonic,
                    ): void {
                        $this->dispatchDurableCertificateRetirementEvent($intent);
                        $store->advanceRetirementPhase(
                            $intent,
                            ProjectCertificateGenerationStore::RETIREMENT_PHASE_DATABASE_RETIRED,
                            ProjectCertificateGenerationStore::RETIREMENT_PHASE_EVENT_DISPATCHED,
                            \hash(
                                'sha256',
                                GatewayClient::canonicalJson([
                                    'event_id' => (string)($intent['event_id'] ?? ''),
                                    'operation' => (string)($intent['operation'] ?? ''),
                                ]),
                            ),
                            $deadlineMonotonic,
                        );
                    },
                );
                continue;
            }
            if (\hash_equals(
                ProjectCertificateGenerationStore::RETIREMENT_PHASE_EVENT_DISPATCHED,
                $phase,
            )) {
                $this->withCertificateRetirementLifecycleLock(
                    $store,
                    $deadlineMonotonic,
                    fn (): bool => $store->finishRetirementIntent(
                        $intent,
                        $deadlineMonotonic,
                    ),
                );
                continue;
            }
            throw new \RuntimeException(
                'Certificate retirement contains an unknown replay phase: ' . $phase,
            );
        }
        throw new \RuntimeException('Certificate retirement exceeded its bounded stage count.');
    }

    /** @param array<string,mixed> $intent */
    private function commitCertificateRetirementDatabaseFact(array $intent): string
    {
        $domain = (string)$intent['domain'];
        $certId = (int)($intent['certificate_id'] ?? 0);
        $record = ObjectManager::getInstance(SslCertificate::class, [], false);
        $record->load($certId);
        if (!$record->getCertId()) {
            if (\hash_equals(
                ProjectCertificateGenerationStore::RETIREMENT_OPERATION_DELETE,
                (string)($intent['operation'] ?? ''),
            )) {
                return \hash('sha256', 'retirement-row-already-absent:' . $certId);
            }
            throw new \RuntimeException(
                'The certificate row disappeared before its disable fact committed.',
            );
        }
        $recordDomain = self::normalizeCertificateStorageDomain($record->getDomain());
        $rowDigest = $this->certificateRetirementRowDigest($record);
        if (!\hash_equals($domain, $recordDomain)
            || !\hash_equals(
                (string)($intent['expected_row_digest'] ?? ''),
                $rowDigest,
            )
        ) {
            throw new \RuntimeException(
                'The certificate row identity changed before retirement commit.',
            );
        }
        if (!\hash_equals(SslCertificate::STATUS_REVOKED, $record->getStatus())
            || $record->isHttpsEnabled()
            || $record->isAutoRenew()
        ) {
            $record->setHttpsEnabled(false)
                ->setAutoRenew(false)
                ->setStatus(SslCertificate::STATUS_REVOKED)
                ->setRenewError('')
                ->save();
        }
        return \hash(
            'sha256',
            GatewayClient::canonicalJson([
                'certificate_id' => $certId,
                'row_digest' => $this->certificateRetirementRowDigest($record),
                'status' => $record->getStatus(),
                'https_enabled' => $record->isHttpsEnabled(),
                'auto_renew' => $record->isAutoRenew(),
            ]),
        );
    }

    private function certificateRetirementRowDigest(SslCertificate $record): string
    {
        return \hash(
            'sha256',
            GatewayClient::canonicalJson([
                'certificate_id' => $record->getCertId(),
                'domain' => self::normalizeCertificateStorageDomain($record->getDomain()),
                'certificate_type' => $record->getCertType(),
                'website_id' => $record->getWebsiteId(),
                'certificate_path' => $record->getCertPath(),
                'private_key_path' => $record->getKeyPath(),
                'chain_path' => $record->getChainPath(),
                'issuer' => $record->getIssuer(),
                'provider' => $record->getProvider(),
                'issued_at' => (string)$record->getData(
                    SslCertificate::schema_fields_ISSUED_AT,
                ),
                'expires_at' => $record->getExpiresAt(),
            ]),
        );
    }

    /** @param array<string,mixed> $intent */
    private function deleteCertificateRetirementDatabaseRow(array $intent): string
    {
        $certId = (int)($intent['certificate_id'] ?? 0);
        $record = ObjectManager::getInstance(SslCertificate::class, [], false);
        $record->load($certId);
        if (!$record->getCertId()) {
            return 'already_absent';
        }
        if (!\hash_equals(
                (string)$intent['domain'],
                self::normalizeCertificateStorageDomain($record->getDomain()),
            )
            || !\hash_equals(
                (string)($intent['expected_row_digest'] ?? ''),
                $this->certificateRetirementRowDigest($record),
            )
            || !\hash_equals(SslCertificate::STATUS_REVOKED, $record->getStatus())
            || $record->isHttpsEnabled()
        ) {
            throw new \RuntimeException(
                'The certificate row changed before retirement deletion.',
            );
        }
        $record->delete()->fetch();
        return 'deleted';
    }

    /** @param array<string,mixed> $intent */
    private function dispatchDurableCertificateRetirementEvent(array $intent): void
    {
        $operation = (string)($intent['operation'] ?? '');
        if (\hash_equals(
            ProjectCertificateGenerationStore::RETIREMENT_OPERATION_PROJECTION,
            $operation,
        )) {
            return;
        }
        $event = $operation
            === ProjectCertificateGenerationStore::RETIREMENT_OPERATION_DELETE
            ? 'Weline_Server::domain::certificate_deleted'
            : 'Weline_Server::domain::certificate_disabled';
        ObjectManager::getInstance(EventsManager::class)->dispatch($event, [
            'domain' => (string)$intent['domain'],
            'cert_id' => (int)($intent['certificate_id'] ?? 0),
            'reason' => (string)($intent['reason'] ?? ''),
            'retirement_generation' => (int)$intent['generation'],
            'retirement_intent_id' => (string)$intent['intent_id'],
            'retirement_event_id' => (string)$intent['event_id'],
            'retirement_operation' => $operation,
        ]);
    }

    private function assertCertificateRetirementBudget(
        float $deadlineMonotonic,
        float $minimumSeconds,
    ): float {
        $remaining = $deadlineMonotonic - (\hrtime(true) / 1_000_000_000);
        if (!\is_finite($deadlineMonotonic)
            || !\is_finite($minimumSeconds)
            || $minimumSeconds <= 0.0
            || $remaining < $minimumSeconds
        ) {
            throw new \RuntimeException(
                'Certificate retirement replay exhausted its global time budget.',
            );
        }
        return $remaining;
    }

    private function withCertificateRetirementLifecycleLock(
        ProjectCertificateGenerationStore $store,
        float $deadlineMonotonic,
        callable $callback,
    ): mixed {
        $remaining = $this->assertCertificateRetirementBudget(
            $deadlineMonotonic,
            0.05,
        );
        $waitTimeout = \max(0.01, \min(0.25, $remaining / 2.0));
        return $store->withCertificateLifecycleLock(
            function () use ($deadlineMonotonic, $callback): mixed {
                $this->assertCertificateRetirementBudget(
                    $deadlineMonotonic,
                    0.01,
                );
                return $callback();
            },
            $waitTimeout,
        );
    }

    /** @param array<string,mixed> $intent */
    private function retireLegacyNginxCertificateGeneration(
        array $intent,
        float $deadlineMonotonic,
    ): string {
        $this->assertCertificateRetirementBudget($deadlineMonotonic, 1.0);
        $managed = ManagedNginxService::fromEnv();
        $before = $managed->retirementSnapshot($deadlineMonotonic);
        $running = ($before['running'] ?? false) === true;
        $legacyObserved = $running
            || \trim((string)($before['owner_instance'] ?? '')) !== ''
            || \trim((string)($before['owner_config_sha256'] ?? '')) !== ''
            || \trim((string)($before['owner_ssl_certificate_sha256'] ?? '')) !== '';
        $legacyConfigPath = $managed->paths()->confFile();
        $legacyConfigStatus = @\lstat($legacyConfigPath);
        $legacyConfig = '';
        if (\is_array($legacyConfigStatus)) {
            $legacyConfig = self::readRegularFileNoFollow(
                $legacyConfigPath,
                self::MAX_CERTIFICATE_MATERIAL_BYTES,
                true,
                false,
            ) ?? '';
            if ($legacyConfig === '') {
                throw new \RuntimeException(
                    'Legacy managed Nginx configuration cannot be read safely.',
                );
            }
            $legacyObserved = true;
        } elseif (\file_exists($legacyConfigPath) || \is_link($legacyConfigPath)) {
            throw new \RuntimeException(
                'Legacy managed Nginx configuration identity is indeterminate.',
            );
        }
        if ($running && ($before['runtime_owner_active'] ?? false) !== true) {
            throw new \RuntimeException(
                'Legacy managed Nginx is running without an exact owner/configuration identity.',
            );
        }

        $legacyMasterPid = $running ? (int)($before['pid'] ?? 0) : 0;
        $legacyWorkerPids = [];
        if ($legacyMasterPid > 0 && \PHP_OS_FAMILY !== 'Windows') {
            $workers = NginxChildProcessProbe::workerPids(
                $legacyMasterPid,
                $deadlineMonotonic,
            );
            if (!\is_array($workers) || $workers === []) {
                throw new \RuntimeException(
                    'Unable to enumerate the exact legacy Nginx worker generation.',
                );
            }
            $legacyWorkerPids = $workers;
        }

        $stop = ['ok' => true, 'message' => 'not running'];
        if ($running) {
            $this->assertCertificateRetirementBudget($deadlineMonotonic, 1.0);
            $stop = $managed->stop($deadlineMonotonic);
            if (($stop['ok'] ?? false) !== true) {
                throw new \RuntimeException((string)(
                    $stop['message'] ?? 'Legacy managed Nginx stop failed.'
                ));
            }
        }

        // Compatibility state is rewritten only after the exact process tree
        // is stopped, so a failed write cannot reload the retired PEM.
        $this->writeLegacyCertificateCompatibilityMap($deadlineMonotonic);
        $after = $managed->retirementSnapshot($deadlineMonotonic);
        if (($after['running'] ?? false) === true
            || (int)($after['pid'] ?? 0) > 0
        ) {
            throw new \RuntimeException(
                'Legacy managed Nginx still reports a live process after retirement.',
            );
        }
        foreach ($legacyWorkerPids as $workerPid) {
            $workerRunning = NginxChildProcessProbe::processIsRunning(
                (int)$workerPid,
                $deadlineMonotonic,
            );
            if ($workerRunning === null) {
                throw new \RuntimeException(
                    'A retired legacy Nginx worker state is indeterminate.',
                );
            }
            if ($workerRunning) {
                throw new \RuntimeException(
                    'A worker from the retired legacy Nginx generation remains alive.',
                );
            }
        }

        $ports = [];
        if ($legacyObserved) {
            foreach ([$before, $after] as $snapshot) {
                foreach (['listen_https', 'owner_listen_https'] as $field) {
                    $port = (int)($snapshot[$field] ?? 0);
                    if ($port > 0 && $port <= 65535) {
                        $ports[$port] = true;
                    }
                }
            }
            if ($legacyConfig !== '') {
                $matches = [];
                $matched = \preg_match_all(
                    '/^\s*listen\s+([1-9][0-9]{0,4})\s+(?:ssl|quic)(?:\s|;)/mi',
                    $legacyConfig,
                    $matches,
                );
                if ($matched === false) {
                    throw new \RuntimeException(
                        'Legacy managed Nginx TLS listeners cannot be parsed safely.',
                    );
                }
                foreach ((array)($matches[1] ?? []) as $matchedPort) {
                    $port = (int)$matchedPort;
                    if ($port > 0 && $port <= 65535) {
                        $ports[$port] = true;
                    }
                }
            }
            if ($ports === []) {
                throw new \RuntimeException(
                    'Legacy managed Nginx was observed without an exact TLS listener port.',
                );
            }
        }
        \ksort($ports, SORT_NUMERIC);
        $listenerProofs = [];
        foreach (\array_keys($ports) as $port) {
            foreach (['tcp', 'udp'] as $transport) {
                $this->assertCertificateRetirementBudget(
                    $deadlineMonotonic,
                    0.25,
                );
                $errno = 0;
                $error = '';
                $socket = @\stream_socket_server(
                    $transport . '://0.0.0.0:' . $port,
                    $errno,
                    $error,
                    $transport === 'tcp'
                        ? STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
                        : STREAM_SERVER_BIND,
                );
                if (!\is_resource($socket)) {
                    throw new \RuntimeException(
                        'The retired legacy TLS listener cannot be proven absent on '
                            . $transport . '/0.0.0.0:' . $port . '.',
                    );
                }
                @\fclose($socket);
                $listenerProofs[] = $transport . ':0.0.0.0:' . $port . ':exclusive';
            }
        }
        return \hash(
            'sha256',
            GatewayClient::canonicalJson([
                'intent_id' => (string)$intent['intent_id'],
                'before' => \hash('sha256', GatewayClient::canonicalJson($before)),
                'stop' => \hash('sha256', GatewayClient::canonicalJson($stop)),
                'after' => \hash('sha256', GatewayClient::canonicalJson($after)),
                'retired_master_pid' => $legacyMasterPid,
                'retired_worker_pids' => $legacyWorkerPids,
                'listener_proofs' => $listenerProofs,
            ]),
        );
    }

    private function sameCertificateRetirementIdentity(array $left, array $right): bool
    {
        return \hash_equals(
            (string)($left['intent_id'] ?? ''),
            (string)($right['intent_id'] ?? ''),
        )
            && \hash_equals(
                (string)($left['domain'] ?? ''),
                (string)($right['domain'] ?? ''),
            )
            && \is_int($left['generation'] ?? null)
            && \is_int($right['generation'] ?? null)
            && (int)$left['generation'] === (int)$right['generation']
            && \hash_equals(
                (string)($left['source_digest'] ?? ''),
                (string)($right['source_digest'] ?? ''),
            )
            && \hash_equals(
                (string)($left['metadata_digest'] ?? ''),
                (string)($right['metadata_digest'] ?? ''),
            );
    }

    /** @return array<string,mixed> */
    private function certificateRetirementSupersededResult(
        string $domain,
        int $certId,
        string $message,
    ): array {
        return [
            'success' => false,
            'phase' => 'retirement_superseded',
            'domain' => $domain,
            'cert_id' => $certId,
            'endpoint_updates' => 0,
            'desired_revoked' => false,
            'serving_removed' => false,
            'message' => (string)__($message),
        ];
    }

    private function revokeDomainFromInstanceConfigs(
        string $domain,
        float $deadlineMonotonic,
    ): int
    {
        $updated = 0;
        foreach ([
            [
                'directory' => Env::VAR_DIR . 'server' . DS . 'config' . DS,
                'managed_endpoint' => false,
            ],
            [
                'directory' => Env::VAR_DIR . 'server' . DS . 'instances' . DS,
                'managed_endpoint' => true,
            ],
        ] as $source) {
            $directory = (string)$source['directory'];
            $managedEndpoint = (bool)$source['managed_endpoint'];
            foreach ($this->boundedJsonFiles(
                $directory,
                'WLS instance configuration directory',
            ) as $file) {
                $this->assertCertificateRetirementBudget(
                    $deadlineMonotonic,
                    0.01,
                );
                $encoded = self::readRegularFileNoFollow($file);
                try {
                    $current = \is_string($encoded)
                        ? \json_decode($encoded, true, 64, JSON_THROW_ON_ERROR)
                        : null;
                } catch (\JsonException $exception) {
                    throw new \RuntimeException(
                        'WLS instance configuration JSON is invalid.',
                        0,
                        $exception,
                    );
                }
                if (!\is_array($current) || \array_is_list($current)) {
                    throw new \RuntimeException(
                        'WLS instance configuration payload is invalid.',
                    );
                }
                [, $previewChanged] = $this->revokeDomainFromEndpointPayload(
                    $current,
                    $domain,
                );
                if (!$previewChanged) {
                    continue;
                }
                $applied = false;
                $mutate = function (array $latest) use (
                    $domain,
                    $deadlineMonotonic,
                    &$applied,
                ): array {
                    $this->assertCertificateRetirementBudget(
                        $deadlineMonotonic,
                        0.01,
                    );
                    if (\array_is_list($latest)) {
                        throw new \RuntimeException(
                            'WLS certificate endpoint payload changed to an invalid value.',
                        );
                    }
                    [$next, $applied] = $this->revokeDomainFromEndpointPayload(
                        $latest,
                        $domain,
                    );
                    return $next;
                };
                $lockBudget = \min(
                    0.25,
                    $this->assertCertificateRetirementBudget(
                        $deadlineMonotonic,
                        0.01,
                    ),
                );
                if ($managedEndpoint) {
                    if (!ServerInstanceManager::updateJsonFileAtomically(
                        $file,
                        $mutate,
                        $lockBudget,
                    )) {
                        throw new \RuntimeException(
                            'Unable to update the WLS certificate endpoint record.',
                        );
                    }
                } else {
                    GatewayProjectStateFilesystem::withExclusiveLock(
                        $file . '.lock',
                        function () use (
                            $file,
                            $mutate,
                            &$applied,
                        ): void {
                            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                                $file,
                                self::MAX_CERTIFICATE_MATERIAL_BYTES,
                                'saved WLS certificate configuration',
                                function (string $candidate) use ($file): void {
                                    $this->assertSslStateTargetMode(
                                        $file,
                                        0600,
                                        'saved WLS certificate configuration',
                                    );
                                    $this->decodeCertificateEndpointRecord($candidate);
                                },
                            );
                            $latestEncoded = GatewayProjectStateFilesystem::read(
                                $file,
                                self::MAX_CERTIFICATE_MATERIAL_BYTES,
                                'saved WLS certificate configuration',
                            );
                            $next = $mutate(
                                $this->decodeCertificateEndpointRecord($latestEncoded),
                            );
                            if (!$applied) {
                                return;
                            }
                            $nextEncoded = \json_encode(
                                $next,
                                JSON_PRETTY_PRINT
                                    | JSON_UNESCAPED_UNICODE
                                    | JSON_UNESCAPED_SLASHES
                                    | JSON_THROW_ON_ERROR,
                            );
                            GatewayProjectStateFilesystem::atomicWrite(
                                $file,
                                $nextEncoded,
                                0600,
                            );
                            $this->assertSslStateTargetMode(
                                $file,
                                0600,
                                'published WLS certificate configuration',
                            );
                            $published = $this->decodeCertificateEndpointRecord(
                                GatewayProjectStateFilesystem::read(
                                    $file,
                                    self::MAX_CERTIFICATE_MATERIAL_BYTES,
                                    'published WLS certificate configuration',
                                ),
                            );
                            if (!\hash_equals(
                                \hash('sha256', GatewayClient::canonicalJson($next)),
                                \hash('sha256', GatewayClient::canonicalJson($published)),
                            )) {
                                throw new \RuntimeException(
                                    'Published WLS certificate configuration did not verify.',
                                );
                            }
                        },
                        waitTimeoutSeconds: $lockBudget,
                    );
                }
                if ($applied) {
                    ++$updated;
                }
            }
        }
        return $updated;
    }

    /** @return array<string,mixed> */
    private function decodeCertificateEndpointRecord(string $encoded): array
    {
        try {
            $decoded = \json_decode(
                $encoded,
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'WLS certificate endpoint JSON is invalid.',
                0,
                $exception,
            );
        }
        if (!\is_array($decoded) || \array_is_list($decoded)) {
            throw new \RuntimeException(
                'WLS certificate endpoint payload is invalid.',
            );
        }
        return $decoded;
    }

    /** @return array{0:array<string,mixed>,1:bool} */
    private function revokeDomainFromEndpointPayload(array $data, string $domain): array
    {
        $gateway = \is_array($data['gateway'] ?? null) ? $data['gateway'] : [];
        $source = \is_array($gateway['certificate_source'] ?? null)
            ? $gateway['certificate_source']
            : [];
        $rawTopDomain = \is_string($data['ssl_domain'] ?? null)
            ? \trim((string)$data['ssl_domain'])
            : '';
        $rawSourceDomain = \is_string($source['domain'] ?? null)
            ? \trim((string)$source['domain'])
            : '';
        $topDomain = $this->normalizedEndpointCertificateDomain(
            $rawTopDomain,
        );
        $sourceDomain = $this->normalizedEndpointCertificateDomain(
            $rawSourceDomain,
        );
        if (($rawTopDomain !== '' && $topDomain === '')
            || ($rawSourceDomain !== '' && $sourceDomain === '')
        ) {
            throw new \RuntimeException(
                'WLS endpoint contains a malformed certificate domain.',
            );
        }
        $publicDomain = $topDomain === '' && $sourceDomain === ''
            ? $this->normalizedEndpointCertificateDomain(
                $data['public_host'] ?? '',
            )
            : '';
        $topMatches = $topDomain !== '' && \hash_equals($domain, $topDomain);
        $sourceMatches = $sourceDomain !== '' && \hash_equals($domain, $sourceDomain);
        $publicMatches = $publicDomain !== '' && \hash_equals($domain, $publicDomain);
        if (!$topMatches && !$sourceMatches && !$publicMatches) {
            return [$data, false];
        }
        if ($topDomain !== ''
            && $sourceDomain !== ''
            && !\hash_equals($topDomain, $sourceDomain)
        ) {
            throw new \RuntimeException(
                'WLS endpoint certificate domains disagree; refusing an ambiguous revoke.',
            );
        }
        if ($topMatches || $publicMatches || ($sourceMatches && $topDomain === '')) {
            $data['ssl_enabled'] = false;
            $data['ssl_cert'] = '';
            $data['ssl_key'] = '';
            $data['ssl_domain'] = '';
        }
        if ($sourceMatches
            || $publicMatches
            || ($topMatches && $sourceDomain === '')
        ) {
            unset($gateway['certificate_source'], $gateway['certificate_pending']);
            $data['gateway'] = $gateway;
        }
        return [$data, true];
    }

    private function normalizedEndpointCertificateDomain(mixed $value): string
    {
        if (!\is_string($value) || \trim($value) === '') {
            return '';
        }
        try {
            return self::normalizeCertificateStorageDomain($value);
        } catch (\Throwable) {
            return '';
        }
    }

    private function recordCertificateLifecycleFailure(
        int $certId,
        string $domain,
        string $phase,
        \Throwable $throwable,
    ): void {
        try {
            $record = ObjectManager::getInstance(SslCertificate::class, [], false);
            $record->load($certId);
            if (!$record->getCertId()
                || !\hash_equals(
                    $domain,
                    self::normalizeCertificateStorageDomain(
                        (string)$record->getDomain(),
                    ),
                )
            ) {
                return;
            }
            $message = \preg_replace(
                '/[\x00-\x1f\x7f]+/',
                ' ',
                $throwable->getMessage(),
            );
            $record->setRenewError(\substr(
                'WLS certificate lifecycle ' . $phase . ' failed: '
                    . (string)$message,
                0,
                1024,
            ))->save();
        } catch (\Throwable $diagnosticFailure) {
            w_log_error('[SslCertificateService] ' . (string)__(
                '证书生命周期失败诊断写入失败：%{1}',
                [$diagnosticFailure->getMessage()],
            ));
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

        $query = $this->certificateModel()->clearQuery();
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

        $certificates = $query->select()->fetchIterator();

        $expiredCerts = [];
        $foundCertificate = false;
        foreach ($certificates as $cert) {
            $cert = \is_array($cert) ? $cert : (\method_exists($cert, 'getData') ? $cert->getData() : []);
            $foundCertificate = true;
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

        if (!$foundCertificate) {
            $result['errors'][] = $domain
                ? (string) __('未找到域名 %{1} 的证书记录', [$domain])
                : (string) __('证书管理中没有可重载的证书记录');
            return $result;
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

    private const CERTIFICATE_REMOVABLE_LEAVES = [
        'fullchain.pem',
        'privkey.pem',
        'chain.pem',
        'cert.pem',
        'domain.key',
        'csr.pem',
        self::SSL_ISSUANCE_LOCK_FILENAME,
        'key.pem',
        'ssl.crt',
        'ssl.key',
        'ssl.pem',
        'server.crt',
        'server.key',
        'certificate.crt',
        'private.key',
    ];

    /**
     * @return list<array{directory:string,entries:array<string,array<string,int|string>>,status:array<string,mixed>}>
     */
    private function prepareCertificateDirectoryRemoval(
        string $domain,
        float $deadlineMonotonic,
    ): array
    {
        $directories = [];
        foreach (self::certificateStorageSegmentCandidatesForProbe($domain) as $segment) {
            $this->assertCertificateRetirementBudget($deadlineMonotonic, 0.01);
            $certDir = $this->certificateDirectoryForSegment($segment, false);
            if ($certDir === null) {
                continue;
            }
            $entries = $this->boundedDirectoryEntries(
                $certDir,
                self::MAX_CERTIFICATE_DIRECTORY_ENTRIES,
                'certificate domain directory',
            );
            $entryIdentities = [];
            foreach ($entries as $entry) {
                $this->assertCertificateRetirementBudget(
                    $deadlineMonotonic,
                    0.01,
                );
                $managedLeaf = \in_array(
                    $entry,
                    self::CERTIFICATE_REMOVABLE_LEAVES,
                    true,
                );
                if (!$managedLeaf) {
                    foreach (self::CERTIFICATE_REMOVABLE_LEAVES as $allowedLeaf) {
                        if (\preg_match(
                            '/\A' . \preg_quote($allowedLeaf, '/')
                                . '(?:\.tmp-[a-f0-9]{24}|\.wls-backup-[a-f0-9]{16})\z/D',
                            $entry,
                        ) === 1) {
                            $managedLeaf = true;
                            break;
                        }
                    }
                }
                if (!$managedLeaf) {
                    throw new \RuntimeException(
                        'Certificate directory contains an unmanaged leaf: ' . $entry,
                    );
                }
                $leafStatus = @\lstat($certDir . $entry);
                $leafType = \is_array($leafStatus)
                    ? (((int)($leafStatus['mode'] ?? 0)) & 0170000)
                    : 0;
                if (!\is_array($leafStatus)
                    || !\in_array($leafType, [0100000, 0120000], true)
                    || ($leafType === 0100000
                        && (int)($leafStatus['nlink'] ?? 0) !== 1)
                ) {
                    throw new \RuntimeException(
                        'Certificate directory contains a special or hard-linked leaf: '
                        . $entry,
                    );
                }
                $entryIdentities[$entry] = $this->certificateRemovalLeafIdentity(
                    $certDir . $entry,
                    $leafStatus,
                );
            }
            $directoryStatus = @\lstat(\rtrim($certDir, '/\\'));
            if (!\is_array($directoryStatus)
                || (((int)($directoryStatus['mode'] ?? 0)) & 0170000) !== 0040000
            ) {
                throw new \RuntimeException(
                    'Certificate directory changed after deletion preflight.',
                );
            }
            $directories[] = [
                'directory' => $certDir,
                'entries' => $entryIdentities,
                'status' => $directoryStatus,
            ];
        }
        return $directories;
    }

    /**
     * @param list<array{directory:string,entries:array<string,array<string,int|string>>,status:array<string,mixed>}> $plan
     */
    private function removeCertificateDirectoryPlan(
        array $plan,
        float $deadlineMonotonic,
    ): void
    {
        foreach ($plan as $directory) {
            $this->assertCertificateRetirementBudget($deadlineMonotonic, 0.01);
            $certDir = (string)$directory['directory'];
            $expectedStatus = (array)$directory['status'];
            foreach ((array)$directory['entries'] as $entry => $leafIdentity) {
                $this->assertCertificateRetirementBudget(
                    $deadlineMonotonic,
                    0.01,
                );
                $this->removeCertificateLeafSafely(
                    $certDir,
                    (string)$entry,
                    $expectedStatus,
                    \is_array($leafIdentity) ? $leafIdentity : null,
                );
            }
            $beforeRmdir = @\lstat(\rtrim($certDir, '/\\'));
            if (!\is_array($beforeRmdir)
                || (int)($expectedStatus['dev'] ?? -1)
                    !== (int)($beforeRmdir['dev'] ?? -2)
                || (int)($expectedStatus['ino'] ?? -1)
                    !== (int)($beforeRmdir['ino'] ?? -2)
            ) {
                throw new \RuntimeException(
                    'Certificate directory changed before final removal.',
                );
            }
            if (!@\rmdir(\rtrim($certDir, '/\\'))) {
                throw new \RuntimeException(
                    'Unable to remove the empty certificate directory.',
                );
            }
            GatewayProjectStateFilesystem::syncDirectory(\dirname(
                \rtrim($certDir, '/\\'),
            ));
        }
    }

    protected function clearDomainCertificate(string $domain, array $cert, array &$result): void
    {
        unset($cert);
        $deletion = $this->deleteManagedCertificate(
            $domain,
            (string)__('server:ssl:reload --clear 清理'),
        );
        if (($deletion['success'] ?? false) !== true) {
            $result['skipped'] = ($result['skipped'] ?? 0) + 1;
            $result['errors'][] = (string)(
                $deletion['message'] ?? __('证书清理失败')
            );
            return;
        }
        ++$result['deleted'];
        $result['deleted_domains'][] = (string)($deletion['domain'] ?? $domain);
    }

    protected function clearServerCache(): void
    {
        // 清除实例配置中指向不存在证书的 ssl_cert/ssl_key/ssl_domain，避免 server:start 加载失效路径
        $this->clearInvalidSslPathsFromInstanceConfigs();

        // Endpoint facts must converge before publishing the manifest that
        // consumes them; otherwise a stale fallback can recreate a route.
        $this->regenerateCertificateMap();
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
            if ($sslCert !== '' || $sslKey !== '') {
                $certExists = $sslCert !== ''
                    && self::readRegularFileNoFollow($sslCert) !== null;
                $keyExists = $sslKey !== ''
                    && self::readPrivateKeyFileNoFollow($sslKey) !== null;
                if (!$certExists || !$keyExists) {
                    $data['ssl_cert'] = '';
                    $data['ssl_key'] = '';
                    $data['ssl_domain'] = '';
                }
            }

            $gateway = \is_array($data['gateway'] ?? null)
                ? $data['gateway']
                : [];
            $source = \is_array($gateway['certificate_source'] ?? null)
                ? $gateway['certificate_source']
                : null;
            if (\is_array($source)) {
                $sourceCert = \trim((string)($source['cert_path'] ?? ''));
                $sourceKey = \trim((string)($source['key_path'] ?? ''));
                $pending = ($source['pending'] ?? false) === true
                    || ($gateway['certificate_pending'] ?? false) === true;
                $pendingWithoutMaterial = $pending
                    && $sourceCert === ''
                    && $sourceKey === '';
                $sourceValid = $pendingWithoutMaterial
                    || ($sourceCert !== ''
                        && $sourceKey !== ''
                        && self::readRegularFileNoFollow($sourceCert) !== null
                        && self::readPrivateKeyFileNoFollow($sourceKey) !== null);
                if (!$sourceValid) {
                    unset(
                        $gateway['certificate_source'],
                        $gateway['certificate_pending'],
                    );
                    $data['gateway'] = $gateway;
                }
            }
            return $data;
        };
        foreach ($dirsToClear as $dir) {
            foreach ($this->boundedJsonFiles($dir, 'WLS instance configuration directory') as $file) {
                if (!ServerInstanceManager::updateJsonFileAtomically(
                    (string)$file,
                    $clearModifier,
                )) {
                    throw new \RuntimeException(
                        'Unable to atomically clear an invalid WLS certificate endpoint.',
                    );
                }
            }
        }
    }
    
    /**
     * 重新生成证书映射文件
     * 
     * 在证书签发、续签、启用/禁用后调用，确保证书映射文件包含最新的域名映射
     * 特别是处理泛域名证书的展开，使子域名能够正确匹配证书
     */
    public function regenerateCertificateMap(
        bool $broadcastReload = true,
        string $changedDomain = '',
        array $revocationIntent = [],
    ): void
    {
        (new ProjectCertificateGenerationStore())->withCertificateLifecycleLock(
            function () use (
                $broadcastReload,
                $changedDomain,
                $revocationIntent,
            ): void {
                $securityFirst = $broadcastReload && $revocationIntent !== [];
                if ($securityFirst) {
                    $this->publishCertificateRuntimeUpdate(
                        $changedDomain,
                        $revocationIntent,
                    );
                }
                $this->writeLegacyCertificateCompatibilityMap();

                if (!$broadcastReload || $securityFirst) {
                    return;
                }
                $this->publishCertificateRuntimeUpdate($changedDomain, []);
            },
        );
    }

    /**
     * Replay the complete runtime -> legacy -> endpoint -> source -> PostgreSQL
     * -> generation-bound-event chain under one project-global worker lease.
     * A durable rotating cursor and total monotonic deadline prevent one broken
     * domain or many WLS instances from creating an unbounded replay storm.
     *
     * @return array{attempted:int,completed:int,failures:list<string>,deferred:bool}
     */
    public function replayPendingCertificateRetirements(
        float $totalBudgetSeconds = 15.0,
        int $maximumIntents = 4,
    ): array {
        if (!\is_finite($totalBudgetSeconds)
            || $totalBudgetSeconds < 1.0
            || $totalBudgetSeconds > 80.0
            || $maximumIntents < 1
            || $maximumIntents > 64
        ) {
            throw new \InvalidArgumentException(
                'Certificate retirement replay budget or batch is invalid.',
            );
        }
        $store = new ProjectCertificateGenerationStore();
        try {
            return $store->withRetirementReplayLease(function () use (
                $store,
                $totalBudgetSeconds,
                $maximumIntents,
            ): array {
                $deadline = (\hrtime(true) / 1_000_000_000) + $totalBudgetSeconds;
                $pending = $store->pendingRetirementBatch(
                    $maximumIntents,
                    $deadline,
                );
                $attempted = 0;
                $completed = 0;
                $failures = [];
                foreach ($pending as $domain => $intent) {
                    if ($deadline - (\hrtime(true) / 1_000_000_000) < 0.25) {
                        break;
                    }
                    ++$attempted;
                    try {
                        $result = $this->resumeCertificateRetirementIntent(
                            $intent,
                            $deadline,
                        );
                        if (($result['success'] ?? false) === true
                            || \hash_equals(
                                'retirement_superseded',
                                (string)($result['phase'] ?? ''),
                            )
                        ) {
                            ++$completed;
                        }
                    } catch (\Throwable $throwable) {
                        $failures[] = (string)$domain . ': '
                            . \Weline\Server\Service\Edge\Gateway\GatewayBoundedText::singleLine(
                                $throwable->getMessage(),
                                2048,
                                'certificate retirement replay failed',
                            );
                    } finally {
                        try {
                            $store->advanceRetirementReplayCursor($intent, $deadline);
                        } catch (\Throwable $cursorError) {
                            $failures[] = (string)$domain . ': replay cursor: '
                                . \Weline\Server\Service\Edge\Gateway\GatewayBoundedText::singleLine(
                                    $cursorError->getMessage(),
                                    1024,
                                    'certificate retirement cursor update failed',
                                );
                        }
                    }
                }
                if ($failures !== []) {
                    throw new \RuntimeException(
                        \Weline\Server\Service\Edge\Gateway\GatewayBoundedText::singleLine(
                            \implode('; ', $failures),
                            4096,
                            'Certificate retirement replay did not converge.',
                        ),
                    );
                }
                return [
                    'attempted' => $attempted,
                    'completed' => $completed,
                    'failures' => [],
                    'deferred' => $attempted < \count($pending)
                        || \count($pending) >= $maximumIntents,
                ];
            });
        } catch (\RuntimeException $throwable) {
            if (\hash_equals(
                'Timed out acquiring the WLS state lock.',
                $throwable->getMessage(),
            )) {
                return [
                    'attempted' => 0,
                    'completed' => 0,
                    'failures' => [],
                    'deferred' => true,
                ];
            }
            throw $throwable;
        }
    }

    /** @param array<string,mixed> $revocationIntent */
    protected function publishCertificateRuntimeUpdate(
        string $domain,
        array $revocationIntent,
    ): void {
        ObjectManager::getInstance(\Weline\Server\Service\Edge\EdgeAdapterResolver::class)
            ->resolve()
            ->onCertificateMaterialUpdated(
                $domain,
                $revocationIntent === []
                    ? []
                    : ['wls_revocation_intent' => $revocationIntent],
            );
    }

    protected function writeLegacyCertificateCompatibilityMap(
        ?float $deadlineMonotonic = null,
    ): void
    {
        if ($deadlineMonotonic !== null) {
            $this->assertCertificateRetirementBudget($deadlineMonotonic, 0.01);
        }
        // WLS 1.x workers still consume this compatibility map. WLS 2.0 TLS
        // workers never read it; their only serving input is the bound
        // immutable manifest published by the edge coordinator.
        $mapFile = Env::VAR_DIR . 'server' . DS . 'ssl_certificate_map.json';
        $mapDir = \dirname($mapFile);
        if (!\is_dir($mapDir)) {
            @\mkdir($mapDir, 0755, true);
        }
        $map = $this->getCertificateMap();
        if ($deadlineMonotonic !== null) {
            $this->assertCertificateRetirementBudget($deadlineMonotonic, 0.01);
        }
        $encodedMap = \json_encode(
            $map,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $this->writeLockedSslStateAtomically(
            $mapFile,
            $encodedMap,
            0600,
            self::MAX_CERTIFICATE_MATERIAL_BYTES,
            'legacy certificate compatibility map',
            function (string $candidate): void {
                $this->assertLegacyCertificateCompatibilityMap($candidate);
            },
        );
        if ($deadlineMonotonic !== null) {
            $this->assertCertificateRetirementBudget($deadlineMonotonic, 0.01);
        }
        w_log_debug(
            '[SslCertificateService] 兼容证书映射已重新生成，包含 '
            . \count($map) . ' 个域名',
        );
    }

    private function assertLegacyCertificateCompatibilityMap(string $contents): void
    {
        try {
            $map = \json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'Legacy certificate compatibility map JSON is malformed.',
                0,
                $exception,
            );
        }
        if (!\is_array($map)
            || (\array_is_list($map) && $map !== [])
            || \count($map) > self::MAX_LEGACY_CERTIFICATE_MAP_ENTRIES
        ) {
            throw new \RuntimeException(
                'Legacy certificate compatibility map has an invalid shape or size.',
            );
        }
        $allowedFields = [
            'cert',
            'key',
            'chain',
            'cert_type',
            'force_https',
            'force_root_to_www',
        ];
        foreach ($map as $domain => $entry) {
            if (!\is_string($domain)
                || !\is_array($entry)
                || \array_is_list($entry)
            ) {
                throw new \RuntimeException(
                    'Legacy certificate compatibility map entry is malformed.',
                );
            }
            try {
                $normalized = self::normalizeCertificateStorageDomain($domain);
            } catch (\Throwable $throwable) {
                throw new \RuntimeException(
                    'Legacy certificate compatibility map domain is invalid.',
                    0,
                    $throwable,
                );
            }
            if (!\hash_equals($normalized, $domain)) {
                throw new \RuntimeException(
                    'Legacy certificate compatibility map domain is not canonical.',
                );
            }
            $fields = \array_keys($entry);
            foreach ($fields as $field) {
                if (!\is_string($field) || !\in_array($field, $allowedFields, true)) {
                    throw new \RuntimeException(
                        'Legacy certificate compatibility map contains an unknown field.',
                    );
                }
            }
            foreach (['cert', 'key'] as $requiredPath) {
                $value = $entry[$requiredPath] ?? null;
                if (!\is_string($value)
                    || $value === ''
                    || \strlen($value) > 4096
                    || \str_contains($value, "\0")
                ) {
                    throw new \RuntimeException(
                        'Legacy certificate compatibility map path is invalid.',
                    );
                }
            }
            if (\array_key_exists('chain', $entry)
                && (!\is_string($entry['chain'])
                    || \strlen($entry['chain']) > 4096
                    || \str_contains($entry['chain'], "\0"))
            ) {
                throw new \RuntimeException(
                    'Legacy certificate compatibility map chain path is invalid.',
                );
            }
            if (\array_key_exists('cert_type', $entry)
                && (!\is_string($entry['cert_type']) || $entry['cert_type'] === '')
            ) {
                throw new \RuntimeException(
                    'Legacy certificate compatibility map certificate type is invalid.',
                );
            }
            foreach (['force_https', 'force_root_to_www'] as $policy) {
                if (!\array_key_exists($policy, $entry)) {
                    continue;
                }
                $value = $entry[$policy];
                if (!\is_bool($value)
                    && !(\is_int($value) && ($value === 0 || $value === 1))
                ) {
                    throw new \RuntimeException(
                        'Legacy certificate compatibility map policy is invalid.',
                    );
                }
            }
        }
    }
}
