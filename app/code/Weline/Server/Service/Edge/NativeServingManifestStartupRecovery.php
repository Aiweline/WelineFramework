<?php
declare(strict_types=1);

namespace Weline\Server\Service\Edge;

use Weline\Server\Service\Edge\Gateway\GatewayRuntimeServingProjection;
use Weline\Server\Service\Edge\Gateway\ProjectServingManifestStore;

/**
 * Cold-start authority for a previous exact native-WLS serving manifest.
 *
 * Raw PEM files are source material, not desired-state authority. Once a
 * schema-2 manifest exists, startup must either bootstrap from one of its
 * active routes or fail closed when the desired set has no active certificate.
 */
final class NativeServingManifestStartupRecovery
{
    public const SCHEMA = 'wls-native-serving-manifest-startup-recovery/1';
    public const CONFIG_KEY = 'native_serving_manifest_startup_recovery';

    /** @return array<string,mixed>|null */
    public static function fromEndpoint(array $endpoint, string $instanceId): ?array
    {
        $instanceId = \trim($instanceId);
        $endpointName = \trim((string)(
            $endpoint['instance_name'] ?? $endpoint['name'] ?? ''
        ));
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        if ($instanceId === ''
            || !\hash_equals($instanceId, $endpointName)
            || !\hash_equals($instanceId, (string)($gateway['instance_id'] ?? ''))
            || !\hash_equals(
                EdgeAdapterInterface::NAME_WLS,
                \strtolower(\trim((string)($endpoint['edge_adapter'] ?? ''))),
            )
            || ($endpoint['ssl_enabled'] ?? null) !== true
        ) {
            return null;
        }
        $fence = GatewayRuntimeServingProjection::endpointFence($endpoint);
        if (!\is_array($fence)) {
            return null;
        }
        $proof = [
            'schema' => self::SCHEMA,
            'instance_id' => $instanceId,
            'project_uuid' => \strtolower(\trim((string)(
                $gateway['project_uuid'] ?? ''
            ))),
            'path' => \trim((string)($gateway['serving_manifest_path'] ?? '')),
            'generation' => (int)($gateway['serving_manifest_generation'] ?? 0),
            'digest' => \strtolower(\trim((string)(
                $gateway['serving_manifest_digest'] ?? ''
            ))),
            'instance_generation' => (int)$fence['instance_generation'],
            'master_pid' => (int)$fence['master_pid'],
            'master_epoch' => (int)$fence['master_epoch'],
            'launch_id' => (string)$fence['launch_id'],
        ];
        try {
            $decision = self::validate($proof);
        } catch (\Throwable) {
            return null;
        }
        $proof['request_digest'] = (string)$decision['request_digest'];
        return $proof;
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
    public static function validate(array $proof): array
    {
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
        ) {
            throw new \RuntimeException('Native WLS startup manifest proof is invalid.');
        }
        $manifest = (new ProjectServingManifestStore((string)BP))->currentForFence([
            'instance_id' => $instanceId,
            'instance_generation' => $instanceGeneration,
            'master_pid' => $masterPid,
            'master_epoch' => $masterEpoch,
            'launch_id' => $launchId,
        ]);
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
                'Current native WLS manifest lacks complete schema-2 desired-state facts.',
            );
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
        if ($activeRouteCount === 0) {
            $hasPending = false;
            foreach ($desiredRoutes as $desiredRoute) {
                $state = \is_array($desiredRoute)
                    ? \strtolower(\trim((string)(
                        $desiredRoute['certificate_state'] ?? ''
                    )))
                    : '';
                if (!\in_array($state, ['disabled', 'pending'], true)) {
                    throw new \RuntimeException(
                        'Native WLS inactive desired route state is invalid.',
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
        $route = $routes[0] ?? null;
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
        if ($domain === ''
            || $certPath === ''
            || $keyPath === ''
            || $certificateGeneration < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1
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
        ];
    }
}
