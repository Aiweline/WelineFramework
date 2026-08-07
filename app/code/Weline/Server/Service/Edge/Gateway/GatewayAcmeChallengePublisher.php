<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Framework\System\Process\Processer;

/**
 * Publishes project-owned ACME desired state only when the target domain is
 * currently served by the trusted host gateway.
 */
final class GatewayAcmeChallengePublisher
{
    /**
     * @param (\Closure(): array<string,array<string,mixed>>)|null $endpointProvider
     * @param (\Closure(string): array<string,mixed>)|null $registrationProvider
     * @param (\Closure(string,int,array,string): bool)|null $sync
     * @param (\Closure(): array<string,mixed>)|null $statusProvider
     * @param (\Closure(string): array<string,mixed>)|null $leaseReceiptProvider
     */
    public function __construct(
        private readonly ?\Closure $endpointProvider = null,
        private readonly ?\Closure $registrationProvider = null,
        private readonly ?\Closure $sync = null,
        private readonly ?\Closure $statusProvider = null,
        private readonly ?\Closure $leaseReceiptProvider = null,
    ) {
    }

    /**
     * @param array{generation:int,digest:string,challenges:list<array<string,mixed>>} $desired
     */
    public function publish(
        array $desired,
        ?string $requiredDomain = null,
        ?float $deadlineMonotonic = null,
    ): bool {
        if (!$this->deadlineAvailable($deadlineMonotonic)) {
            return false;
        }
        $generation = (int)($desired['generation'] ?? 0);
        $challenges = \is_array($desired['challenges'] ?? null)
            ? \array_values($desired['challenges'])
            : [];
        $claimedDigest = \strtolower(\trim((string)($desired['digest'] ?? '')));
        $computedDigest = \hash('sha256', GatewayClient::canonicalJson($challenges));
        if ($generation < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $claimedDigest) !== 1
            || !\hash_equals($computedDigest, $claimedDigest)
        ) {
            return false;
        }
        $requiredDomain = $requiredDomain !== null
            ? $this->normalizeDomain($requiredDomain)
            : null;
        if ($requiredDomain === '') {
            return false;
        }

        try {
            $endpoints = $this->endpointProvider !== null
                ? ($this->endpointProvider)()
                : $this->endpoints();
        } catch (\Throwable) {
            return false;
        }
        if (!\is_array($endpoints) || !$this->deadlineAvailable($deadlineMonotonic)) {
            return false;
        }

        $gatewayIntentObserved = false;
        $participatingInstances = [];
        $completeCandidateInstances = [];
        $projectUuid = '';
        $candidates = [];
        $hostManager = null;
        foreach ($endpoints as $instanceName => $endpoint) {
            if (!$this->deadlineAvailable($deadlineMonotonic)) {
                return false;
            }
            if (!\is_array($endpoint)) {
                continue;
            }
            if (!GatewayRuntimeServingProjection::participatesInGateway($endpoint)) {
                continue;
            }
            $instanceName = (string)$instanceName;
            $participatingInstances[$instanceName] = true;
            if ($requiredDomain === null
                || $this->endpointAdvertisesDomain($endpoint, $requiredDomain)
            ) {
                $gatewayIntentObserved = true;
            }
            $masterPid = (int)($endpoint['master_pid'] ?? 0);
            if ($masterPid < 1 || !Processer::processExists($masterPid)) {
                continue;
            }
            try {
                if ($this->leaseReceiptProvider !== null) {
                    $receipt = ($this->leaseReceiptProvider)($instanceName);
                } else {
                    $hostManager ??= new GatewayHostManager();
                    $receipt = $hostManager->validatedLeaseReceiptForInstance(
                        $instanceName,
                        $deadlineMonotonic,
                    );
                }
                $registration = $this->registrationProvider !== null
                    ? ($this->registrationProvider)($instanceName)
                    : (new GatewayRegistrationBuilder())->build(
                        $instanceName,
                        $deadlineMonotonic,
                    );
            } catch (\Throwable) {
                continue;
            }
            if (!\is_array($registration)
                || !\is_array($receipt)
                || !$this->deadlineAvailable($deadlineMonotonic)
            ) {
                continue;
            }
            $registrationProjectUuid = \strtolower(\trim((string)(
                $registration['project_uuid'] ?? ''
            )));
            if (\preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $registrationProjectUuid,
            ) !== 1
                || !$this->receiptMatchesRegistration(
                    $receipt,
                    $registration,
                    $instanceName,
                )
            ) {
                continue;
            }
            if ($projectUuid !== '' && !\hash_equals($projectUuid, $registrationProjectUuid)) {
                continue;
            }
            $projectUuid = $registrationProjectUuid;
            $routes = [];
            foreach ((array)($registration['routes'] ?? []) as $route) {
                if (!$this->deadlineAvailable($deadlineMonotonic)) {
                    return false;
                }
                if (!\is_array($route)) {
                    continue;
                }
                $domain = $this->normalizeDomain((string)($route['domain'] ?? ''));
                if ($domain === '' || \str_starts_with($domain, '*.')) {
                    continue;
                }
                $routeId = \strtolower(\trim((string)($route['route_id'] ?? '')));
                if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                    || isset($routes[$routeId])
                ) {
                    $routes = [];
                    break;
                }
                $routes[$routeId] = $route;
                if ($requiredDomain !== null && \hash_equals($requiredDomain, $domain)) {
                    $gatewayIntentObserved = true;
                }
            }
            if ($routes !== []) {
                $candidates[] = [
                    'instance_id' => $instanceName,
                    'receipt' => $receipt,
                    'registration' => $registration,
                    'routes' => $routes,
                ];
                $completeCandidateInstances[$instanceName] = true;
            }
        }

        // A required domain that belongs only to pure WLS must not be blocked
        // by an unrelated gateway tenant. Once any endpoint advertises the
        // domain, however, publication is a full project replay: accepting a
        // partial endpoint view could delete another instance's challenges.
        if ($requiredDomain !== null && !$gatewayIntentObserved) {
            return true;
        }
        if ($participatingInstances === []) {
            return true;
        }
        if ($candidates === []
            || $projectUuid === ''
            || \array_diff_key(
                $participatingInstances,
                $completeCandidateInstances,
            ) !== []
        ) {
            return false;
        }
        try {
            if ($this->statusProvider !== null) {
                $status = ($this->statusProvider)();
            } else {
                $hostManager ??= new GatewayHostManager();
                $status = $hostManager->status(
                    5.0,
                    $deadlineMonotonic,
                );
            }
        } catch (\Throwable) {
            return false;
        }
        if (!$this->deadlineAvailable($deadlineMonotonic)
            || !\is_array($status)
            || !GatewayHostManager::controlPlaneAcceptsRegistration($status)
            || !\hash_equals(
                $projectUuid,
                \strtolower(\trim((string)($status['project_uuid'] ?? ''))),
            )
        ) {
            return false;
        }
        $statusEpoch = \strtolower(\trim((string)($status['epoch'] ?? '')));
        $remoteRoutes = $this->authenticatedChallengeRoutes($status, $projectUuid);
        if ($remoteRoutes === null) {
            return false;
        }
        $allowedDomains = [];
        $expectedRemoteRoutes = [];
        foreach ($candidates as $candidate) {
            if (!$this->deadlineAvailable($deadlineMonotonic)) {
                return false;
            }
            $receipt = (array)$candidate['receipt'];
            if (!\hash_equals(
                $statusEpoch,
                \strtolower((string)($receipt['gateway_epoch'] ?? '')),
            )) {
                return false;
            }
            foreach ((array)$candidate['routes'] as $routeId => $route) {
                if (!$this->deadlineAvailable($deadlineMonotonic)) {
                    return false;
                }
                $remote = $remoteRoutes[(string)$routeId] ?? null;
                if (!\is_array($route)
                    || !\is_array($remote)
                    || !$this->remoteRouteMatchesCandidate(
                        $remote,
                        $route,
                        (string)$candidate['instance_id'],
                        $projectUuid,
                    )
                ) {
                    return false;
                }
                $expectedRemoteRoutes[(string)$routeId] = true;
                $domain = $this->normalizeDomain((string)($route['domain'] ?? ''));
                if ($domain !== '' && !\str_starts_with($domain, '*.')) {
                    $allowedDomains[$domain] = true;
                }
            }
        }
        if ($allowedDomains === []
            || \array_diff_key($remoteRoutes, $expectedRemoteRoutes) !== []
        ) {
            return false;
        }
        if ($requiredDomain !== null && !isset($allowedDomains[$requiredDomain])) {
            return false;
        }

        $filtered = [];
        $requiredLeaseFound = $requiredDomain === null;
        foreach ($challenges as $challenge) {
            if (!$this->deadlineAvailable($deadlineMonotonic)) {
                return false;
            }
            if (!\is_array($challenge)) {
                return false;
            }
            $domain = $this->normalizeDomain((string)($challenge['domain'] ?? ''));
            if ($domain === '' || !isset($allowedDomains[$domain])) {
                continue;
            }
            $challenge['domain'] = $domain;
            $filtered[] = $challenge;
            if ($requiredDomain !== null && \hash_equals($requiredDomain, $domain)) {
                $requiredLeaseFound = true;
            }
        }
        if (!$requiredLeaseFound) {
            return false;
        }
        \usort(
            $filtered,
            static fn (array $left, array $right): int => [
                (string)($left['domain'] ?? ''),
                (string)($left['token'] ?? ''),
            ] <=> [
                (string)($right['domain'] ?? ''),
                (string)($right['token'] ?? ''),
            ],
        );
        $filteredDigest = \hash('sha256', GatewayClient::canonicalJson($filtered));
        try {
            if ($this->sync !== null) {
                $synced = (bool)($this->sync)(
                    $projectUuid,
                    $generation,
                    $filtered,
                    $filteredDigest,
                );
                return $synced && $this->deadlineAvailable($deadlineMonotonic);
            }
            (new GatewayHostManager())->syncAcmeChallenges(
                $projectUuid,
                $generation,
                $filtered,
                $filteredDigest,
                $deadlineMonotonic,
            );
            return $this->deadlineAvailable($deadlineMonotonic);
        } catch (\Throwable) {
            return false;
        }
    }

    private function deadlineAvailable(?float $deadlineMonotonic): bool
    {
        return $deadlineMonotonic === null
            || (\is_finite($deadlineMonotonic)
                && $deadlineMonotonic >= 0.0
                && (\hrtime(true) / 1_000_000_000) < $deadlineMonotonic);
    }

    /** @param array<string,mixed> $endpoint */
    private function endpointAdvertisesDomain(array $endpoint, string $requiredDomain): bool
    {
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $source = \is_array($gateway['certificate_source'] ?? null)
            ? $gateway['certificate_source']
            : [];
        foreach ([
            $endpoint['public_host'] ?? '',
            $endpoint['ssl_domain'] ?? '',
            $source['domain'] ?? '',
        ] as $candidate) {
            $candidate = $this->normalizeDomain((string)$candidate);
            if ($candidate !== '' && \hash_equals($requiredDomain, $candidate)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $registration */
    private function receiptMatchesRegistration(
        array $receipt,
        array $registration,
        string $instanceName,
    ): bool {
        $routeGenerations = \is_array($receipt['route_generations'] ?? null)
            ? $receipt['route_generations']
            : [];
        if ($routeGenerations === [] || \count($routeGenerations) > 256) {
            return false;
        }
        foreach ($routeGenerations as $routeId => $generation) {
            if (!\is_string($routeId)
                || \preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || !\is_int($generation)
                || $generation < 1
            ) {
                return false;
            }
        }
        return \hash_equals(
            (string)($registration['project_uuid'] ?? ''),
            (string)($receipt['project_uuid'] ?? ''),
        )
            && \hash_equals($instanceName, (string)($receipt['instance_id'] ?? ''))
            && (int)($registration['project_generation'] ?? 0)
                === (int)($receipt['project_generation'] ?? -1)
            && (int)($registration['instance_generation'] ?? 0)
                === (int)($receipt['instance_generation'] ?? -1)
            && (int)($registration['master_epoch'] ?? 0)
                === (int)($receipt['master_epoch'] ?? -1)
            && \hash_equals(
                (string)($registration['launch_id'] ?? ''),
                (string)($receipt['launch_id'] ?? ''),
            )
            && \hash_equals(
                (string)($registration['request_digest'] ?? ''),
                (string)($receipt['request_digest'] ?? ''),
            );
    }

    /**
     * @param array<string,mixed> $status
     * @return array<string,array<string,mixed>>|null
     */
    private function authenticatedChallengeRoutes(array $status, string $projectUuid): ?array
    {
        $routes = $status['desired_routes'] ?? null;
        if (!\is_array($routes) || !\array_is_list($routes) || \count($routes) > 256) {
            return null;
        }
        $accepted = [];
        foreach ($routes as $route) {
            if (!\is_array($route)
                || !\hash_equals($projectUuid, (string)($route['project_uuid'] ?? ''))
            ) {
                continue;
            }
            $routeId = \strtolower(\trim((string)($route['route_id'] ?? '')));
            $domain = $this->normalizeDomain((string)($route['domain'] ?? ''));
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || $domain === ''
                || \str_starts_with($domain, '*.')
                || isset($accepted[$routeId])
                || !\in_array(
                    (string)($route['status'] ?? ''),
                    ['PENDING_CERTIFICATE', 'ACTIVE'],
                    true,
                )
            ) {
                return null;
            }
            $accepted[$routeId] = $route;
        }
        return $accepted;
    }

    /** @param array<string,mixed> $remote @param array<string,mixed> $local */
    private function remoteRouteMatchesCandidate(
        array $remote,
        array $local,
        string $instanceName,
        string $projectUuid,
    ): bool {
        $localDomain = $this->normalizeDomain((string)($local['domain'] ?? ''));
        $remoteDomain = $this->normalizeDomain((string)($remote['domain'] ?? ''));
        $localIdentity = \is_array($local['backend_identity'] ?? null)
            ? $local['backend_identity']
            : [];
        $instances = \is_array($remote['backend_instances'] ?? null)
            ? $remote['backend_instances']
            : [];
        $remoteBackend = \is_array($instances[$instanceName] ?? null)
            ? $instances[$instanceName]
            : [];
        $remoteIdentity = \is_array($remoteBackend['backend_identity'] ?? null)
            ? $remoteBackend['backend_identity']
            : [];
        $localDigest = \strtolower(\trim((string)($localIdentity['public_digest'] ?? '')));
        return $localDomain !== ''
            && \hash_equals($localDomain, $remoteDomain)
            && \hash_equals($projectUuid, (string)($remoteIdentity['project_uuid'] ?? ''))
            && \hash_equals($instanceName, (string)($remoteIdentity['instance_id'] ?? ''))
            && \preg_match('/\A[a-f0-9]{64}\z/D', $localDigest) === 1
            && \hash_equals($localDigest, (string)($remoteIdentity['public_digest'] ?? ''));
    }

    /** @return array<string,array<string,mixed>> */
    private function endpoints(): array
    {
        return (new GatewayProjectEndpointReader())->all();
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = \strtolower(\rtrim(\trim($domain), '.'));
        if ($domain === '') {
            return '';
        }
        if (\function_exists('idn_to_ascii')) {
            $ascii = \idn_to_ascii(
                $domain,
                IDNA_DEFAULT,
                INTL_IDNA_VARIANT_UTS46,
            );
            if (!\is_string($ascii) || $ascii === '') {
                return '';
            }
            $domain = \strtolower($ascii);
        }
        return $domain;
    }
}
