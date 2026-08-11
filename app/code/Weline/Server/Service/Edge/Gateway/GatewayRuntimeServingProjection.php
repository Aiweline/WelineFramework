<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Reads the CAS-published runtime edge observation from an instance endpoint.
 *
 * gateway.mode and edge_adapter are immutable startup intent. They must never
 * be used to decide which public surface is serving after an auto fallback
 * joins (or leaves) the host gateway.
 */
final class GatewayRuntimeServingProjection
{
    public const SERVING_GATEWAY = 'gateway';
    public const SERVING_FALLBACK_WLS = 'fallback_wls';
    private const OBSERVATION_TTL_SECONDS = 75;
    private const READ_BUDGET_SECONDS = 1.0;

    /** @param array<string,mixed> $endpoint */
    public static function gatewayIsServing(
        array $endpoint,
        ?float $deadlineMonotonic = null,
    ): bool
    {
        try {
            $deadlineMonotonic = self::projectionDeadline($deadlineMonotonic);
        } catch (\Throwable) {
            return false;
        }
        $gateway = self::gateway($endpoint);
        $fallbackState = \strtoupper(\trim((string)(
            $gateway['fallback_state'] ?? ''
        )));
        return \hash_equals(
                self::SERVING_GATEWAY,
                \strtolower(\trim((string)($gateway['serving_mode'] ?? ''))),
            )
            && \hash_equals(GatewayPaths::PROTOCOL, (string)($gateway['protocol'] ?? ''))
            && self::observationFenceMatches($endpoint, $gateway)
            && \preg_match(
                '/\A[a-f0-9]{32}\z/D',
                \strtolower(\trim((string)($gateway['epoch'] ?? ''))),
            ) === 1
            && self::validPort($gateway['public_http'] ?? 0)
            && self::validPort($gateway['public_https'] ?? 0)
            && (int)$gateway['public_http'] !== (int)$gateway['public_https']
            && (int)($gateway['runtime_generation'] ?? 0) > 0
            && self::observationIsFresh($gateway)
            && self::runtimeProjectProofMatchesEndpoint(
                $endpoint,
                $gateway,
                $deadlineMonotonic,
            )
            && \in_array($fallbackState, [
                'NATIVE_EDGE_DRAINING',
                'GATEWAY_ACTIVE',
                'NATIVE_EDGE_STANDBY',
            ], true);
    }

    /** @param array<string,mixed> $endpoint */
    public static function fallbackWlsIsServing(
        array $endpoint,
        ?float $deadlineMonotonic = null,
    ): bool
    {
        try {
            $deadlineMonotonic = self::projectionDeadline($deadlineMonotonic);
        } catch (\Throwable) {
            return false;
        }
        $gateway = self::gateway($endpoint);
        $httpsPort = (int)($gateway['public_https'] ?? 0);
        return \hash_equals(
                self::SERVING_FALLBACK_WLS,
                \strtolower(\trim((string)($gateway['serving_mode'] ?? ''))),
            )
            && \hash_equals(GatewayPaths::PROTOCOL, (string)($gateway['protocol'] ?? ''))
            && self::observationFenceMatches($endpoint, $gateway)
            && \hash_equals(
                'DEGRADED_WLS',
                \strtoupper(\trim((string)($gateway['fallback_state'] ?? ''))),
            )
            && (int)($gateway['public_http'] ?? 0) === 0
            && $httpsPort >= 20000
            && $httpsPort <= 29999
            && \trim((string)($gateway['degraded_reason'] ?? '')) !== ''
            && self::observationIsFresh($gateway)
            && self::fallbackLeaseProofMatches(
                $endpoint,
                $gateway,
                $deadlineMonotonic,
            );
    }

    /**
     * Return the exact connectable endpoint only after the persisted proof has
     * been revalidated against the current host lease and process identities.
     *
     * @param array<string,mixed> $endpoint
     * @return array{origin:string,bind_host:string,connect_host:string,authority_host:string,port:int,https:bool}|null
     */
    public static function fallbackServingEndpoint(
        array $endpoint,
        ?float $deadlineMonotonic = null,
    ): ?array
    {
        try {
            $deadlineMonotonic = self::projectionDeadline($deadlineMonotonic);
        } catch (\Throwable) {
            return null;
        }
        $observation = self::fallbackServingObservation(
            $endpoint,
            $deadlineMonotonic,
        );
        if ($observation !== null && $observation['authority_host'] !== null) {
            $bindHost = $observation['bind_host'];
            $authorityHost = $observation['authority_host'];
            $port = $observation['port'];
            try {
                $origin = \Weline\Server\Service\Edge\PureWlsPublicOrigin::fromHostAndPort(
                    $authorityHost,
                    $port,
                    true,
                );
            } catch (\Throwable) {
                return null;
            }
            return [
                'origin' => $origin,
                'bind_host' => $bindHost,
                'connect_host' => match ($bindHost) {
                    '0.0.0.0' => '127.0.0.1',
                    '::' => '::1',
                    default => $bindHost,
                },
                'authority_host' => $authorityHost,
                'port' => $port,
                'https' => true,
            ];
        }
        $gateway = self::gateway($endpoint);
        if (!self::initialAutoFallbackUsesPrimaryLease($endpoint, $gateway)) {
            return null;
        }
        return self::projectOwnedWlsServingEndpoint(
            $endpoint,
            GatewayStartupDecision::MODE_AUTO,
            $deadlineMonotonic,
        );
    }

    /**
     * Return the authenticated listener projection even when wildcard-only TLS
     * cannot supply a concrete browser authority. `bind_endpoint` describes the
     * transport listener only; callers must not turn it into an HTTPS URL.
     *
     * @param array<string,mixed> $endpoint
     * @return array{
     *   bind_host:string,
     *   bind_endpoint:string,
     *   connect_host:string,
     *   authority_host:?string,
     *   route_domains:list<string>,
     *   limitations:list<string>,
     *   port:int,
     *   https:true
     * }|null
     */
    public static function fallbackServingObservation(
        array $endpoint,
        ?float $deadlineMonotonic = null,
    ): ?array
    {
        try {
            $deadlineMonotonic = self::projectionDeadline($deadlineMonotonic);
        } catch (\Throwable) {
            return null;
        }
        if (!self::fallbackWlsIsServing($endpoint, $deadlineMonotonic)) {
            return null;
        }
        $gateway = self::gateway($endpoint);
        $proof = (array)$gateway['fallback_lease_proof'];
        $bindHost = (string)$proof['bind_host'];
        $authorityHost = (string)$proof['authority_host'];
        $port = (int)$proof['port'];
        $bindAuthority = \str_contains($bindHost, ':')
            ? '[' . $bindHost . ']'
            : $bindHost;
        $limitations = [
            'not_public_80_443',
            'dns_mapping_required',
            'firewall_or_load_balancer_may_block',
        ];
        if ($authorityHost === '') {
            $limitations[] = 'hostname_and_sni_required';
            $limitations[] = 'wildcard_route_requires_concrete_hostname';
        }
        return [
            'bind_host' => $bindHost,
            'bind_endpoint' => $bindAuthority . ':' . $port,
            'connect_host' => match ($bindHost) {
                '0.0.0.0' => '127.0.0.1',
                '::' => '::1',
                default => $bindHost,
            },
            'authority_host' => $authorityHost !== '' ? $authorityHost : null,
            'route_domains' => (array)$proof['route_domains'],
            'limitations' => $limitations,
            'port' => $port,
            'https' => true,
        ];
    }

    /**
     * Resolve an explicitly requested project-owned WLS listener only while
     * its schema-6 host lease still belongs to the exact live process
     * generation. HTTPS additionally requires the current immutable serving
     * manifest to cover the persisted browser authority. A configured origin
     * by itself is never evidence that a public listener still exists.
     *
     * @param array<string,mixed> $endpoint
     * @return array{origin:string,bind_host:string,connect_host:string,authority_host:string,port:int,https:bool}|null
     */
    public static function explicitPureWlsServingEndpoint(
        array $endpoint,
        ?float $deadlineMonotonic = null,
    ): ?array
    {
        try {
            $deadlineMonotonic = self::projectionDeadline($deadlineMonotonic);
        } catch (\Throwable) {
            return null;
        }
        return self::projectOwnedWlsServingEndpoint(
            $endpoint,
            GatewayStartupDecision::MODE_WLS,
            $deadlineMonotonic,
        );
    }

    /**
     * @param array<string,mixed> $endpoint
     * @return array{origin:string,bind_host:string,connect_host:string,authority_host:string,port:int,https:bool}|null
     */
    private static function projectOwnedWlsServingEndpoint(
        array $endpoint,
        string $requestedMode,
        float $deadlineMonotonic,
    ): ?array
    {
        if (!\hash_equals(
            'wls',
            \strtolower(\trim((string)($endpoint['edge_adapter'] ?? ''))),
        )) {
            return null;
        }
        $gateway = self::gateway($endpoint);
        if (!\hash_equals(
            $requestedMode,
            \strtolower(\trim((string)(
                $gateway['requested_mode'] ?? $gateway['mode'] ?? ''
            ))),
        )) {
            return null;
        }
        $fence = self::endpointFence($endpoint);
        $leaseIntent = \is_array($gateway['public_lease'] ?? null)
            ? $gateway['public_lease']
            : [];
        $instanceId = (string)($gateway['instance_id'] ?? '');
        $projectUuid = (string)($gateway['project_uuid'] ?? '');
        $leaseId = \strtolower(\trim((string)($leaseIntent['lease_id'] ?? '')));
        $bindHost = \strtolower(\trim(
            (string)($leaseIntent['bind_host'] ?? ''),
            " \t\n\r\0\x0B[]",
        ));
        $port = (int)($leaseIntent['port'] ?? 0);
        $allocationScope = (string)($leaseIntent['allocation_scope'] ?? '');
        if ($fence === null
            || (int)($leaseIntent['schema_version'] ?? 0)
                !== GatewayPortLeaseAllocator::SCHEMA_VERSION
            || !\hash_equals($projectUuid, (string)($leaseIntent['project_uuid'] ?? ''))
            || !\hash_equals($instanceId, (string)($leaseIntent['instance'] ?? ''))
            || \preg_match('/\A[a-f0-9]{32}\z/D', $leaseId) !== 1
            || !self::validCanonicalBindHost($bindHost)
            || $port < 1
            || $port > 65535
            || (int)($endpoint['port'] ?? $endpoint['main_port'] ?? 0) !== $port
            || !\in_array($allocationScope, ['exact', 'stable_range'], true)
            || ($allocationScope === 'stable_range'
                && ($port < 20000 || $port > 29999))
            || !\is_bool($endpoint['ssl_enabled'] ?? null)
        ) {
            return null;
        }

        try {
            $origin = \Weline\Server\Service\Edge\PureWlsPublicOrigin::normalize(
                (string)($endpoint['public_origin'] ?? ''),
            );
            $originParts = \parse_url($origin);
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($originParts)) {
            return null;
        }
        $https = (bool)$endpoint['ssl_enabled'];
        $originPort = isset($originParts['port'])
            ? (int)$originParts['port']
            : ($https ? 443 : 80);
        $authorityHost = self::canonicalOriginAuthorityHost(
            (string)($originParts['host'] ?? ''),
        );
        if ($authorityHost === null) {
            return null;
        }
        if ($originPort !== $port
            || !\hash_equals($https ? 'https' : 'http', (string)($originParts['scheme'] ?? ''))
        ) {
            return null;
        }

        try {
            $leases = new GatewayPortLeaseAllocator(
                operationDeadlineMonotonic: $deadlineMonotonic,
            );
            $liveLease = $leases->liveServingLeaseForAnyOwner(
                $instanceId,
                $bindHost,
                $port,
                $leaseId,
                $fence['master_pid'],
            );
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($liveLease)) {
            return null;
        }
        $workerLaunchId = \strtolower(\trim((string)(
            $liveLease['launch_id'] ?? ''
        )));
        $confirmedTimestamp = (int)($liveLease['confirmed_timestamp'] ?? 0);
        if ((int)($liveLease['schema_version'] ?? 0)
                !== GatewayPortLeaseAllocator::SCHEMA_VERSION
            || !\in_array((string)($liveLease['state'] ?? ''), ['ACTIVE', 'DRAINING'], true)
            || !\hash_equals($projectUuid, (string)($liveLease['project_uuid'] ?? ''))
            || !\hash_equals($instanceId, (string)($liveLease['instance'] ?? ''))
            || !\hash_equals($leaseId, (string)($liveLease['lease_id'] ?? ''))
            || !\hash_equals($bindHost, (string)($liveLease['bind_host'] ?? ''))
            || !\hash_equals($allocationScope, (string)(
                $liveLease['allocation_scope'] ?? ''
            ))
            || (int)($liveLease['port'] ?? 0) !== $port
            || (int)($liveLease['master_pid'] ?? 0) !== $fence['master_pid']
            || \preg_match('/\A[a-f0-9]{32}\z/D', $workerLaunchId) !== 1
            || $confirmedTimestamp < 1
        ) {
            return null;
        }

        if ($https) {
            $generation = (int)($gateway['serving_manifest_generation'] ?? 0);
            $digest = \strtolower(\trim((string)(
                $gateway['serving_manifest_digest'] ?? ''
            )));
            $manifest = self::currentManifest(
                $endpoint,
                $gateway,
                $generation,
                $digest,
                $deadlineMonotonic,
            );
            if ($manifest === null
                || !self::manifestPayloadCoversHost(
                    (array)$manifest['payload'],
                    $authorityHost,
                )
            ) {
                return null;
            }
        }

        return [
            'origin' => $origin,
            'bind_host' => $bindHost,
            'connect_host' => match ($bindHost) {
                '0.0.0.0' => '127.0.0.1',
                '::' => '::1',
                default => $bindHost,
            },
            'authority_host' => $authorityHost,
            'port' => $port,
            'https' => $https,
        ];
    }

    /**
     * Initial auto fallback is the project Master listener itself and owns the
     * plain instance lease. Runtime outage fallback is a separate service with
     * a ROLE_FALLBACK lease and proof. Never let one identity substitute for
     * the other after the supplemental lifecycle has started.
     *
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $gateway
     */
    private static function initialAutoFallbackUsesPrimaryLease(
        array $endpoint,
        array $gateway,
    ): bool {
        $lease = \is_array($gateway['public_lease'] ?? null)
            ? $gateway['public_lease']
            : [];
        return \hash_equals(
                'wls',
                \strtolower(\trim((string)($endpoint['edge_adapter'] ?? ''))),
            )
            && \hash_equals(
                GatewayStartupDecision::MODE_AUTO,
                \strtolower(\trim((string)(
                    $gateway['requested_mode'] ?? ''
                ))),
            )
            && \hash_equals(
                GatewayStartupDecision::MODE_WLS,
                \strtolower(\trim((string)($gateway['mode'] ?? ''))),
            )
            && \hash_equals(
                self::SERVING_FALLBACK_WLS,
                \strtolower(\trim((string)($gateway['serving_mode'] ?? ''))),
            )
            && \hash_equals(
                GatewayPaths::PROTOCOL,
                (string)($gateway['protocol'] ?? ''),
            )
            && \hash_equals(
                'DEGRADED_WLS',
                \strtoupper(\trim((string)($gateway['fallback_state'] ?? ''))),
            )
            && \trim((string)($gateway['degraded_reason'] ?? '')) !== ''
            && (int)($gateway['public_http'] ?? 0) === 0
            && (int)($gateway['public_https'] ?? 0) === 0
            && !\array_key_exists('fallback_lease_proof', $gateway)
            && \hash_equals(
                'stable_range',
                (string)($lease['allocation_scope'] ?? ''),
            )
            && (int)($lease['port'] ?? 0) >= 20000
            && (int)($lease['port'] ?? 0) <= 29999;
    }

    /**
     * Certificate and ACME facts must keep targeting a gateway-capable auto
     * project even while its current serving surface is native/fallback WLS.
     *
     * @param array<string,mixed> $endpoint
     */
    public static function participatesInGateway(array $endpoint): bool
    {
        if (self::endpointFence($endpoint) === null) {
            return false;
        }
        $gateway = self::gateway($endpoint);
        $requested = \strtolower(\trim((string)(
            $gateway['requested_mode'] ?? $gateway['mode'] ?? ''
        )));
        return \in_array($requested, [
            GatewayStartupDecision::MODE_AUTO,
            GatewayStartupDecision::MODE_GATEWAY,
        ], true);
    }

    /** @param array<string,mixed> $endpoint */
    public static function isExplicitLegacyManagedNginx(array $endpoint): bool
    {
        if (\strtolower(\trim((string)($endpoint['edge_adapter'] ?? ''))) !== 'nginx') {
            return false;
        }
        $gateway = self::gateway($endpoint);
        $requested = \strtolower(\trim((string)(
            $gateway['requested_mode'] ?? $gateway['mode'] ?? ''
        )));
        return $requested === GatewayStartupDecision::MODE_LEGACY;
    }

    /**
     * @param array<string,mixed> $endpoint
     * @return array{master_pid:int,master_epoch:int,launch_id:string,instance_generation:int}|null
     */
    public static function endpointFence(array $endpoint): ?array
    {
        $gateway = self::gateway($endpoint);
        $masterPid = (int)($endpoint['master_pid'] ?? 0);
        $masterEpoch = (int)($endpoint['master_epoch'] ?? 0);
        $launchId = \strtolower(\trim((string)($gateway['launch_id'] ?? '')));
        $instanceGeneration = (int)($gateway['instance_generation'] ?? 0);
        $instanceId = \trim((string)($gateway['instance_id'] ?? ''));
        $projectUuid = \strtolower(\trim((string)($gateway['project_uuid'] ?? '')));
        if ($masterPid < 1
            || $masterEpoch < 1
            || $instanceGeneration < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
            || \preg_match(
                '/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D',
                $instanceId,
            ) !== 1
            || \preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $projectUuid,
            ) !== 1
            || !\hash_equals(
                GatewayRegistrationBuilder::BACKEND_IDENTITY_SCHEMA,
                (string)($gateway['backend_identity_schema'] ?? ''),
            )
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
     * @param array<string,mixed> $gateway
     */
    private static function observationFenceMatches(
        array $endpoint,
        array $gateway,
    ): bool {
        $expected = self::endpointFence($endpoint);
        $observed = \is_array($gateway['runtime_fence'] ?? null)
            ? $gateway['runtime_fence']
            : [];
        return $expected !== null
            && (int)($observed['master_pid'] ?? 0) === $expected['master_pid']
            && (int)($observed['master_epoch'] ?? 0) === $expected['master_epoch']
            && (int)($observed['instance_generation'] ?? 0)
                === $expected['instance_generation']
            && \hash_equals(
                $expected['launch_id'],
                \strtolower(\trim((string)($observed['launch_id'] ?? ''))),
            );
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $gateway
     */
    private static function runtimeProjectProofMatchesEndpoint(
        array $endpoint,
        array $gateway,
        float $deadlineMonotonic,
    ): bool {
        $proof = \is_array($gateway['runtime_project_proof'] ?? null)
            ? $gateway['runtime_project_proof']
            : [];
        $routes = \is_array($proof['active_routes'] ?? null)
            ? $proof['active_routes']
            : [];
        $digest = \strtolower(\trim((string)($proof['proof_digest'] ?? '')));
        $unsigned = $proof;
        unset($unsigned['proof_digest']);
        $proofTrustProfile = (string)($proof['certificate_trust_profile'] ?? '');
        try {
            $proofTrustProfile = ProjectCertificateGenerationStore::normalizeTrustProfile(
                $proofTrustProfile,
            );
            $runtimeTrustProfile = ProjectCertificateGenerationStore::normalizeTrustProfile(
                (string)($gateway['certificate_profile'] ?? ''),
            );
        } catch (\Throwable) {
            return false;
        }
        if (($proof['schema_version'] ?? null) !== 3
            || ($proof['public_probe_verified'] ?? false) !== true
            || !\hash_equals(
                (string)($gateway['project_uuid'] ?? ''),
                (string)($proof['project_uuid'] ?? ''),
            )
            || !\hash_equals(
                (string)($gateway['instance_id'] ?? ''),
                (string)($proof['instance_id'] ?? ''),
            )
            || !\hash_equals($runtimeTrustProfile, $proofTrustProfile)
            || !\hash_equals(
                \strtolower((string)($gateway['epoch'] ?? '')),
                (string)($proof['gateway_epoch'] ?? ''),
            )
            || !\hash_equals(
                (string)($gateway['runtime_observed_host_boot_id'] ?? ''),
                (string)($proof['host_boot_id'] ?? ''),
            )
            || (int)($gateway['runtime_generation'] ?? 0)
                !== (int)($proof['active_config_generation'] ?? -1)
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($proof['active_config_digest'] ?? ''),
            ) !== 1
            || (int)($proof['project_generation'] ?? 0) < 1
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($proof['request_digest'] ?? ''),
            ) !== 1
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($proof['non_certificate_desired_digest'] ?? ''),
            ) !== 1
            || (int)($proof['instance_generation'] ?? 0)
                !== (int)($gateway['instance_generation'] ?? -1)
            || (int)($proof['master_pid'] ?? 0) !== (int)($endpoint['master_pid'] ?? -1)
            || (int)($proof['master_epoch'] ?? 0) !== (int)($endpoint['master_epoch'] ?? -1)
            || !\hash_equals(
                (string)($gateway['launch_id'] ?? ''),
                (string)($proof['launch_id'] ?? ''),
            )
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($proof['instance_digest'] ?? ''),
            ) !== 1
            || (int)($proof['serving_manifest_generation'] ?? 0) < 1
            || (int)($gateway['serving_manifest_generation'] ?? 0)
                !== (int)($proof['serving_manifest_generation'] ?? -1)
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $proof['serving_manifest_digest'] ?? ''
            )) !== 1
            || !\hash_equals(
                (string)($gateway['serving_manifest_digest'] ?? ''),
                (string)($proof['serving_manifest_digest'] ?? ''),
            )
            || $routes === []
            || !\array_is_list($routes)
            || \count($routes) > 256
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\hash_equals(
                $digest,
                \hash('sha256', GatewayClient::canonicalJson($unsigned)),
            )
        ) {
            return false;
        }
        $seen = [];
        foreach ($routes as $route) {
            if (!\is_array($route)) {
                return false;
            }
            $routeId = (string)($route['route_id'] ?? '');
            $domain = (string)($route['domain'] ?? '');
            $sourceDigest = \strtolower(\trim((string)(
                $route['certificate_source_digest'] ?? ''
            )));
            $trustProfile = (string)($route['certificate_trust_profile'] ?? '');
            $provider = (string)($route['certificate_provider'] ?? '');
            $materialClass = \strtolower(\trim((string)(
                $route['certificate_material_class'] ?? ''
            )));
            $provenanceDigest = \strtolower(\trim((string)(
                $route['certificate_provenance_digest'] ?? ''
            )));
            try {
                $trustProfile = ProjectCertificateGenerationStore::normalizeTrustProfile(
                    $trustProfile,
                );
                $provider = ProjectCertificateGenerationStore::normalizeProvider($provider);
                $provenanceValid = \hash_equals(
                    ProjectCertificateGenerationStore::provenanceDigest(
                        $domain,
                        $sourceDigest,
                        $trustProfile,
                        $provider,
                        $materialClass,
                    ),
                    $provenanceDigest,
                );
            } catch (\Throwable) {
                $provenanceValid = false;
            }
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || isset($seen[$routeId])
                || \trim($domain) === ''
                || (int)($route['route_generation'] ?? 0) < 1
                || (int)($route['certificate_generation'] ?? 0) < 1
                || \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    (string)($route['certificate_source_digest'] ?? ''),
                ) !== 1
                || !\hash_equals($proofTrustProfile, $trustProfile)
                || \preg_match('/\A[a-f0-9]{64}\z/D', $provenanceDigest) !== 1
                || !$provenanceValid
                || \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    (string)($route['backend_public_digest'] ?? ''),
                ) !== 1
                || !\is_bool($route['force_https'] ?? null)
                || !\is_bool($route['force_root_to_www'] ?? null)
                || ($route['root_to_www_target_ready'] ?? null) !== true
                || (($route['force_root_to_www'] ?? false) === true
                    && \str_starts_with((string)$route['domain'], '*.'))
                || (($route['force_root_to_www'] ?? false) === true
                    && !\hash_equals(
                        'www.' . (string)$route['domain'],
                        (string)($route['root_to_www_target'] ?? ''),
                    ))
                || (($route['force_root_to_www'] ?? false) === false
                    && (string)($route['root_to_www_target'] ?? '') !== '')
            ) {
                return false;
            }
            $seen[$routeId] = true;
        }
        return self::currentManifestMatchesGatewayProof(
            $endpoint,
            $gateway,
            $proof,
            $deadlineMonotonic,
        );
    }

    /** @param array<string,mixed> $gateway */
    private static function observationIsFresh(array $gateway): bool
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
        $now = \hrtime(true) / 1_000_000_000;
        return (float)$observed <= $now
            && (float)$observed >= $now - self::OBSERVATION_TTL_SECONDS;
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $gateway
     */
    private static function fallbackLeaseProofMatches(
        array $endpoint,
        array $gateway,
        float $deadlineMonotonic,
    ): bool {
        $proof = \is_array($gateway['fallback_lease_proof'] ?? null)
            ? $gateway['fallback_lease_proof']
            : [];
        $unsigned = $proof;
        $digest = \strtolower(\trim((string)($unsigned['proof_digest'] ?? '')));
        unset($unsigned['proof_digest']);
        $instanceId = (string)($gateway['instance_id'] ?? '');
        $leaseInstanceId = GatewayLeaseIdentity::forRole(
            $instanceId,
            GatewayLeaseIdentity::ROLE_FALLBACK,
        );
        $port = (int)($gateway['public_https'] ?? 0);
        $masterPid = (int)($endpoint['master_pid'] ?? 0);
        $bindHost = \strtolower(\trim(
            (string)($proof['bind_host'] ?? ''),
            " \t\n\r\0\x0B[]",
        ));
        $rawAuthorityHost = (string)($proof['authority_host'] ?? '');
        try {
            $authorityHost = $rawAuthorityHost === ''
                ? ''
                : ProjectServingManifestStore::normalizeHost(
                    $rawAuthorityHost,
                    false,
                );
            $routeDomains = self::fallbackRouteDomains(
                $proof['route_domains'] ?? null,
            );
        } catch (\Throwable) {
            return false;
        }
        $projectedRouteDomains = $gateway['fallback_route_domains'] ?? null;
        if (($proof['schema_version'] ?? null) !== 2
            || !\hash_equals((string)($gateway['project_uuid'] ?? ''), (string)(
                $proof['project_uuid'] ?? ''
            ))
            || !\hash_equals($instanceId, (string)($proof['instance_id'] ?? ''))
            || !\hash_equals($leaseInstanceId, (string)(
                $proof['lease_instance_id'] ?? ''
            ))
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                $proof['lease_id'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                $proof['worker_launch_id'] ?? ''
            )) !== 1
            || !self::validCanonicalBindHost($bindHost)
            || \str_starts_with($authorityHost, '*.')
            || !\hash_equals($authorityHost, $rawAuthorityHost)
            || !\is_array($projectedRouteDomains)
            || $routeDomains !== $projectedRouteDomains
            || (int)($proof['port'] ?? 0) !== $port
            || (int)($proof['master_pid'] ?? 0) !== $masterPid
            || !\hash_equals('ACTIVE', (string)($proof['state'] ?? ''))
            || (int)($proof['confirmed_timestamp'] ?? 0) < 1
            || (int)($proof['serving_manifest_generation'] ?? 0) < 1
            || (int)($gateway['serving_manifest_generation'] ?? 0)
                !== (int)($proof['serving_manifest_generation'] ?? -1)
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $proof['serving_manifest_digest'] ?? ''
            )) !== 1
            || !\hash_equals(
                (string)($gateway['serving_manifest_digest'] ?? ''),
                (string)($proof['serving_manifest_digest'] ?? ''),
            )
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\hash_equals(
                $digest,
                \hash('sha256', GatewayClient::canonicalJson($unsigned)),
            )
        ) {
            return false;
        }
        try {
            $live = (new GatewayPortLeaseAllocator(
                operationDeadlineMonotonic: $deadlineMonotonic,
            ))->liveServingLease(
                $leaseInstanceId,
                $bindHost,
                $port,
                (string)$proof['lease_id'],
                (string)$proof['worker_launch_id'],
                $masterPid,
            );
        } catch (\Throwable) {
            return false;
        }
        if (!\is_array($live)
            || !\hash_equals((string)$proof['state'], (string)($live['state'] ?? ''))
            || !\hash_equals($bindHost, (string)($live['bind_host'] ?? ''))
            || (int)$proof['confirmed_timestamp']
                !== (int)($live['confirmed_timestamp'] ?? 0)
        ) {
            return false;
        }
        $manifest = self::currentManifest(
            $endpoint,
            $gateway,
            (int)$proof['serving_manifest_generation'],
            (string)$proof['serving_manifest_digest'],
            $deadlineMonotonic,
        );
        if ($manifest === null) {
            return false;
        }
        try {
            $manifestRouteDomains = self::fallbackRouteDomains(
                (array)($manifest['payload']['routes'] ?? []),
                true,
            );
        } catch (\Throwable) {
            return false;
        }
        if ($routeDomains !== $manifestRouteDomains) {
            return false;
        }
        if ($authorityHost === '') {
            foreach ($routeDomains as $domain) {
                if (!\str_starts_with($domain, '*.')) {
                    return false;
                }
            }
            return $routeDomains !== [];
        }
        return self::manifestPayloadCoversHost(
            (array)$manifest['payload'],
            $authorityHost,
        );
    }

    /** @return list<string> */
    private static function fallbackRouteDomains(
        mixed $value,
        bool $routes = false,
    ): array {
        if (!\is_array($value)
            || !\array_is_list($value)
            || $value === []
            || \count($value) > 256
        ) {
            throw new \RuntimeException('Fallback route-domain proof is invalid.');
        }
        $domains = [];
        foreach ($value as $entry) {
            $candidate = $routes && \is_array($entry)
                ? ($entry['domain'] ?? null)
                : $entry;
            if (!\is_string($candidate)) {
                throw new \RuntimeException('Fallback route-domain proof is invalid.');
            }
            $domain = ProjectServingManifestStore::normalizeHost($candidate);
            if (isset($domains[$domain])) {
                throw new \RuntimeException('Fallback route-domain proof is duplicated.');
            }
            $domains[$domain] = true;
        }
        \ksort($domains, SORT_STRING);
        $normalized = \array_keys($domains);
        if (!$routes && $normalized !== $value) {
            throw new \RuntimeException('Fallback route-domain proof is not canonical.');
        }
        return $normalized;
    }

    /** @param array<string,mixed> $proof */
    private static function currentManifestMatchesGatewayProof(
        array $endpoint,
        array $gateway,
        array $proof,
        float $deadlineMonotonic,
    ): bool {
        $manifest = self::currentManifest(
            $endpoint,
            $gateway,
            (int)($proof['serving_manifest_generation'] ?? 0),
            (string)($proof['serving_manifest_digest'] ?? ''),
            $deadlineMonotonic,
        );
        if ($manifest === null) {
            return false;
        }
        $payload = (array)$manifest['payload'];
        if ((int)($payload['project_generation'] ?? 0)
                !== (int)($proof['project_generation'] ?? -1)
            || !\hash_equals(
                (string)($payload['certificate_trust_profile'] ?? ''),
                (string)($proof['certificate_trust_profile'] ?? ''),
            )
            || !\hash_equals(
                (string)($payload['request_digest'] ?? ''),
                (string)($proof['request_digest'] ?? ''),
            )
            || !\hash_equals(
                (string)($payload['non_certificate_desired_digest'] ?? ''),
                (string)($proof['non_certificate_desired_digest'] ?? ''),
            )
        ) {
            return false;
        }
        $manifestRoutes = [];
        foreach ((array)($payload['routes'] ?? []) as $route) {
            if (\is_array($route)) {
                $manifestRoutes[(string)($route['route_id'] ?? '')] = $route;
            }
        }
        $proofRoutes = (array)($proof['active_routes'] ?? []);
        if (\count($manifestRoutes) !== \count($proofRoutes)) {
            return false;
        }
        foreach ($proofRoutes as $proofRoute) {
            if (!\is_array($proofRoute)) {
                return false;
            }
            $manifestRoute = $manifestRoutes[(string)($proofRoute['route_id'] ?? '')] ?? null;
            $policy = \is_array($manifestRoute['policy'] ?? null)
                ? $manifestRoute['policy']
                : [];
            if (!\is_array($manifestRoute)
                || !\hash_equals(
                    (string)($manifestRoute['domain'] ?? ''),
                    (string)($proofRoute['domain'] ?? ''),
                )
                || (int)($manifestRoute['route_generation'] ?? -1)
                    !== (int)($proofRoute['route_generation'] ?? -2)
                || (int)($manifestRoute['certificate_generation'] ?? 0)
                    !== (int)($proofRoute['certificate_generation'] ?? -1)
                || !\hash_equals(
                    (string)($manifestRoute['certificate_source_digest'] ?? ''),
                    (string)($proofRoute['certificate_source_digest'] ?? ''),
                )
                || !\hash_equals(
                    (string)($manifestRoute['certificate_trust_profile'] ?? ''),
                    (string)($proofRoute['certificate_trust_profile'] ?? ''),
                )
                || !\hash_equals(
                    (string)($manifestRoute['certificate_provider'] ?? ''),
                    (string)($proofRoute['certificate_provider'] ?? ''),
                )
                || !\hash_equals(
                    (string)($manifestRoute['certificate_material_class'] ?? ''),
                    (string)($proofRoute['certificate_material_class'] ?? ''),
                )
                || !\hash_equals(
                    (string)($manifestRoute['certificate_provenance_digest'] ?? ''),
                    (string)($proofRoute['certificate_provenance_digest'] ?? ''),
                )
                || ($policy['force_https'] ?? null)
                    !== ($proofRoute['force_https'] ?? null)
                || ($policy['force_root_to_www'] ?? null)
                    !== ($proofRoute['force_root_to_www'] ?? null)
                || !\hash_equals(
                    (string)($policy['root_to_www_target'] ?? ''),
                    (string)($proofRoute['root_to_www_target'] ?? ''),
                )
                || ($policy['root_to_www_target_ready'] ?? null)
                    !== ($proofRoute['root_to_www_target_ready'] ?? null)
            ) {
                return false;
            }
        }
        return true;
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

    /** @return array<string,mixed>|null */
    private static function currentManifest(
        array $endpoint,
        array $gateway,
        int $generation,
        string $digest,
        float $deadlineMonotonic,
    ): ?array {
        $digest = \strtolower(\trim($digest));
        if ($generation < 1 || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
            return null;
        }
        try {
            $manifest = (new ProjectServingManifestStore())->currentForFence(
                [
                    'instance_id' => (string)($gateway['instance_id'] ?? ''),
                    'instance_generation' => (int)($gateway['instance_generation'] ?? 0),
                    'master_pid' => (int)($endpoint['master_pid'] ?? 0),
                    'master_epoch' => (int)($endpoint['master_epoch'] ?? 0),
                    'launch_id' => (string)($gateway['launch_id'] ?? ''),
                ],
                $deadlineMonotonic,
            );
        } catch (\Throwable) {
            return null;
        }
        $payload = \is_array($manifest['payload'] ?? null) ? $manifest['payload'] : [];
        if ((int)$manifest['generation'] !== $generation
            || !\hash_equals($digest, (string)$manifest['digest'])
            || (int)($manifest['route_count'] ?? 0) < 1
            || !\hash_equals(
                (string)($gateway['project_uuid'] ?? ''),
                (string)($payload['project_uuid'] ?? ''),
            )
        ) {
            return null;
        }
        return $manifest;
    }

    private static function projectionDeadline(?float $deadlineMonotonic): float
    {
        if ($deadlineMonotonic === null) {
            return self::monotonicNow() + self::READ_BUDGET_SECONDS;
        }
        if (!\is_finite($deadlineMonotonic)
            || $deadlineMonotonic <= self::monotonicNow()
        ) {
            throw new \RuntimeException(
                'Gateway runtime serving projection deadline was exhausted.',
            );
        }
        return $deadlineMonotonic;
    }

    private static function monotonicNow(): float
    {
        $now = \hrtime(true) / 1_000_000_000;
        if (!\is_finite($now) || $now <= 0.0) {
            throw new \RuntimeException(
                'Gateway runtime serving projection monotonic clock is invalid.',
            );
        }
        return $now;
    }

    /** @param array<string,mixed> $endpoint @return array<string,mixed> */
    private static function gateway(array $endpoint): array
    {
        return \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
    }

    private static function validPort(mixed $value): bool
    {
        return \is_int($value) && $value >= 1 && $value <= 65535;
    }

    private static function validCanonicalBindHost(string $host): bool
    {
        if ($host === '' || \filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $packed = @\inet_pton($host);
        $canonical = \is_string($packed) ? @\inet_ntop($packed) : false;
        return \is_string($canonical) && \hash_equals($host, \strtolower($canonical));
    }

    /**
     * HTTP diagnostic listeners may legitimately advertise a concrete IP or
     * localhost and do not have a TLS route manifest. HTTPS still passes this
     * value through manifestPayloadCoversHost(), so a non-domain authority can
     * never bypass the exact certificate-route fence.
     */
    private static function canonicalOriginAuthorityHost(string $host): ?string
    {
        $host = \strtolower(\trim($host, " \t\n\r\0\x0B[]"));
        if ($host === '' || \strlen($host) > 253) {
            return null;
        }
        if (\filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $packed = @\inet_pton($host);
            $canonical = \is_string($packed) ? @\inet_ntop($packed) : false;
            return \is_string($canonical) ? \strtolower($canonical) : null;
        }
        if (\hash_equals('localhost', $host)) {
            return $host;
        }
        try {
            return ProjectServingManifestStore::normalizeHost($host, false);
        } catch (\Throwable) {
            return null;
        }
    }
}
