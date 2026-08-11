<?php
declare(strict_types=1);

namespace Weline\Server\Service\Edge;

use Weline\Server\Service\Edge\Gateway\GatewayRuntimeServingProjection;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Gateway\ProjectServingManifestStore;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Gateway\ServingManifestAuthorityTransitionException;

/**
 * Cold-start authority for a previous exact native-WLS serving manifest.
 *
 * Raw PEM files are source material, not desired-state authority. Once a
 * schema-3 manifest exists, startup must either bootstrap from one of its
 * active routes or fail closed when the desired set has no active certificate.
 */
final class NativeServingManifestStartupRecovery
{
    public const SCHEMA = 'wls-native-serving-manifest-startup-recovery/2';
    public const CONFIG_KEY = 'native_serving_manifest_startup_recovery';

    /** @return array<string,mixed>|null */
    public static function fromEndpoint(
        array $endpoint,
        string $instanceId,
        ?string $requiredTrustProfile = null,
        ?float $deadlineMonotonic = null,
        ?string $requestedHost = null,
    ): ?array
    {
        $instanceId = \trim($instanceId);
        $endpointName = \trim((string)(
            $endpoint['instance_name'] ?? $endpoint['name'] ?? ''
        ));
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $manifestPath = \trim((string)($gateway['serving_manifest_path'] ?? ''));
        $manifestGeneration = (int)($gateway['serving_manifest_generation'] ?? 0);
        $manifestDigest = \strtolower(\trim((string)(
            $gateway['serving_manifest_digest'] ?? ''
        )));
        $hasManifestBinding = $manifestPath !== ''
            || $manifestGeneration !== 0
            || $manifestDigest !== '';
        if (!$hasManifestBinding) {
            return null;
        }
        $requiredTrustProfile = ProjectCertificateGenerationStore::normalizeTrustProfile(
            $requiredTrustProfile
                ?? ProjectCertificateGenerationStore::TRUST_PROFILE_PRODUCTION,
        );
        $requestedHost = ProjectServingManifestStore::normalizeHost(
            $requestedHost
                ?? (string)($endpoint['public_host'] ?? $endpoint['host'] ?? ''),
            false,
        );
        if ($instanceId === ''
            || !\hash_equals($instanceId, $endpointName)
            || !\hash_equals($instanceId, (string)($gateway['instance_id'] ?? ''))
            || !\hash_equals(
                EdgeAdapterInterface::NAME_WLS,
                \strtolower(\trim((string)($endpoint['edge_adapter'] ?? ''))),
            )
            || ($endpoint['ssl_enabled'] ?? null) !== true
        ) {
            throw new \RuntimeException(
                'TLS_SERVING_MANIFEST_RECOVERY_INVALID: endpoint identity does not bind its manifest.',
            );
        }
        $fence = GatewayRuntimeServingProjection::endpointFence($endpoint);
        if (!\is_array($fence)) {
            $manifest = (new ProjectServingManifestStore((string)BP))->readBound(
                $manifestPath,
                $manifestGeneration,
                $manifestDigest,
            );
            $fence = self::terminalRecoveryFence(
                $endpoint,
                $gateway,
                \is_array($manifest['payload'] ?? null)
                    ? $manifest['payload']
                    : [],
            );
        }
        if (!\is_array($fence)) {
            throw new \RuntimeException(
                'TLS_SERVING_MANIFEST_RECOVERY_INVALID: endpoint runtime fence is unavailable.',
            );
        }
        $proof = [
            'schema' => self::SCHEMA,
            'instance_id' => $instanceId,
            'project_uuid' => \strtolower(\trim((string)(
                $gateway['project_uuid'] ?? ''
            ))),
            'path' => $manifestPath,
            'generation' => $manifestGeneration,
            'digest' => $manifestDigest,
            'instance_generation' => (int)$fence['instance_generation'],
            'master_pid' => (int)$fence['master_pid'],
            'master_epoch' => (int)$fence['master_epoch'],
            'launch_id' => (string)$fence['launch_id'],
            'required_trust_profile' => $requiredTrustProfile,
            'requested_host' => $requestedHost,
        ];
        $decision = self::validate(
            $proof,
            $requiredTrustProfile,
            $deadlineMonotonic,
            $requestedHost,
        );
        $proof['request_digest'] = (string)$decision['request_digest'];
        return $proof;
    }

    /**
     * A terminal endpoint deliberately clears its live Master PID. Its exact
     * content-addressed current manifest still carries the retired launch
     * fence needed for cold recovery, but only when every surviving endpoint
     * identity agrees and no live/starting state is being reinterpreted.
     *
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $gateway
     * @param array<string,mixed> $payload
     * @return array{master_pid:int,master_epoch:int,launch_id:string,instance_generation:int}|null
     */
    private static function terminalRecoveryFence(
        array $endpoint,
        array $gateway,
        array $payload,
    ): ?array {
        $state = \strtolower(\trim((string)(
            $endpoint['lifecycle_state'] ?? $endpoint['startup_phase'] ?? ''
        )));
        $instanceId = \trim((string)($gateway['instance_id'] ?? ''));
        $projectUuid = \strtolower(\trim((string)(
            $gateway['project_uuid'] ?? ''
        )));
        $launchId = \strtolower(\trim((string)(
            $gateway['launch_id'] ?? ''
        )));
        $instanceGeneration = (int)($gateway['instance_generation'] ?? 0);
        $masterEpoch = (int)($endpoint['master_epoch'] ?? 0);
        $payloadMasterPid = (int)($payload['master_pid'] ?? 0);
        if (!\in_array($state, [
            'failed',
            'stopped',
            'stale_cleanup',
            'master_exited',
        ], true)
            || (int)($endpoint['pid'] ?? 0) !== 0
            || (int)($endpoint['master_pid'] ?? 0) !== 0
            || $masterEpoch < 1
            || $payloadMasterPid < 1
            || $instanceGeneration < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
            || !\hash_equals(
                GatewayRegistrationBuilder::BACKEND_IDENTITY_SCHEMA,
                (string)($gateway['backend_identity_schema'] ?? ''),
            )
            || !\hash_equals($instanceId, (string)($payload['instance_id'] ?? ''))
            || !\hash_equals($projectUuid, (string)($payload['project_uuid'] ?? ''))
            || $instanceGeneration !== (int)($payload['instance_generation'] ?? 0)
            || $masterEpoch !== (int)($payload['master_epoch'] ?? 0)
            || !\hash_equals($launchId, (string)($payload['launch_id'] ?? ''))
        ) {
            return null;
        }
        return [
            'master_pid' => $payloadMasterPid,
            'master_epoch' => $masterEpoch,
            'launch_id' => $launchId,
            'instance_generation' => $instanceGeneration,
        ];
    }

    /**
     * @return array{
     *   state:string,
     *   reason:string,
     *   request_digest:string,
     *   desired_route_count:int,
     *   active_route_count:int,
     *   domain:string,
     *   cert_path:string,
     *   key_path:string,
     *   certificate_generation:int,
     *   certificate_source_digest:string
     * }
     */
    public static function validate(
        array $proof,
        ?string $requiredTrustProfile = null,
        ?float $deadlineMonotonic = null,
        ?string $requestedHost = null,
    ): array
    {
        $requiredTrustProfile = ProjectCertificateGenerationStore::normalizeTrustProfile(
            $requiredTrustProfile
                ?? ProjectCertificateGenerationStore::TRUST_PROFILE_PRODUCTION,
        );
        if (!\hash_equals(
            $requiredTrustProfile,
            (string)($proof['required_trust_profile'] ?? ''),
        )) {
            throw new \RuntimeException(
                'Native WLS startup proof trust profile differs from runtime policy.',
            );
        }
        $instanceId = \trim((string)($proof['instance_id'] ?? ''));
        $projectUuid = \strtolower(\trim((string)(
            $proof['project_uuid'] ?? ''
        )));
        $path = \trim((string)($proof['path'] ?? ''));
        $generation = (int)($proof['generation'] ?? 0);
        $digest = \strtolower(\trim((string)($proof['digest'] ?? '')));
        $instanceGeneration = (int)($proof['instance_generation'] ?? 0);
        $masterPid = (int)($proof['master_pid'] ?? 0);
        $masterEpoch = (int)($proof['master_epoch'] ?? 0);
        $launchId = \strtolower(\trim((string)($proof['launch_id'] ?? '')));
        $proofRequestedHost = ProjectServingManifestStore::normalizeHost(
            (string)($proof['requested_host'] ?? ''),
            false,
        );
        $requestedHost = ProjectServingManifestStore::normalizeHost(
            $requestedHost ?? $proofRequestedHost,
            false,
        );
        if (!\hash_equals(self::SCHEMA, (string)($proof['schema'] ?? ''))
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceId) !== 1
            || \preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $projectUuid,
            ) !== 1
            || $path === ''
            || $generation < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || $instanceGeneration < 1
            || $masterPid < 1
            || $masterEpoch < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
            || !\hash_equals($requestedHost, $proofRequestedHost)
        ) {
            throw new \RuntimeException('Native WLS startup manifest proof is invalid.');
        }
        $expected = [
            'path' => $path,
            'generation' => $generation,
            'digest' => $digest,
            'project_uuid' => $projectUuid,
            'certificate_trust_profile' => $requiredTrustProfile,
        ];
        if (\array_key_exists('request_digest', $proof)) {
            $expected['request_digest'] = (string)$proof['request_digest'];
        }
        try {
            $manifest = (new ProjectServingManifestStore((string)BP))
                ->currentForStartupRecoveryFence(
                    [
                        'instance_id' => $instanceId,
                        'instance_generation' => $instanceGeneration,
                        'master_pid' => $masterPid,
                        'master_epoch' => $masterEpoch,
                        'launch_id' => $launchId,
                    ],
                    $expected,
                    $deadlineMonotonic,
                );
        } catch (ServingManifestAuthorityTransitionException $exception) {
            throw new NativeServingManifestRebuildRequiredException(
                $exception->transitions,
                $exception->activeDomains,
                $exception,
            );
        }
        $payload = \is_array($manifest['payload'] ?? null)
            ? $manifest['payload']
            : [];
        $desiredRoutes = $payload['desired_routes'] ?? null;
        $desiredRouteCount = (int)($payload['desired_route_count'] ?? 0);
        $requestDigest = \strtolower(\trim((string)(
            $payload['request_digest'] ?? ''
        )));
        if (!\hash_equals($path, (string)($manifest['path'] ?? ''))
            || (int)($manifest['generation'] ?? 0) !== $generation
            || !\hash_equals($digest, (string)($manifest['digest'] ?? ''))
            || !\hash_equals($instanceId, (string)($payload['instance_id'] ?? ''))
            || !\hash_equals($projectUuid, (string)($payload['project_uuid'] ?? ''))
            || $desiredRouteCount < 1
            || $desiredRouteCount > ProjectServingManifestStore::MAX_ROUTES
            || !\is_array($desiredRoutes)
            || !\array_is_list($desiredRoutes)
            || \count($desiredRoutes) !== $desiredRouteCount
            || \preg_match('/\A[a-f0-9]{64}\z/D', $requestDigest) !== 1
        ) {
            throw new \RuntimeException(
                'Current native WLS manifest lacks complete schema-3 desired-state facts.',
            );
        }
        foreach ($desiredRoutes as $desiredRoute) {
            if (!\is_array($desiredRoute)
                || !\hash_equals(
                    $requiredTrustProfile,
                    (string)($desiredRoute['certificate_trust_profile'] ?? ''),
                )
            ) {
                throw new \RuntimeException(
                    'Native WLS desired routes do not share the required certificate trust profile.',
                );
            }
        }
        if (isset($proof['request_digest'])
            && !\hash_equals((string)$proof['request_digest'], $requestDigest)
        ) {
            throw new \RuntimeException('Native WLS startup desired state changed.');
        }
        $routes = \is_array($payload['routes'] ?? null) ? $payload['routes'] : [];
        $activeRouteCount = (int)($manifest['route_count'] ?? -1);
        if (!\array_is_list($routes) || \count($routes) !== $activeRouteCount) {
            throw new \RuntimeException('Native WLS active route facts are inconsistent.');
        }
        foreach ($routes as $activeRoute) {
            $activeDomain = \is_array($activeRoute)
                ? (string)($activeRoute['domain'] ?? '')
                : '';
            $activeSourceDigest = \is_array($activeRoute)
                ? \strtolower(\trim((string)(
                    $activeRoute['certificate_source_digest'] ?? ''
                )))
                : '';
            $activeTrustProfile = \is_array($activeRoute)
                ? (string)($activeRoute['certificate_trust_profile'] ?? '')
                : '';
            $activeProvider = \is_array($activeRoute)
                ? (string)($activeRoute['certificate_provider'] ?? '')
                : '';
            $activeMaterialClass = \is_array($activeRoute)
                ? (string)($activeRoute['certificate_material_class'] ?? '')
                : '';
            $activeProvenanceDigest = \is_array($activeRoute)
                ? \strtolower(\trim((string)(
                    $activeRoute['certificate_provenance_digest'] ?? ''
                )))
                : '';
            if ($activeDomain === ''
                || \preg_match('/\A[a-f0-9]{64}\z/D', $activeSourceDigest) !== 1
                || !\hash_equals($requiredTrustProfile, $activeTrustProfile)
                || \preg_match('/\A[a-f0-9]{64}\z/D', $activeProvenanceDigest) !== 1
                || !\hash_equals(
                    $activeProvenanceDigest,
                    ProjectCertificateGenerationStore::provenanceDigest(
                        $activeDomain,
                        $activeSourceDigest,
                        $activeTrustProfile,
                        $activeProvider,
                        $activeMaterialClass,
                    ),
                )
                || ($activeTrustProfile
                    === ProjectCertificateGenerationStore::TRUST_PROFILE_PRODUCTION
                    && $activeMaterialClass
                        !== ProjectCertificateGenerationStore::MATERIAL_CLASS_PUBLIC_TRUST)
            ) {
                throw new \RuntimeException(
                    'Native WLS active routes do not share valid certificate provenance.',
                );
            }
        }
        if ($activeRouteCount === 0) {
            $hasPending = false;
            foreach ($desiredRoutes as $desiredRoute) {
                $state = \is_array($desiredRoute)
                    ? \strtolower(\trim((string)(
                        $desiredRoute['certificate_state'] ?? ''
                    )))
                    : '';
                $desiredTrustProfile = \is_array($desiredRoute)
                    ? (string)($desiredRoute['certificate_trust_profile'] ?? '')
                    : '';
                if (!\in_array($state, ['disabled', 'pending'], true)) {
                    throw new \RuntimeException(
                        'Native WLS inactive desired route state is invalid.',
                    );
                }
                if (!\hash_equals($requiredTrustProfile, $desiredTrustProfile)) {
                    throw new \RuntimeException(
                        'Native WLS inactive desired route trust profile is inconsistent.',
                    );
                }
                $hasPending = $hasPending || $state === 'pending';
            }
            return [
                'state' => 'unavailable',
                'reason' => $hasPending ? 'certificate_pending' : 'all_certificates_disabled',
                'request_digest' => $requestDigest,
                'desired_route_count' => $desiredRouteCount,
                'active_route_count' => 0,
                'domain' => '',
                'cert_path' => '',
                'key_path' => '',
                'certificate_generation' => 0,
                'certificate_source_digest' => '',
            ];
        }
        $route = self::selectActiveRouteForRequestedHost($routes, $requestedHost);
        if ($route === null) {
            throw new \RuntimeException(
                'Current native WLS manifest has no active route for the requested Host.',
            );
        }
        $certificate = \is_array($route['certificate'] ?? null)
            ? $route['certificate']
            : [];
        $privateKey = \is_array($route['private_key'] ?? null)
            ? $route['private_key']
            : [];
        $domain = \is_array($route) ? (string)($route['domain'] ?? '') : '';
        $certPath = (string)($certificate['path'] ?? '');
        $keyPath = (string)($privateKey['path'] ?? '');
        $certificateGeneration = \is_array($route)
            ? (int)($route['certificate_generation'] ?? 0)
            : 0;
        $sourceDigest = \is_array($route)
            ? \strtolower(\trim((string)(
                $route['certificate_source_digest'] ?? ''
            )))
            : '';
        $trustProfile = \is_array($route)
            ? (string)($route['certificate_trust_profile'] ?? '')
            : '';
        $provider = \is_array($route)
            ? (string)($route['certificate_provider'] ?? '')
            : '';
        $materialClass = \is_array($route)
            ? (string)($route['certificate_material_class'] ?? '')
            : '';
        $provenanceDigest = \is_array($route)
            ? \strtolower(\trim((string)(
                $route['certificate_provenance_digest'] ?? ''
            )))
            : '';
        $snapshot = \is_array($route['certificate_snapshot'] ?? null)
            ? $route['certificate_snapshot']
            : [];
        $leafFingerprint = \strtolower(\trim((string)(
            $snapshot['leaf_fingerprint_sha256'] ?? ''
        )));
        if ($domain === ''
            || $certPath === ''
            || $keyPath === ''
            || $certificateGeneration < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1
            || !\hash_equals($requiredTrustProfile, $trustProfile)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $provenanceDigest) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $leafFingerprint) !== 1
            || !\hash_equals(
                $provenanceDigest,
                ProjectCertificateGenerationStore::provenanceDigest(
                    $domain,
                    $sourceDigest,
                    $trustProfile,
                    $provider,
                    $materialClass,
                ),
            )
        ) {
            throw new \RuntimeException('Native WLS bootstrap certificate route is incomplete.');
        }
        return [
            'state' => 'active',
            'reason' => 'active_manifest_route',
            'request_digest' => $requestDigest,
            'desired_route_count' => $desiredRouteCount,
            'active_route_count' => $activeRouteCount,
            'domain' => $domain,
            'cert_path' => $certPath,
            'key_path' => $keyPath,
            'certificate_generation' => $certificateGeneration,
            'certificate_source_digest' => $sourceDigest,
            'trust_profile' => $trustProfile,
            'provider' => $provider,
            'material_class' => $materialClass,
            'certificate_provenance_digest' => $provenanceDigest,
            'leaf_fingerprint_sha256' => $leafFingerprint,
        ];
    }

    /**
     * @param list<array<string,mixed>> $routes
     * @return array<string,mixed>|null
     */
    private static function selectActiveRouteForRequestedHost(
        array $routes,
        string $requestedHost,
    ): ?array {
        $routesByDomain = [];
        foreach ($routes as $route) {
            if (!\is_array($route)) {
                return null;
            }
            try {
                $domain = ProjectServingManifestStore::normalizeHost(
                    (string)($route['domain'] ?? ''),
                );
            } catch (\Throwable) {
                return null;
            }
            if (isset($routesByDomain[$domain])) {
                throw new \RuntimeException(
                    'Native WLS active routes contain a duplicate normalized Host.',
                );
            }
            $routesByDomain[$domain] = $route;
        }

        return ProjectServingManifestStore::routeForHost(
            $requestedHost,
            $routesByDomain,
        );
    }
}
