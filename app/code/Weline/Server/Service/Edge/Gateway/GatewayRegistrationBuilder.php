<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\SslCertificateService;
use Weline\Server\Service\Runtime\ProtocolEdgeRuntime;

/**
 * Builds a complete project-owned desired-state registration.
 */
final class GatewayRegistrationBuilder
{
    public function __construct(
        private readonly ProjectIdentityStore $identity = new ProjectIdentityStore(),
        private readonly ProjectCertificateGenerationStore $certificateGenerations
            = new ProjectCertificateGenerationStore(),
        private readonly GatewayBackendCapabilityResolver $backendCapabilities
            = new GatewayBackendCapabilityResolver(),
        private readonly GatewayBackendCapabilityStateStore $backendCapabilityState
            = new GatewayBackendCapabilityStateStore(),
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(string $instanceName): array
    {
        /** @var ServerInstanceManager $instances */
        $instances = ObjectManager::getInstance(ServerInstanceManager::class);
        $endpoint = $instances->getRawInstanceData($instanceName);
        if (!\is_array($endpoint)) {
            throw new \RuntimeException('WLS instance endpoint is missing: ' . $instanceName);
        }
        $projectUuid = $this->projectUuid();
        $endpointGateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $endpointProjectUuid = \strtolower(\trim((string)($endpointGateway['project_uuid'] ?? '')));
        if ($endpointProjectUuid !== '' && !\hash_equals($projectUuid, $endpointProjectUuid)) {
            throw new \RuntimeException('WLS endpoint project UUID does not match project-owned identity.');
        }
        $backend = $this->resolveBackend($endpoint, $instanceName, $projectUuid);
        $port = (int)$backend['port'];
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('WLS instance endpoint has no valid backend port.');
        }
        $host = \trim((string)$backend['host']);
        if (!\in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) {
            throw new \RuntimeException('Gateway backend must be a loopback WLS endpoint.');
        }

        $projectRoot = \realpath((string)BP);
        if (!\is_string($projectRoot) || $projectRoot === '') {
            throw new \RuntimeException('Unable to resolve canonical project root.');
        }
        $launchId = \strtolower(\trim((string)($endpointGateway['launch_id'] ?? '')));
        if (\preg_match('/^[a-f0-9]{32}$/D', $launchId) !== 1) {
            // Compatibility for endpoint records created before launch_id was
            // added. A newly started generation always persists a random ID.
            $launchId = \substr(\hash(
                'sha256',
                $projectUuid . ':' . $instanceName . ':'
                    . (string)($endpoint['started_timestamp'] ?? 0),
            ), 0, 32);
        }
        $certificateRoots = $this->certificateRoots($projectRoot);
        $certificateMap = [];
        try {
            /** @var SslCertificateService $certificates */
            $certificates = ObjectManager::getInstance(SslCertificateService::class);
            $certificateMap = $certificates->getCertificateMap($certificateRoots);
        } catch (\Throwable) {
            // The endpoint certificate remains a valid file-mode source when
            // storage is unavailable during early recovery.
        }

        $publicHost = \trim((string)($endpoint['public_host'] ?? ''));
        $endpointCert = \trim((string)($endpoint['ssl_cert'] ?? ''));
        $endpointKey = \trim((string)($endpoint['ssl_key'] ?? ''));
        $gatewayCertificate = \is_array($endpoint['gateway']['certificate_source'] ?? null)
            ? $endpoint['gateway']['certificate_source']
            : [];
        if ($endpointCert === '') {
            $endpointCert = \trim((string)($gatewayCertificate['cert_path'] ?? ''));
        }
        if ($endpointKey === '') {
            $endpointKey = \trim((string)($gatewayCertificate['key_path'] ?? ''));
        }
        if ($publicHost === '') {
            $publicHost = \trim((string)($gatewayCertificate['domain'] ?? ''));
        }
        if ($publicHost !== '') {
            $certificateMap[$publicHost] ??= [
                'cert' => $endpointCert,
                'key' => $endpointKey,
                'chain' => '',
                'cert_type' => \str_starts_with($publicHost, '*.') ? 'wildcard' : 'exact',
                'force_https' => 1,
            ];
        }
        if ($certificateMap === []) {
            throw new \RuntimeException(
                'No project-owned domain is available for gateway registration.'
            );
        }
        $instanceGeneration = (int)($endpointGateway['instance_generation'] ?? 0);
        if ($instanceGeneration < 1) {
            $instanceGeneration = \max(
                1,
                (int)($endpoint['started_timestamp'] ?? 0),
                (int)($endpoint['startup_event_seq'] ?? 0),
            );
        }
        $edgeCapabilitySecret = ProtocolEdgeRuntime::readToken($instanceName);
        $backendCapability = $this->backendCapabilityState->stabilize(
            $this->backendCapabilities->resolve($endpoint),
        );
        $backendIdentity = [
            'project_uuid' => $projectUuid,
            'instance_id' => $instanceName,
            'generation' => $instanceGeneration,
            'endpoint_file' => $this->endpointFile($instanceName),
            'master_pid' => (int)($endpoint['master_pid'] ?? 0),
            'master_epoch' => \max(
                1,
                (int)($endpoint['master_epoch'] ?? 0),
                (int)($endpoint['epoch'] ?? 0),
            ),
            'launch_id' => $launchId,
            'edge_capability_secret' => $edgeCapabilitySecret,
            'edge_capability_digest' => \hash('sha256', $edgeCapabilitySecret),
            ...$this->backendCapabilities->instanceIdentityState($backendCapability),
        ];
        $backendIdentity['digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($backendIdentity),
        );

        $routes = [];
        foreach ($certificateMap as $domain => $material) {
            if (!\is_string($domain) || !\is_array($material)) {
                continue;
            }
            $domain = $this->normalizeGatewayDomain($domain);
            if ($domain === null) {
                continue;
            }
            $cert = \trim((string)($material['cert'] ?? ''));
            $key = \trim((string)($material['key'] ?? ''));
            $activeCertificate = null;
            $certReference = [];
            $keyReference = [];
            if ($cert !== '' && $key !== '') {
                $chainPath = \trim((string)($material['chain'] ?? ''));
                $activeCertificate = $this->certificateGenerations->activate(
                    $domain,
                    $cert,
                    $key,
                    $chainPath,
                    $certificateRoots,
                );
                $certReference = $this->certificateReference(
                    (string)$activeCertificate['cert_path'],
                    $certificateRoots,
                );
                $keyReference = $this->certificateReference(
                    (string)$activeCertificate['key_path'],
                    $certificateRoots,
                );
            }
            $pendingDigest = \hash('sha256', 'wls-pending-certificate' . "\0" . $domain);
            $routes[] = [
                'route_id' => \substr(\hash('sha256', $projectUuid . "\0" . $domain), 0, 32),
                'domain' => $domain,
                'backends' => [[
                    'host' => $host === 'localhost' ? '127.0.0.1' : $host,
                    'port' => $port,
                    'weight' => 1,
                ]],
                'backend_identity' => $backendIdentity,
                'certificate' => [
                    'cert' => $certReference,
                    'key' => $keyReference,
                    // The activated cert_path already contains the verified
                    // full chain. Re-supplying chain would duplicate it.
                    'chain' => null,
                    'source_digest' => $activeCertificate !== null
                        ? (string)$activeCertificate['source_digest']
                        : $pendingDigest,
                    'generation' => $activeCertificate !== null
                        ? (int)$activeCertificate['generation']
                        : 0,
                    'pending' => $activeCertificate === null,
                ],
                'force_https' => (bool)($material['force_https'] ?? true),
            ];
        }
        if ($routes === []) {
            throw new \RuntimeException('No valid project route can be built for gateway registration.');
        }
        \usort($routes, static fn (array $a, array $b): int => (string)$a['domain'] <=> (string)$b['domain']);
        $certificateDigest = \hash('sha256', GatewayClient::canonicalJson(\array_map(
            static fn (array $route): array => [
                'domain' => (string)$route['domain'],
                'source_digest' => (string)($route['certificate']['source_digest'] ?? ''),
            ],
            $routes,
        )));
        $this->identity->advanceCertificateState($certificateDigest);

        $projectDesired = [
            'project_uuid' => $projectUuid,
            'project_root' => $projectRoot,
            'backend_capability' => $this->backendCapabilities
                ->projectDesiredState($backendCapability),
            'routes' => \array_map(
                static fn (array $route): array => [
                    'route_id' => (string)$route['route_id'],
                    'domain' => (string)$route['domain'],
                    'certificate' => [
                        'source_digest' => (string)($route['certificate']['source_digest'] ?? ''),
                        'generation' => (int)($route['certificate']['generation'] ?? 0),
                    ],
                    'force_https' => (bool)$route['force_https'],
                ],
                $routes,
            ),
        ];
        $digest = \hash('sha256', GatewayClient::canonicalJson($projectDesired));
        [$generation, $idempotencyKey] = $this->resolveGeneration($digest);
        $instanceDigest = \hash('sha256', GatewayClient::canonicalJson([
            'project_uuid' => $projectUuid,
            'instance_id' => $instanceName,
            'instance_generation' => $instanceGeneration,
            'backend_identity' => $backendIdentity,
            'backends' => \array_map(
                static fn (array $route): array => $route['backends'],
                $routes,
            ),
        ]));

        return [
            'project_uuid' => $projectUuid,
            'project_root' => $projectRoot,
            'instance_id' => $instanceName,
            'master_epoch' => (int)$backendIdentity['master_epoch'],
            'launch_id' => $launchId,
            'instance_generation' => $instanceGeneration,
            'instance_digest' => $instanceDigest,
            'gateway_epoch' => '',
            'routes' => $routes,
            'project_generation' => $generation,
            'request_digest' => $digest,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    public function projectUuid(): string
    {
        return $this->identity->projectUuid();
    }

    /**
     * Resolve a protocol certificate reference back to the current project's
     * enrolled source without trusting an absolute path supplied by a peer.
     *
     * @param array<string,mixed> $reference
     */
    public function resolveCertificateSourceReference(array $reference): ?string
    {
        $projectRoot = $this->identity->projectRoot();
        $alias = \trim((string)($reference['root_alias'] ?? ''));
        $relative = \str_replace('\\', '/', \trim(
            (string)($reference['relative_path'] ?? ''),
        ));
        if ($projectRoot === ''
            || \preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', $alias) !== 1
            || $relative === ''
            || \str_starts_with($relative, '/')
            || \preg_match('/\A[A-Za-z]:\//D', $relative) === 1
        ) {
            return null;
        }
        $segments = \explode('/', $relative);
        foreach ($segments as $segment) {
            if ($segment === ''
                || $segment === '.'
                || $segment === '..'
                || \str_contains($segment, "\0")
            ) {
                return null;
            }
        }
        try {
            $roots = $this->certificateRoots($projectRoot);
        } catch (\Throwable) {
            return null;
        }
        $root = $roots[$alias] ?? '';
        $canonicalRoot = \realpath($root);
        if (!\is_string($canonicalRoot) || $canonicalRoot === '') {
            return null;
        }
        $candidate = \rtrim($canonicalRoot, '/\\');
        foreach ($segments as $segment) {
            $candidate .= DIRECTORY_SEPARATOR . $segment;
            if (\is_link($candidate)) {
                return null;
            }
        }
        $real = \realpath($candidate);
        if (!\is_string($real)
            || !\is_file($real)
            || \is_link($candidate)
            || !$this->pathInside($real, $canonicalRoot)
        ) {
            return null;
        }
        return $real;
    }

    public function requiresJoinBackend(string $instanceName): bool
    {
        /** @var ServerInstanceManager $instances */
        $instances = ObjectManager::getInstance(ServerInstanceManager::class);
        $endpoint = $instances->getRawInstanceData($instanceName);
        return \is_array($endpoint) && $this->endpointRequiresJoinBackend($endpoint);
    }

    /**
     * @return array<string,mixed>
     */
    public function joinBackendStatus(string $instanceName): array
    {
        /** @var ServerInstanceManager $instances */
        $instances = ObjectManager::getInstance(ServerInstanceManager::class);
        $endpoint = $instances->getRawInstanceData($instanceName);
        if (!\is_array($endpoint)) {
            return [];
        }
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        return \is_array($gateway['join_backend'] ?? null)
            ? $gateway['join_backend']
            : [];
    }

    public function nativeEdgeState(string $instanceName): string
    {
        /** @var ServerInstanceManager $instances */
        $instances = ObjectManager::getInstance(ServerInstanceManager::class);
        $endpoint = $instances->getRawInstanceData($instanceName);
        if (!\is_array($endpoint)) {
            return 'ACTIVE';
        }
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $native = \is_array($gateway['native_edge'] ?? null)
            ? $gateway['native_edge']
            : [];
        return \strtoupper(\trim((string)($native['state'] ?? 'ACTIVE')));
    }

    /**
     * @param array<string,mixed> $endpoint
     * @return array{host:string,port:int}
     */
    private function resolveBackend(
        array $endpoint,
        string $instanceName,
        string $projectUuid,
    ): array {
        if (!$this->endpointRequiresJoinBackend($endpoint)) {
            return [
                'host' => (string)($endpoint['host'] ?? '127.0.0.1'),
                'port' => (int)($endpoint['main_port'] ?? $endpoint['port'] ?? 0),
            ];
        }
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $join = \is_array($gateway['join_backend'] ?? null)
            ? $gateway['join_backend']
            : [];
        $tokenDigest = \hash('sha256', ProtocolEdgeRuntime::readToken($instanceName));
        $valid = \hash_equals('ACTIVE', (string)($join['state'] ?? ''))
            && \hash_equals($projectUuid, (string)($join['project_uuid'] ?? ''))
            && \hash_equals($instanceName, (string)($join['instance_id'] ?? ''))
            && (int)($join['instance_generation'] ?? 0)
                === (int)($gateway['instance_generation'] ?? 0)
            && (int)($join['master_pid'] ?? 0) === (int)($endpoint['master_pid'] ?? 0)
            && (int)($join['master_epoch'] ?? 0)
                === \max(
                    (int)($endpoint['master_epoch'] ?? 0),
                    (int)($endpoint['epoch'] ?? 0),
                )
            && \hash_equals(
                $tokenDigest,
                (string)($join['edge_capability_digest'] ?? ''),
            )
            && $this->joinBackendHasLiveWorker($join);
        $host = \trim((string)($join['host'] ?? ''));
        $port = (int)($join['port'] ?? 0);
        if (!$valid
            || !\in_array($host, ['127.0.0.1', '::1'], true)
            || $port < 20000
            || $port > 29999
        ) {
            throw new \RuntimeException(
                'Pure-WLS auto mode requires a verified ACTIVE loopback gateway join backend.'
            );
        }
        return ['host' => $host, 'port' => $port];
    }

    /** @param array<string,mixed> $join */
    private function joinBackendHasLiveWorker(array $join): bool
    {
        foreach ((array)($join['workers'] ?? []) as $worker) {
            if (!\is_array($worker)
                || !\hash_equals('READY', \strtoupper((string)($worker['state'] ?? '')))
            ) {
                continue;
            }
            if (Processer::isRunningByPid((int)($worker['pid'] ?? 0))) {
                return true;
            }
        }
        return Processer::isRunningByPid((int)($join['worker_pid'] ?? 0));
    }

    /**
     * @param array<string,mixed> $endpoint
     */
    private function endpointRequiresJoinBackend(array $endpoint): bool
    {
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        return \strtolower(\trim((string)($endpoint['edge_adapter'] ?? ''))) === 'wls'
            && \strtolower(\trim((string)($gateway['requested_mode'] ?? ''))) === 'auto';
    }

    /**
     * @return list<string>
     */
    public function desiredDomains(): array
    {
        try {
            /** @var SslCertificateService $certificates */
            $certificates = ObjectManager::getInstance(SslCertificateService::class);
            $projectRoot = \realpath((string)BP);
            $map = \is_string($projectRoot) && $projectRoot !== ''
                ? $certificates->getCertificateMap($this->certificateRoots($projectRoot))
                : [];
        } catch (\Throwable) {
            $map = [];
        }
        $domains = [];
        foreach (\array_keys((array)$map) as $domain) {
            $domain = $this->normalizeGatewayDomain((string)$domain);
            if ($domain !== null) {
                $domains[] = $domain;
            }
        }
        $domains = \array_values(\array_unique($domains));
        \sort($domains, SORT_STRING);
        return $domains;
    }

    /**
     * @return array<string,string>
     */
    public function enrollmentCertificateRoots(string $projectRoot): array
    {
        return $this->certificateRoots($projectRoot);
    }

    /**
     * Project-local TLS may legitimately include localhost or IP certificates,
     * but WLS Edge Protocol 2 only publishes DNS/SNI tenant routes.
     */
    private function normalizeGatewayDomain(string $domain): ?string
    {
        $domain = \strtolower(\rtrim(\trim($domain), '.'));
        $wildcard = \str_starts_with($domain, '*.');
        $body = $wildcard ? \substr($domain, 2) : $domain;
        if ($body === '') {
            return null;
        }
        if (\function_exists('idn_to_ascii')) {
            $variant = \defined('INTL_IDNA_VARIANT_UTS46')
                ? \constant('INTL_IDNA_VARIANT_UTS46')
                : 0;
            $ascii = @\idn_to_ascii($body, IDNA_DEFAULT, $variant);
            if (\is_string($ascii) && $ascii !== '') {
                $body = \strtolower($ascii);
            }
        }
        if (\strlen($body) > 253
            || \preg_match(
                '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)'
                    . '(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))+\z/D',
                $body,
            ) !== 1
        ) {
            return null;
        }
        return $wildcard ? '*.' . $body : $body;
    }

    private function endpointFile(string $instanceName): string
    {
        return Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'instances'
            . DIRECTORY_SEPARATOR . $instanceName . '.json';
    }

    /**
     * @return array<string,string>
     */
    private function certificateRoots(string $projectRoot): array
    {
        $roots = ['project_ssl' => $projectRoot . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl'];
        $env = Env::getInstance()->getConfig();
        $configured = \is_array($env)
            ? ($env['wls']['gateway']['certificate_roots'] ?? [])
            : [];
        $next = 1;
        foreach ((array)$configured as $configuredAlias => $root) {
            $root = \trim((string)$root);
            if ($root === '') {
                continue;
            }
            if (!\str_starts_with($root, '/') && \preg_match('/^[A-Za-z]:[\\\\\\/]/', $root) !== 1) {
                $root = $projectRoot . DIRECTORY_SEPARATOR . $root;
            }
            $real = \realpath($root);
            if (\is_string($real) && $real !== '') {
                $alias = \is_string($configuredAlias)
                    && \preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', $configuredAlias) === 1
                        ? $configuredAlias
                        : 'extra_' . $next++;
                if ($alias === 'project_ssl') {
                    throw new \RuntimeException(
                        'Configured certificate root alias project_ssl is reserved.'
                    );
                }
                $roots[$alias] = $real;
            }
        }
        return $roots;
    }

    /**
     * @param array<string,string> $roots
     * @return array{root_alias:string,relative_path:string}
     */
    private function certificateReference(string $path, array $roots): array
    {
        $real = \realpath($path);
        if (!\is_string($real) || !\is_file($real) || \is_link($path)) {
            throw new \RuntimeException('Certificate source is missing or is a symbolic link.');
        }
        \uasort($roots, static fn (string $left, string $right): int => \strlen($right) <=> \strlen($left));
        foreach ($roots as $alias => $root) {
            $canonicalRoot = \realpath($root);
            if (!\is_string($canonicalRoot) || !$this->pathInside($real, $canonicalRoot)) {
                continue;
            }
            $relative = \str_replace('\\', '/', \ltrim(
                \substr($real, \strlen(\rtrim($canonicalRoot, '/\\'))),
                '/\\',
            ));
            if ($relative === ''
                || \preg_match('#(?:^|/)(?:\\.|\\.\\.)(?:/|$)#D', $relative) === 1
            ) {
                break;
            }
            return ['root_alias' => (string)$alias, 'relative_path' => $relative];
        }
        throw new \RuntimeException(
            'Certificate source is outside every enrolled certificate root: ' . $path
        );
    }

    private function pathInside(string $path, string $root): bool
    {
        $path = \str_replace('\\', '/', \rtrim($path, '/\\'));
        $root = \str_replace('\\', '/', \rtrim($root, '/\\'));
        if (\PHP_OS_FAMILY === 'Windows') {
            $path = \strtolower($path);
            $root = \strtolower($root);
        }
        return $path === $root || \str_starts_with($path, $root . '/');
    }

    /**
     * @return array{0:int,1:string}
     */
    private function resolveGeneration(string $digest): array
    {
        $state = $this->identity->advanceDesiredState($digest);
        return [$state['generation'], $state['idempotency_key']];
    }
}
