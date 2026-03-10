<?php
declare(strict_types=1);

namespace Weline\Server\Extends\Module\Weline_Framework\Query;

use Weline\Framework\App\Env;
use Weline\Server\IPC\ControlMessage;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Server\Model\SslCertificate as CertModel;
use Weline\Server\Service\Control\BackendStatusService;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\OptimizationGuideService;
use Weline\Server\Service\SslCertificateService;
use Weline\Server\Service\Control\SharedStateAdminService;

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
        private readonly SharedStateAdminService $sharedStateAdminService,
        private readonly CertModel $sslCertModel
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
            'status' => $this->status($params),
            'start' => $this->start($params),
            'stop' => $this->stop($params),
            'restart' => $this->restart($params),
            'reload' => $this->reload($params),
            'maintenanceEnable' => $this->maintenanceEnable($params),
            'maintenanceDisable' => $this->maintenanceDisable($params),
            'optimizationData' => $this->optimizationData(),
            'listCertificates' => $this->listCertificates(),
            'toggleHttps' => $this->toggleHttps($params),
            'renewCertificate' => $this->renewCertificate($params),
            'deleteCertificate' => $this->deleteCertificate($params),
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
            default => throw new \InvalidArgumentException(
                (string)__('Server 查询器不支持的操作：%{1}', $operation)
            ),
        };
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
                ['name' => 'status', 'description' => __('获取服务器状态'), 'params' => []],
                ['name' => 'start', 'description' => __('启动实例'), 'params' => []],
                ['name' => 'stop', 'description' => __('停止实例'), 'params' => []],
                ['name' => 'restart', 'description' => __('重启实例'), 'params' => []],
                ['name' => 'reload', 'description' => __('热重载实例'), 'params' => []],
                ['name' => 'maintenanceEnable', 'description' => __('启用维护模式'), 'params' => []],
                ['name' => 'maintenanceDisable', 'description' => __('禁用维护模式'), 'params' => []],
                ['name' => 'optimizationData', 'description' => __('获取优化指南数据'), 'params' => []],
                ['name' => 'listCertificates', 'description' => __('获取 SSL 证书列表'), 'params' => []],
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
        if ($webroot === '') {
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
        $provider = (string) ($params['provider'] ?? SslCertificateService::PROVIDER_LETS_ENCRYPT);
        $certType = (string) ($params['cert_type'] ?? 'exact');
        $certStrategy = (string) ($params['cert_strategy'] ?? '');
        $challengeStrategy = (string) ($params['challenge_strategy'] ?? SslCertificateService::CHALLENGE_AUTO);
        $poolId = (int) ($params['pool_id'] ?? 0);
        $domainId = (int) ($params['domain_id'] ?? 0);

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
            $domainId
        );

        $cert = $result['cert'] ?? null;
        $certId = $cert?->getCertId();

        return [
            'success'  => $result['success'] ?? false,
            'message'  => $result['message'] ?? '',
            'cert_id'  => $certId,
            'cert'     => $cert,
            'domain'   => $requestedDomain,
            'requested_domain' => $domain,
        ];
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
        $result = $this->ipcControlGateway->command(
            $instance,
            ControlMessage::ACTION_RELOAD,
            ControlMessage::RELOAD_TYPE_FORCE,
            [],
            8.0
        );
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('重启命令已发送')),
            'servers' => $this->optimizationGuideService->getServerStatus(),
        ];
    }

    private function reload(array $params): array
    {
        $instance = (string)($params['instance'] ?? 'default');
        $result = $this->ipcControlGateway->command(
            $instance,
            ControlMessage::ACTION_RELOAD,
            ControlMessage::RELOAD_TYPE_CODE,
            [],
            8.0
        );
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('热重载命令已发送')),
            'servers' => $this->optimizationGuideService->getServerStatus(),
        ];
    }

    private function maintenanceEnable(array $params): array
    {
        $instance = (string)($params['instance'] ?? 'default');
        $result = $this->ipcControlGateway->command($instance, ControlMessage::ACTION_MAINTENANCE_ENABLE, '', [], 8.0);
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('维护模式已启用')),
            'data' => $result['data'] ?? [],
        ];
    }

    private function maintenanceDisable(array $params): array
    {
        $instance = (string)($params['instance'] ?? 'default');
        $result = $this->ipcControlGateway->command($instance, ControlMessage::ACTION_MAINTENANCE_DISABLE, '', [], 8.0);
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? __('维护模式已禁用')),
            'data' => $result['data'] ?? [],
        ];
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
            'data' => $certificates,
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
}
