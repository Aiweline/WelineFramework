<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Server\Service\ServerInstanceManager;

/**
 * Publishes the observed edge surface without changing project-owned route,
 * certificate, launch or backend identity fields.
 */
final class GatewayRuntimeEndpointPublisher
{
    private const OBSERVATION_REFRESH_SECONDS = 10;
    private const MAX_ROUTE_BACKENDS = 16;

    public function __construct(
        private readonly ?ServerInstanceManager $instances = null,
    ) {
    }

    /**
     * @param array<string,mixed> $status
     */
    public function publishHealthy(
        string $instanceName,
        array $status,
        string $nativeEdgeState,
        array $registration,
        array $activeRouteIds,
    ): bool {
        self::assertInstanceName($instanceName);
        self::assertHealthyObservation($status);
        $instances = $this->instances ?? new ServerInstanceManager();
        $file = $instances->getInstanceFile($instanceName);
        $current = $instances->getRawInstanceData($instanceName);
        if (!\is_array($current)) {
            return false;
        }
        $fence = self::endpointRuntimeFence($current);
        if ($fence === null) {
            return false;
        }
        $servingProof = self::buildServingProof(
            $instanceName,
            $current,
            $status,
            $registration,
            $activeRouteIds,
        );
        if (self::healthyProjectionIsCurrent(
            $current,
            $status,
            $nativeEdgeState,
            $servingProof,
        )) {
            return true;
        }
        $now = \time();
        $monotonicNow = self::monotonicNow();
        $casApplied = false;
        $updated = ServerInstanceManager::updateJsonFileAtomically(
            $file,
            static function (array $endpoint) use (
                $fence,
                $status,
                $nativeEdgeState,
                $servingProof,
                $now,
                $monotonicNow,
                &$casApplied,
            ): array {
                if (!self::endpointRuntimeFenceMatches($endpoint, $fence)) {
                    return $endpoint;
                }
                if (!self::servingManifestProofIsCurrent($endpoint, $servingProof)) {
                    return $endpoint;
                }
                $casApplied = true;
                return self::applyHealthyObservation(
                    $endpoint,
                    $status,
                    $nativeEdgeState,
                    $servingProof,
                    $now,
                    $monotonicNow,
                );
            },
        );
        return $updated && $casApplied;
    }

    public function publishFallbackActive(
        string $instanceName,
        array $leaseObservation,
        string $reason,
    ): bool {
        self::assertInstanceName($instanceName);
        $httpsPort = (int)($leaseObservation['port'] ?? 0);
        if ($httpsPort < 20000 || $httpsPort > 29999) {
            throw new \RuntimeException('Fallback HTTPS port is outside the project lease range.');
        }
        $reason = self::normalizeReason($reason);
        $instances = $this->instances ?? new ServerInstanceManager();
        $file = $instances->getInstanceFile($instanceName);
        $current = $instances->getRawInstanceData($instanceName);
        if (!\is_array($current)) {
            return false;
        }
        $fence = self::endpointRuntimeFence($current);
        if ($fence === null) {
            return false;
        }
        $servingManifest = self::currentServingManifestForEndpoint($current);
        if ((int)($servingManifest['route_count'] ?? 0) < 1) {
            throw new \RuntimeException(
                'HTTP-only project state has no TLS route for a fallback endpoint.',
            );
        }
        $leaseProof = self::buildFallbackLeaseProof(
            $instanceName,
            $current,
            $leaseObservation,
            $servingManifest,
        );
        $gateway = \is_array($current['gateway'] ?? null) ? $current['gateway'] : [];
        if (GatewayRuntimeServingProjection::fallbackWlsIsServing($current)
            && (string)($gateway['fallback_state'] ?? '') === 'DEGRADED_WLS'
            && (int)($gateway['public_https'] ?? 0) === $httpsPort
            && (string)($gateway['degraded_reason'] ?? '') === $reason
            && \hash_equals(
                (string)$leaseProof['proof_digest'],
                (string)($gateway['fallback_lease_proof']['proof_digest'] ?? ''),
            )
            && self::observationWasRecentlyPublished($gateway)
        ) {
            return true;
        }
        $now = \time();
        $monotonicNow = self::monotonicNow();
        $casApplied = false;
        $updated = ServerInstanceManager::updateJsonFileAtomically(
            $file,
            static function (array $endpoint) use (
                $fence,
                $httpsPort,
                $leaseProof,
                $reason,
                $now,
                $monotonicNow,
                &$casApplied,
            ): array {
                if (!self::endpointRuntimeFenceMatches($endpoint, $fence)) {
                    return $endpoint;
                }
                if (!self::servingManifestReferenceIsCurrent(
                    $endpoint,
                    (int)$leaseProof['serving_manifest_generation'],
                    (string)$leaseProof['serving_manifest_digest'],
                )) {
                    return $endpoint;
                }
                $casApplied = true;
                return self::applyFallbackObservation(
                    $endpoint,
                    $httpsPort,
                    $reason,
                    $now,
                    $leaseProof,
                    $monotonicNow,
                );
            },
        );
        return $updated && $casApplied;
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    public static function applyHealthyObservation(
        array $endpoint,
        array $status,
        string $nativeEdgeState,
        array $servingProof,
        int $now,
        ?float $monotonicNow = null,
    ): array {
        self::assertHealthyObservation($status);
        self::assertServingProof($endpoint, $status, $servingProof);
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $runtimeFence = self::endpointRuntimeFence($endpoint);
        if ($runtimeFence === null) {
            throw new \RuntimeException(
                'Gateway runtime observation has no current Master fence.',
            );
        }
        $nativeState = \strtoupper(\trim($nativeEdgeState));
        // mode/edge_decision are the immutable startup intent. Runtime
        // observations only describe which edge is currently serving.
        $gateway['serving_mode'] = 'gateway';
        $gateway['protocol'] = GatewayPaths::PROTOCOL;
        $gateway['epoch'] = \strtolower((string)$status['epoch']);
        $gateway['public_http'] = (int)$status['public_http'];
        $gateway['public_https'] = (int)$status['public_https'];
        $gateway['degraded_reason'] = '';
        $gateway['fallback_state'] = match ($nativeState) {
            'DRAINING' => 'NATIVE_EDGE_DRAINING',
            'DRAINED' => 'GATEWAY_ACTIVE',
            default => 'NATIVE_EDGE_STANDBY',
        };
        $gateway['runtime_generation'] = \max(
            0,
            (int)($status['active_config_generation'] ?? 0),
        );
        $gateway['runtime_project_proof'] = $servingProof;
        $gateway['serving_manifest_generation'] = (int)(
            $servingProof['serving_manifest_generation'] ?? 0
        );
        $gateway['serving_manifest_digest'] = (string)(
            $servingProof['serving_manifest_digest'] ?? ''
        );
        unset($gateway['fallback_lease_proof']);
        $gateway['runtime_fence'] = $runtimeFence;
        $gateway['runtime_observed_at'] = \gmdate(DATE_ATOM, $now);
        $gateway['runtime_observed_timestamp'] = $now;
        $gateway['runtime_observed_monotonic'] = $monotonicNow ?? self::monotonicNow();
        $gateway['runtime_observed_host_boot_id'] = (string)$servingProof['host_boot_id'];
        $gateway['runtime_observed_launch_id'] = (string)$runtimeFence['launch_id'];
        $endpoint['gateway'] = $gateway;

        return $endpoint;
    }

    /**
     * @param array<string,mixed> $endpoint
     * @return array<string,mixed>
     */
    public static function applyFallbackObservation(
        array $endpoint,
        int $httpsPort,
        string $reason,
        int $now,
        array $leaseProof,
        ?float $monotonicNow = null,
    ): array {
        if ($httpsPort < 20000 || $httpsPort > 29999) {
            throw new \RuntimeException('Fallback HTTPS port is outside the project lease range.');
        }
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $runtimeFence = self::endpointRuntimeFence($endpoint);
        if ($runtimeFence === null) {
            throw new \RuntimeException(
                'Gateway fallback observation has no current Master fence.',
            );
        }
        self::assertFallbackLeaseProofShape($endpoint, $httpsPort, $leaseProof);
        $bindHost = (string)$leaseProof['bind_host'];
        $bindAuthority = \str_contains($bindHost, ':')
            ? '[' . $bindHost . ']'
            : $bindHost;
        $publicAuthority = (string)$leaseProof['authority_host'];
        $gateway['serving_mode'] = 'fallback_wls';
        $gateway['public_http'] = 0;
        $gateway['public_https'] = $httpsPort;
        $gateway['degraded_reason'] = self::normalizeReason($reason);
        $gateway['fallback_state'] = 'DEGRADED_WLS';
        $gateway['fallback_lease_proof'] = $leaseProof;
        $gateway['serving_manifest_generation'] = (int)(
            $leaseProof['serving_manifest_generation'] ?? 0
        );
        $gateway['serving_manifest_digest'] = (string)(
            $leaseProof['serving_manifest_digest'] ?? ''
        );
        // These fields are diagnostic projections, not startup intent. Always
        // derive them from the same live lease proof so a stale config value
        // can never advertise an address different from the retained listener.
        $gateway['fallback_bind_host'] = $bindHost;
        $gateway['fallback_bind'] = $bindAuthority . ':' . $httpsPort;
        $gateway['fallback_urls'] = [
            'https://' . self::formatUrlHost($publicAuthority) . ':' . $httpsPort,
        ];
        // Retain the last authenticated gateway project proof while native
        // fallback serves. It remains historical evidence only; serving-mode
        // projection never treats it as a current gateway observation.
        $gateway['runtime_fence'] = $runtimeFence;
        $gateway['runtime_observed_at'] = \gmdate(DATE_ATOM, $now);
        $gateway['runtime_observed_timestamp'] = $now;
        $gateway['runtime_observed_monotonic'] = $monotonicNow ?? self::monotonicNow();
        $gateway['runtime_observed_host_boot_id'] = GatewayHostBootIdentity::current();
        $gateway['runtime_observed_launch_id'] = (string)$runtimeFence['launch_id'];
        $endpoint['gateway'] = $gateway;

        return $endpoint;
    }

    /**
     * @param array<string,mixed> $status
     */
    private static function assertHealthyObservation(array $status): void
    {
        $epoch = \strtolower(\trim((string)($status['epoch'] ?? '')));
        $http = (int)($status['public_http'] ?? 0);
        $https = (int)($status['public_https'] ?? 0);
        self::statusHostBootId($status);
        if (($status['ok'] ?? false) !== true
            || ($status['project_ready'] ?? false) !== true
            || ($status['data_plane']['running'] ?? false) !== true
            || (string)($status['state'] ?? '') === 'DATA_PLANE_DOWN'
            || !\hash_equals(GatewayPaths::PROTOCOL, (string)($status['protocol'] ?? ''))
            || \preg_match('/^[a-f0-9]{32}$/D', $epoch) !== 1
            || $http < 1
            || $http > 65535
            || $https < 1
            || $https > 65535
            || $http === $https
        ) {
            throw new \RuntimeException('Gateway observation is not authenticated and healthy.');
        }
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $status
     */
    private static function healthyProjectionIsCurrent(
        array $endpoint,
        array $status,
        string $nativeEdgeState,
        array $servingProof,
    ): bool {
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $nativeState = \strtoupper(\trim($nativeEdgeState));
        $fallbackState = match ($nativeState) {
            'DRAINING' => 'NATIVE_EDGE_DRAINING',
            'DRAINED' => 'GATEWAY_ACTIVE',
            default => 'NATIVE_EDGE_STANDBY',
        };

        return GatewayRuntimeServingProjection::gatewayIsServing($endpoint)
            && self::observationWasRecentlyPublished($gateway)
            && \hash_equals(GatewayPaths::PROTOCOL, (string)($gateway['protocol'] ?? ''))
            && \hash_equals(
                \strtolower((string)$status['epoch']),
                \strtolower((string)($gateway['epoch'] ?? '')),
            )
            && (int)($gateway['public_http'] ?? 0) === (int)$status['public_http']
            && (int)($gateway['public_https'] ?? 0) === (int)$status['public_https']
            && (string)($gateway['degraded_reason'] ?? '') === ''
            && (string)($gateway['fallback_state'] ?? '') === $fallbackState
            && (int)($gateway['runtime_generation'] ?? 0)
                === \max(0, (int)($status['active_config_generation'] ?? 0))
            && (int)($gateway['serving_manifest_generation'] ?? 0)
                === (int)($servingProof['serving_manifest_generation'] ?? -1)
            && \hash_equals(
                (string)($servingProof['serving_manifest_digest'] ?? ''),
                (string)($gateway['serving_manifest_digest'] ?? ''),
            )
            && \hash_equals(
                (string)$servingProof['proof_digest'],
                (string)($gateway['runtime_project_proof']['proof_digest'] ?? ''),
            );
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $status
     * @param array<string,mixed> $registration
     * @param list<string> $activeRouteIds
     * @return array<string,mixed>
     */
    private static function buildServingProof(
        string $instanceName,
        array $endpoint,
        array $status,
        array $registration,
        array $activeRouteIds,
    ): array {
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $projectUuid = \strtolower(\trim((string)(
            $gateway['project_uuid'] ?? ''
        )));
        $hostBootId = self::statusHostBootId($status);
        if (!\hash_equals($instanceName, (string)($gateway['instance_id'] ?? ''))
            || !\hash_equals($instanceName, (string)($registration['instance_id'] ?? ''))
            || !\hash_equals($projectUuid, (string)($registration['project_uuid'] ?? ''))
            || !\hash_equals(
                $projectUuid,
                \strtolower(\trim((string)($status['project_uuid'] ?? ''))),
            )
            || \preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $projectUuid,
            ) !== 1
            || (int)($registration['project_generation'] ?? 0) < 1
            || (int)($status['project_generation'] ?? 0)
                !== (int)($registration['project_generation'] ?? -1)
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($registration['request_digest'] ?? ''),
            ) !== 1
            || !\hash_equals(
                (string)($registration['request_digest'] ?? ''),
                \strtolower(\trim((string)($status['request_digest'] ?? ''))),
            )
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $registration['non_certificate_desired_digest'] ?? ''
            )) !== 1
            || !\hash_equals(
                (string)($registration['non_certificate_desired_digest'] ?? ''),
                \strtolower(\trim((string)(
                    $status['non_certificate_desired_digest'] ?? ''
                ))),
            )
            || ($status['publication_exact'] ?? false) !== true
            || (int)($status['active_config_generation'] ?? 0) < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', \strtolower(\trim((string)(
                $status['active_config_digest'] ?? ''
            )))) !== 1
            || !\array_is_list($activeRouteIds)
            || \count($activeRouteIds) > 256
        ) {
            throw new \RuntimeException(
                'Gateway serving proof does not match the endpoint tenant identity.'
            );
        }
        $active = [];
        foreach ($activeRouteIds as $routeId) {
            $routeId = \strtolower(\trim((string)$routeId));
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || isset($active[$routeId])
            ) {
                throw new \RuntimeException('Gateway serving proof route set is invalid.');
            }
            $active[$routeId] = true;
        }
        $desired = [];
        $desiredRoutes = $registration['routes'] ?? null;
        if (!\is_array($desiredRoutes)
            || !\array_is_list($desiredRoutes)
            || $desiredRoutes === []
            || \count($desiredRoutes) > 256
        ) {
            throw new \RuntimeException(
                'Gateway serving proof desired route set is outside bounds.',
            );
        }
        foreach ($desiredRoutes as $route) {
            if (!\is_array($route)) {
                throw new \RuntimeException(
                    'Gateway serving proof desired route set is malformed.',
                );
            }
            $desiredRouteId = \strtolower(\trim((string)($route['route_id'] ?? '')));
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $desiredRouteId) !== 1
                || isset($desired[$desiredRouteId])
            ) {
                throw new \RuntimeException(
                    'Gateway serving proof desired route identity is duplicated or malformed.',
                );
            }
            $desired[$desiredRouteId] = $route;
        }
        $observed = [];
        $publishedRoutes = $status['active_routes'] ?? null;
        if (!\is_array($publishedRoutes)
            || !\array_is_list($publishedRoutes)
            || \count($publishedRoutes) > 256
        ) {
            throw new \RuntimeException(
                'Gateway serving proof has no bounded active publication.'
            );
        }
        foreach ($publishedRoutes as $route) {
            if (\is_array($route)
                && \hash_equals($projectUuid, (string)($route['project_uuid'] ?? ''))
            ) {
                $routeId = (string)($route['route_id'] ?? '');
                if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                    || isset($observed[$routeId])
                ) {
                    throw new \RuntimeException(
                        'Gateway serving proof active publication is duplicated or malformed.'
                    );
                }
                $observed[$routeId] = $route;
            }
        }
        $observedActive = [];
        foreach ($observed as $routeId => $route) {
            if (\hash_equals('ACTIVE', (string)($route['status'] ?? ''))) {
                $observedActive[$routeId] = true;
            }
        }
        $requestedActive = $active;
        \ksort($observedActive, SORT_STRING);
        \ksort($requestedActive, SORT_STRING);
        if (\array_keys($observedActive) !== \array_keys($requestedActive)) {
            throw new \RuntimeException(
                'Gateway serving proof route set is not the exact ACTIVE publication.',
            );
        }
        $publishedInstances = $status['instances'] ?? null;
        if (!\is_array($publishedInstances)
            || !\array_is_list($publishedInstances)
            || \count($publishedInstances) > 512
        ) {
            throw new \RuntimeException(
                'Gateway serving proof instance publication is outside bounds.',
            );
        }
        $instanceMatches = [];
        foreach ($publishedInstances as $instance) {
            if (\is_array($instance)
                && \hash_equals($instanceName, (string)($instance['instance_id'] ?? ''))
            ) {
                $instanceMatches[] = $instance;
            }
        }
        $publishedInstance = $instanceMatches[0] ?? null;
        if (\count($instanceMatches) !== 1
            || !\is_array($publishedInstance)
            || (int)($publishedInstance['generation'] ?? 0)
                !== (int)($registration['instance_generation'] ?? -1)
            || !\hash_equals(
                (string)($registration['instance_digest'] ?? ''),
                (string)($publishedInstance['digest'] ?? ''),
            )
            || (int)($publishedInstance['master_epoch'] ?? 0)
                !== (int)($registration['master_epoch'] ?? -1)
            || !\hash_equals(
                (string)($registration['launch_id'] ?? ''),
                (string)($publishedInstance['launch_id'] ?? ''),
            )
        ) {
            throw new \RuntimeException(
                'Gateway serving proof instance publication fence is stale.'
            );
        }
        $routes = [];
        foreach (\array_keys($active) as $routeId) {
            $local = $desired[$routeId] ?? null;
            $remote = $observed[$routeId] ?? null;
            if (!\is_array($local)
                || !\is_array($remote)
                || !\hash_equals('ACTIVE', (string)($remote['status'] ?? ''))
                || !\hash_equals(
                    (string)($local['domain'] ?? ''),
                    (string)($remote['domain'] ?? ''),
                )
                || !\is_bool($remote['force_https'] ?? null)
                || !\is_bool($remote['force_root_to_www'] ?? null)
                || (bool)$remote['force_https'] !== (bool)($local['force_https'] ?? true)
                || (bool)$remote['force_root_to_www']
                    !== (bool)($local['force_root_to_www'] ?? false)
                || (int)($remote['route_generation'] ?? 0) < 1
            ) {
                throw new \RuntimeException(
                    'Gateway serving proof route is not an authenticated ACTIVE route.'
                );
            }
            $localCertificate = \is_array($local['certificate'] ?? null)
                ? $local['certificate']
                : [];
            $remoteCertificate = \is_array($remote['certificate'] ?? null)
                ? $remote['certificate']
                : [];
            $sourceDigest = \strtolower(\trim((string)(
                $localCertificate['source_digest'] ?? ''
            )));
            $certificateGeneration = (int)($localCertificate['generation'] ?? 0);
            $backend = self::backendInstanceForServingProof(
                $remote['backend_instances'] ?? null,
                $instanceName,
            );
            $identity = \is_array($backend['backend_identity'] ?? null)
                ? $backend['backend_identity']
                : [];
            $publicDigest = \strtolower(\trim((string)(
                $identity['public_digest'] ?? ''
            )));
            $forceRootToWww = (bool)($local['force_root_to_www'] ?? false);
            $rootToWwwTarget = (string)($local['root_to_www_target'] ?? '');
            $remoteRootToWwwTarget = $remote['root_to_www_target'] ?? null;
            $remoteRedirectTargetReady = $remote['root_to_www_target_ready'] ?? null;
            $redirectTargetInActiveSet = !$forceRootToWww
                && $rootToWwwTarget === '';
            if ($forceRootToWww
                && \hash_equals('www.' . (string)$local['domain'], $rootToWwwTarget)
            ) {
                foreach ($desired as $targetRouteId => $targetRoute) {
                    if (isset($active[$targetRouteId])
                        && \is_array($targetRoute)
                        && \hash_equals(
                            $rootToWwwTarget,
                            (string)($targetRoute['domain'] ?? ''),
                        )
                    ) {
                        $redirectTargetInActiveSet = true;
                        break;
                    }
                }
            }
            if ($certificateGeneration < 1
                || \preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1
                || $certificateGeneration
                    !== (int)($remoteCertificate['generation'] ?? 0)
                || !\hash_equals(
                    $sourceDigest,
                    (string)($remoteCertificate['source_digest'] ?? ''),
                )
                || !\hash_equals($projectUuid, (string)($identity['project_uuid'] ?? ''))
                || !\hash_equals($instanceName, (string)($identity['instance_id'] ?? ''))
                || (int)($identity['generation'] ?? 0)
                    !== (int)($gateway['instance_generation'] ?? 0)
                || (int)($identity['master_pid'] ?? 0)
                    !== (int)($endpoint['master_pid'] ?? 0)
                || (int)($identity['master_epoch'] ?? 0)
                    !== (int)($endpoint['master_epoch'] ?? 0)
                || !\hash_equals(
                    (string)($gateway['launch_id'] ?? ''),
                    (string)($identity['launch_id'] ?? ''),
                )
                || \preg_match('/\A[a-f0-9]{64}\z/D', $publicDigest) !== 1
                || !\is_string($remoteRootToWwwTarget)
                || !\hash_equals($rootToWwwTarget, $remoteRootToWwwTarget)
                || $remoteRedirectTargetReady !== true
                || !$redirectTargetInActiveSet
            ) {
                throw new \RuntimeException(
                    'Gateway serving proof backend or certificate fence is invalid.'
                );
            }
            $routes[$routeId] = [
                'route_id' => $routeId,
                'domain' => (string)$local['domain'],
                'route_generation' => (int)$remote['route_generation'],
                'certificate_generation' => $certificateGeneration,
                'certificate_source_digest' => $sourceDigest,
                'backend_public_digest' => $publicDigest,
                'force_https' => (bool)$local['force_https'],
                'force_root_to_www' => $forceRootToWww,
                'root_to_www_target' => $rootToWwwTarget,
                // This readiness bit is authenticated by the gateway and is
                // independently closed over this exact ACTIVE project subset.
                'root_to_www_target_ready' => true,
            ];
        }
        \ksort($routes, SORT_STRING);
        $routeGenerations = [];
        foreach ($routes as $routeId => $route) {
            $routeGenerations[(string)$routeId] = (int)$route['route_generation'];
        }
        $manifest = (new ProjectServingManifestStore((string)(
            $registration['project_root'] ?? ''
        )))->publishFromRegistration(
            $registration,
            \array_keys($routes),
            $routeGenerations,
            \count($routes) === \count($desired),
        );
        $proof = [
            'schema_version' => 2,
            'project_uuid' => $projectUuid,
            'instance_id' => $instanceName,
            'project_generation' => (int)$registration['project_generation'],
            'request_digest' => (string)$registration['request_digest'],
            'non_certificate_desired_digest' => (string)(
                $registration['non_certificate_desired_digest'] ?? ''
            ),
            'instance_generation' => (int)$registration['instance_generation'],
            'instance_digest' => (string)$registration['instance_digest'],
            'master_pid' => (int)($endpoint['master_pid'] ?? 0),
            'master_epoch' => (int)$registration['master_epoch'],
            'launch_id' => (string)$registration['launch_id'],
            'gateway_epoch' => \strtolower((string)$status['epoch']),
            'host_boot_id' => $hostBootId,
            'active_config_generation' => (int)$status['active_config_generation'],
            'active_config_digest' => \strtolower(\trim((string)(
                $status['active_config_digest'] ?? ''
            ))),
            'serving_manifest_generation' => (int)$manifest['generation'],
            'serving_manifest_digest' => (string)$manifest['digest'],
            'active_routes' => \array_values($routes),
            'public_probe_verified' => true,
        ];
        $proof['proof_digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($proof),
        );
        self::assertServingProof($endpoint, $status, $proof);
        return $proof;
    }

    /**
     * The wire protocol intentionally represents backend instances as a list:
     * numeric-only instance IDs are valid and cannot safely be JSON object
     * keys in PHP. Validate the complete ordered closure before selecting the
     * one launch that this project endpoint is allowed to publish.
     *
     * @return array<string,mixed>
     */
    private static function backendInstanceForServingProof(
        mixed $value,
        string $instanceName,
    ): array {
        if (!\is_array($value)
            || !\array_is_list($value)
            || $value === []
            || \count($value) > self::MAX_ROUTE_BACKENDS
        ) {
            throw new \RuntimeException(
                'Gateway serving proof backend-instance closure is malformed.',
            );
        }
        $match = null;
        $lastInstanceId = '';
        $backendCount = 0;
        foreach ($value as $instance) {
            if (!\is_array($instance) || \array_is_list($instance)) {
                throw new \RuntimeException(
                    'Gateway serving proof backend-instance row is malformed.',
                );
            }
            $fields = \array_keys($instance);
            \sort($fields, SORT_STRING);
            if ($fields !== ['backend_identity', 'backends', 'instance_id']) {
                throw new \RuntimeException(
                    'Gateway serving proof backend-instance fields changed.',
                );
            }
            $instanceId = $instance['instance_id'] ?? null;
            $backends = $instance['backends'] ?? null;
            $identity = $instance['backend_identity'] ?? null;
            if (!\is_string($instanceId)
                || \preg_match(
                    '/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D',
                    $instanceId,
                ) !== 1
                || ($lastInstanceId !== ''
                    && \strcmp($lastInstanceId, $instanceId) >= 0)
                || !\is_array($backends)
                || !\array_is_list($backends)
                || $backends === []
                || !\is_array($identity)
                || (\array_is_list($identity) && $identity !== [])
                || $identity === []
            ) {
                throw new \RuntimeException(
                    'Gateway serving proof backend-instance identity is invalid.',
                );
            }
            $backendCount += \count($backends);
            if ($backendCount > self::MAX_ROUTE_BACKENDS) {
                throw new \RuntimeException(
                    'Gateway serving proof backend-instance closure exceeds its bound.',
                );
            }
            if (\hash_equals($instanceName, $instanceId)) {
                if ($match !== null) {
                    throw new \RuntimeException(
                        'Gateway serving proof backend instance is duplicated.',
                    );
                }
                $match = $instance;
            }
            $lastInstanceId = $instanceId;
        }
        return \is_array($match) ? $match : [];
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $status
     * @param array<string,mixed> $proof
     */
    private static function assertServingProof(
        array $endpoint,
        array $status,
        array $proof,
    ): void {
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $digest = \strtolower(\trim((string)($proof['proof_digest'] ?? '')));
        $unsigned = $proof;
        unset($unsigned['proof_digest']);
        $routes = \is_array($proof['active_routes'] ?? null)
            ? $proof['active_routes']
            : [];
        $hostBootId = self::statusHostBootId($status);
        if (($proof['schema_version'] ?? null) !== 2
            || ($proof['public_probe_verified'] ?? false) !== true
            || !\array_is_list($routes)
            || \count($routes) > 256
            || !\hash_equals(
                (string)($gateway['project_uuid'] ?? ''),
                (string)($proof['project_uuid'] ?? ''),
            )
            || !\hash_equals(
                (string)($gateway['instance_id'] ?? ''),
                (string)($proof['instance_id'] ?? ''),
            )
            || !\hash_equals(
                (string)($status['project_uuid'] ?? ''),
                (string)($proof['project_uuid'] ?? ''),
            )
            || !\hash_equals(
                \strtolower((string)($status['epoch'] ?? '')),
                (string)($proof['gateway_epoch'] ?? ''),
            )
            || !\hash_equals($hostBootId, (string)($proof['host_boot_id'] ?? ''))
            || (int)($status['active_config_generation'] ?? 0)
                !== (int)($proof['active_config_generation'] ?? -1)
            || !\hash_equals(
                \strtolower(\trim((string)($status['active_config_digest'] ?? ''))),
                (string)($proof['active_config_digest'] ?? ''),
            )
            || (int)($proof['serving_manifest_generation'] ?? 0) < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $proof['serving_manifest_digest'] ?? ''
            )) !== 1
            || (int)($proof['project_generation'] ?? 0) < 1
            || (int)($status['project_generation'] ?? 0)
                !== (int)($proof['project_generation'] ?? -1)
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($proof['request_digest'] ?? ''),
            ) !== 1
            || !\hash_equals(
                \strtolower(\trim((string)($status['request_digest'] ?? ''))),
                (string)($proof['request_digest'] ?? ''),
            )
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $proof['non_certificate_desired_digest'] ?? ''
            )) !== 1
            || !\hash_equals(
                \strtolower(\trim((string)(
                    $status['non_certificate_desired_digest'] ?? ''
                ))),
                (string)($proof['non_certificate_desired_digest'] ?? ''),
            )
            || (int)($proof['instance_generation'] ?? 0)
                !== (int)($gateway['instance_generation'] ?? -1)
            || (int)($proof['master_pid'] ?? 0) !== (int)($endpoint['master_pid'] ?? -1)
            || (int)($proof['master_epoch'] ?? 0) !== (int)($endpoint['master_epoch'] ?? -1)
            || !\hash_equals(
                (string)($gateway['launch_id'] ?? ''),
                (string)($proof['launch_id'] ?? ''),
            )
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $proof['instance_digest'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\hash_equals(
                $digest,
                \hash('sha256', GatewayClient::canonicalJson($unsigned)),
            )
        ) {
            throw new \RuntimeException(
                'Gateway runtime project serving proof is invalid.'
            );
        }
        $seenRoutes = [];
        foreach ($routes as $route) {
            if (!\is_array($route)) {
                throw new \RuntimeException(
                    'Gateway runtime project serving proof route is invalid.'
                );
            }
            $routeId = (string)($route['route_id'] ?? '');
            $domain = (string)($route['domain'] ?? '');
            $rootTarget = (string)($route['root_to_www_target'] ?? '');
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || isset($seenRoutes[$routeId])
                || $domain === ''
                || !\is_bool($route['force_https'] ?? null)
                || !\is_bool($route['force_root_to_www'] ?? null)
                || ($route['root_to_www_target_ready'] ?? null) !== true
                || (($route['force_root_to_www'] ?? false) === true
                    && !\hash_equals('www.' . $domain, $rootTarget))
                || (($route['force_root_to_www'] ?? false) === false
                    && $rootTarget !== '')
            ) {
                throw new \RuntimeException(
                    'Gateway runtime project serving proof policy fence is invalid.'
                );
            }
            $seenRoutes[$routeId] = true;
        }
    }

    /**
     * @param array<string,mixed> $endpoint
     * @return array{master_pid:int,master_epoch:int,launch_id:string,instance_generation:int}|null
     */
    private static function endpointRuntimeFence(array $endpoint): ?array
    {
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $masterPid = (int)($endpoint['master_pid'] ?? 0);
        $masterEpoch = (int)($endpoint['master_epoch'] ?? 0);
        $launchId = \strtolower(\trim((string)($gateway['launch_id'] ?? '')));
        $instanceGeneration = (int)($gateway['instance_generation'] ?? 0);
        if ($masterPid < 1
            || $masterEpoch < 1
            || $instanceGeneration < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
        ) {
            return null;
        }
        return [
            'master_pid' => $masterPid,
            'master_epoch' => $masterEpoch,
            'launch_id' => $launchId,
            'instance_generation' => $instanceGeneration,
        ];
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array{master_pid:int,master_epoch:int,launch_id:string,instance_generation:int} $expected
     */
    private static function endpointRuntimeFenceMatches(
        array $endpoint,
        array $expected,
    ): bool {
        $actual = self::endpointRuntimeFence($endpoint);
        return $actual !== null
            && $actual['master_pid'] === $expected['master_pid']
            && $actual['master_epoch'] === $expected['master_epoch']
            && $actual['instance_generation'] === $expected['instance_generation']
            && \hash_equals($expected['launch_id'], $actual['launch_id']);
    }

    /** @return array<string,mixed> */
    private static function currentServingManifestForEndpoint(array $endpoint): array
    {
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $fence = self::endpointRuntimeFence($endpoint);
        if ($fence === null) {
            throw new \RuntimeException('WLS serving manifest has no current endpoint fence.');
        }
        $manifest = (new ProjectServingManifestStore())->currentForFence([
            ...$fence,
            'instance_id' => (string)($gateway['instance_id'] ?? ''),
        ]);
        $payload = \is_array($manifest['payload'] ?? null) ? $manifest['payload'] : [];
        if ((int)($payload['desired_route_count'] ?? 0) < 1
            || (int)($manifest['route_count'] ?? -1) < 0
            || !\hash_equals(
                (string)($gateway['project_uuid'] ?? ''),
                (string)($payload['project_uuid'] ?? ''),
            )
        ) {
            throw new \RuntimeException(
                'Current WLS serving manifest cannot serve the fallback endpoint.',
            );
        }
        return $manifest;
    }

    /** @param array<string,mixed> $proof */
    private static function servingManifestProofIsCurrent(
        array $endpoint,
        array $proof,
    ): bool {
        if (!self::servingManifestReferenceIsCurrent(
            $endpoint,
            (int)($proof['serving_manifest_generation'] ?? 0),
            (string)($proof['serving_manifest_digest'] ?? ''),
        )) {
            return false;
        }
        try {
            $manifest = self::currentServingManifestForEndpoint($endpoint);
        } catch (\Throwable) {
            return false;
        }
        $payload = (array)$manifest['payload'];
        return (int)($payload['project_generation'] ?? 0)
                === (int)($proof['project_generation'] ?? -1)
            && \hash_equals(
                (string)($payload['request_digest'] ?? ''),
                (string)($proof['request_digest'] ?? ''),
            )
            && \hash_equals(
                (string)($payload['non_certificate_desired_digest'] ?? ''),
                (string)($proof['non_certificate_desired_digest'] ?? ''),
            );
    }

    private static function servingManifestReferenceIsCurrent(
        array $endpoint,
        int $generation,
        string $digest,
    ): bool {
        $digest = \strtolower(\trim($digest));
        if ($generation < 1 || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
            return false;
        }
        try {
            $manifest = self::currentServingManifestForEndpoint($endpoint);
        } catch (\Throwable) {
            return false;
        }
        return (int)$manifest['generation'] === $generation
            && \hash_equals($digest, (string)$manifest['digest']);
    }

    private static function normalizeReason(string $reason): string
    {
        $reason = \trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Gateway runtime observation reason is empty.');
        }
        return GatewayBoundedText::singleLine(
            $reason,
            256,
            'Gateway runtime observation unavailable.',
        );
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $observation
     * @return array<string,mixed>
     */
    private static function buildFallbackLeaseProof(
        string $instanceName,
        array $endpoint,
        array $observation,
        array $servingManifest,
    ): array {
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $projectUuid = \strtolower(\trim((string)($gateway['project_uuid'] ?? '')));
        $leaseInstanceId = GatewayLeaseIdentity::forRole(
            $instanceName,
            GatewayLeaseIdentity::ROLE_FALLBACK,
        );
        $port = (int)($observation['port'] ?? 0);
        $bindHost = \strtolower(\trim((string)($observation['bind_host'] ?? ''), " \t\n\r\0\x0B[]"));
        $leaseId = \strtolower(\trim((string)($observation['lease_id'] ?? '')));
        $workerLaunchId = \strtolower(\trim((string)(
            $observation['worker_launch_id'] ?? ''
        )));
        $masterPid = (int)($observation['master_pid'] ?? 0);
        if (!\hash_equals($projectUuid, (string)($observation['project_uuid'] ?? ''))
            || !\hash_equals($leaseInstanceId, (string)(
                $observation['lease_instance_id'] ?? ''
            ))
            || $masterPid !== (int)($endpoint['master_pid'] ?? 0)
        ) {
            throw new \RuntimeException(
                'Fallback lease observation does not match the current project endpoint.'
            );
        }
        $live = (new GatewayPortLeaseAllocator())->liveServingLease(
            $leaseInstanceId,
            $bindHost,
            $port,
            $leaseId,
            $workerLaunchId,
            $masterPid,
        );
        if (!\is_array($live)) {
            throw new \RuntimeException('Fallback lease observation is no longer live.');
        }
        $authorityHost = self::fallbackAuthorityForManifest(
            $endpoint,
            $servingManifest,
        );
        $proof = [
            'schema_version' => 2,
            'project_uuid' => $projectUuid,
            'instance_id' => $instanceName,
            'lease_instance_id' => $leaseInstanceId,
            'lease_id' => $leaseId,
            'bind_host' => (string)$live['bind_host'],
            'authority_host' => $authorityHost,
            'port' => $port,
            'master_pid' => $masterPid,
            'worker_launch_id' => $workerLaunchId,
            'state' => (string)$live['state'],
            'confirmed_timestamp' => (int)($live['confirmed_timestamp'] ?? 0),
            'serving_manifest_generation' => (int)$servingManifest['generation'],
            'serving_manifest_digest' => (string)$servingManifest['digest'],
        ];
        $proof['proof_digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($proof),
        );
        self::assertFallbackLeaseProofShape($endpoint, $port, $proof);
        return $proof;
    }

    /** @param array<string,mixed> $endpoint @param array<string,mixed> $proof */
    private static function assertFallbackLeaseProofShape(
        array $endpoint,
        int $httpsPort,
        array $proof,
    ): void {
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $unsigned = $proof;
        $digest = \strtolower(\trim((string)($unsigned['proof_digest'] ?? '')));
        unset($unsigned['proof_digest']);
        try {
            $authorityHost = ProjectServingManifestStore::normalizeHost(
                (string)($proof['authority_host'] ?? ''),
                false,
            );
        } catch (\Throwable) {
            throw new \RuntimeException('Gateway fallback authority proof is invalid.');
        }
        if (($proof['schema_version'] ?? null) !== 2
            || !\hash_equals((string)($gateway['project_uuid'] ?? ''), (string)(
                $proof['project_uuid'] ?? ''
            ))
            || !\hash_equals((string)($gateway['instance_id'] ?? ''), (string)(
                $proof['instance_id'] ?? ''
            ))
            || !\hash_equals(
                GatewayLeaseIdentity::forRole(
                    (string)($gateway['instance_id'] ?? ''),
                    GatewayLeaseIdentity::ROLE_FALLBACK,
                ),
                (string)($proof['lease_instance_id'] ?? ''),
            )
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                $proof['lease_id'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                $proof['worker_launch_id'] ?? ''
            )) !== 1
            || !self::validLiteralBindHost((string)($proof['bind_host'] ?? ''))
            || \str_starts_with($authorityHost, '*.')
            || !\hash_equals($authorityHost, (string)($proof['authority_host'] ?? ''))
            || (int)($proof['port'] ?? 0) !== $httpsPort
            || (int)($proof['master_pid'] ?? 0) !== (int)($endpoint['master_pid'] ?? 0)
            || !\in_array((string)($proof['state'] ?? ''), ['ACTIVE', 'DRAINING'], true)
            || (int)($proof['confirmed_timestamp'] ?? 0) < 1
            || (int)($proof['serving_manifest_generation'] ?? 0) < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $proof['serving_manifest_digest'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\hash_equals(
                $digest,
                \hash('sha256', GatewayClient::canonicalJson($unsigned)),
            )
        ) {
            throw new \RuntimeException('Gateway fallback lease proof is invalid.');
        }
    }

    /** @param array<string,mixed> $gateway */
    private static function observationWasRecentlyPublished(array $gateway): bool
    {
        $observed = $gateway['runtime_observed_monotonic'] ?? null;
        $observedBootId = \strtolower(\trim((string)(
            $gateway['runtime_observed_host_boot_id'] ?? ''
        )));
        $launchId = \strtolower(\trim((string)(
            $gateway['runtime_observed_launch_id'] ?? ''
        )));
        $runtimeFence = \is_array($gateway['runtime_fence'] ?? null)
            ? $gateway['runtime_fence']
            : [];
        try {
            $currentBootId = GatewayHostBootIdentity::current();
        } catch (\Throwable) {
            return false;
        }
        if (!(\is_int($observed) || \is_float($observed))
            || !\is_finite((float)$observed)
            || (float)$observed < 0.0
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
            || !\hash_equals(
                $launchId,
                \strtolower(\trim((string)($runtimeFence['launch_id'] ?? ''))),
            )
            || !\hash_equals($currentBootId, $observedBootId)
        ) {
            return false;
        }
        $now = self::monotonicNow();
        return (float)$observed <= $now
            && (float)$observed >= $now - self::OBSERVATION_REFRESH_SECONDS;
    }

    private static function monotonicNow(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    /** @param array<string,mixed> $status */
    private static function statusHostBootId(array $status): string
    {
        $current = GatewayHostBootIdentity::current();
        $observed = \strtolower(\trim((string)($status['host_boot_id'] ?? '')));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $observed) !== 1
            || !\hash_equals($current, $observed)
        ) {
            throw new \RuntimeException(
                'Authenticated gateway observation belongs to another host boot.',
            );
        }
        return $current;
    }

    private static function assertInstanceName(string $instanceName): void
    {
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceName) !== 1) {
            throw new \InvalidArgumentException('Gateway runtime instance name is invalid.');
        }
    }

    private static function validLiteralBindHost(string $host): bool
    {
        $host = \strtolower(\trim($host, " \t\n\r\0\x0B[]"));
        if ($host === '' || \filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $packed = @\inet_pton($host);
        $canonical = \is_string($packed) ? @\inet_ntop($packed) : false;
        return \is_string($canonical) && \hash_equals($host, \strtolower($canonical));
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $servingManifest
     */
    private static function fallbackAuthorityForManifest(
        array $endpoint,
        array $servingManifest,
    ): string
    {
        $payload = \is_array($servingManifest['payload'] ?? null)
            ? $servingManifest['payload']
            : [];
        foreach (['public_host', 'ssl_domain'] as $field) {
            $candidate = \strtolower(\rtrim(\trim(
                (string)($endpoint[$field] ?? ''),
                " \t\n\r\0\x0B",
            ), '.'));
            if ($candidate === ''
                || \in_array($candidate, ['localhost', '0.0.0.0', '::', '*'], true)
                || \filter_var($candidate, FILTER_VALIDATE_IP) !== false
                || \preg_match('/\A[0-9.]+\z/D', $candidate) === 1
            ) {
                continue;
            }
            try {
                $candidate = ProjectServingManifestStore::normalizeHost($candidate, false);
            } catch (\Throwable) {
                continue;
            }
            if (!\str_starts_with($candidate, '*.')
                && self::manifestPayloadCoversHost($payload, $candidate)
            ) {
                return $candidate;
            }
        }
        foreach ((array)($payload['routes'] ?? []) as $route) {
            if (!\is_array($route)) {
                throw new \RuntimeException('Fallback serving manifest route is invalid.');
            }
            try {
                $domain = ProjectServingManifestStore::normalizeHost(
                    (string)($route['domain'] ?? ''),
                );
            } catch (\Throwable $throwable) {
                throw new \RuntimeException(
                    'Fallback serving manifest has no canonical authority.',
                    0,
                    $throwable,
                );
            }
            if (!\str_starts_with($domain, '*.')) {
                return $domain;
            }
        }
        throw new \RuntimeException(
            'Fallback serving manifest requires one concrete certificate authority host.',
        );
    }

    /** @param array<string,mixed> $payload */
    private static function manifestPayloadCoversHost(array $payload, string $host): bool
    {
        $routes = $payload['routes'] ?? null;
        if (!\is_array($routes)
            || !\array_is_list($routes)
            || $routes === []
            || \count($routes) > 256
        ) {
            return false;
        }
        foreach ($routes as $route) {
            if (!\is_array($route)) {
                return false;
            }
            try {
                $domain = ProjectServingManifestStore::normalizeHost(
                    (string)($route['domain'] ?? ''),
                );
            } catch (\Throwable) {
                return false;
            }
            if (\hash_equals($domain, $host)) {
                return true;
            }
            if (\str_starts_with($domain, '*.')
                && \str_ends_with($host, \substr($domain, 1))
                && \substr_count($host, '.') === \substr_count($domain, '.')
            ) {
                return true;
            }
        }

        return false;
    }

    private static function formatUrlHost(string $host): string
    {
        return \str_contains($host, ':') ? '[' . $host . ']' : $host;
    }
}
