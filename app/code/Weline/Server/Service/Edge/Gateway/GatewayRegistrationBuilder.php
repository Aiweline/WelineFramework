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
    public const BACKEND_IDENTITY_SCHEMA = 'wls-backend-listener-identity/2';

    public function __construct(
        private readonly ProjectIdentityStore $identity = new ProjectIdentityStore(),
        private readonly ProjectCertificateGenerationStore $certificateGenerations
            = new ProjectCertificateGenerationStore(),
        private readonly GatewayBackendCapabilityResolver $backendCapabilities
            = new GatewayBackendCapabilityResolver(),
        private readonly ?ProjectServingManifestStore $servingManifests = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(string $instanceName): array
    {
        return $this->withServingPublicationTransaction(
            $instanceName,
            static fn (\Closure $buildGateway, \Closure $buildServing): array =>
                $buildGateway(),
        );
    }

    /**
     * Keep one global lock order for every publisher:
     * project desired-state authority, then per-instance serving transaction.
     * The callback may retain both locks through a synchronous Worker ACK.
     *
     * @template TResult
     * @param \Closure(\Closure():array<string,mixed>,\Closure():array<string,mixed>):TResult $callback
     * @return TResult
     */
    public function withServingPublicationTransaction(
        string $instanceName,
        \Closure $callback,
    ): mixed {
        return $this->withServingPublicationTransactions(
            [$instanceName],
            static function (array $transactions) use (
                $instanceName,
                $callback,
            ): mixed {
                $transaction = $transactions[$instanceName] ?? null;
                if (!\is_array($transaction)) {
                    throw new \RuntimeException(
                        'WLS serving publication transaction was not acquired.',
                    );
                }
                return $callback(
                    $transaction['build_gateway'],
                    $transaction['build_serving'],
                );
            },
        );
    }

    /**
     * Freeze every named serving face behind one project desired-state lock
     * and deterministic per-instance publication locks. The callback may
     * retain the complete lock set through publication and exact ACK, so a
     * certificate revocation cannot converge instance A while an unrelated
     * publisher leaves instance B on an older TLS generation.
     *
     * @template TResult
     * @param list<string> $instanceNames
     * @param \Closure(array<string,array{build_gateway:\Closure():array<string,mixed>,build_serving:\Closure():array<string,mixed>}>):TResult $callback
     * @return TResult
     */
    public function withServingPublicationTransactions(
        array $instanceNames,
        \Closure $callback,
    ): mixed {
        if (!\array_is_list($instanceNames)
            || $instanceNames === []
            || \count($instanceNames) > 256
        ) {
            throw new \InvalidArgumentException(
                'WLS serving publication instance set is outside bounds.',
            );
        }
        $normalized = [];
        foreach ($instanceNames as $instanceName) {
            if (!\is_string($instanceName)
                || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceName) !== 1
                || isset($normalized[$instanceName])
            ) {
                throw new \InvalidArgumentException(
                    'WLS serving publication instance set is malformed.',
                );
            }
            $normalized[$instanceName] = true;
        }
        $instanceNames = \array_keys($normalized);
        \sort($instanceNames, SORT_STRING);
        $projectRoot = $this->identity->projectRoot();
        $stores = [];
        foreach ($instanceNames as $instanceName) {
            $stores[$instanceName] = \count($instanceNames) === 1
                && $this->servingManifests !== null
                    ? $this->servingManifests
                    : new ProjectServingManifestStore($projectRoot);
        }
        $transactions = [];
        foreach ($stores as $instanceName => $store) {
            $transactions[$instanceName] = [
                'build_gateway' => fn (): array => $this->buildLocked(
                    $instanceName,
                    $store,
                ),
                'build_serving' => fn (): array => $this->buildServingManifestLocked(
                    $instanceName,
                    $store,
                ),
            ];
        }
        $acquire = function (int $offset) use (
            &$acquire,
            $instanceNames,
            $stores,
            $transactions,
            $callback,
        ): mixed {
            if ($offset >= \count($instanceNames)) {
                return $callback($transactions);
            }
            $instanceName = $instanceNames[$offset];
            return $stores[$instanceName]->withPublicationTransaction(
                $instanceName,
                fn (): mixed => $acquire($offset + 1),
            );
        };
        return $this->identity->withDesiredStateBuildLock(
            fn (): mixed => $acquire(0),
        );
    }

    /**
     * Publish project serving truth without requiring a gateway listener
     * lease. Explicit --edge=wls instances intentionally have no enrollable
     * gateway backend, but their TLS Workers require the same immutable
     * certificate generations and launch fence.
     *
     * @return array{path:string,generation:int,digest:string,converged:bool,route_count:int,payload:array<string,mixed>}
     */
    public function buildServingManifest(string $instanceName): array
    {
        return $this->withServingPublicationTransaction(
            $instanceName,
            static fn (\Closure $buildGateway, \Closure $buildServing): array =>
                $buildServing(),
        );
    }

    /** @return array<string,mixed> */
    private function buildServingManifestLocked(
        string $instanceName,
        ProjectServingManifestStore $servingManifests,
    ): array {
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceName) !== 1) {
            throw new \InvalidArgumentException('WLS serving instance ID is outside bounds.');
        }
        /** @var ServerInstanceManager $instances */
        $instances = ObjectManager::getInstance(ServerInstanceManager::class);
        $endpoint = $instances->getRawInstanceData($instanceName);
        if (!\is_array($endpoint)) {
            throw new \RuntimeException('WLS instance endpoint is missing: ' . $instanceName);
        }
        $this->assertBackendIdentitySchema($endpoint);
        $projectUuid = $this->projectUuid();
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        if (!\hash_equals($instanceName, (string)($gateway['instance_id'] ?? ''))) {
            throw new \RuntimeException(
                'WLS serving endpoint instance identity does not match its persisted name.',
            );
        }
        $endpointProjectUuid = \strtolower(\trim((string)(
            $gateway['project_uuid'] ?? ''
        )));
        if ($endpointProjectUuid !== '' && !\hash_equals($projectUuid, $endpointProjectUuid)) {
            throw new \RuntimeException(
                'WLS endpoint project UUID does not match project-owned identity.',
            );
        }
        $projectRoot = \realpath((string)BP);
        $launchId = \strtolower(\trim((string)($gateway['launch_id'] ?? '')));
        $instanceGeneration = (int)($gateway['instance_generation'] ?? 0);
        $masterPid = (int)($endpoint['master_pid'] ?? 0);
        $masterEpoch = (int)($endpoint['master_epoch'] ?? 0);
        if (!\is_string($projectRoot)
            || $projectRoot === ''
            || \strlen($projectRoot) > 4096
            || \str_contains($projectRoot, "\0")
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
            || $instanceGeneration < 1
            || $masterPid < 1
            || $masterEpoch < 1
        ) {
            throw new \RuntimeException(
                'WLS serving manifest endpoint fence is incomplete; restart is required.',
            );
        }
        $certificateRoots = $this->certificateRoots($projectRoot);
        /** @var SslCertificateService $certificates */
        $certificates = ObjectManager::getInstance(SslCertificateService::class);
        $certificateMap = $certificates->getGatewayRouteMap($certificateRoots);
        $publicHost = \trim((string)($endpoint['public_host'] ?? ''));
        $endpointCert = \trim((string)($endpoint['ssl_cert'] ?? ''));
        $endpointKey = \trim((string)($endpoint['ssl_key'] ?? ''));
        $gatewayCertificate = \is_array($gateway['certificate_source'] ?? null)
            ? $gateway['certificate_source']
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
        if ($publicHost !== '' && $endpointCert !== '' && $endpointKey !== '') {
            $existingMaterial = \is_array($certificateMap[$publicHost] ?? null)
                ? $certificateMap[$publicHost]
                : [];
            $existingState = \strtolower(\trim((string)(
                $existingMaterial['certificate_state'] ?? ''
            )));
            if ($existingState !== 'disabled'
                && (\trim((string)($existingMaterial['cert'] ?? '')) === ''
                    || \trim((string)($existingMaterial['key'] ?? '')) === '')
            ) {
                $certificateMap[$publicHost] = [
                'cert' => $endpointCert,
                'key' => $endpointKey,
                'chain' => '',
                'cert_type' => \str_starts_with($publicHost, '*.') ? 'wildcard' : 'exact',
                'force_https' => 1,
                'force_root_to_www' => 0,
                'certificate_state' => 'active',
                ];
            }
        }
        if ($certificateMap === []) {
            throw new \RuntimeException(
                'No project-owned domain is available for WLS serving publication.',
            );
        }
        $preflightRoutes = $this->preflightCertificateRoutes($certificateMap);
        $backendCapability = $this->backendCapabilities
            ->capabilityFromLaunchSnapshot($endpoint);
        $routes = [];
        foreach ($preflightRoutes as $preflightRoute) {
            $domain = (string)$preflightRoute['domain'];
            $material = (array)$preflightRoute['material'];
            $certificate = $this->resolveRouteCertificate(
                $domain,
                $material,
                $certificateRoots,
            );
            $forceHttps = (bool)$preflightRoute['force_https']
                && $certificate['state'] !== 'disabled';
            $forceRootToWww = (bool)$preflightRoute['force_root_to_www']
                && $certificate['state'] !== 'disabled';
            $routes[] = [
                'route_id' => \substr(\hash('sha256', $projectUuid . "\0" . $domain), 0, 32),
                'domain' => $domain,
                'certificate' => [
                    'state' => (string)$certificate['state'],
                    'cert' => (array)$certificate['cert'],
                    'key' => (array)$certificate['key'],
                    'chain' => null,
                    'source_digest' => (string)$certificate['source_digest'],
                    'leaf_fingerprint_sha256' => (string)$certificate['leaf_fingerprint_sha256'],
                    'generation' => (int)$certificate['generation'],
                    'pending' => (bool)$certificate['pending'],
                ],
                'force_https' => $forceHttps,
                'force_root_to_www' => $forceRootToWww,
                'root_to_www_target' => (string)$preflightRoute['root_to_www_target'],
            ];
        }
        \usort($routes, static fn (array $a, array $b): int =>
            (string)$a['domain'] <=> (string)$b['domain']);
        $certificateDigest = \hash('sha256', GatewayClient::canonicalJson(\array_map(
            static fn (array $route): array => [
                'domain' => (string)$route['domain'],
                'state' => (string)($route['certificate']['state'] ?? ''),
                'source_digest' => (string)($route['certificate']['source_digest'] ?? ''),
            ],
            $routes,
        )));
        $this->identity->advanceCertificateState($certificateDigest);
        $projectCapability = $this->backendCapabilities
            ->projectDesiredState($backendCapability);
        $projectDesired = [
            'project_uuid' => $projectUuid,
            'project_root' => $projectRoot,
            'backend_capability' => $projectCapability,
            'routes' => \array_map(
                static fn (array $route): array => [
                    'route_id' => (string)$route['route_id'],
                    'domain' => (string)$route['domain'],
                    'certificate' => [
                        'state' => (string)$route['certificate']['state'],
                        'source_digest' => (string)$route['certificate']['source_digest'],
                        'generation' => (int)$route['certificate']['generation'],
                    ],
                    'force_https' => (bool)$route['force_https'],
                    'force_root_to_www' => (bool)$route['force_root_to_www'],
                    'root_to_www_target' => (string)$route['root_to_www_target'],
                ],
                $routes,
            ),
        ];
        $nonCertificateDesiredDigest = \hash(
            'sha256',
            GatewayClient::canonicalJson([
                'project_uuid' => $projectUuid,
                'project_root' => $projectRoot,
                'backend_capability' => $projectCapability,
                'routes' => \array_map(
                    static fn (array $route): array => [
                        'route_id' => (string)$route['route_id'],
                        'domain' => (string)$route['domain'],
                        'force_https' => (bool)$route['force_https'],
                        'force_root_to_www' => (bool)$route['force_root_to_www'],
                        'root_to_www_target' => (string)$route['root_to_www_target'],
                    ],
                    $routes,
                ),
            ]),
        );
        $requestDigest = \hash('sha256', GatewayClient::canonicalJson($projectDesired));
        [$projectGeneration, $idempotencyKey] = $this->resolveGeneration($requestDigest);
        $registration = [
            'project_uuid' => $projectUuid,
            'project_root' => $projectRoot,
            'instance_id' => $instanceName,
            'master_pid' => $masterPid,
            'master_epoch' => $masterEpoch,
            'launch_id' => $launchId,
            'instance_generation' => $instanceGeneration,
            'instance_digest' => \hash('sha256', GatewayClient::canonicalJson([
                'project_uuid' => $projectUuid,
                'instance_id' => $instanceName,
                'instance_generation' => $instanceGeneration,
                'master_pid' => $masterPid,
                'master_epoch' => $masterEpoch,
                'launch_id' => $launchId,
            ])),
            'routes' => $routes,
            'project_generation' => $projectGeneration,
            'request_digest' => $requestDigest,
            'non_certificate_desired_digest' => $nonCertificateDesiredDigest,
            'idempotency_key' => $idempotencyKey,
        ];
        $this->assertRegistrationEnvelope($registration);
        $servingManifest = $servingManifests->publishFromRegistration($registration);
        $requestedMode = \strtolower(\trim((string)($gateway['requested_mode'] ?? '')));
        if (\in_array($requestedMode, [
            GatewayStartupDecision::MODE_AUTO,
            GatewayStartupDecision::MODE_GATEWAY,
        ], true)) {
            // A gateway/controller outage may force the caller down this
            // local-only publication path. Persist the same coalescing intent
            // only after the immutable project snapshot and manifest succeed,
            // so recovery can replay the newest complete desired state.
            (new ProjectCertificateRenewalIntentStore($projectRoot))
                ->enqueueFromRegistration($registration);
        }
        return $servingManifest;
    }

    /** @return array<string,mixed> */
    private function buildLocked(
        string $instanceName,
        ProjectServingManifestStore $servingManifests,
    ): array {
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceName) !== 1) {
            throw new \InvalidArgumentException('Gateway instance ID is outside protocol bounds.');
        }
        /** @var ServerInstanceManager $instances */
        $instances = ObjectManager::getInstance(ServerInstanceManager::class);
        $endpoint = $instances->getRawInstanceData($instanceName);
        if (!\is_array($endpoint)) {
            throw new \RuntimeException('WLS instance endpoint is missing: ' . $instanceName);
        }
        $this->assertBackendIdentitySchema($endpoint);
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
        if (\strlen($projectRoot) > 4096 || \str_contains($projectRoot, "\0")) {
            throw new \RuntimeException('Canonical project root is outside protocol bounds.');
        }
        $launchId = \strtolower(\trim((string)($endpointGateway['launch_id'] ?? '')));
        if (\preg_match('/^[a-f0-9]{32}$/D', $launchId) !== 1) {
            throw new \RuntimeException(
                'WLS backend launch identity is missing; restart the managed instance before registration.',
            );
        }
        $certificateRoots = $this->certificateRoots($projectRoot);
        /** @var SslCertificateService $certificates */
        $certificates = ObjectManager::getInstance(SslCertificateService::class);
        // A transient storage failure is not proof that all previously known
        // domains were intentionally removed. Full desired-state construction
        // must therefore fail closed instead of collapsing to the endpoint's
        // single file-mode certificate and publishing destructive removals.
        $certificateMap = $certificates->getGatewayRouteMap($certificateRoots);

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
        if ($publicHost !== '' && $endpointCert !== '' && $endpointKey !== '') {
            $existingMaterial = \is_array($certificateMap[$publicHost] ?? null)
                ? $certificateMap[$publicHost]
                : [];
            $existingState = \strtolower(\trim((string)(
                $existingMaterial['certificate_state'] ?? ''
            )));
            if ($existingState !== 'disabled'
                && (\trim((string)($existingMaterial['cert'] ?? '')) === ''
                    || \trim((string)($existingMaterial['key'] ?? '')) === '')
            ) {
                $certificateMap[$publicHost] = [
                'cert' => $endpointCert,
                'key' => $endpointKey,
                'chain' => '',
                'cert_type' => \str_starts_with($publicHost, '*.') ? 'wildcard' : 'exact',
                'force_https' => 1,
                'force_root_to_www' => 0,
                'certificate_state' => 'active',
                ];
            }
        }
        if ($certificateMap === []) {
            throw new \RuntimeException(
                'No project-owned domain is available for gateway registration.'
            );
        }
        // Complete every bounded, side-effect-free route check before the first
        // immutable certificate generation is activated. A 257th endpoint
        // fallback domain, normalized duplicate, invalid policy or missing www
        // target must not leave a partial per-domain generation behind.
        $preflightRoutes = $this->preflightCertificateRoutes($certificateMap);
        $instanceGeneration = (int)($endpointGateway['instance_generation'] ?? 0);
        if ($instanceGeneration < 1) {
            throw new \RuntimeException(
                'WLS backend instance generation is missing; restart the managed instance before registration.',
            );
        }
        $edgeCapabilitySecret = ProtocolEdgeRuntime::readToken($instanceName);
        $backendCapability = $this->backendCapabilities
            ->capabilityFromLaunchSnapshot($endpoint);
        $masterPid = (int)($endpoint['master_pid'] ?? 0);
        $masterEpoch = (int)($endpoint['master_epoch'] ?? 0);
        if ($masterPid < 1 || $masterEpoch < 1) {
            throw new \RuntimeException(
                'WLS backend Master fence is missing; restart the managed instance before registration.',
            );
        }
        $listenerLeaseId = $this->resolveListenerLeaseId(
            $endpoint,
            $instanceName,
            $host === 'localhost' ? '127.0.0.1' : $host,
            $port,
        );
        $backendIdentity = [
            'schema' => self::BACKEND_IDENTITY_SCHEMA,
            'project_uuid' => $projectUuid,
            'instance_id' => $instanceName,
            'generation' => $instanceGeneration,
            'master_pid' => $masterPid,
            'master_epoch' => $masterEpoch,
            'launch_id' => $launchId,
            'listener_lease_id' => $listenerLeaseId,
            'edge_capability_secret' => $edgeCapabilitySecret,
            'edge_capability_digest' => \hash('sha256', $edgeCapabilitySecret),
            ...$this->backendCapabilities->instanceIdentityState($backendCapability),
        ];
        $publicBackendIdentity = $backendIdentity;
        unset($publicBackendIdentity['edge_capability_secret']);
        $backendIdentity['public_digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($publicBackendIdentity),
        );
        $backendIdentity['digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($backendIdentity),
        );

        $routes = [];
        $normalizedDomains = [];
        foreach ($preflightRoutes as $preflightRoute) {
            $domain = (string)$preflightRoute['domain'];
            $material = (array)$preflightRoute['material'];
            $normalizedDomains[$domain] = true;
            $certificate = $this->resolveRouteCertificate(
                $domain,
                $material,
                $certificateRoots,
            );
            $forceHttps = (bool)$preflightRoute['force_https']
                && $certificate['state'] !== 'disabled';
            $forceRootToWww = (bool)$preflightRoute['force_root_to_www']
                && $certificate['state'] !== 'disabled';
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
                    'state' => (string)$certificate['state'],
                    'cert' => (array)$certificate['cert'],
                    'key' => (array)$certificate['key'],
                    // The activated cert_path already contains the verified
                    // full chain. Re-supplying chain would duplicate it.
                    'chain' => null,
                    'source_digest' => (string)$certificate['source_digest'],
                    'leaf_fingerprint_sha256' => (string)$certificate['leaf_fingerprint_sha256'],
                    'generation' => (int)$certificate['generation'],
                    'pending' => (bool)$certificate['pending'],
                ],
                'force_https' => $forceHttps,
                'force_root_to_www' => $forceRootToWww,
                'root_to_www_target' => (string)$preflightRoute['root_to_www_target'],
            ];
        }
        if ($routes === []) {
            throw new \RuntimeException('No valid project route can be built for gateway registration.');
        }
        \usort($routes, static fn (array $a, array $b): int => (string)$a['domain'] <=> (string)$b['domain']);
        $certificateDigest = \hash('sha256', GatewayClient::canonicalJson(\array_map(
            static fn (array $route): array => [
                'domain' => (string)$route['domain'],
                'state' => (string)($route['certificate']['state'] ?? ''),
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
                        'state' => (string)($route['certificate']['state'] ?? ''),
                        'source_digest' => (string)($route['certificate']['source_digest'] ?? ''),
                        'generation' => (int)($route['certificate']['generation'] ?? 0),
                    ],
                    'force_https' => (bool)$route['force_https'],
                    'force_root_to_www' => (bool)$route['force_root_to_www'],
                    'root_to_www_target' => (string)$route['root_to_www_target'],
                ],
                $routes,
            ),
        ];
        $nonCertificateDesiredDigest = \hash(
            'sha256',
            GatewayClient::canonicalJson([
                'project_uuid' => $projectUuid,
                'project_root' => $projectRoot,
                'backend_capability' => $projectDesired['backend_capability'],
                'routes' => \array_map(
                    static fn (array $route): array => [
                        'route_id' => (string)$route['route_id'],
                        'domain' => (string)$route['domain'],
                        'force_https' => (bool)$route['force_https'],
                        'force_root_to_www' => (bool)$route['force_root_to_www'],
                        'root_to_www_target' => (string)$route['root_to_www_target'],
                    ],
                    $routes,
                ),
            ]),
        );
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

        $registration = [
            'project_uuid' => $projectUuid,
            'project_root' => $projectRoot,
            'instance_id' => $instanceName,
            'master_pid' => $masterPid,
            'master_epoch' => (int)$backendIdentity['master_epoch'],
            'launch_id' => $launchId,
            'instance_generation' => $instanceGeneration,
            'instance_digest' => $instanceDigest,
            'gateway_epoch' => '',
            'routes' => $routes,
            'project_generation' => $generation,
            'request_digest' => $digest,
            'non_certificate_desired_digest' => $nonCertificateDesiredDigest,
            'idempotency_key' => $idempotencyKey,
        ];
        $this->assertRegistrationEnvelope($registration);
        $servingManifest = $servingManifests->publishFromRegistration($registration);
        $registration['serving_manifest_generation'] = (int)$servingManifest['generation'];
        $registration['serving_manifest_digest'] = (string)$servingManifest['digest'];
        $registration['serving_manifest_path'] = (string)$servingManifest['path'];
        $registration['serving_manifest_converged'] = (bool)$servingManifest['converged'];
        $registration['serving_manifest_route_count'] = (int)$servingManifest['route_count'];
        // Certificate activation above commits the project-owned immutable
        // generation first. Only then publish the non-secret convergence
        // intent, so a gateway outage can never lose a renewal notification.
        (new ProjectCertificateRenewalIntentStore())
            ->enqueueFromRegistration($registration);
        return $registration;
    }

    /**
     * A running child is generation-bound to the schema and identity argv it
     * received at spawn time. Rewriting only endpoint metadata would create a
     * generation that no live Worker/Dispatcher can attest. Legacy endpoints
     * therefore require a real restart, which persists schema 2 before the new
     * child generation is launched.
     *
     * @param array<string,mixed> $endpoint
     * @return array<string,mixed>
     */
    private function assertBackendIdentitySchema(array $endpoint): void
    {
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $schema = \trim((string)($gateway['backend_identity_schema'] ?? ''));
        if ($schema === self::BACKEND_IDENTITY_SCHEMA) {
            return;
        }
        throw new \RuntimeException(
            $schema === ''
                ? 'Legacy WLS backend identity requires one instance restart before gateway registration.'
                : 'WLS backend identity schema is unsupported; restart with a compatible runtime.'
        );
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
            || \strlen($projectRoot) > 4096
            || \preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', $alias) !== 1
            || $relative === ''
            || \strlen($relative) > 4096
            || \str_starts_with($relative, '/')
            || \preg_match('/\A[A-Za-z]:\//D', $relative) === 1
        ) {
            return null;
        }
        $segments = \explode('/', $relative);
        if (\count($segments) > 256) {
            return null;
        }
        foreach ($segments as $segment) {
            if ($segment === ''
                || $segment === '.'
                || $segment === '..'
                || \strlen($segment) > 255
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
                === (int)($endpoint['master_epoch'] ?? 0)
            && \hash_equals(
                $tokenDigest,
                (string)($join['edge_capability_digest'] ?? ''),
            )
            && $this->joinBackendHasLiveWorker($join);
        $host = \trim((string)($join['host'] ?? ''));
        $port = (int)($join['port'] ?? 0);
        if (!$valid
            || !\in_array($host, ['127.0.0.1', '::1'], true)
            || $port < 1
            || $port > 65535
        ) {
            throw new \RuntimeException(
                'Pure-WLS auto mode requires a verified ACTIVE loopback gateway join backend.'
            );
        }
        return ['host' => $host, 'port' => $port];
    }

    /**
     * Resolve the listener fence from the exact host allocator identity. The
     * endpoint copy is useful only when it matches the live schema-5 lease;
     * neither synthesized IDs nor an unbound startup handoff are accepted.
     *
     * @param array<string,mixed> $endpoint
     */
    private function resolveListenerLeaseId(
        array $endpoint,
        string $instanceName,
        string $host,
        int $port,
    ): string {
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $requiresJoin = $this->endpointRequiresJoinBackend($endpoint);
        if ($requiresJoin) {
            $join = \is_array($gateway['join_backend'] ?? null)
                ? $gateway['join_backend']
                : [];
            $leaseId = \strtolower(\trim((string)(
                $join['listener_lease_id'] ?? ''
            )));
            $leaseInstance = GatewayLeaseIdentity::forRole(
                $instanceName,
                GatewayLeaseIdentity::ROLE_BACKEND,
            );
        } else {
            $embedded = \is_array($gateway['backend_lease'] ?? null)
                ? $gateway['backend_lease']
                : [];
            $leaseId = \strtolower(\trim((string)($embedded['lease_id'] ?? '')));
            $leaseInstance = GatewayLeaseIdentity::forRole(
                $instanceName,
                GatewayLeaseIdentity::ROLE_INITIAL_BACKEND,
            );
            if ((int)($embedded['schema_version'] ?? 0) !== 5
                || !\hash_equals('RESERVED', (string)($embedded['state'] ?? ''))
                || !\hash_equals($leaseInstance, (string)($embedded['instance'] ?? ''))
                || !\hash_equals($host, (string)($embedded['bind_host'] ?? ''))
                || (int)($embedded['port'] ?? 0) !== $port
            ) {
                throw new \RuntimeException(
                    'Gateway backend endpoint has no exact schema-5 startup lease; restart is required.',
                );
            }
            $handoff = $gateway['startup_listener_handoff'] ?? null;
            if ($handoff !== null
                && (!\is_array($handoff)
                    || ($handoff['continuous_ownership'] ?? false) !== true
                    || !\hash_equals($leaseId, (string)($handoff['lease_id'] ?? ''))
                    || !\hash_equals($leaseInstance, (string)($handoff['instance'] ?? ''))
                    || !\hash_equals($host, (string)($handoff['bind_host'] ?? ''))
                    || (int)($handoff['port'] ?? 0) !== $port)
            ) {
                throw new \RuntimeException(
                    'Gateway backend startup handoff does not match its exact listener lease.',
                );
            }
        }
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $leaseId) !== 1) {
            throw new \RuntimeException(
                'Gateway backend listener lease identity is missing; restart is required.',
            );
        }

        $lease = (new GatewayPortLeaseAllocator())->status($leaseInstance);
        if (!\is_array($lease)
            || (int)($lease['schema_version'] ?? 0) !== 5
            || !\hash_equals('ACTIVE', (string)($lease['state'] ?? ''))
            || !\hash_equals($leaseInstance, (string)($lease['instance'] ?? ''))
            || !\hash_equals($leaseId, (string)($lease['lease_id'] ?? ''))
            || !\hash_equals($host, (string)($lease['bind_host'] ?? ''))
            || (int)($lease['port'] ?? 0) !== $port
            || (int)($lease['master_pid'] ?? 0) !== (int)($endpoint['master_pid'] ?? 0)
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $lease['master_process_birth'] ?? ''
            )) !== 1
        ) {
            throw new \RuntimeException(
                'Gateway backend listener lease is not ACTIVE for the current Master fence.',
            );
        }
        if ($requiresJoin) {
            $requestedPort = $this->requestedGatewayBackendPort($gateway);
            $allocationScope = (string)($lease['allocation_scope'] ?? '');
            $allocationMatches = $requestedPort > 0
                ? $allocationScope === 'exact' && $port === $requestedPort
                : $allocationScope === 'stable_range'
                    && $port >= 20000
                    && $port <= 29999;
            if (!$allocationMatches) {
                throw new \RuntimeException(
                    'Gateway join backend lease does not match the requested loopback allocation policy.',
                );
            }
        }
        return $leaseId;
    }

    /** @param array<string,mixed> $gateway */
    private function requestedGatewayBackendPort(array $gateway): int
    {
        $raw = $gateway['requested_backend_port'] ?? 0;
        if (\is_int($raw)) {
            $port = $raw;
        } elseif (\is_string($raw)
            && \preg_match('/\A(?:0|[1-9][0-9]{0,4})\z/D', $raw) === 1
        ) {
            $port = (int)$raw;
        } else {
            throw new \RuntimeException(
                'Gateway requested backend port must be a literal integer.',
            );
        }
        if ($port < 0 || $port > 65535) {
            throw new \RuntimeException(
                'Gateway requested backend port must be zero or between 1 and 65535.',
            );
        }
        return $port;
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
                ? $certificates->getGatewayRouteMap($this->certificateRoots($projectRoot))
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
    public function enrollmentCertificateRoots(
        string $projectRoot,
        array $additionalRoots = [],
    ): array
    {
        if (\count($additionalRoots) > 31) {
            throw new \RuntimeException('Certificate enrollment exceeds the 32-root limit.');
        }
        $roots = $this->certificateRoots($projectRoot);
        foreach ($additionalRoots as $alias => $root) {
            if (!\is_string($alias) || isset($roots[$alias])) {
                throw new \RuntimeException('Certificate root alias is invalid or duplicated.');
            }
            $roots[$alias] = (string)$root;
        }
        return $this->validateCertificateRoots($projectRoot, $roots);
    }

    /**
     * @param array<string,string> $roots
     * @return array<string,string>
     */
    private function validateCertificateRoots(string $projectRoot, array $roots): array
    {
        if (\count($roots) < 1 || \count($roots) > 32 || \strlen($projectRoot) > 4096) {
            throw new \RuntimeException('Certificate root set is outside protocol bounds.');
        }
        $canonicalProject = \realpath($projectRoot);
        $projectStatus = @\lstat($projectRoot);
        if (!\is_string($canonicalProject)
            || !\is_array($projectStatus)
            || \is_link($projectRoot)
            || ((((int)($projectStatus['mode'] ?? 0)) & 0170000) !== 0040000)
            || !$this->samePath($projectRoot, $canonicalProject)
            || $this->isFilesystemRoot($canonicalProject)
        ) {
            throw new \RuntimeException('Project root for certificate enrollment is unsafe.');
        }
        $validated = [];
        $seenPaths = [];
        foreach ($roots as $alias => $root) {
            if (\preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', (string)$alias) !== 1) {
                throw new \RuntimeException('Certificate root alias is invalid.');
            }
            $candidate = \trim((string)$root);
            if ($candidate === ''
                || \strlen($candidate) > 4096
                || \str_contains($candidate, "\0")
            ) {
                throw new \RuntimeException('Certificate root path is invalid.');
            }
            if (!$this->isAbsolutePath($candidate)) {
                $candidate = $canonicalProject . DIRECTORY_SEPARATOR . $candidate;
            }
            $real = \realpath($candidate);
            $status = @\lstat($candidate);
            if (!\is_string($real)
                || !\is_array($status)
                || \is_link($candidate)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
                || !$this->samePath($candidate, $real)
                || $this->isFilesystemRoot($real)
            ) {
                throw new \RuntimeException(
                    'Certificate roots must be canonical, unlinked directories.'
                );
            }
            $this->assertNoLinkedDirectoryComponents($real);
            $pathKey = $this->pathKey($real);
            if (isset($seenPaths[$pathKey])) {
                throw new \RuntimeException('Certificate root paths must be unique.');
            }
            $seenPaths[$pathKey] = true;
            $validated[(string)$alias] = $real;
        }
        if (!isset($validated['project_ssl'])) {
            throw new \RuntimeException('The project_ssl certificate root is required.');
        }
        return $validated;
    }

    private function assertNoLinkedDirectoryComponents(string $directory): void
    {
        // The enrolled root is the trust boundary. Its parent may legitimately
        // be a sticky shared directory such as /tmp; walking above the root
        // would reject an otherwise owner-only enrollment and does not improve
        // the Broker's beneath/no-follow guarantee. Intermediate symlinks below
        // the textual candidate were already rejected by realpath equality.
        $status = @\lstat(\rtrim($directory, '/\\'));
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            || (\PHP_OS_FAMILY !== 'Windows'
                && (((int)($status['mode'] ?? 0)) & 0022) !== 0)
        ) {
            throw new \RuntimeException(
                'Certificate root is linked, special or group/world-writable.'
            );
        }
    }

    private function samePath(string $path, string $real): bool
    {
        return $this->pathKey($path) === $this->pathKey($real);
    }

    private function pathKey(string $path): string
    {
        $path = \str_replace('\\', '/', \rtrim($path, '/\\'));
        return \PHP_OS_FAMILY === 'Windows' ? \strtolower($path) : $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        return \str_starts_with($path, '/')
            || \preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) === 1
            || \str_starts_with($path, '\\\\');
    }

    private function normalizeRoutePolicyBoolean(
        mixed $value,
        string $field,
        string $domain,
    ): bool {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (\is_string($value) && ($value === '0' || $value === '1')) {
            return $value === '1';
        }
        throw new \RuntimeException(
            'Gateway route policy ' . $field . ' is not a canonical boolean: ' . $domain,
        );
    }

    /**
     * @param array<mixed,mixed> $certificateMap
     * @return list<array{
     *   domain:string,
     *   material:array<string,mixed>,
     *   force_https:bool,
     *   force_root_to_www:bool,
     *   root_to_www_target:string
     * }>
     */
    private function preflightCertificateRoutes(array $certificateMap): array
    {
        if ($certificateMap === [] || \count($certificateMap) > 256) {
            throw new \RuntimeException(
                'Gateway registration must contain 1..256 project certificate routes.'
            );
        }
        $routes = [];
        foreach ($certificateMap as $sourceDomain => $material) {
            if (!\is_string($sourceDomain) || !\is_array($material)) {
                throw new \RuntimeException(
                    'Project certificate map contains a non-domain or malformed entry.'
                );
            }
            $domain = $this->normalizeGatewayDomain($sourceDomain);
            if ($domain === null) {
                throw new \RuntimeException(
                    'Project certificate map contains a domain outside WLS Edge Protocol 2: '
                    . $sourceDomain,
                );
            }
            if (isset($routes[$domain])) {
                throw new \RuntimeException(
                    'Project certificate map contains duplicate normalized domain: ' . $domain,
                );
            }
            $certificate = \trim((string)($material['cert'] ?? ''));
            $privateKey = \trim((string)($material['key'] ?? ''));
            if (($certificate === '') !== ($privateKey === '')) {
                throw new \RuntimeException(
                    'Project certificate source must provide both certificate and private key: '
                    . $domain,
                );
            }
            $certificateState = \strtolower(\trim((string)(
                $material['certificate_state']
                    ?? (($certificate !== '' && $privateKey !== '') ? 'active' : 'pending')
            )));
            if (!\in_array($certificateState, ['active', 'pending', 'disabled'], true)) {
                throw new \RuntimeException(
                    'Project certificate source has an invalid lifecycle state: ' . $domain,
                );
            }
            if (($certificateState === 'active') !== ($certificate !== '' && $privateKey !== '')) {
                throw new \RuntimeException(
                    'Project certificate lifecycle state does not match its source material: '
                    . $domain,
                );
            }
            if ($certificateState !== 'active' && \str_starts_with($domain, '*.')) {
                throw new \RuntimeException(
                    'Pending or disabled gateway routes must use an exact domain: ' . $domain,
                );
            }
            $forceHttps = $this->normalizeRoutePolicyBoolean(
                $material['force_https'] ?? true,
                'force_https',
                $domain,
            );
            $forceRootToWww = $this->normalizeRoutePolicyBoolean(
                $material['force_root_to_www'] ?? false,
                'force_root_to_www',
                $domain,
            );
            if ($certificateState === 'disabled') {
                $forceHttps = false;
                $forceRootToWww = false;
            }
            $material['certificate_state'] = $certificateState;
            $routes[$domain] = [
                'domain' => $domain,
                'material' => $material,
                'force_https' => $forceHttps,
                'force_root_to_www' => $forceRootToWww,
                'root_to_www_target' => $forceRootToWww ? 'www.' . $domain : '',
            ];
        }
        foreach ($routes as $route) {
            if ($route['force_root_to_www'] !== true) {
                continue;
            }
            $domain = (string)$route['domain'];
            $target = (string)$route['root_to_www_target'];
            if (\str_starts_with($domain, '*.')
                || \str_starts_with($domain, 'www.')
                || !isset($routes[$target])
            ) {
                throw new \RuntimeException(
                    'force_root_to_www requires the exact same-project target route: '
                    . $target,
                );
            }
        }
        \ksort($routes, SORT_STRING);
        return \array_values($routes);
    }

    /**
     * Resolve an explicit certificate lifecycle state without allowing an old
     * immutable generation to resurrect after a project-owned revocation.
     *
     * @param array<string,mixed> $material
     * @param array<string,string> $certificateRoots
     * @return array{
     *   state:string,
     *   cert:array<string,mixed>,
     *   key:array<string,mixed>,
     *   source_digest:string,
     *   leaf_fingerprint_sha256:string,
     *   generation:int,
     *   pending:bool
     * }
     */
    private function resolveRouteCertificate(
        string $domain,
        array $material,
        array $certificateRoots,
    ): array {
        $requestedState = (string)($material['certificate_state'] ?? 'pending');
        $activeCertificate = null;
        $disabledCertificate = null;
        if ($requestedState === 'active') {
            $activeCertificate = $this->certificateGenerations->activate(
                $domain,
                \trim((string)($material['cert'] ?? '')),
                \trim((string)($material['key'] ?? '')),
                \trim((string)($material['chain'] ?? '')),
                $certificateRoots,
            );
        } elseif ($requestedState === 'disabled') {
            $this->certificateGenerations->deactivate($domain);
            $disabledCertificate = $this->certificateGenerations->disabled($domain);
            if ($disabledCertificate === null) {
                throw new \RuntimeException(
                    'Disabled certificate generation was not durably published: ' . $domain,
                );
            }
        } else {
            // Missing source material is not by itself a revocation. Preserve a
            // previously activated generation unless a newer disabled tombstone
            // has already made that generation ineligible for serving.
            $activeCertificate = $this->certificateGenerations->active($domain);
            $disabledCertificate = $this->certificateGenerations->disabled($domain);
            if ($activeCertificate !== null && $disabledCertificate !== null) {
                if ((int)$activeCertificate['generation'] <= (int)$disabledCertificate['generation']) {
                    $activeCertificate = null;
                } else {
                    $disabledCertificate = null;
                }
            } elseif ($activeCertificate !== null) {
                $disabledCertificate = null;
            }
        }

        if ($activeCertificate !== null) {
            return [
                'state' => 'active',
                'cert' => $this->certificateReference(
                    (string)$activeCertificate['cert_path'],
                    $certificateRoots,
                ),
                'key' => $this->certificateReference(
                    (string)$activeCertificate['key_path'],
                    $certificateRoots,
                ),
                'source_digest' => (string)$activeCertificate['source_digest'],
                'leaf_fingerprint_sha256' => (string)(
                    $activeCertificate['leaf_fingerprint_sha256'] ?? ''
                ),
                'generation' => (int)$activeCertificate['generation'],
                'pending' => false,
            ];
        }
        if ($disabledCertificate !== null) {
            return [
                'state' => 'disabled',
                'cert' => [],
                'key' => [],
                'source_digest' => (string)$disabledCertificate['source_digest'],
                'leaf_fingerprint_sha256' => '',
                'generation' => (int)$disabledCertificate['generation'],
                'pending' => true,
            ];
        }
        return [
            'state' => 'pending',
            'cert' => [],
            'key' => [],
            'source_digest' => \hash(
                'sha256',
                'wls-pending-certificate' . "\0" . $domain,
            ),
            'leaf_fingerprint_sha256' => '',
            'generation' => 0,
            'pending' => true,
        ];
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
        // Local loopback fact keys remain valid without a public TLD.
        if ($body === 'localhost') {
            return $wildcard ? '*.' . $body : $body;
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
            if (!\is_string($real) || $real === '') {
                throw new \RuntimeException('Configured certificate root is unavailable.');
            }
            $alias = \is_string($configuredAlias)
                && \preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', $configuredAlias) === 1
                    ? $configuredAlias
                    : 'extra_' . $next++;
            if ($alias === 'project_ssl') {
                throw new \RuntimeException(
                    'Configured certificate root alias project_ssl is reserved.'
                );
            }
            if (isset($roots[$alias])) {
                throw new \RuntimeException('Configured certificate root alias is duplicated: ' . $alias);
            }
            $roots[$alias] = $real;
        }
        return $this->validateCertificateRoots($projectRoot, $roots);
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
                || \strlen($relative) > 4096
                || \preg_match('#(?:^|/)(?:\\.|\\.\\.)(?:/|$)#D', $relative) === 1
            ) {
                break;
            }
            $segments = \explode('/', $relative);
            if (\count($segments) > 256) {
                break;
            }
            foreach ($segments as $segment) {
                if ($segment === '' || \strlen($segment) > 255) {
                    break 2;
                }
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
        if ($root === '' || $this->isFilesystemRoot($root)) {
            return false;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $path = \strtolower($path);
            $root = \strtolower($root);
        }
        return $path === $root || \str_starts_with($path, $root . '/');
    }

    private function isFilesystemRoot(string $path): bool
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
            || \preg_match('#\A//[?.]/Volume\{[0-9A-Fa-f-]+\}\z#Di', $path) === 1;
    }

    /**
     * @return array{0:int,1:string}
     */
    private function resolveGeneration(string $digest): array
    {
        $state = $this->identity->advanceDesiredState($digest);
        return [$state['generation'], $state['idempotency_key']];
    }

    /** @param array<string,mixed> $registration */
    private function assertRegistrationEnvelope(array $registration): void
    {
        $projectUuid = \strtolower((string)($registration['project_uuid'] ?? ''));
        $instanceId = (string)($registration['instance_id'] ?? '');
        $generation = (int)($registration['project_generation'] ?? 0);
        $instanceGeneration = (int)($registration['instance_generation'] ?? 0);
        $masterEpoch = (int)($registration['master_epoch'] ?? 0);
        $requestDigest = \strtolower((string)($registration['request_digest'] ?? ''));
        $nonCertificateDesiredDigest = \strtolower((string)(
            $registration['non_certificate_desired_digest'] ?? ''
        ));
        $instanceDigest = \strtolower((string)($registration['instance_digest'] ?? ''));
        $launchId = \strtolower((string)($registration['launch_id'] ?? ''));
        $idempotencyKey = \strtolower((string)($registration['idempotency_key'] ?? ''));
        $expectedIdempotencyKey = \substr(\hash(
            'sha256',
            $projectUuid . ':desired:' . $generation . ':' . $requestDigest,
        ), 0, 40);
        if (\preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            $projectUuid,
        ) !== 1
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceId) !== 1
            || $generation < 1
            || $instanceGeneration < 1
            || $masterEpoch < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $requestDigest) !== 1
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                $nonCertificateDesiredDigest,
            ) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $instanceDigest) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
            || \preg_match('/\A[a-f0-9]{40}\z/D', $idempotencyKey) !== 1
            || !\hash_equals($expectedIdempotencyKey, $idempotencyKey)
        ) {
            throw new \RuntimeException('Gateway registration envelope failed strict client validation.');
        }
        $registeredDomains = [];
        foreach ((array)($registration['routes'] ?? []) as $route) {
            if (!\is_array($route)) {
                throw new \RuntimeException('Gateway registration contains an invalid route.');
            }
            $domain = (string)($route['domain'] ?? '');
            $expectedRouteId = \substr(\hash(
                'sha256',
                $projectUuid . "\0" . $domain,
            ), 0, 32);
            if ($domain === ''
                || \strlen($domain) > 253
                || !\hash_equals($expectedRouteId, (string)($route['route_id'] ?? ''))
                || !\is_bool($route['force_https'] ?? null)
                || !\is_bool($route['force_root_to_www'] ?? null)
            ) {
                throw new \RuntimeException('Gateway route envelope failed strict client validation.');
            }
            $registeredDomains[$domain] = true;
        }
        foreach ((array)($registration['routes'] ?? []) as $route) {
            if (!\is_array($route) || ($route['force_root_to_www'] ?? false) !== true) {
                continue;
            }
            $domain = (string)($route['domain'] ?? '');
            if (\str_starts_with($domain, '*.')
                || \str_starts_with($domain, 'www.')
                || !isset($registeredDomains['www.' . $domain])
            ) {
                throw new \RuntimeException(
                    'Gateway root-to-www policy is missing its same-project target route.'
                );
            }
        }
    }
}
