<?php

declare(strict_types=1);

namespace Weline\Server\Security;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\Policy\PolicyStage;
use Weline\Framework\Runtime\Policy\RequestEnvelope;
use Weline\Framework\Runtime\Policy\RuntimePolicyBundle;
use Weline\Framework\Runtime\Policy\RuntimePolicyDescriptor;
use Weline\Server\Service\AttackLogService;
use Weline\Server\Service\Contract\MemoryStateFacadeInterface;
use Weline\Server\Service\MemoryStateFacade;
use Weline\Server\Service\Policy\RuntimePolicyCompiler;
use Weline\Server\Service\Policy\RuntimePolicyStore;
use Weline\Server\Service\Policy\RuntimePolicyValidator;
use Weline\Server\Service\Runtime\RoutingPolicyRegistry;

require_once \dirname(__DIR__) . '/bin/worker_http_message.php';

/**
 * Topology-neutral mandatory request policy engine.
 *
 * It is booted once per Worker, consumes immutable data descriptors and runs
 * before Static/FPC in every transport adapter. Dispatcher and direct modes
 * therefore differ only at the connection/transport layer.
 */
final class WorkerPolicyKernel
{
    private const MAX_ATTACK_URI_BYTES = 8192;

    private const MAX_ATTACK_HEADER_BYTES = 2048;

    private const MAX_ATTACK_SUBJECT_BYTES = 65536;

    /**
     * Normal browser asset fan-out must not look like a path scanner. Sensitive
     * dot paths and executable/server-side extensions intentionally remain in
     * the scan budget and are still evaluated by protected-path rules.
     *
     * @var array<string, true>
     */
    private const PATH_SCAN_STATIC_EXTENSIONS = [
        'avif' => true,
        'css' => true,
        'eot' => true,
        'gif' => true,
        'ico' => true,
        'jpeg' => true,
        'jpg' => true,
        'js' => true,
        'map' => true,
        'mjs' => true,
        'mp3' => true,
        'mp4' => true,
        'ogg' => true,
        'otf' => true,
        'png' => true,
        'svg' => true,
        'ttf' => true,
        'wasm' => true,
        'wav' => true,
        'webm' => true,
        'webp' => true,
        'woff' => true,
        'woff2' => true,
    ];

    /** @var list<string> */
    private const SAFE_ATTACK_SCAN_HEADERS = [
        'origin',
        'x-forwarded-host',
        'x-original-url',
        'x-rewrite-url',
    ];

    /** @var list<string> */
    private const SAFE_ATTACK_LOG_HEADERS = [
        'accept',
        'content-type',
        'origin',
        'user-agent',
        'x-forwarded-host',
        'x-original-url',
        'x-requested-with',
        'x-rewrite-url',
    ];

    private static ?self $instance = null;

    private RuntimePolicyBundle $bundle;

    /** @var list<RuntimePolicyDescriptor> */
    private array $mandatoryDescriptors = [];

    /** @var list<string> */
    private array $trustedProxyCidrs = [];

    /** @var list<string> */
    private array $whitelistCidrs = [];

    private int $maxRequestHeaderBytes = 65536;

    private int $maxRequestBodyBytes = 16777216;

    /**
     * Request-path cache execution facts compiled once from the active bundle.
     * The integer is copied into each immutable WorkerPolicyDecision so a hot
     * policy activation cannot mix old cache behavior with a new digest.
     */
    private int $cachePolicyFlags = 0;

    private string $loadedDigest = '';

    private bool $maintenanceMode;

    private float $lastStateReconnectAttempt = 0.0;

    private function __construct(
        private readonly string $instanceName,
        private readonly string $topology,
        private readonly int $readyWorkers,
        private readonly CanonicalClientIdentity $identityResolver,
        private readonly GlobalRateLimiter $rateLimiter,
        private ?MemoryStateFacadeInterface $state,
        RuntimePolicyBundle $bundle,
    ) {
        $this->maintenanceMode = (bool)Env::get('system.maintenance', Env::get('maintenance', false));
        $this->installBundle($bundle);
    }

    public static function boot(string $instanceName, string $topology = 'direct', int $readyWorkers = 1): self
    {
        $instanceName = \trim($instanceName) !== '' ? \trim($instanceName) : 'default';
        $topology = \strtolower(\trim($topology));
        if (!\in_array($topology, ['direct', 'dispatcher'], true)) {
            $topology = PHP_OS_FAMILY === 'Windows' ? 'dispatcher' : 'direct';
        }
        $_SERVER['WLS_RUNTIME_TOPOLOGY'] = $topology;
        $_ENV['WLS_RUNTIME_TOPOLOGY'] = $topology;
        @\putenv('WLS_RUNTIME_TOPOLOGY=' . $topology);

        $bundle = RoutingPolicyRegistry::getActiveBundle();
        if (!$bundle instanceof RuntimePolicyBundle) {
            try {
                $bundle = (new RuntimePolicyStore())->active($instanceName);
            } catch (\Throwable) {
                $bundle = null;
            }
        }
        if (!$bundle instanceof RuntimePolicyBundle) {
            $bundle = (new RuntimePolicyCompiler())->compile($topology, ['instance' => $instanceName]);
        }
        if (!$bundle->supportsTopology($topology)) {
            throw new \RuntimeException("Runtime policy {$bundle->digest} does not support topology {$topology}.");
        }
        RoutingPolicyRegistry::prepare($bundle);
        RoutingPolicyRegistry::activate($bundle->digest);

        $state = null;
        try {
            $state = new MemoryStateFacade([
                'consumer_code' => $instanceName . ':policy:' . (string)(\getmypid() ?: 0),
                'prefer_direct_connect' => true,
                'fail_fast_on_unhealthy' => true,
                'connect_timeout' => 0.02,
                'timeout' => 0.02,
                'acquire_timeout' => 0.005,
                'pool_size' => 1,
            ]);
        } catch (\Throwable) {
            $state = null;
        }

        return self::$instance = new self(
            $instanceName,
            $topology,
            \max(1, $readyWorkers),
            new CanonicalClientIdentity(),
            new GlobalRateLimiter($state, \max(1, $readyWorkers), $instanceName),
            $state,
            $bundle,
        );
    }

    public static function instance(): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }
        $instance = (string)(\getenv('WLS_INSTANCE') ?: \getenv('WLS_INSTANCE_NAME') ?: 'default');
        $topology = (string)(\getenv('WLS_RUNTIME_TOPOLOGY') ?: Env::get('wls.runtime.topology', 'auto'));
        $workers = (int)(\getenv('WLS_WORKER_COUNT') ?: Env::get('wls.worker_count', 1));
        return self::boot($instance, $topology, $workers);
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function setMaintenanceMode(bool $enabled): void
    {
        $this->maintenanceMode = $enabled;
    }

    public function clearSecurityBans(?string $ip = null, bool $clearAll = false): bool
    {
        $cleared = $this->rateLimiter->clearBans($ip, $clearAll);
        $connectionGates = ConnectionAcceptGatePool::instanceOrNull();
        if ($connectionGates !== null) {
            $cleared = $connectionGates->clearBans($ip, $clearAll) && $cleared;
        }

        return $cleared;
    }

    public function policyDigest(): string
    {
        $this->refreshActivatedBundle();
        return $this->loadedDigest;
    }

    /**
     * Install the newly activated immutable bundle before an activation ACK is
     * emitted. This keeps descriptor compilation in the control plane instead
     * of charging the first request after a hot policy switch.
     */
    public function synchronizeActivatedBundle(string $expectedDigest): void
    {
        $expectedDigest = \strtolower(\trim($expectedDigest));
        $active = RoutingPolicyRegistry::getActiveBundle();
        if (!$active instanceof RuntimePolicyBundle
            || $expectedDigest === ''
            || !\hash_equals($expectedDigest, $active->digest)
        ) {
            throw new \RuntimeException('Activated Worker policy bundle is unavailable or mismatched.');
        }
        if (!\hash_equals($this->loadedDigest, $active->digest)) {
            $this->installBundle($active);
        }
        if (!\hash_equals($this->loadedDigest, $expectedDigest)) {
            throw new \RuntimeException('Worker policy kernel did not install the activated digest.');
        }
    }

    /** @return array{max_header_bytes:int,max_body_bytes:int,max_buffer_bytes:int} */
    public function framingLimits(): array
    {
        $this->refreshActivatedBundle();

        return [
            'max_header_bytes' => $this->maxRequestHeaderBytes,
            'max_body_bytes' => $this->maxRequestBodyBytes,
            'max_buffer_bytes' => $this->maxRequestHeaderBytes + $this->maxRequestBodyBytes,
        ];
    }

    /**
     * Direct Workers share the policy kernel's already-open SharedState facade
     * with the L4 gate; no second policy connection is created per process.
     */
    public function bootConnectionAcceptGatePool(int $workerOrdinal): ConnectionAcceptGatePool
    {
        $this->refreshActivatedBundle();
        return ConnectionAcceptGatePool::boot(
            topology: $this->topology,
            instanceName: $this->instanceName,
            state: $this->state,
            readyWorkers: $this->readyWorkers,
            workerOrdinal: $workerOrdinal,
            initialBundle: $this->bundle,
        );
    }

    /**
     * @param array<string, mixed>|null $parsedFrame Frame metadata returned by
     *        wlsParseHttpRequestFrame() in the transport adapter.
     */
    public function evaluate(
        string $rawRequest,
        string $transportPeer = '',
        ?array $parsedFrame = null,
    ): WorkerPolicyDecision
    {
        $this->refreshActivatedBundle();
        $this->reconnectSharedStateIfDue();

        $parsed = $this->parseRequest($rawRequest, $parsedFrame);
        if (isset($parsed['error'])) {
            return $this->deny(
                $parsed,
                $this->identityResolver->normalizePeer($transportPeer) ?: '127.0.0.1',
                false,
                400,
                'request_shape',
            );
        }

        $identity = $this->identityResolver->resolve($transportPeer, $parsed['headers'], $this->trustedProxyCidrs);
        $clientIp = $identity['ip'];
        $trustedProxy = $identity['trusted_proxy'];
        $envelope = new RequestEnvelope(
            peerIp: $clientIp,
            method: $parsed['method'],
            path: $parsed['path'],
            host: $parsed['host'],
            headers: $parsed['headers'],
            body: $parsed['body'],
            attributes: [
                'target' => $parsed['target'],
                'query' => $parsed['query'],
                'protocol' => $parsed['protocol'],
                'trusted_proxy' => $trustedProxy,
                'topology' => $this->topology,
                'policy_digest' => $this->loadedDigest,
            ],
        );

        // A loopback transport peer is common when Nginx/Caddy proxies to a
        // direct Worker. It is transport metadata, not proof that the original
        // client is local. Only the compiled, explicit whitelist may bypass
        // bans, quotas and request attack rules.
        $whitelisted = $this->identityResolver->matchesAny($clientIp, $this->whitelistCidrs);
        if (!$whitelisted && $this->rateLimiter->isBanned($clientIp)) {
            return $this->deny($parsed, $clientIp, $trustedProxy, 403, 'shared_ban');
        }

        // Only requests which a transport adapter handles as a dedicated
        // system response may use the system-policy bypass. Prefix matches,
        // aliases and unsupported methods must continue through maintenance,
        // quota and attack policies like ordinary application requests.
        $systemPath = $this->isTransportSystemRequest($parsed['method'], $parsed['path']);

        foreach ($this->mandatoryDescriptors as $descriptor) {
            if (!\in_array($this->topology, $descriptor->supportedTopologies, true)) {
                if ($descriptor->critical) {
                    return $this->deny($parsed, $clientIp, $trustedProxy, 503, 'critical_policy_topology');
                }
                continue;
            }
            $decision = $this->evaluateDescriptor($descriptor, $envelope, $parsed, $whitelisted, $systemPath);
            if ($decision instanceof WorkerPolicyDecision) {
                return $decision;
            }
        }

        return WorkerPolicyDecision::allow(
            $clientIp,
            $parsed['method'],
            $parsed['protocol'],
            $parsed['target'],
            $parsed['path'],
            $parsed['headers'],
            $parsed['body'],
            $this->loadedDigest,
            $trustedProxy,
            $this->cachePolicyFlags,
        );
    }

    private function installBundle(RuntimePolicyBundle $bundle): void
    {
        (new RuntimePolicyValidator())->assertValid($bundle, $this->topology);
        $mandatory = [];
        $trusted = [];
        $whitelist = [];
        $maxHeaderBytes = 65536;
        $maxBodyBytes = 16 * 1024 * 1024;
        $cachePolicyFlags = 0;
        foreach ($bundle->descriptors as $descriptor) {
            if ($descriptor->stage === PolicyStage::CONNECTION
                || $descriptor->stage === PolicyStage::MANDATORY_REQUEST
                || $descriptor->stage === PolicyStage::DEEP_REQUEST
            ) {
                $mandatory[] = $descriptor;
            }
            if (($descriptor->matcher['type'] ?? '') === 'ip_policy') {
                $trusted = $this->normalizeStringList($descriptor->matcher['trusted_proxy_cidrs'] ?? []);
                $whitelist = $this->normalizeStringList($descriptor->matcher['whitelist_cidrs'] ?? []);
            }
            if (($descriptor->matcher['type'] ?? '') === 'request_limits') {
                $maxHeaderBytes = \max(1024, (int)($descriptor->matcher['max_header_bytes'] ?? 65536));
                $maxBodyBytes = \max(0, (int)($descriptor->matcher['max_body_bytes'] ?? 16 * 1024 * 1024));
            }
            if ($descriptor->stage !== PolicyStage::CACHE
                || !\in_array($this->topology, $descriptor->supportedTopologies, true)
                || ($descriptor->action['type'] ?? '') !== 'cache_lookup'
                || !($descriptor->matcher['enabled'] ?? true)
            ) {
                continue;
            }

            $methods = $this->normalizeStringList($descriptor->matcher['methods'] ?? []);
            if (!\in_array('GET', $methods, true) && !\in_array('HEAD', $methods, true)) {
                continue;
            }
            $layers = $descriptor->action['layers'] ?? [$descriptor->action['layer'] ?? ''];
            $layers = $this->normalizeStringList(\is_array($layers) ? $layers : []);
            if ($descriptor->id === 'server.cache.static'
                && ($descriptor->matcher['type'] ?? '') === 'static_cache'
                && \in_array('process_l1', $layers, true)
            ) {
                $cachePolicyFlags |= WorkerPolicyDecision::CACHE_STATIC_PROCESS_L1;
            }
            if ($descriptor->id === 'server.cache.fpc' && ($descriptor->matcher['type'] ?? '') === 'fpc') {
                if (\in_array('process_l1', $layers, true)) {
                    $cachePolicyFlags |= WorkerPolicyDecision::CACHE_FPC_PROCESS_L1;
                }
                if (\in_array('shared_l2', $layers, true)) {
                    $cachePolicyFlags |= WorkerPolicyDecision::CACHE_FPC_SHARED_L2;
                }
            }
        }
        $this->bundle = $bundle;
        $this->mandatoryDescriptors = $mandatory;
        $this->trustedProxyCidrs = $trusted;
        $this->whitelistCidrs = $whitelist;
        $this->maxRequestHeaderBytes = $maxHeaderBytes;
        $this->maxRequestBodyBytes = $maxBodyBytes;
        $this->cachePolicyFlags = $cachePolicyFlags;
        $this->loadedDigest = $bundle->digest;
    }

    private function refreshActivatedBundle(): void
    {
        $active = RoutingPolicyRegistry::getActiveBundle();
        if ($active instanceof RuntimePolicyBundle && !\hash_equals($this->loadedDigest, $active->digest)) {
            $this->installBundle($active);
        }
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private function evaluateDescriptor(
        RuntimePolicyDescriptor $descriptor,
        RequestEnvelope $envelope,
        array $parsed,
        bool $whitelisted,
        bool $systemPath,
    ): ?WorkerPolicyDecision {
        if ($descriptor->stage === PolicyStage::DEEP_REQUEST && $envelope->body === '') {
            return null;
        }
        $type = (string)($descriptor->matcher['type'] ?? '');
        switch ($type) {
            case 'ip_policy':
                return null;

            case 'host_guard':
                if ($parsed['host'] === '' || !$this->validHost($parsed['host'])) {
                    return $this->deny($parsed, $envelope->peerIp, (bool)$envelope->attributes['trusted_proxy'], 400, $descriptor->id);
                }
                $allowedHosts = $this->normalizeStringList($descriptor->matcher['allowed_hosts'] ?? []);
                $managedLocalRoots = $this->normalizeStringList($descriptor->matcher['managed_local_roots'] ?? []);
                $hostOnly = $this->hostWithoutPort($parsed['host']);
                $legacyLocalHost = \preg_match('/^weline-p[0-9a-f]{8}\.local$/iD', $hostOnly) === 1;
                $loopbackAllowed = (bool)($descriptor->matcher['allow_loopback'] ?? false)
                    && ($hostOnly === 'localhost' || $hostOnly === '::1' || \str_starts_with($hostOnly, '127.'));
                $managedLocalAllowed = $this->managedSingleLabelHostAllowed($hostOnly, $managedLocalRoots);
                $strict = (bool)($descriptor->matcher['strict'] ?? ($allowedHosts !== []));
                if ($legacyLocalHost || ($strict
                    && !$loopbackAllowed
                    && !$managedLocalAllowed
                    && !$this->hostAllowed($hostOnly, $allowedHosts)
                )) {
                    return $this->deny($parsed, $envelope->peerIp, (bool)$envelope->attributes['trusted_proxy'], 403, $descriptor->id);
                }
                return null;

            case 'request_limits':
                $maxUri = \max(256, (int)($descriptor->matcher['max_uri_bytes'] ?? 8192));
                $maxHeader = \max(1024, (int)($descriptor->matcher['max_header_bytes'] ?? 65536));
                $maxBody = \max(0, (int)($descriptor->matcher['max_body_bytes'] ?? 16 * 1024 * 1024));
                if (\strlen($parsed['target']) > $maxUri
                    || $parsed['header_bytes'] > $maxHeader
                    || \strlen($parsed['body']) > $maxBody
                ) {
                    $status = \strlen($parsed['body']) > $maxBody ? 413 : 400;
                    return $this->deny($parsed, $envelope->peerIp, (bool)$envelope->attributes['trusted_proxy'], $status, $descriptor->id);
                }
                return null;

            case 'backend_key':
                if ($this->isUnkeyedBackendPath($parsed['path'], $descriptor->matcher)) {
                    return $this->deny($parsed, $envelope->peerIp, (bool)$envelope->attributes['trusted_proxy'], 404, $descriptor->id);
                }
                return null;

            case 'origin_token':
                if ($systemPath || !($descriptor->matcher['enabled'] ?? false)) {
                    return null;
                }
                // Origin authentication follows the same single bypass source
                // as the rest of the policy kernel. Loopback alone is never a
                // credential because it may be a local reverse proxy peer.
                if ($whitelisted) {
                    return null;
                }
                $header = \strtolower((string)($descriptor->matcher['header'] ?? 'X-Weline-Origin-Token'));
                $expectedHash = (string)($descriptor->matcher['token_sha256'] ?? '');
                $received = (string)($envelope->headers[$header] ?? '');
                if ($expectedHash === '' || !\hash_equals($expectedHash, \hash('sha256', $received))) {
                    return $this->deny($parsed, $envelope->peerIp, (bool)$envelope->attributes['trusted_proxy'], 403, $descriptor->id);
                }
                return null;

            case 'token_bucket':
                if ($whitelisted || $systemPath) {
                    return null;
                }
                $config = \is_array($descriptor->matcher['config'] ?? null) ? $descriptor->matcher['config'] : [];
                if (($config['enabled'] ?? true)
                    && !$this->rateLimiter->allow(
                        'global',
                        $envelope->peerIp,
                        (int)($config['max_requests'] ?? 3000),
                        (int)($config['window'] ?? 60),
                    )
                ) {
                    return $this->deny($parsed, $envelope->peerIp, (bool)$envelope->attributes['trusted_proxy'], 429, $descriptor->id);
                }
                return null;

            case 'path_token_bucket':
                if ($whitelisted || $systemPath) {
                    return null;
                }
                return $this->evaluatePathRateLimit($descriptor, $envelope, $parsed);

            case 'attack_rules':
            case 'body_attack_rules':
                if ($whitelisted || $systemPath) {
                    return null;
                }
                if ($type === 'attack_rules') {
                    $pathScan = \is_array($descriptor->matcher['path_scan'] ?? null)
                        ? $descriptor->matcher['path_scan']
                        : [];
                    if (($pathScan['enabled'] ?? false)
                        && $this->shouldTrackPathForScan($envelope)
                        && !$this->rateLimiter->allowUniquePath(
                            $envelope->peerIp,
                            $envelope->path,
                            (int)($pathScan['max_unique_paths'] ?? 50),
                            (int)($pathScan['window'] ?? 60),
                        )
                    ) {
                        $blockDuration = (int)($pathScan['block_duration'] ?? 600);
                        $this->rateLimiter->ban($envelope->peerIp, $blockDuration);
                        AttackLogService::log(
                            [
                                'is_attack' => true,
                                'type' => 'path_scan',
                                'reason' => 'unique request path threshold exceeded',
                                'should_block' => true,
                            ],
                            [
                                'instance' => $this->instanceName,
                                'ip' => $envelope->peerIp,
                                'domain' => $envelope->host,
                                'uri' => $envelope->path,
                                'method' => $envelope->method,
                                'user_agent' => (string)($envelope->headers['user-agent'] ?? ''),
                                'headers' => $this->safeAttackLogHeaders($envelope->headers),
                                'block_duration' => $blockDuration,
                            ],
                        );
                        return $this->deny(
                            $parsed,
                            $envelope->peerIp,
                            (bool)$envelope->attributes['trusted_proxy'],
                            403,
                            $descriptor->id . ':path_scan',
                        );
                    }
                }
                $attack = $this->matchCompiledAttackRules($descriptor, $envelope, $type === 'body_attack_rules');
                if ($attack !== null) {
                    $this->rateLimiter->ban($envelope->peerIp, (int)($attack['block_duration'] ?? 300));
                    AttackLogService::log(
                        ['is_attack' => true, 'type' => $attack['type'], 'reason' => $attack['reason'], 'should_block' => true],
                        [
                            'instance' => $this->instanceName,
                            'ip' => $envelope->peerIp,
                            'domain' => $envelope->host,
                            'uri' => $envelope->path,
                            'method' => $envelope->method,
                            'user_agent' => (string)($envelope->headers['user-agent'] ?? ''),
                            'headers' => $this->safeAttackLogHeaders($envelope->headers),
                            'block_duration' => (int)($attack['block_duration'] ?? 300),
                        ],
                    );
                    return $this->deny($parsed, $envelope->peerIp, (bool)$envelope->attributes['trusted_proxy'], 403, $descriptor->id);
                }
                return null;

            case 'maintenance_epoch':
                if ($systemPath) {
                    return null;
                }
                if ($this->maintenanceMode) {
                    return $this->deny($parsed, $envelope->peerIp, (bool)$envelope->attributes['trusted_proxy'], 503, $descriptor->id);
                }
                return null;

            default:
                return $descriptor->critical
                    ? $this->deny($parsed, $envelope->peerIp, (bool)$envelope->attributes['trusted_proxy'], 503, 'unsupported:' . $descriptor->id)
                    : null;
        }
    }

    /** @param array<string, mixed> $parsed */
    private function evaluatePathRateLimit(
        RuntimePolicyDescriptor $descriptor,
        RequestEnvelope $envelope,
        array $parsed,
    ): ?WorkerPolicyDecision {
        $config = \is_array($descriptor->matcher['config'] ?? null) ? $descriptor->matcher['config'] : [];
        if (!($config['enabled'] ?? true)) {
            return null;
        }
        foreach ((array)($config['rules'] ?? []) as $rule) {
            if (!\is_array($rule) || !($rule['enabled'] ?? true)) {
                continue;
            }
            $prefix = '/' . \trim((string)($rule['path'] ?? ''), '/');
            if ($prefix === '/' || ($envelope->path !== $prefix && !\str_starts_with($envelope->path, $prefix . '/'))) {
                continue;
            }
            if (!$this->rateLimiter->allow(
                'path:' . $prefix,
                $envelope->peerIp,
                (int)($rule['max_requests'] ?? 120),
                (int)($rule['window'] ?? 60),
            )) {
                return $this->deny($parsed, $envelope->peerIp, (bool)$envelope->attributes['trusted_proxy'], 429, $descriptor->id);
            }
        }
        return null;
    }

    private function shouldTrackPathForScan(RequestEnvelope $envelope): bool
    {
        if (!\in_array($envelope->method, ['GET', 'HEAD'], true)) {
            return true;
        }

        $path = \strtolower($envelope->path);
        $slash = \strrpos($path, '/');
        $basename = $slash === false ? $path : \substr($path, $slash + 1);
        if ($basename === '' || \str_starts_with($basename, '.')) {
            return true;
        }

        $dot = \strrpos($basename, '.');
        if ($dot === false || $dot === \strlen($basename) - 1) {
            return true;
        }

        return !isset(self::PATH_SCAN_STATIC_EXTENSIONS[\substr($basename, $dot + 1)]);
    }

    /**
     * @return array{type:string,reason:string,block_duration:int}|null
     */
    private function matchCompiledAttackRules(
        RuntimePolicyDescriptor $descriptor,
        RequestEnvelope $envelope,
        bool $bodyOnly,
    ): ?array {
        $matcher = $descriptor->matcher;
        $malicious = \is_array($matcher['malicious_patterns'] ?? null) ? $matcher['malicious_patterns'] : [];
        if (!$bodyOnly) {
            foreach (['ban_on_path_match', 'protected_paths'] as $ruleName) {
                $rule = \is_array($matcher[$ruleName] ?? null) ? $matcher[$ruleName] : [];
                if (!($rule['enabled'] ?? true)) {
                    continue;
                }
                foreach ((array)($rule['paths'] ?? []) as $path) {
                    $path = \strtolower(\trim((string)$path));
                    if ($path !== '' && \str_contains(\strtolower($envelope->path), $path)) {
                        return [
                            'type' => $ruleName,
                            'reason' => 'request path matched a protected policy',
                            'block_duration' => (int)($rule['block_duration'] ?? 1800),
                        ];
                    }
                }
            }
            $badUa = \is_array($matcher['bad_user_agents'] ?? null) ? $matcher['bad_user_agents'] : [];
            if ($badUa['enabled'] ?? true) {
                $ua = (string)($envelope->headers['user-agent'] ?? '');
                foreach ((array)($badUa['patterns'] ?? []) as $pattern) {
                    if ($this->safeRegexMatch((string)$pattern, $ua)) {
                        return [
                            'type' => 'bad_user_agent',
                            'reason' => 'user agent matched a blocked policy',
                            'block_duration' => (int)($badUa['block_duration'] ?? 300),
                        ];
                    }
                }
            }
        }
        if ($bodyOnly) {
            if (!($malicious['enabled'] ?? true)) {
                return null;
            }
            if ($this->bodyMatchesCompiledAttackRules($descriptor, $envelope, (array)($malicious['patterns'] ?? []))) {
                return [
                    'type' => 'malicious_body',
                    'reason' => 'request content matched a blocked policy',
                    'block_duration' => (int)($malicious['block_duration'] ?? 3600),
                ];
            }

            return null;
        }

        $subjectSet = $this->attackRuleSubjects($descriptor, $envelope);
        if ($subjectSet['invalid']) {
            return [
                'type' => 'malformed_url_header',
                'reason' => 'URL-like request header decoded to a forbidden control character',
                'block_duration' => (int)($malicious['block_duration'] ?? 3600),
            ];
        }
        if (!($malicious['enabled'] ?? true)) {
            return null;
        }
        foreach ((array)($malicious['patterns'] ?? []) as $pattern) {
            foreach ($subjectSet['subjects'] as $subject) {
                if ($subject !== '' && $this->safeRegexMatch((string)$pattern, $subject)) {
                    return [
                        'type' => 'malicious_uri',
                        'reason' => 'request content matched a blocked policy',
                        'block_duration' => (int)($malicious['block_duration'] ?? 3600),
                    ];
                }
            }
        }
        return null;
    }

    /** @return array{subjects:list<string>,invalid:bool} */
    private function attackRuleSubjects(
        RuntimePolicyDescriptor $descriptor,
        RequestEnvelope $envelope,
    ): array {
        $target = (string)($envelope->attributes['target'] ?? $envelope->path);
        try {
            $rawQuery = \parse_url($target, PHP_URL_QUERY);
        } catch (\ValueError) {
            $rawQuery = null;
        }
        $rawQuery = \is_string($rawQuery) ? $rawQuery : '';
        $queryVariants = [\rawurldecode($rawQuery)];
        $formQuery = \urldecode($rawQuery);
        if ($formQuery !== $queryVariants[0]) {
            $queryVariants[] = $formQuery;
        }

        $subjects = [];
        $totalBytes = 0;
        foreach ($queryVariants as $query) {
            $canonicalUri = $envelope->path . ($query !== '' ? '?' . $query : '');
            $subject = \substr($canonicalUri, 0, self::MAX_ATTACK_URI_BYTES);
            if (!\in_array($subject, $subjects, true)) {
                $subjects[] = $subject;
                $totalBytes += \strlen($subject);
            }
        }
        $selected = $this->normalizeStringList($descriptor->matcher['selected_headers'] ?? []);
        foreach ($selected as $headerName) {
            $headerName = \strtolower($headerName);
            if (!\in_array($headerName, self::SAFE_ATTACK_SCAN_HEADERS, true)) {
                continue;
            }
            $value = (string)($envelope->headers[$headerName] ?? '');
            if ($value === '') {
                continue;
            }
            $decodedValue = \rawurldecode($value);
            if (\str_contains($value, "\0")
                || \preg_match('/[\r\n]/', $value) === 1
                || \str_contains($decodedValue, "\0")
                || \preg_match('/[\r\n]/', $decodedValue) === 1
            ) {
                return ['subjects' => $subjects, 'invalid' => true];
            }
            foreach ([$value, $decodedValue] as $headerValue) {
                $subject = $headerName . ': ' . \substr($headerValue, 0, self::MAX_ATTACK_HEADER_BYTES);
                if (\in_array($subject, $subjects, true)) {
                    continue;
                }
                if ($totalBytes + \strlen($subject) > self::MAX_ATTACK_SUBJECT_BYTES) {
                    continue;
                }
                $subjects[] = $subject;
                $totalBytes += \strlen($subject);
            }
        }

        return ['subjects' => $subjects, 'invalid' => false];
    }

    /**
     * @param list<mixed> $patterns
     */
    private function bodyMatchesCompiledAttackRules(
        RuntimePolicyDescriptor $descriptor,
        RequestEnvelope $envelope,
        array $patterns,
    ): bool {
        if ($envelope->body === '' || $patterns === []) {
            return false;
        }
        $chunkBytes = \max(32768, \min(524288, (int)($descriptor->matcher['scan_chunk_bytes'] ?? 262144)));
        $overlapBytes = \max(4096, \min(65536, (int)($descriptor->matcher['scan_overlap_bytes'] ?? 65536)));
        $overlapBytes = \min($overlapBytes, (int)($chunkBytes / 2));

        if ($this->bodyVariantMatchesPatterns($envelope->body, $patterns, $chunkBytes, $overlapBytes)) {
            return true;
        }

        $contentType = \strtolower(\trim((string)($envelope->headers['content-type'] ?? '')));
        $separator = \strpos($contentType, ';');
        if ($separator !== false) {
            $contentType = \trim(\substr($contentType, 0, $separator));
        }
        $urlEncodedTypes = $this->normalizeStringList(
            $descriptor->matcher['urlencoded_content_types'] ?? ['application/x-www-form-urlencoded'],
        );
        if (!\in_array($contentType, $urlEncodedTypes, true)) {
            return false;
        }

        // Decode the form body exactly once. The decoded value is bounded by
        // request_limits and each PCRE still sees only a bounded chunk.
        $decodedBody = \urldecode($envelope->body);
        return $decodedBody !== $envelope->body
            && $this->bodyVariantMatchesPatterns($decodedBody, $patterns, $chunkBytes, $overlapBytes);
    }

    /**
     * @param list<mixed> $patterns
     */
    private function bodyVariantMatchesPatterns(
        string $body,
        array $patterns,
        int $chunkBytes,
        int $overlapBytes,
    ): bool {
        $bodyBytes = \strlen($body);
        for ($offset = 0; $offset < $bodyBytes; $offset += $chunkBytes) {
            $start = \max(0, $offset - $overlapBytes);
            $length = \min($bodyBytes - $start, $chunkBytes + $overlapBytes);
            $chunk = \substr($body, $start, $length);
            foreach ($patterns as $pattern) {
                if ($this->safeRegexMatch((string)$pattern, $chunk)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, string> $headers @return array<string, string> */
    private function safeAttackLogHeaders(array $headers): array
    {
        $safe = [];
        foreach (self::SAFE_ATTACK_LOG_HEADERS as $name) {
            $value = (string)($headers[$name] ?? '');
            if ($value !== '') {
                $safe[$name] = \substr($value, 0, self::MAX_ATTACK_HEADER_BYTES);
            }
        }

        return $safe;
    }

    private function isTransportSystemRequest(string $method, string $path): bool
    {
        if ($method !== 'GET') {
            return false;
        }
        if ($path === '/_wls/health') {
            return true;
        }

        return \preg_match('#^/\.well-known/acme-challenge/[A-Za-z0-9_-]{1,256}/?$#D', $path) === 1;
    }

    /**
     * @return array{method:string,protocol:string,target:string,path:string,query:string,host:string,headers:array<string,string>,body:string,header_bytes:int,error?:string}
     */
    private function parseRequest(string $rawRequest, ?array $parsedFrame = null): array
    {
        if (\is_array($parsedFrame)
            && ($parsedFrame['status'] ?? '') === 'complete'
            && (int)($parsedFrame['consumed'] ?? 0) === \strlen($rawRequest)
            && isset(
                $parsedFrame['method'],
                $parsedFrame['target'],
                $parsedFrame['protocol'],
                $parsedFrame['headers'],
                $parsedFrame['body'],
            )
        ) {
            return $this->parseValidatedFrame($parsedFrame);
        }

        $frame = \wlsParseHttpRequestFrame(
            $rawRequest,
            $this->maxRequestHeaderBytes,
            $this->maxRequestBodyBytes,
        );
        if (($frame['status'] ?? '') !== 'complete'
            || (int)($frame['consumed'] ?? 0) !== \strlen($rawRequest)
        ) {
            return $this->invalidParsed(
                (string)($frame['error'] ?? '') !== ''
                    ? (string)$frame['error']
                    : 'invalid_message_framing'
            );
        }

        $headerEnd = \strpos($rawRequest, "\r\n\r\n");
        if ($headerEnd === false || $headerEnd > 1024 * 1024) {
            return $this->invalidParsed('incomplete_headers');
        }
        $headerBlock = \substr($rawRequest, 0, $headerEnd);
        $body = \substr($rawRequest, $headerEnd + 4);
        $lines = \explode("\r\n", $headerBlock);
        $requestLine = \array_shift($lines);
        if (!\is_string($requestLine)
            || \preg_match('/^([A-Z][A-Z0-9-]{0,31})\s+(\S{1,65535})\s+HTTP\/(1\.0|1\.1)$/D', $requestLine, $match) !== 1
        ) {
            return $this->invalidParsed('invalid_request_line');
        }
        $method = \strtoupper($match[1]);
        $protocol = 'HTTP/' . $match[3];
        $target = $match[2];
        try {
            $path = \parse_url($target, PHP_URL_PATH);
            $query = \parse_url($target, PHP_URL_QUERY);
        } catch (\ValueError) {
            $path = false;
            $query = false;
        }
        if (!\is_string($path) || $path === '' || ($query !== null && !\is_string($query))) {
            return $this->invalidParsed('invalid_target');
        }
        $decodedPath = \rawurldecode($path);
        if (\str_contains($decodedPath, "\0") || \str_contains($decodedPath, '\\')) {
            return $this->invalidParsed('invalid_path');
        }
        $segments = \explode('/', $decodedPath);
        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.') {
                return $this->invalidParsed('path_traversal');
            }
        }
        $path = '/' . \ltrim((string)(\preg_replace('#/+#', '/', $decodedPath) ?? $decodedPath), '/');
        $query = \is_string($query) ? \rawurldecode($query) : '';
        if (\str_contains($query, "\0") || \preg_match('/[\r\n]/', $query) === 1) {
            return $this->invalidParsed('invalid_query');
        }

        $headers = [];
        foreach ($lines as $line) {
            if ($line === '' || \str_starts_with($line, ' ') || \str_starts_with($line, "\t")) {
                return $this->invalidParsed('invalid_header_folding');
            }
            $separator = \strpos($line, ':');
            if ($separator === false) {
                return $this->invalidParsed('invalid_header');
            }
            $name = \strtolower(\trim(\substr($line, 0, $separator)));
            $value = \trim(\substr($line, $separator + 1));
            if ($name === '' || \preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/D', $name) !== 1
                || \preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1
            ) {
                return $this->invalidParsed('invalid_header');
            }
            $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
        }
        $host = (string)($headers['host'] ?? '');
        if ($host === '') {
            return $this->invalidParsed('missing_host');
        }
        return [
            'method' => $method,
            'protocol' => $protocol,
            'target' => $target,
            'path' => $path,
            'query' => $query,
            'host' => $host,
            'headers' => $headers,
            'body' => $body,
            'header_bytes' => $headerEnd + 4,
        ];
    }

    /**
     * Consume the transport's already validated immutable frame without a
     * second request-line/header scan.
     *
     * @param array<string, mixed> $frame
     * @return array{method:string,protocol:string,target:string,path:string,query:string,host:string,headers:array<string,string>,body:string,header_bytes:int,error?:string}
     */
    private function parseValidatedFrame(array $frame): array
    {
        $target = (string)$frame['target'];
        try {
            $path = \parse_url($target, PHP_URL_PATH);
            $query = \parse_url($target, PHP_URL_QUERY);
        } catch (\ValueError) {
            $path = false;
            $query = false;
        }
        if (!\is_string($path) || $path === '' || ($query !== null && !\is_string($query))) {
            return $this->invalidParsed('invalid_target');
        }
        $decodedPath = \rawurldecode($path);
        if (\str_contains($decodedPath, "\0") || \str_contains($decodedPath, '\\')) {
            return $this->invalidParsed('invalid_path');
        }
        foreach (\explode('/', $decodedPath) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return $this->invalidParsed('path_traversal');
            }
        }
        $path = '/' . \ltrim((string)(\preg_replace('#/+#', '/', $decodedPath) ?? $decodedPath), '/');
        $query = \is_string($query) ? \rawurldecode($query) : '';
        if (\str_contains($query, "\0") || \preg_match('/[\r\n]/', $query) === 1) {
            return $this->invalidParsed('invalid_query');
        }

        $headers = \is_array($frame['headers']) ? $frame['headers'] : [];
        $host = (string)($headers['host'] ?? '');
        if ($host === '') {
            return $this->invalidParsed('missing_host');
        }

        return [
            'method' => (string)$frame['method'],
            'protocol' => (string)$frame['protocol'],
            'target' => $target,
            'path' => $path,
            'query' => $query,
            'host' => $host,
            'headers' => $headers,
            'body' => (string)$frame['body'],
            'header_bytes' => (int)($frame['header_bytes'] ?? 0),
        ];
    }

    /** @return array{method:string,protocol:string,target:string,path:string,query:string,host:string,headers:array<string,string>,body:string,header_bytes:int,error:string} */
    private function invalidParsed(string $reason): array
    {
        return [
            'method' => 'GET',
            'protocol' => 'HTTP/1.1',
            'target' => '/',
            'path' => '/',
            'query' => '',
            'host' => '',
            'headers' => [],
            'body' => '',
            'header_bytes' => 0,
            'error' => $reason,
        ];
    }

    /**
     * @param array<string, mixed> $matcher
     */
    private function isUnkeyedBackendPath(string $path, array $matcher): bool
    {
        $segments = \array_values(\array_filter(\explode('/', \trim($path, '/')), static fn(string $part): bool => $part !== ''));
        if ($segments === []) {
            return false;
        }
        $backendPrefix = (string)($matcher['backend_prefix'] ?? '');
        $restBackendPrefix = (string)($matcher['rest_backend_prefix'] ?? '');
        if (($backendPrefix !== '' && \hash_equals($backendPrefix, (string)$segments[0]))
            || ($restBackendPrefix !== '' && \hash_equals($restBackendPrefix, (string)$segments[0]))
        ) {
            return false;
        }
        $index = 0;
        while ($index < 2 && isset($segments[$index]) && $this->isLocalizationSegment($segments[$index])) {
            $index++;
        }
        $candidate = \strtolower((string)($segments[$index] ?? ''));
        foreach ((array)($matcher['protected_prefixes'] ?? ['admin']) as $protected) {
            if ($candidate === \strtolower(\trim((string)$protected, '/'))) {
                return true;
            }
        }
        return false;
    }

    private function isLocalizationSegment(string $segment): bool
    {
        return \preg_match('/^[A-Z]{3}$/D', $segment) === 1
            || \preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z]{2,8}){1,2}$/D', $segment) === 1;
    }

    private function validHost(string $host): bool
    {
        if ($host === '' || \strlen($host) > 255 || \preg_match('~[\x00-\x20\x7f\\\\/]~', $host) === 1) {
            return false;
        }
        if ($host[0] === '[') {
            if (\preg_match('/^\[([^]]+)\](?::([0-9]{1,5}))?$/D', $host, $match) !== 1
                || \filter_var($match[1], \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) === false
            ) {
                return false;
            }
            return !isset($match[2]) || $this->validHostPort($match[2]);
        }
        if (\preg_match('/^([^:]+)(?::([0-9]{1,5}))?$/D', $host, $match) !== 1) {
            return false;
        }
        $hostname = $match[1];
        if (\strlen($hostname) > 253) {
            return false;
        }
        foreach (\explode('.', $hostname) as $label) {
            if ($label === ''
                || \strlen($label) > 63
                || \preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD', $label) !== 1
            ) {
                return false;
            }
        }

        return !isset($match[2]) || $this->validHostPort($match[2]);
    }

    private function validHostPort(string $port): bool
    {
        $port = (int)$port;
        return $port >= 1 && $port <= 65535;
    }

    /** @param list<string> $allowedHosts */
    private function hostAllowed(string $host, array $allowedHosts): bool
    {
        $hostOnly = $this->hostWithoutPort($host);
        foreach ($allowedHosts as $allowed) {
            $allowed = $this->hostWithoutPort($allowed);
            if ($allowed === $hostOnly || (\str_starts_with($allowed, '*.') && \str_ends_with($hostOnly, \substr($allowed, 1)))) {
                return true;
            }
        }
        return false;
    }

    private function hostWithoutPort(string $host): string
    {
        $host = \strtolower(\trim($host));
        if ($host === '') {
            return '';
        }
        if ($host[0] === '[') {
            $end = \strpos($host, ']');
            return $end === false ? $host : \substr($host, 1, $end - 1);
        }
        if (\substr_count($host, ':') === 1) {
            $host = (string)\explode(':', $host, 2)[0];
        }

        return \rtrim($host, '.');
    }

    /** @param list<string> $roots */
    private function managedSingleLabelHostAllowed(string $host, array $roots): bool
    {
        foreach ($roots as $root) {
            $root = \strtolower(\trim($root, '.'));
            $suffix = '.' . $root;
            if ($root === '' || !\str_ends_with($host, $suffix)) {
                continue;
            }
            $label = \substr($host, 0, -\strlen($suffix));
            if ($label !== '' && !\str_contains($label, '.')
                && \preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    private function safeRegexMatch(string $pattern, string $subject): bool
    {
        if ($pattern === '' || \strlen($pattern) > 1024 || \strlen($subject) > 2 * 1024 * 1024) {
            return false;
        }
        return @\preg_match($pattern, $subject) === 1;
    }

    /** @param mixed $value @return list<string> */
    private function normalizeStringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $normalized = [];
        foreach ($value as $item) {
            $item = \trim((string)$item);
            if ($item !== '') {
                $normalized[] = $item;
            }
        }
        return \array_values(\array_unique($normalized));
    }

    /** @param array<string, mixed> $parsed */
    private function deny(array $parsed, string $clientIp, bool $trustedProxy, int $status, string $reason): WorkerPolicyDecision
    {
        $reasonPhrase = match ($status) {
            400 => 'Bad Request',
            403 => 'Forbidden',
            404 => 'Not Found',
            413 => 'Request Entity Too Large',
            429 => 'Too Many Requests',
            503 => 'Service Unavailable',
            default => 'Rejected',
        };
        $body = $reasonPhrase;
        $headers = "Content-Type: text/plain; charset=utf-8\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . 'X-WLS-Policy-Digest: ' . $this->loadedDigest . "\r\n";
        if ($status === 429) {
            $headers .= "Retry-After: 1\r\n";
        }
        $response = "HTTP/1.1 {$status} {$reasonPhrase}\r\n{$headers}\r\n{$body}";
        return WorkerPolicyDecision::deny(
            $clientIp,
            (string)($parsed['method'] ?? 'GET'),
            (string)($parsed['protocol'] ?? 'HTTP/1.1'),
            (string)($parsed['target'] ?? '/'),
            (string)($parsed['path'] ?? '/'),
            \is_array($parsed['headers'] ?? null) ? $parsed['headers'] : [],
            (string)($parsed['body'] ?? ''),
            $response,
            $reason,
            $this->loadedDigest,
            $trustedProxy,
        );
    }

    private function reconnectSharedStateIfDue(): void
    {
        if (!$this->rateLimiter->shouldReconnectSharedState()) {
            return;
        }
        $now = \microtime(true);
        if ($now - $this->lastStateReconnectAttempt < 1.0) {
            return;
        }
        $this->lastStateReconnectAttempt = $now;
        try {
            $state = new MemoryStateFacade([
                'consumer_code' => $this->instanceName . ':policy:' . (string)(\getmypid() ?: 0),
                'prefer_direct_connect' => true,
                'fail_fast_on_unhealthy' => true,
                'connect_timeout' => 0.01,
                'timeout' => 0.01,
                'acquire_timeout' => 0.003,
                'pool_size' => 1,
            ]);
            $this->state = $state;
            $this->rateLimiter->attachState($state);
            ConnectionAcceptGatePool::instanceOrNull()?->attachState($state);
        } catch (\Throwable) {
            $this->state = null;
            $this->rateLimiter->attachState(null);
            ConnectionAcceptGatePool::instanceOrNull()?->attachState(null);
        }
    }
}
