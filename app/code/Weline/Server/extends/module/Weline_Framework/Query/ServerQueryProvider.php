<?php
declare(strict_types=1);

namespace Weline\Server\Extends\Module\Weline_Framework\Query;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Console\Server\Hosts\Add as HostsAddCommand;
use Weline\Server\Model\AttackLog;
use Weline\Server\Model\SslCertificate as CertModel;
use Weline\Server\Service\Control\BackendStatusService;
use Weline\Server\Service\Control\BroadcastControlDispatchService;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Control\SharedStateAdminService;
use Weline\Server\Service\HealthAllowCookieService;
use Weline\Server\Service\HostsFileManager;
use Weline\Server\Service\LocalDomainPolicy;
use Weline\Server\Service\OptimizationGuideService;
use Weline\Server\Service\SslCertificateService;

/**
 * Weline Server 统一查询器
 *
 * 暴露证书申请等能力，供其他模块通过 w_query('server', ...) 调用
 */
class ServerQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly SslCertificateService $sslCertificateService,
        private readonly OptimizationGuideService $optimizationGuideService,
        private readonly BackendStatusService $backendStatusService,
        private readonly IpcControlGateway $ipcControlGateway,
        private readonly BroadcastControlDispatchService $broadcastControlDispatchService,
        private readonly SharedStateAdminService $sharedStateAdminService,
        private readonly CertModel $sslCertModel,
        private readonly ?AttackLog $attackLog = null,
        private readonly ?HealthAllowCookieService $healthAllowCookieService = null
    ) {
    }

    public function getProviderName(): string
    {
        return 'server';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'requestCertificate' => $this->requestCertificate($params),
            'importCertificate' => $this->importCertificate($params),
            'checkDomainReachability' => $this->checkDomainReachability($params),
            'resolveManagedCertificate' => $this->resolveManagedCertificate($params),
            'hasValidManagedCertificate' => $this->hasValidManagedCertificate($params),
            'isManagedCertificateHealthy' => $this->isManagedCertificateHealthy($params),
            'status' => $this->status($params),
            'start' => $this->start($params),
            'stop' => $this->stop($params),
            'restart' => $this->restart($params),
            'reload' => $this->reload($params),
            'hostsAdd' => $this->hostsAdd($params),
            'ensureLocalWelineWildcardCertificate' => $this->ensureLocalWelineWildcardCertificate($params),
            'maintenanceEnable' => $this->maintenanceEnable($params),
            'maintenanceDisable' => $this->maintenanceDisable($params),
            'sessionList' => $this->sessionList($params),
            'sessionDestroy' => $this->sessionDestroy($params),
            'sessionPersist' => $this->sessionPersist(),
            'sessionGc' => $this->sessionGc($params),
            'memoryNamespaces' => $this->memoryNamespaces($params),
            'memoryNamespaceDetail' => $this->memoryNamespaceDetail($params),
            'memoryNamespaceClear' => $this->memoryNamespaceClear($params),
            'memoryKeyDelete' => $this->memoryKeyDelete($params),
            'memoryPersist' => $this->memoryPersist(),
            'memoryGc' => $this->memoryGc($params),
            'attackStats' => $this->attackStats($params),
            'setHealthAllowCookie' => $this->setHealthAllowCookie(),
            'optimizationData' => $this->optimizationData(),
            'listCertificates' => $this->listCertificates(),
            'certificateDetail' => $this->certificateDetail($params),
            'toggleHttps' => $this->toggleHttps($params),
            'renewCertificate' => $this->renewCertificate($params),
            'deleteCertificate' => $this->deleteCertificate($params),
            'fiberStats' => $this->fiberStats($params),
            'fiberSetConfig' => $this->fiberSetConfig($params),
            'fiberReleaseIdle' => $this->fiberReleaseIdle($params),
            'pageBuilderPageInvalidate' => $this->pageBuilderPageInvalidate($params),
            default => throw new \InvalidArgumentException(
                (string)__('Server 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    /**
     * 通过 Master 向各 Worker 广播 PageBuilder 单页失效（进程内 handle + ObjectManager）
     *
     * @param array<string, mixed> $params instance?, website_id, handle, is_home_page
     */
    private function pageBuilderPageInvalidate(array $params): array
    {
        $instance = (string)($params['instance'] ?? 'default');

        return $this->ipcControlGateway->command(
            $instance,
            ControlMessage::ACTION_PAGEBUILDER_PAGE_INVALIDATE,
            '',
            [
                'website_id' => (int)($params['website_id'] ?? 0),
                'handle' => (string)($params['handle'] ?? ''),
                'is_home_page' => (bool)($params['is_home_page'] ?? false),
            ],
            4.0
        );
    }

    public function getDescriptor(): array
    {
        return [
            'provider'    => 'server',
            'name'        => __('Server 查询'),
            'description' => __('提供 SSL 证书申请等能力'),
            'module'      => 'Weline_Server',
            'operations'  => [
                [
                    'name'        => 'requestCertificate',
                    'description' => __('申请 SSL 证书'),
                    'params'      => [
                        ['name' => 'domain',      'type' => 'string', 'required' => true,  'description' => __('域名')],
                        ['name' => 'webroot',     'type' => 'string', 'required' => false, 'description' => __('Webroot 路径')],
                        ['name' => 'email',       'type' => 'string', 'required' => false, 'description' => __('联系邮箱')],
                        ['name' => 'website_id',  'type' => 'int',    'required' => false, 'description' => __('关联网站 ID')],
                        ['name' => 'provider',    'type' => 'string', 'required' => false, 'description' => __('证书提供商 letsencrypt/litessl')],
                        ['name' => 'cert_type',   'type' => 'string', 'required' => false, 'description' => __('证书类型 exact|wildcard')],
                        ['name' => 'cert_strategy', 'type' => 'string', 'required' => false, 'description' => __('策略 single|wildcard_prefer|both')],
                        ['name' => 'challenge_strategy', 'type' => 'string', 'required' => false, 'description' => __('验证策略 auto|http01|dns01，非80端口时 auto 自动用 dns01')],
                        ['name' => 'pool_id',     'type' => 'int', 'required' => false, 'description' => __('域名池 ID，DNS-01 时用于解析 DNS 账户')],
                        ['name' => 'domain_id',   'type' => 'int', 'required' => false, 'description' => __('根域名 ID，DNS-01 时用于解析 DNS 账户')],
                    ],
                ],
                [
                    'name'        => 'importCertificate',
                    'description' => __('手动导入 SSL 证书（支持 PEM/PFX）'),
                    'params'      => [
                        ['name' => 'domain', 'type' => 'string', 'required' => true, 'description' => __('域名')],
                        ['name' => 'website_id', 'type' => 'int', 'required' => false, 'description' => __('关联网站 ID')],
                        ['name' => 'provider', 'type' => 'string', 'required' => false, 'description' => __('证书提供商标记，默认 manual')],
                        ['name' => 'fullchain_pem', 'type' => 'string', 'required' => false, 'description' => __('fullchain PEM 文本')],
                        ['name' => 'private_key_pem', 'type' => 'string', 'required' => false, 'description' => __('private key PEM 文本')],
                        ['name' => 'chain_pem', 'type' => 'string', 'required' => false, 'description' => __('chain PEM 文本')],
                        ['name' => 'pfx_base64', 'type' => 'string', 'required' => false, 'description' => __('pfx/p12 文件 base64 内容')],
                        ['name' => 'pfx_password', 'type' => 'string', 'required' => false, 'description' => __('pfx/p12 文件密码')],
                    ],
                ],
                [
                    'name' => 'checkDomainReachability',
                    'description' => __('校验域名 URL 可达性及是否命中期望 IP'),
                    'params' => [
                        ['name' => 'domain', 'type' => 'string', 'required' => true, 'description' => __('域名')],
                        ['name' => 'url', 'type' => 'string', 'required' => false, 'description' => __('访问 URL，默认 https://domain/')],
                        ['name' => 'expected_ipv4', 'type' => 'string', 'required' => false, 'description' => __('期望 IPv4')],
                        ['name' => 'expected_ipv6', 'type' => 'string', 'required' => false, 'description' => __('期望 IPv6')],
                    ],
                ],
                [
                    'name' => 'resolveManagedCertificate',
                    'description' => __('根据主机名解析管理证书，返回安全数据'),
                    'params' => [
                        ['name' => 'hostname', 'type' => 'string', 'required' => true, 'description' => __('主机名')],
                        ['name' => 'preferred_cert_id', 'type' => 'int', 'required' => false, 'description' => __('优先绑定的证书 ID')],
                    ],
                ],
                [
                    'name' => 'hasValidManagedCertificate',
                    'description' => __('判断主机名是否存在 active 且未过期的管理证书'),
                    'params' => [
                        ['name' => 'hostname', 'type' => 'string', 'required' => true, 'description' => __('主机名')],
                        ['name' => 'preferred_cert_id', 'type' => 'int', 'required' => false, 'description' => __('优先绑定的证书 ID')],
                    ],
                ],
                [
                    'name' => 'isManagedCertificateHealthy',
                    'description' => __('判断主机名的管理证书是否健康（含覆盖与文件有效性）'),
                    'params' => [
                        ['name' => 'hostname', 'type' => 'string', 'required' => true, 'description' => __('主机名')],
                    ],
                ],
                ['name' => 'status', 'description' => __('获取服务器状态'), 'params' => []],
                ['name' => 'start', 'description' => __('启动实例'), 'params' => []],
                ['name' => 'stop', 'description' => __('停止实例'), 'params' => []],
                ['name' => 'restart', 'description' => __('重启实例'), 'params' => []],
                ['name' => 'reload', 'description' => __('热重载实例'), 'params' => []],
                ['name' => 'maintenanceEnable', 'description' => __('启用维护模式'), 'params' => []],
                ['name' => 'maintenanceDisable', 'description' => __('禁用维护模式'), 'params' => []],
                ['name' => 'optimizationData', 'description' => __('获取优化指南数据'), 'params' => []],
                ['name' => 'listCertificates', 'description' => __('获取 SSL 证书列表'), 'params' => []],
                [
                    'name' => 'certificateDetail',
                    'description' => __('获取 SSL 证书详情'),
                    'params' => [
                        ['name' => 'domain', 'type' => 'string', 'required' => true, 'description' => __('域名')],
                    ],
                ],
                ['name' => 'fiberStats', 'description' => __('查询各 Worker Fiber 池统计（挂起数、配置）'), 'params' => [['name' => 'instance', 'type' => 'string', 'required' => false, 'description' => __('实例名，默认 default')]]],
                [
                    'name' => 'fiberSetConfig',
                    'description' => __('下发 Fiber 池配置：闲置超时与最大活跃数'),
                    'params' => [
                        ['name' => 'instance', 'type' => 'string', 'required' => false, 'description' => __('实例名')],
                        ['name' => 'idle_ttl_sec', 'type' => 'int', 'required' => false, 'description' => __('挂起超过此秒数释放，0=不自动释放')],
                        ['name' => 'max_active', 'type' => 'int', 'required' => false, 'description' => __('最大挂起 Fiber 数，0=不限制')],
                    ],
                ],
                ['name' => 'fiberReleaseIdle', 'description' => __('通知各 Worker 立即释放闲置 Fiber'), 'params' => [['name' => 'instance', 'type' => 'string', 'required' => false, 'description' => __('实例名')]]],
                [
                    'name' => 'pageBuilderPageInvalidate',
                    'description' => __('广播 PageBuilder 单页失效到各 Worker（handle 静态缓存 + ObjectManager）'),
                    'params' => [
                        ['name' => 'instance', 'type' => 'string', 'required' => false, 'description' => __('实例名')],
                        ['name' => 'website_id', 'type' => 'int', 'required' => true],
                        ['name' => 'handle', 'type' => 'string', 'required' => false],
                        ['name' => 'is_home_page', 'type' => 'bool', 'required' => true],
                    ],
                ],
                [
                    'name' => 'attackStats',
                    'description' => __('读取 WLS 攻击统计'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        ['name' => 'instance', 'type' => 'string', 'required' => false, 'description' => __('实例名')],
                        ['name' => 'days', 'type' => 'int', 'required' => false, 'description' => __('统计天数')],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Load WLS attack stats',
                ],
                [
                    'name' => 'setHealthAllowCookie',
                    'description' => __('为当前后台浏览器设置 WLS 健康检查放行 Cookie'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Set WLS health allow cookie',
                ],
            ],
        ];
    }

    private function requestCertificate(array $params): array
    {
        $domain = (string) ($params['domain'] ?? '');
        if ($domain === '') {
            return ['success' => false, 'message' => __('域名不能为空')];
        }

        $webroot = (string) ($params['webroot'] ?? '');
        if (!empty($params['use_wls_virtual_http01'])) {
            $webroot = SslCertificateService::WEBROOT_WLS_VIRTUAL;
        } elseif ($webroot === '') {
            $webroot = \defined('PUB') ? PUB : (BP . 'pub');
        }

        $email = (string) ($params['email'] ?? '');
        if ($email === '') {
            $email = Env::getInstance()->getConfig('ssl.contact_email') ?? '';
        }
        if ($email === '') {
            $email = 'admin@' . $domain;
        }

        $websiteId = (int) ($params['website_id'] ?? 0);
        $providerRaw = $params['provider'] ?? SslCertificateService::PROVIDER_LETS_ENCRYPT;
        $provider = \is_array($providerRaw)
            ? (string) ($providerRaw[0] ?? SslCertificateService::PROVIDER_LETS_ENCRYPT)
            : (string) $providerRaw;
        if ($provider === '' || $provider === 'Array') {
            $provider = SslCertificateService::PROVIDER_LETS_ENCRYPT;
        }
        $certType = (string) ($params['cert_type'] ?? 'exact');
        $certStrategy = (string) ($params['cert_strategy'] ?? '');
        $challengeStrategy = \trim((string) ($params['challenge_strategy'] ?? SslCertificateService::CHALLENGE_AUTO));
        if ($challengeStrategy === '') {
            $challengeStrategy = SslCertificateService::CHALLENGE_AUTO;
        }
        $poolId = (int) ($params['pool_id'] ?? 0);
        $domainId = (int) ($params['domain_id'] ?? 0);
        $onProgress = $params['_on_progress'] ?? null;
        $onProgress = $onProgress instanceof \Closure ? $onProgress : null;

        $requestedDomain = $domain;
        if ($certType === 'wildcard' || $certStrategy === 'wildcard_prefer' || $certStrategy === 'both') {
            $parts = \explode('.', $domain);
            if (\count($parts) >= 2 && !\str_starts_with($domain, '*.')) {
                \array_shift($parts);
                $requestedDomain = '*.' . \implode('.', $parts);
            }
        }

        $result = $this->sslCertificateService->requestCertificate(
            $requestedDomain,
            $webroot,
            $email,
            $websiteId,
            $provider,
            $challengeStrategy,
            $poolId,
            $domainId,
            $onProgress
        );

        $cert = $result['cert'] ?? null;
        $certId = $cert?->getCertId();
        $certPath = $cert !== null && \method_exists($cert, 'getCertPath') ? (string) $cert->getCertPath() : '';

        return [
            'success'  => $result['success'] ?? false,
            'message'  => $result['message'] ?? '',
            'cert_id'  => $certId,
            'cert_path' => $certPath,
            'cert'     => $cert,
            'domain'   => $requestedDomain,
            'requested_domain' => $domain,
        ];
    }

    private function importCertificate(array $params): array
    {
        $domain = (string) ($params['domain'] ?? '');
        if ($domain === '') {
            return ['success' => false, 'message' => __('域名不能为空')];
        }

        $websiteId = (int) ($params['website_id'] ?? 0);
        $provider = (string) ($params['provider'] ?? 'manual');
        $fullchainPem = (string) ($params['fullchain_pem'] ?? '');
        $privateKeyPem = (string) ($params['private_key_pem'] ?? '');
        $chainPem = (string) ($params['chain_pem'] ?? '');
        $pfxBase64 = (string) ($params['pfx_base64'] ?? '');
        $pfxPassword = (string) ($params['pfx_password'] ?? '');

        if ($pfxBase64 !== '') {
            $decoded = \base64_decode($pfxBase64, true);
            if ($decoded === false) {
                return ['success' => false, 'message' => __('PFX 文件内容无效（Base64 解码失败）')];
            }
            $certs = [];
            if (!@\openssl_pkcs12_read($decoded, $certs, $pfxPassword)) {
                return ['success' => false, 'message' => __('PFX/P12 解析失败，请确认文件与密码正确')];
            }
            $fullchainPem = (string) ($certs['cert'] ?? '');
            $privateKeyPem = (string) ($certs['pkey'] ?? '');
            if ($chainPem === '') {
                $extraCerts = $certs['extracerts'] ?? '';
                if (\is_array($extraCerts)) {
                    $chainPem = \implode("\n", $extraCerts);
                } else {
                    $chainPem = (string) $extraCerts;
                }
            }
        }
        if ($fullchainPem === '' || $privateKeyPem === '') {
            return ['success' => false, 'message' => __('证书内容不完整，请提供 fullchain 与 private key')];
        }

        $result = $this->sslCertificateService->importManualCertificate(
            $domain,
            $fullchainPem,
            $privateKeyPem,
            $chainPem,
            $websiteId,
            true,
            $provider
        );

        return [
            'success' => (bool) ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
            'cert_id' => (int) (($result['cert_id'] ?? 0)),
            'cert' => $result['cert'] ?? null,
        ];
    }

    private function checkDomainReachability(array $params): array
    {
        $domain = \strtolower(\trim((string) ($params['domain'] ?? '')));
        if ($domain === '') {
            return ['success' => false, 'message' => __('域名不能为空')];
        }

        $url = \trim((string) ($params['url'] ?? ''));
        $baseUrl = $url !== '' ? $url : ('https://' . $domain . '/');
        $probe = $this->createTemporaryReachabilityProbe();
        if (!(bool) ($probe['success'] ?? false)) {
            return ['success' => false, 'message' => (string) ($probe['message'] ?? __('创建检测地址失败'))];
        }
        $probeUrl = $this->composeProbeUrl($baseUrl, (string) ($probe['path'] ?? ''));
        $expectedIpv4 = \trim((string) ($params['expected_ipv4'] ?? ''));
        $expectedIpv6 = \trim((string) ($params['expected_ipv6'] ?? ''));

        $aRecords = @\dns_get_record($domain, DNS_A) ?: [];
        $aaaaRecords = @\dns_get_record($domain, DNS_AAAA) ?: [];
        $resolvedIpv4 = [];
        $resolvedIpv6 = [];
        foreach ($aRecords as $record) {
            $ip = (string) ($record['ip'] ?? '');
            if ($ip !== '') {
                $resolvedIpv4[] = $ip;
            }
        }
        foreach ($aaaaRecords as $record) {
            $ip = (string) ($record['ipv6'] ?? '');
            if ($ip !== '') {
                $resolvedIpv6[] = $ip;
            }
        }
        $resolved = $resolvedIpv4 !== [] || $resolvedIpv6 !== [];

        $ipMatched = false;
        if ($expectedIpv4 === '' && $expectedIpv6 === '') {
            $ipMatched = $resolved;
        } else {
            if ($expectedIpv4 !== '' && \in_array($expectedIpv4, $resolvedIpv4, true)) {
                $ipMatched = true;
            }
            if (!$ipMatched && $expectedIpv6 !== '' && \in_array($expectedIpv6, $resolvedIpv6, true)) {
                $ipMatched = true;
            }
        }

        $probeToken = (string) ($probe['token'] ?? '');
        $curlCheck = static function (string $targetUrl, string $token): array {
            $ch = \curl_init($targetUrl);
            if ($ch === false) {
                return ['reachable' => false, 'http_code' => 0, 'error' => 'curl_init failed', 'url' => $targetUrl];
            }
            \curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => false,
                CURLOPT_HEADER => false,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $body = \curl_exec($ch);
            $errno = \curl_errno($ch);
            $error = \curl_error($ch);
            $code = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);
            $hasToken = \is_string($body) && $token !== '' && \str_contains($body, $token);
            $reachable = $errno === 0 && $code > 0 && $code < 500 && $hasToken;
            return [
                'reachable' => $reachable,
                'http_code' => $code,
                'error' => $reachable ? '' : ($error !== '' ? $error : ($hasToken ? ('HTTP ' . $code) : 'probe_token_mismatch')),
                'url' => $targetUrl,
            ];
        };
        try {
            $reachResult = $curlCheck($probeUrl, $probeToken);
            if (!$reachResult['reachable'] && \str_starts_with(\strtolower($probeUrl), 'https://')) {
                $fallbackUrl = 'http://' . \preg_replace('#^https://#i', '', $probeUrl);
                $fallback = $curlCheck($fallbackUrl, $probeToken);
                if ($fallback['reachable']) {
                    $reachResult = $fallback;
                }
            }
        } finally {
            $this->removeTemporaryReachabilityProbe((string) ($probe['file'] ?? ''));
        }

        $success = $resolved && $ipMatched && (bool) $reachResult['reachable'];
        $message = $success
            ? (string) __('校验通过：域名已解析到期望 IP 且 URL 可达')
            : (string) __('校验失败：请确认域名解析已指向本机且 URL 可访问');

        return [
            'success' => $success,
            'message' => $message,
            'domain' => $domain,
            'probe_url' => $probeUrl,
            'resolved' => $resolved,
            'ip_matched' => $ipMatched,
            'resolved_ipv4' => $resolvedIpv4,
            'resolved_ipv6' => $resolvedIpv6,
            'expected_ipv4' => $expectedIpv4,
            'expected_ipv6' => $expectedIpv6,
            'reachability' => $reachResult,
        ];
    }

    /**
     * 检测时临时创建一个静态探测地址，检测后删除。
     */
    private function createTemporaryReachabilityProbe(): array
    {
        $pubDir = \defined('PUB') ? \rtrim((string) PUB, '\\/') : \rtrim((string) (BP . DS . 'pub'), '\\/');
        $probeDir = $pubDir . DS . 'wls-reachability';
        if (!\is_dir($probeDir) && !@\mkdir($probeDir, 0755, true) && !\is_dir($probeDir)) {
            return ['success' => false, 'message' => __('创建检测目录失败')];
        }
        $token = \bin2hex(\random_bytes(16));
        $filename = $token . '.txt';
        $file = $probeDir . DS . $filename;
        if (\file_put_contents($file, $token) === false) {
            return ['success' => false, 'message' => __('创建检测文件失败')];
        }
        return [
            'success' => true,
            'token' => $token,
            'path' => '/wls-reachability/' . $filename,
            'file' => $file,
        ];
    }

    private function removeTemporaryReachabilityProbe(string $file): void
    {
        if ($file !== '' && \is_file($file)) {
            @\unlink($file);
        }
    }

    private function composeProbeUrl(string $baseUrl, string $probePath): string
    {
        $parts = \parse_url($baseUrl);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? (':' . (int) $parts['port']) : '';
        if ($host === '') {
            return $baseUrl;
        }
        return $scheme . '://' . $host . $port . $probePath;
    }

    private function status(array $params): array
    {
        $instance = (string)($params['instance'] ?? 'default');
        $statusDto = $this->backendStatusService->getStatusDto($instance, true);
        return [
            'success' => true,
            'servers' => $this->optimizationGuideService->getServerStatus(),
            'summary' => $this->optimizationGuideService->getOptimizationSummary(),
            'php_info' => $this->optimizationGuideService->getPhpInfo(),
            'orchestrator' => $statusDto,
            'session' => $this->sharedStateAdminService->getSessionOverview(),
            'memory' => $this->sharedStateAdminService->getMemoryOverview(),
            'timestamp' => \time(),
        ];
    }

    private function start(array $params): array
    {
        $instance = (string)($params['instance'] ?? 'default');
        $workers = (int)($params['workers'] ?? 0);
        $result = $this->ipcControlGateway->startInstance($instance, $workers);
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('启动命令已提交')),
            'servers' => $this->optimizationGuideService->getServerStatus(),
        ];
    }

    private function stop(array $params): array
    {
        $instance = (string)($params['instance'] ?? 'default');
        $result = $this->ipcControlGateway->command($instance, ControlMessage::ACTION_STOP, '', [], 8.0);
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('停止命令已发送')),
            'servers' => $this->optimizationGuideService->getServerStatus(),
        ];
    }

    private function restart(array $params): array
    {
        $instance = (string)($params['instance'] ?? 'default');
        $result = $this->broadcastControlDispatchService->reloadAsync($instance, ControlMessage::RELOAD_TYPE_FORCE, 8.0);
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('重启命令已发送')),
            'servers' => $this->optimizationGuideService->getServerStatus(),
        ];
    }

    private function reload(array $params): array
    {
        $instance = (string)($params['instance'] ?? 'default');
        $result = $this->broadcastControlDispatchService->reloadAsync($instance, ControlMessage::RELOAD_TYPE_CODE, 8.0);
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('热重载命令已发送')),
            'servers' => $this->optimizationGuideService->getServerStatus(),
        ];
    }

    private function hostsAdd(array $params): array
    {
        $envType = (string) Env::getInstance()->getConfig('system.env', 'local');
        if (!\in_array($envType, ['local', 'dev', 'test'], true)) {
            return [
                'success' => false,
                'message' => (string)__('Only local/dev/test environment may write hosts entries'),
                'needs_admin' => false,
            ];
        }

        $domain = \strtolower(\trim((string)($params['domain'] ?? '')));
        $ip = \trim((string)($params['ip'] ?? '127.0.0.1'));
        if ($ip === '') {
            $ip = '127.0.0.1';
        }
        if ($domain === '') {
            return ['success' => false, 'message' => (string)__('Domain is required')];
        }
        if (!$this->isEligibleWelineLocalHostDomain($domain)) {
            return [
                'success' => false,
                'message' => (string)__('Only managed local WLS domains may be injected into local hosts'),
                'domain' => $domain,
            ];
        }
        if (!LocalDomainPolicy::requiresHostsEntry($domain)) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => (string)__('The requested .localhost domain resolves to loopback and does not require a hosts entry'),
                'domain' => $domain,
                'ip' => $ip,
            ];
        }
        if (!HostsAddCommand::isEligibleLocalHostname($domain)) {
            return [
                'success' => false,
                'message' => (string)__('The requested domain is not a valid local hostname'),
                'domain' => $domain,
            ];
        }

        return HostsFileManager::addDomain($domain, $ip) + [
            'domain' => $domain,
            'ip' => $ip,
        ];
    }

    private function ensureLocalWelineWildcardCertificate(array $params): array
    {
        $websiteId = (int)($params['website_id'] ?? 0);
        $domain = \strtolower(\trim((string)($params['domain'] ?? LocalDomainPolicy::currentWildcardDomain())));
        if (!LocalDomainPolicy::isManagedWildcardDomain($domain)) {
            return [
                'success' => false,
                'message' => (string)__('Only managed local wildcard domains are allowed for local wildcard certificate issuance'),
                'domain' => $domain,
            ];
        }

        $result = $this->sslCertificateService->ensureCertificate($domain, '', '', $websiteId);
        if (\is_array($result)) {
            $result['domain'] = $domain;
        }

        return \is_array($result) ? $result : [
            'success' => false,
            'message' => (string)__('Failed to ensure the managed local wildcard certificate'),
            'domain' => $domain,
        ];
    }

    private function maintenanceEnable(array $params): array
    {
        $instance = (string)($params['instance'] ?? 'default');
        $result = $this->ipcControlGateway->setMaintenanceMode($instance, true, 8.0);
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('维护模式已启用')),
            'data' => $result['data'] ?? [],
        ];
    }

    private function maintenanceDisable(array $params): array
    {
        $instance = (string)($params['instance'] ?? 'default');
        $result = $this->ipcControlGateway->setMaintenanceMode($instance, false, 8.0);
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('维护模式已禁用')),
            'data' => $result['data'] ?? [],
        ];
    }

    private function attackStats(array $params): array
    {
        if (!$this->isBackendLoggedIn()) {
            return ['success' => false, 'message' => __('请先登录后台')];
        }

        $instance = (string)($params['instance'] ?? '');
        $days = (int)($params['days'] ?? 7);

        return [
            'success' => true,
            'data' => $this->attackLog()->getStatistics($instance, $days),
        ];
    }

    private function setHealthAllowCookie(): array
    {
        return $this->healthAllowCookieService()->issue();
    }

    private function optimizationData(): array
    {
        return [
            'success' => true,
            'php_info' => $this->optimizationGuideService->getPhpInfo(),
            'summary' => $this->optimizationGuideService->getOptimizationSummary(),
            'server_status' => $this->optimizationGuideService->getServerStatus(),
            'is_windows' => \strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN',
            'timestamp' => \time(),
        ];
    }

    private function listCertificates(): array
    {
        $this->sslCertificateService->syncCertificatesFromStorage();
        $certificates = $this->sslCertModel->clearQuery()
            ->order(CertModel::schema_fields_DOMAIN)
            ->select()
            ->fetchArray();
        return [
            'success' => true,
            'data' => \array_map([$this, 'stripSensitiveCertificateFields'], $certificates),
        ];
    }

    private function certificateDetail(array $params): array
    {
        $domain = \strtolower(\trim((string) ($params['domain'] ?? '')));
        if ($domain === '') {
            return ['success' => false, 'message' => __('请指定域名')];
        }

        $cert = $this->sslCertModel->clearQuery()->loadByDomain($domain);
        if (!$cert->getCertId()) {
            return ['success' => false, 'message' => __('未找到证书记录')];
        }

        $data = $cert->getData();
        if ($cert->certificateFilesExist()) {
            $data['cert_info'] = $this->sslCertificateService->parseCertificate($cert->getCertPath());
        }

        $data['pem_summary'] = [
            'has_cert_pem' => $cert->getCertPem() !== '',
            'has_key_pem' => $cert->getKeyPem() !== '',
            'has_chain_pem' => $cert->getChainPem() !== '',
        ];

        return [
            'success' => true,
            'data' => $data,
        ];
    }

    private function toggleHttps(array $params): array
    {
        $domain = (string)($params['domain'] ?? '');
        $enabled = (bool)($params['enabled'] ?? false);
        if ($domain === '') {
            return ['success' => false, 'message' => __('请指定域名')];
        }
        return $this->sslCertificateService->toggleHttps($domain, $enabled);
    }

    private function renewCertificate(array $params): array
    {
        $domain = (string)($params['domain'] ?? '');
        $email = (string)($params['email'] ?? '');
        if ($domain === '') {
            return ['success' => false, 'message' => __('请指定域名')];
        }
        $cert = $this->sslCertModel->clearQuery()->loadByDomain($domain);
        if (!$cert->getCertId()) {
            return ['success' => false, 'message' => __('未找到证书记录')];
        }
        $webroot = BP . 'pub';
        return $this->sslCertificateService->renewCertificate($cert, $webroot, $email ?: 'admin@' . $domain);
    }

    private function deleteCertificate(array $params): array
    {
        $domain = \strtolower(\trim((string)($params['domain'] ?? '')));
        if ($domain === '') {
            return ['success' => false, 'message' => __('请指定域名')];
        }
        if (\in_array($domain, ['localhost', '127.0.0.1', '::1'], true)) {
            return ['success' => false, 'message' => __('本地域名证书不允许删除')];
        }
        $cert = $this->sslCertModel->clearQuery()->loadByDomain($domain);
        if (!$cert->getCertId()) {
            return ['success' => false, 'message' => __('未找到证书记录')];
        }
        if ($cert->isHttpsEnabled()) {
            $cert->setHttpsEnabled(false)->save();
        }
        $certDir = $cert->getCertificateDir();
        if (\is_dir($certDir)) {
            $files = \glob($certDir . '*');
            foreach ($files as $file) {
                @\unlink($file);
            }
            @\rmdir($certDir);
        }
        $cert->clearQuery()->where(CertModel::schema_fields_ID, $cert->getCertId())->delete()->fetch();
        return ['success' => true, 'message' => __('证书已删除')];
    }

    private function stripSensitiveCertificateFields(array $certificate): array
    {
        unset(
            $certificate[CertModel::schema_fields_CERT_PEM],
            $certificate[CertModel::schema_fields_KEY_PEM],
            $certificate[CertModel::schema_fields_CHAIN_PEM]
        );

        return $certificate;
    }

    private function sessionList(array $params): array
    {
        $limit = (int)($params['limit'] ?? 50);
        return [
            'success' => true,
            'message' => (string)__('Session 列表加载完成'),
            'data' => $this->sharedStateAdminService->listSessions([], $limit),
        ];
    }

    private function sessionDestroy(array $params): array
    {
        $sessionId = (string)($params['session_id'] ?? '');
        if ($sessionId === '') {
            return ['success' => false, 'message' => (string)__('缺少 Session ID')];
        }
        $ok = $this->sharedStateAdminService->destroySession($sessionId);
        return [
            'success' => $ok,
            'message' => $ok ? (string)__('Session 已销毁') : (string)__('Session 销毁失败，请稍后重试'),
        ];
    }

    private function sessionPersist(): array
    {
        $ok = $this->sharedStateAdminService->persistSession();
        return [
            'success' => $ok,
            'message' => $ok ? (string)__('Session 数据已持久化') : (string)__('Session 持久化失败'),
        ];
    }

    private function sessionGc(array $params): array
    {
        $maxLifetime = (int)($params['max_lifetime'] ?? 3600);
        $ok = $this->sharedStateAdminService->gcSession($maxLifetime);
        return [
            'success' => $ok,
            'message' => $ok ? (string)__('Session 垃圾回收已执行') : (string)__('Session 垃圾回收执行失败'),
        ];
    }

    private function memoryNamespaces(array $params): array
    {
        $limit = (int)($params['limit'] ?? 200);
        return [
            'success' => true,
            'message' => (string)__('内存命名空间加载完成'),
            'data' => $this->sharedStateAdminService->listMemoryNamespaces($limit),
        ];
    }

    private function memoryNamespaceDetail(array $params): array
    {
        $namespace = (string)($params['namespace'] ?? '');
        $limit = (int)($params['limit'] ?? 100);
        if ($namespace === '') {
            return ['success' => false, 'message' => (string)__('缺少命名空间参数'), 'data' => []];
        }
        return [
            'success' => true,
            'message' => (string)__('命名空间详情加载完成'),
            'data' => $this->sharedStateAdminService->getMemoryNamespaceDetail($namespace, $limit),
        ];
    }

    private function memoryNamespaceClear(array $params): array
    {
        $namespace = (string)($params['namespace'] ?? '');
        if ($namespace === '') {
            return ['success' => false, 'message' => (string)__('缺少命名空间参数')];
        }
        $ok = $this->sharedStateAdminService->clearMemoryNamespace($namespace);
        return [
            'success' => $ok,
            'message' => $ok ? (string)__('内存命名空间已清空') : (string)__('命名空间清理失败'),
        ];
    }

    private function memoryKeyDelete(array $params): array
    {
        $namespace = (string)($params['namespace'] ?? '');
        $key = (string)($params['key'] ?? '');
        if ($namespace === '' || $key === '') {
            return ['success' => false, 'message' => (string)__('缺少命名空间或键名参数')];
        }
        $ok = $this->sharedStateAdminService->deleteMemoryKey($namespace, $key);
        return [
            'success' => $ok,
            'message' => $ok ? (string)__('缓存键已删除') : (string)__('缓存键删除失败'),
        ];
    }

    private function memoryPersist(): array
    {
        $ok = $this->sharedStateAdminService->persistMemory();
        return [
            'success' => $ok,
            'message' => $ok ? (string)__('内存服务数据已持久化') : (string)__('内存服务持久化失败'),
        ];
    }

    private function memoryGc(array $params): array
    {
        $maxLifetime = (int)($params['max_lifetime'] ?? 3600);
        $ok = $this->sharedStateAdminService->gcMemory($maxLifetime);
        return [
            'success' => $ok,
            'message' => $ok ? (string)__('内存服务垃圾回收已执行') : (string)__('内存服务垃圾回收执行失败'),
        ];
    }

    /**
     * 查询各 Worker 的 Fiber 池统计（挂起数、配置）
     */
    private function fiberStats(array $params): array
    {
        $instance = (string)($params['instance'] ?? 'default');
        $result = $this->ipcControlGateway->command(
            $instance,
            ControlMessage::ACTION_FIBER_STATS,
            '',
            [],
            12.0
        );
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? ''),
            'data' => (array)($result['data'] ?? []),
        ];
    }

    /**
     * 下发 Fiber 池配置（闲置超时、最大活跃数）
     *
     * @param array $params idle_ttl_sec (秒), max_active (0=不限制)
     */
    private function fiberSetConfig(array $params): array
    {
        $instance = (string)($params['instance'] ?? 'default');
        $idleTtlSec = (int)($params['idle_ttl_sec'] ?? 0);
        $maxActive = (int)($params['max_active'] ?? 0);
        $result = $this->ipcControlGateway->command(
            $instance,
            ControlMessage::ACTION_FIBER_SET_CONFIG,
            '',
            ['idle_ttl_sec' => $idleTtlSec, 'max_active' => $maxActive],
            8.0
        );
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? ''),
            'data' => (array)($result['data'] ?? []),
        ];
    }

    /**
     * 通知各 Worker 立即释放闲置 Fiber
     */
    private function fiberReleaseIdle(array $params): array
    {
        $instance = (string)($params['instance'] ?? 'default');
        $result = $this->ipcControlGateway->command(
            $instance,
            ControlMessage::ACTION_FIBER_RELEASE_IDLE,
            '',
            [],
            8.0
        );
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? ''),
            'data' => (array)($result['data'] ?? []),
        ];
    }

    private function resolveManagedCertificate(array $params): ?array
    {
        $hostname = \strtolower(\trim((string)($params['hostname'] ?? '')));
        $preferredCertIdRaw = $params['preferred_cert_id'] ?? null;
        $preferredCertId = $preferredCertIdRaw !== null && $preferredCertIdRaw !== ''
            ? (int)$preferredCertIdRaw
            : null;
        if ($hostname === '') {
            return null;
        }

        $cert = CertModel::resolveForWebsiteInfrastructure($preferredCertId, $hostname);
        if ($cert === null || $cert->getCertId() <= 0) {
            return null;
        }

        return $this->buildManagedCertificatePayload($cert, $hostname);
    }

    private function hasValidManagedCertificate(array $params): bool
    {
        $cert = $this->resolveManagedCertificate($params);
        if ($cert === null) {
            return false;
        }

        return (string)($cert['status'] ?? '') === CertModel::STATUS_ACTIVE
            && !((bool)($cert['is_expired'] ?? true));
    }

    private function isManagedCertificateHealthy(array $params): bool
    {
        $hostname = \strtolower(\trim((string)($params['hostname'] ?? '')));
        if ($hostname === '') {
            return false;
        }

        return $this->sslCertificateService->isManagedCertificateHealthyForHostname($hostname);
    }

    private function buildManagedCertificatePayload(CertModel $cert, string $hostname): array
    {
        $payload = $cert->toSafeArray();
        $payload['cert_id'] = (int)($payload['cert_id'] ?? $cert->getCertId());
        $payload['status'] = (string)($payload['status'] ?? $cert->getStatus());
        $payload['expires_at'] = (string)($payload['expires_at'] ?? $cert->getExpiresAt());
        $payload['is_expired'] = $cert->isExpired();
        $payload['valid'] = $cert->getStatus() === CertModel::STATUS_ACTIVE && !$cert->isExpired();
        $payload['covers_hostname'] = $cert->coversHostname($hostname);

        return $payload;
    }

    private function isBackendLoggedIn(): bool
    {
        return SessionFactory::getInstance()->createBackendSession()->isLoggedIn();
    }

    private function attackLog(): AttackLog
    {
        return $this->attackLog ?? ObjectManager::getInstance(AttackLog::class);
    }

    private function healthAllowCookieService(): HealthAllowCookieService
    {
        return $this->healthAllowCookieService ?? ObjectManager::getInstance(HealthAllowCookieService::class);
    }

    private function isEligibleWelineLocalHostDomain(string $domain): bool
    {
        return LocalDomainPolicy::isManagedSingleLabelSubdomain($domain);
    }
}
