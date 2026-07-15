<?php

declare(strict_types=1);

namespace Weline\Server\Service\Policy;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\Policy\PolicyStage;
use Weline\Framework\Runtime\Policy\RuntimePolicyBundle;
use Weline\Framework\Runtime\Policy\RuntimePolicyDescriptor;
use Weline\Framework\Runtime\Policy\RuntimePolicyProviderCompiler;
use Weline\Server\Security\AttackDetector;
use Weline\Server\Service\LocalDomainPolicy;

final class RuntimePolicyCompiler
{
    private const DEFAULT_REGISTRY_FILE = BP . 'generated' . DS . 'framework' . DS . 'runtime_policy_providers.php';

    /** @var list<string> */
    private const ATTACK_SCAN_HEADERS = [
        'origin',
        'x-forwarded-host',
        'x-original-url',
        'x-rewrite-url',
    ];

    public function __construct(
        private readonly ?string $registryFile = null,
        private readonly RuntimePolicyValidator $validator = new RuntimePolicyValidator(),
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<RuntimePolicyDescriptor|array<string, mixed>> $additionalDescriptors
     * @param array<string, mixed> $compileContext
     */
    public function compile(
        string $topology = 'both',
        array $metadata = [],
        array $additionalDescriptors = [],
        array $compileContext = [],
    ): RuntimePolicyBundle {
        $registry = $this->loadRegistry();
        [$builtInDescriptors, $builtInMetadata] = $this->buildServerDescriptors($compileContext);
        $descriptors = $builtInDescriptors;
        foreach ((array)($registry['descriptors'] ?? []) as $row) {
            if (!\is_array($row)) {
                throw new \RuntimeException('Compiled runtime policy descriptor registry contains an invalid row.');
            }
            unset($row['provider'], $row['module']);
            $descriptors[] = RuntimePolicyDescriptor::fromArray($row);
        }
        foreach ($additionalDescriptors as $descriptor) {
            $descriptors[] = $descriptor;
        }
        $registryMaterial = \json_encode(
            $registry,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
        $bundle = RuntimePolicyBundle::fromDescriptors(
            descriptors: $descriptors,
            version: '1',
            topology: $topology,
            metadata: $metadata + $builtInMetadata + [
                'provider_registry_digest' => \hash('sha256', $registryMaterial),
                'provider_count' => \count((array)($registry['providers'] ?? [])),
                'built_in_policy_count' => \count($builtInDescriptors),
            ],
        );
        $this->validator->assertValid($bundle, $topology === 'both' ? null : $topology);
        return $bundle;
    }

    /**
     * Validate the compile-time provider boundary without compiling a bundle.
     * Startup calls this even when an explicitly staged bundle is selected, so
     * a stale bundle cannot hide a missing or corrupt provider registry.
     */
    public function assertProviderRegistryReady(): void
    {
        $this->loadRegistry();
    }

    /**
     * @return array{0:list<RuntimePolicyDescriptor>,1:array<string,mixed>}
     */
    private function buildServerDescriptors(array $compileContext = []): array
    {
        $wls = Env::get('wls', []);
        $wls = \is_array($wls) ? $wls : [];
        $rules = $this->loadAttackRules();
        $backendPrefix = \trim((string)(Env::getAreaRoutePrefix('backend') ?? ''), '/');
        $restBackendPrefix = \trim((string)(Env::getAreaRoutePrefix('rest_backend') ?? ''), '/');
        $originValidation = \is_array($wls['origin_token_validation'] ?? null)
            ? $wls['origin_token_validation']
            : [];
        $originToken = (string)($wls['origin_token'] ?? '');
        $requestLimits = \is_array($wls['request_limits'] ?? null) ? $wls['request_limits'] : [];
        $cache = \is_array($wls['worker']['fpc'] ?? null) ? $wls['worker']['fpc'] : [];
        $instanceHostIntent = $this->hasConfiguredHostIntent($compileContext);
        $instanceHosts = $this->normalizeConfiguredHosts($compileContext);
        $allowedHosts = $this->compileAllowedHosts(
            $wls,
            $rules,
            $instanceHosts,
            $instanceHostIntent,
        );
        $hostPolicyStrict = $instanceHostIntent || $allowedHosts !== [];
        $hostPolicySource = $instanceHostIntent ? 'instance' : 'global';
        $hostContextMaterial = \json_encode(
            [
                'source' => $hostPolicySource,
                'hosts' => $instanceHosts,
            ],
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
        $connectionPolicy = $this->compileConnectionPolicyMatcher($wls, $rules);
        $developmentLoopbackWhitelist = LocalDomainPolicy::isDevelopmentMode();
        if ($developmentLoopbackWhitelist) {
            $connectionPolicy['whitelist_cidrs'] = \array_values(\array_unique(\array_merge(
                (array)($connectionPolicy['whitelist_cidrs'] ?? []),
                ['127.0.0.1/32', '::1/128'],
            )));
        }

        $both = ['direct', 'dispatcher'];
        $descriptors = [
            new RuntimePolicyDescriptor(
                id: 'server.connection.ip_guard',
                priority: 10,
                stage: PolicyStage::CONNECTION,
                requiredInputs: ['peer_ip'],
                matcher: $connectionPolicy,
                action: ['type' => 'allow_or_deny'],
                state: RuntimePolicyDescriptor::STATE_SHARED_ATOMIC,
                critical: true,
                supportedTopologies: $both,
                capabilities: [
                    'shared_atomic_state',
                    'token_lease',
                    'connection_partition',
                    'slowloris_deferred_shared_accounting',
                ],
            ),
            new RuntimePolicyDescriptor(
                id: 'server.request.host_guard',
                priority: 10,
                stage: PolicyStage::MANDATORY_REQUEST,
                requiredInputs: ['host'],
                matcher: [
                    'type' => 'host_guard',
                    'allowed_hosts' => $allowedHosts,
                    'strict' => $hostPolicyStrict,
                    'allow_loopback' => true,
                    'managed_local_roots' => ['weline.test', 'local.test', 'weline.localhost'],
                ],
                action: ['type' => 'reject', 'status' => 400],
                critical: true,
                supportedTopologies: $both,
            ),
            new RuntimePolicyDescriptor(
                id: 'server.request.shape_guard',
                priority: 20,
                stage: PolicyStage::MANDATORY_REQUEST,
                requiredInputs: ['method', 'path', 'headers'],
                matcher: [
                    'type' => 'request_limits',
                    'max_uri_bytes' => (int)($requestLimits['max_uri_bytes'] ?? 8192),
                    'max_header_bytes' => (int)($requestLimits['max_header_bytes'] ?? 65536),
                    'max_body_bytes' => (int)($requestLimits['max_body_bytes'] ?? 16 * 1024 * 1024),
                    'normalize_path' => true,
                ],
                action: ['type' => 'reject', 'status' => 400],
                critical: true,
                supportedTopologies: $both,
            ),
            new RuntimePolicyDescriptor(
                id: 'server.request.backend_key',
                priority: 30,
                stage: PolicyStage::MANDATORY_REQUEST,
                requiredInputs: ['path'],
                matcher: [
                    'type' => 'backend_key',
                    'backend_prefix' => $backendPrefix,
                    'rest_backend_prefix' => $restBackendPrefix,
                    'protected_prefixes' => ['admin', 'rest_admin'],
                    'localized_prefix_order' => 'any',
                ],
                action: ['type' => 'not_found', 'status' => 404],
                critical: true,
                supportedTopologies: $both,
            ),
            new RuntimePolicyDescriptor(
                id: 'server.request.origin_token',
                priority: 40,
                stage: PolicyStage::MANDATORY_REQUEST,
                requiredInputs: ['peer_ip', 'headers'],
                matcher: [
                    'type' => 'origin_token',
                    'enabled' => (bool)($originValidation['enabled'] ?? false),
                    'header' => (string)($originValidation['header'] ?? 'X-Weline-Origin-Token'),
                    'token_sha256' => $originToken !== '' ? \hash('sha256', $originToken) : '',
                    // The only policy bypass is the explicit IP whitelist.
                    // A loopback peer can be an Nginx/Caddy upstream and is
                    // therefore never an implicit Origin credential.
                    'bypass' => 'explicit_whitelist_only',
                ],
                action: ['type' => 'reject', 'status' => 403],
                critical: true,
                supportedTopologies: $both,
            ),
            new RuntimePolicyDescriptor(
                id: 'server.request.global_rate',
                priority: 50,
                stage: PolicyStage::MANDATORY_REQUEST,
                requiredInputs: ['peer_ip'],
                matcher: ['type' => 'token_bucket', 'config' => (array)($rules['rate_limit'] ?? [])],
                action: ['type' => 'rate_limit', 'status' => 429],
                state: RuntimePolicyDescriptor::STATE_SHARED_ATOMIC,
                critical: true,
                supportedTopologies: $both,
                capabilities: ['shared_atomic_state', 'token_lease'],
            ),
            new RuntimePolicyDescriptor(
                id: 'server.request.path_rate',
                priority: 60,
                stage: PolicyStage::MANDATORY_REQUEST,
                requiredInputs: ['peer_ip', 'path'],
                matcher: ['type' => 'path_token_bucket', 'config' => (array)($rules['path_rate_limits'] ?? [])],
                action: ['type' => 'rate_limit', 'status' => 429],
                state: RuntimePolicyDescriptor::STATE_SHARED_ATOMIC,
                critical: true,
                supportedTopologies: $both,
                capabilities: ['shared_atomic_state', 'token_lease'],
            ),
            new RuntimePolicyDescriptor(
                id: 'server.request.attack_guard',
                priority: 70,
                stage: PolicyStage::MANDATORY_REQUEST,
                requiredInputs: ['peer_ip', 'path', 'headers'],
                matcher: [
                    'type' => 'attack_rules',
                    'malicious_patterns' => (array)($rules['malicious_patterns'] ?? []),
                    'bad_user_agents' => (array)($rules['bad_user_agents'] ?? []),
                    'protected_paths' => (array)($rules['protected_paths'] ?? []),
                    'ban_on_path_match' => (array)($rules['ban_on_path_match'] ?? []),
                    'path_scan' => (array)($rules['path_scan'] ?? []),
                    'selected_headers' => self::ATTACK_SCAN_HEADERS,
                ],
                action: ['type' => 'block_and_log', 'status' => 403],
                state: RuntimePolicyDescriptor::STATE_SHARED_ATOMIC,
                critical: true,
                supportedTopologies: $both,
                capabilities: ['shared_atomic_state', 'async_attack_log'],
            ),
            new RuntimePolicyDescriptor(
                id: 'server.request.maintenance',
                priority: 80,
                stage: PolicyStage::MANDATORY_REQUEST,
                requiredInputs: ['path'],
                matcher: ['type' => 'maintenance_epoch'],
                action: ['type' => 'maintenance_response', 'status' => 503],
                state: RuntimePolicyDescriptor::STATE_PROCESS_LOCAL,
                critical: true,
                supportedTopologies: $both,
                capabilities: ['epoch_broadcast'],
            ),
            new RuntimePolicyDescriptor(
                id: 'server.cache.static',
                priority: 10,
                stage: PolicyStage::CACHE,
                requiredInputs: ['method', 'host', 'path', 'headers'],
                matcher: ['type' => 'static_cache', 'methods' => ['GET', 'HEAD']],
                action: ['type' => 'cache_lookup', 'layer' => 'process_l1'],
                supportedTopologies: $both,
                capabilities: ['cache_epoch'],
            ),
            new RuntimePolicyDescriptor(
                id: 'server.cache.fpc',
                priority: 20,
                stage: PolicyStage::CACHE,
                requiredInputs: ['method', 'host', 'path', 'headers'],
                matcher: [
                    'type' => 'fpc',
                    'methods' => ['GET', 'HEAD'],
                    'enabled' => (bool)($cache['enabled'] ?? true),
                ],
                action: ['type' => 'cache_lookup', 'layers' => ['process_l1', 'shared_l2']],
                supportedTopologies: $both,
                capabilities: ['cache_epoch', 'shared_cache'],
            ),
            new RuntimePolicyDescriptor(
                id: 'server.request.body_attack_guard',
                priority: 10,
                stage: PolicyStage::DEEP_REQUEST,
                requiredInputs: ['peer_ip', 'path', 'headers', 'body'],
                matcher: [
                    'type' => 'body_attack_rules',
                    'malicious_patterns' => (array)($rules['malicious_patterns'] ?? []),
                    'scan_chunk_bytes' => 262144,
                    'scan_overlap_bytes' => 65536,
                    'urlencoded_content_types' => ['application/x-www-form-urlencoded'],
                ],
                action: ['type' => 'block_and_log', 'status' => 403],
                state: RuntimePolicyDescriptor::STATE_SHARED_ATOMIC,
                critical: true,
                supportedTopologies: $both,
                capabilities: ['shared_atomic_state', 'async_attack_log'],
            ),
        ];

        $securityRulesJson = \json_encode(
            $rules,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
        return [$descriptors, [
            'backend_prefix' => $backendPrefix,
            'rest_backend_prefix' => $restBackendPrefix,
            'origin_token_enabled' => (bool)($originValidation['enabled'] ?? false),
            'origin_token_header' => (string)($originValidation['header'] ?? 'X-Weline-Origin-Token'),
            'origin_token_bypass' => 'explicit_whitelist_only',
            'security_rules_digest' => \hash('sha256', $securityRulesJson),
            'cache_policy' => [
                'static' => true,
                'fpc' => (bool)($cache['enabled'] ?? true),
                'layers' => ['process_l1', 'shared_l2'],
            ],
            'allowed_host_count' => \count($allowedHosts),
            'host_policy_strict' => $hostPolicyStrict,
            'host_policy_source' => $hostPolicySource,
            'host_policy_context_host_count' => \count($instanceHosts),
            'host_policy_context_digest' => \hash('sha256', $hostContextMaterial),
            'development_loopback_whitelist' => $developmentLoopbackWhitelist,
            'connection_policy' => [
                'max_active_connections' => (int)$connectionPolicy['max_active_connections'],
                'deny_cidr_count' => \count((array)$connectionPolicy['deny_cidrs']),
                'whitelist_cidr_count' => \count((array)$connectionPolicy['whitelist_cidrs']),
                'connection_rate_enabled' => (bool)$connectionPolicy['connection_rate']['enabled'],
                'slowloris_enabled' => (bool)$connectionPolicy['slowloris']['enabled'],
            ],
        ]];
    }

    /**
     * Security policy compilation is fail-closed. A corrupt persisted rules
     * file or a detector bootstrap failure must stop publication; silently
     * substituting an empty ruleset would make every Worker READY without the
     * configured attack policy.
     *
     * @return array<string, mixed>
     */
    private function loadAttackRules(): array
    {
        $rulesFile = AttackDetector::getRulesFilePath();
        if (\is_file($rulesFile)) {
            $raw = @\file_get_contents($rulesFile);
            if (!\is_string($raw) || \trim($raw) === '') {
                throw new \RuntimeException('Runtime attack rules file is unreadable or empty: ' . $rulesFile);
            }
            try {
                $persisted = \json_decode($raw, false, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \RuntimeException(
                    'Runtime attack rules file contains invalid JSON: ' . $rulesFile,
                    0,
                    $exception,
                );
            }
            if (!$persisted instanceof \stdClass) {
                throw new \RuntimeException('Runtime attack rules file must contain a JSON object: ' . $rulesFile);
            }
        }

        try {
            $rules = AttackDetector::getInstance()->getRules();
        } catch (\Throwable $throwable) {
            throw new \RuntimeException('Unable to load runtime attack rules.', 0, $throwable);
        }
        if ($rules === []) {
            throw new \RuntimeException('Runtime attack rules resolved to an empty ruleset.');
        }

        return $rules;
    }

    /**
     * Compile the topology-neutral L4 policy once. The bundle carries only
     * scalar/array data; both Dispatcher and direct Workers build the same
     * ConnectionAcceptGate from this matcher.
     *
     * @param array<string, mixed> $wls
     * @param array<string, mixed> $rules
     * @param list<string> $instanceHosts
     * @return array<string, mixed>
     */
    private function compileConnectionPolicyMatcher(array $wls, array $rules): array
    {
        $acceptGate = \is_array($wls['accept_gate'] ?? null) ? $wls['accept_gate'] : [];
        $connectionRate = \is_array($acceptGate['connection_rate'] ?? null)
            ? $acceptGate['connection_rate']
            : [];
        $slowloris = \is_array($rules['slowloris'] ?? null) ? $rules['slowloris'] : [];
        if (\is_array($acceptGate['slowloris'] ?? null)) {
            $slowloris = \array_replace($slowloris, $acceptGate['slowloris']);
        }

        $denyCidrs = (array)($acceptGate['deny_cidrs'] ?? []);
        foreach (['ip_deny', 'ip_blacklist'] as $ruleName) {
            $rule = \is_array($rules[$ruleName] ?? null) ? $rules[$ruleName] : [];
            if (($rule['enabled'] ?? false) !== false) {
                $denyCidrs = \array_merge($denyCidrs, (array)($rule['ips'] ?? []));
            }
        }

        $trustedProxyCidrs = [];
        $trustedProxy = \is_array($rules['cdn_trusted_ips'] ?? null) ? $rules['cdn_trusted_ips'] : [];
        if (($trustedProxy['enabled'] ?? true) !== false) {
            $trustedProxyCidrs = (array)($trustedProxy['ips'] ?? []);
        }
        $trustedProxyCidrs = \array_merge(
            $trustedProxyCidrs,
            (array)($acceptGate['trusted_proxy_cidrs'] ?? []),
        );

        $whitelistCidrs = [];
        $whitelist = \is_array($rules['ip_whitelist'] ?? null) ? $rules['ip_whitelist'] : [];
        if (($whitelist['enabled'] ?? true) !== false) {
            $whitelistCidrs = (array)($whitelist['ips'] ?? []);
        }
        $whitelistCidrs = \array_merge(
            $whitelistCidrs,
            (array)($acceptGate['whitelist_cidrs'] ?? []),
        );

        return [
            'type' => 'ip_policy',
            'trusted_proxy_cidrs' => $this->normalizePolicyStringList($trustedProxyCidrs),
            'whitelist_cidrs' => $this->normalizePolicyStringList($whitelistCidrs),
            'deny_cidrs' => $this->normalizePolicyStringList($denyCidrs),
            'max_active_connections' => \max(
                1,
                (int)($acceptGate['max_active_connections'] ?? $wls['max_connections'] ?? 10_000),
            ),
            'connection_rate' => [
                // Disabled unless explicitly configured: enabling a new
                // global fresh-connection ceiling must never be an implicit
                // deployment regression. Once enabled, token leases make it
                // instance-wide without one IPC per accepted connection.
                'enabled' => (bool)($connectionRate['enabled'] ?? false),
                'window' => \max(1, (int)($connectionRate['window'] ?? 1)),
                'max_connections' => \max(0, (int)($connectionRate['max_connections'] ?? 0)),
                'per_ip_max_connections' => \max(
                    0,
                    (int)($connectionRate['per_ip_max_connections'] ?? 0),
                ),
                'block_duration' => \max(1, (int)($connectionRate['block_duration'] ?? 30)),
            ],
            'slowloris' => [
                'enabled' => (bool)($slowloris['enabled'] ?? true),
                'max_incomplete_conns' => \max(
                    1,
                    (int)($slowloris['max_incomplete_conns'] ?? 10),
                ),
                'incomplete_timeout' => \max(
                    1,
                    (int)($slowloris['incomplete_timeout'] ?? 30),
                ),
                // Only connections still incomplete after this grace enter
                // shared accounting. Keep the grace above the fresh-TLS
                // latency budget so normal concurrent handshakes cannot be
                // classified as slowloris under scheduler pressure.
                'grace_seconds' => \max(0.01, (float)($slowloris['grace_seconds'] ?? 1.5)),
            ],
        ];
    }

    /**
     * @param array<int, mixed> $values
     * @return list<string>
     */
    private function normalizePolicyStringList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $value = \trim((string)$value);
            if ($value !== '') {
                $normalized[$value] = true;
            }
        }
        $normalized = \array_keys($normalized);
        \sort($normalized, \SORT_STRING);
        return $normalized;
    }

    /**
     * Compile every Server-owned public host once. Worker hot paths must not
     * include env.php or inspect instance files to decide whether a Host is
     * accepted.
     *
     * @param array<string, mixed> $wls
     * @param array<string, mixed> $rules
     * @return list<string>
     */
    private function compileAllowedHosts(
        array $wls,
        array $rules,
        array $instanceHosts = [],
        bool $instanceHostIntent = false,
    ): array
    {
        $candidates = [];
        $ruleHosts = \is_array($rules['allowed_hosts'] ?? null) ? $rules['allowed_hosts'] : [];
        if (($ruleHosts['enabled'] ?? true) !== false) {
            $candidates = (array)($ruleHosts['hosts'] ?? []);
        }

        if ($instanceHostIntent) {
            $candidates = \array_merge($candidates, $instanceHosts);
        } else {
            foreach (['host', 'ssl_domain', 'public_host'] as $key) {
                $candidates[] = $wls[$key] ?? null;
            }
            foreach ((array)($wls['servers'] ?? []) as $server) {
                if (!\is_array($server)) {
                    continue;
                }
                foreach (['host', 'ssl_domain', 'public_host'] as $key) {
                    $candidates[] = $server[$key] ?? null;
                }
            }
        }

        $hosts = [];
        foreach ($candidates as $candidate) {
            if (!\is_scalar($candidate)) {
                continue;
            }
            $host = $this->normalizeConfiguredHost((string)$candidate);
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }

        $hosts = \array_keys($hosts);
        \sort($hosts, \SORT_STRING);
        return $hosts;
    }

    /**
     * @param array<string, mixed> $compileContext
     */
    private function hasConfiguredHostIntent(array $compileContext): bool
    {
        foreach (['host', 'public_host', 'ssl_domain'] as $key) {
            if (\array_key_exists($key, $compileContext)
                && \is_scalar($compileContext[$key])
                && \trim((string)$compileContext[$key]) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $compileContext
     * @return list<string>
     */
    private function normalizeConfiguredHosts(array $compileContext): array
    {
        $hosts = [];
        foreach (['host', 'public_host', 'ssl_domain'] as $key) {
            $candidate = $compileContext[$key] ?? null;
            if (!\is_scalar($candidate)) {
                continue;
            }
            $host = $this->normalizeConfiguredHost((string)$candidate);
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }

        $hosts = \array_keys($hosts);
        \sort($hosts, \SORT_STRING);
        return $hosts;
    }

    private function normalizeConfiguredHost(string $host): string
    {
        $host = \strtolower(\trim($host));
        if ($host === '') {
            return '';
        }
        if (\str_contains($host, '://')) {
            $parsed = \parse_url($host, \PHP_URL_HOST);
            $host = \is_string($parsed) ? \strtolower(\trim($parsed)) : '';
        }
        if ($host === '') {
            return '';
        }
        if ($host[0] === '[') {
            $end = \strpos($host, ']');
            return $end === false ? '' : \substr($host, 1, $end - 1);
        }
        if (\substr_count($host, ':') === 1) {
            [$host] = \explode(':', $host, 2);
        }

        return \rtrim(\trim($host), '.');
    }

    /**
     * @return array<string, mixed>
     */
    private function loadRegistry(): array
    {
        $file = $this->registryFile ?? self::DEFAULT_REGISTRY_FILE;
        if (!\is_file($file)) {
            throw new \RuntimeException(
                'Compiled runtime policy provider registry is missing: ' . $file
                . '. Run: php bin/w framework:compile',
            );
        }
        $registry = require $file;
        if (!\is_array($registry)
            || (int)($registry['format'] ?? 0) !== RuntimePolicyProviderCompiler::FORMAT_VERSION
            || !\array_key_exists('providers', $registry)
            || !\is_array($registry['providers'])
            || !\array_key_exists('descriptors', $registry)
            || !\is_array($registry['descriptors'])
            || !\array_is_list($registry['descriptors'])
        ) {
            throw new \RuntimeException(
                'Compiled runtime policy provider registry is invalid: ' . $file
                . '. Re-run: php bin/w framework:compile',
            );
        }
        return $registry;
    }
}
