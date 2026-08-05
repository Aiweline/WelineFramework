<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Project-owned, coalescing certificate publication queue.
 *
 * Certificate bytes and private-key paths never enter this file. The newest
 * complete desired certificate generation supersedes older unacknowledged
 * generations, while the last authenticated acknowledgement is retained as
 * the gateway-epoch fence used to choose renew versus a full register replay.
 */
final class ProjectCertificateRenewalIntentStore
{
    public const SCHEMA_VERSION = 1;

    private const MAX_STATE_BYTES = 262_144;
    private const MAX_ROUTES = 256;
    private const STATE_FILE = 'state.json';
    private const LOCK_FILE = 'state.lock';

    private readonly string $projectRoot;
    private readonly string $directory;
    private readonly int $projectOwner;
    private readonly int $projectGroup;

    public function __construct(?string $projectRoot = null)
    {
        $requested = $projectRoot ?? (string)BP;
        $real = $requested !== '' && !\str_contains($requested, "\0")
            ? \realpath($requested)
            : false;
        $status = \is_string($real) ? @\lstat($real) : false;
        if (!\is_string($real)
            || $real === ''
            || !\is_array($status)
            || \is_link($requested)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            || $this->isFilesystemRoot($real)
        ) {
            throw new \RuntimeException(
                'Unable to resolve a safe project root for certificate renewal intents.'
            );
        }
        $this->projectRoot = \rtrim($real, '/\\');
        $this->directory = $this->projectRoot . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl'
            . DIRECTORY_SEPARATOR . '.wls-renewal-intents';
        $this->projectOwner = \is_int($status['uid'] ?? null)
            ? (int)$status['uid']
            : -1;
        $this->projectGroup = \is_int($status['gid'] ?? null)
            ? (int)$status['gid']
            : -1;
    }

    /**
     * Persist the newest complete certificate desired state after its project
     * certificate snapshots have been activated by GatewayRegistrationBuilder.
     *
     * @param array<string,mixed> $registration
     * @return array<string,mixed>
     */
    public function enqueueFromRegistration(array $registration): array
    {
        $facts = $this->registrationFacts($registration);
        return $this->withLock(function () use ($facts): array {
            $state = $this->readState((string)$facts['project_uuid']);
            $pending = \is_array($state['pending'] ?? null)
                ? $state['pending']
                : null;
            $lastAck = \is_array($state['last_ack'] ?? null)
                ? $state['last_ack']
                : null;
            foreach (['pending' => $pending, 'last acknowledgement' => $lastAck] as $label => $current) {
                if (!\is_array($current)) {
                    continue;
                }
                $incomingGeneration = (int)$facts['project_generation'];
                $currentGeneration = (int)($current['project_generation'] ?? 0);
                if ($incomingGeneration < $currentGeneration) {
                    // A delayed builder cannot replace a newer pending/acked
                    // publication. The existing state remains authoritative.
                    return $state;
                }
                if ($incomingGeneration === $currentGeneration) {
                    $sameIntent = \hash_equals(
                        (string)$facts['intent_id'],
                        (string)($current['intent_id'] ?? ''),
                    ) && \hash_equals(
                        (string)$facts['request_digest'],
                        (string)($current['request_digest'] ?? ''),
                    );
                    if ($sameIntent) {
                        return $state;
                    }
                    throw new \RuntimeException(
                        'Certificate renewal intent conflicts with the existing '
                        . $label . ' at the same project generation.'
                    );
                }
            }
            $sequence = \max(0, (int)($state['sequence'] ?? 0)) + 1;
            $now = \time();
            $state['sequence'] = $sequence;
            $state['pending'] = [
                'intent_id' => (string)$facts['intent_id'],
                'project_uuid' => (string)$facts['project_uuid'],
                'project_generation' => (int)$facts['project_generation'],
                'request_digest' => (string)$facts['request_digest'],
                'non_certificate_desired_digest' =>
                    (string)$facts['non_certificate_desired_digest'],
                'route_set_digest' => (string)$facts['route_set_digest'],
                'certificate_set_digest' => (string)$facts['certificate_set_digest'],
                'routes' => (array)$facts['routes'],
                'source_instance_id' => (string)$facts['instance_id'],
                'sequence' => $sequence,
                'created_at' => $now,
                'last_attempt' => null,
            ];
            $state['updated_at'] = $now;
            $this->writeState($state);
            return $state;
        });
    }

    /**
     * @return array{intent:array<string,mixed>,last_ack:array<string,mixed>|null}|null
     */
    public function pendingReplay(): ?array
    {
        if (!\is_dir($this->directory) && !\is_link($this->directory)) {
            return null;
        }
        $state = $this->readState();
        $pending = \is_array($state['pending'] ?? null)
            ? $state['pending']
            : null;
        if ($pending === null) {
            return null;
        }
        return [
            'intent' => $pending,
            'last_ack' => \is_array($state['last_ack'] ?? null)
                ? $state['last_ack']
                : null,
        ];
    }

    /**
     * Choose renew only when the authenticated gateway epoch and exact route
     * set still match the last acknowledged project publication. Epoch or
     * route-set changes require a complete register replay.
     *
     * @param array{intent:array<string,mixed>,last_ack:array<string,mixed>|null} $observation
     * @param array<string,mixed> $status authenticated project own-status
     * @return array{action:string,gateway_epoch:string,expected_route_generations:array<string,int>}
     */
    public function replayPlan(array $observation, array $status): array
    {
        $intent = \is_array($observation['intent'] ?? null)
            ? $observation['intent']
            : [];
        $this->assertIntent($intent);
        $projectUuid = (string)$intent['project_uuid'];
        $epoch = \strtolower(\trim((string)($status['epoch'] ?? '')));
        if (!GatewayHostManager::controlPlaneAcceptsRegistration($status)
            || !\hash_equals(
                $projectUuid,
                \strtolower(\trim((string)($status['project_uuid'] ?? ''))),
            )
            || \preg_match('/\A[a-f0-9]{32}\z/D', $epoch) !== 1
        ) {
            throw new \RuntimeException(
                'Certificate renewal replay requires authenticated project own-status.'
            );
        }

        $desired = $this->intentRouteMap($intent);
        $observed = $this->authenticatedRouteMap($status, $projectUuid);
        $lastAck = \is_array($observation['last_ack'] ?? null)
            ? $observation['last_ack']
            : null;
        $ackEpoch = \is_array($lastAck)
            ? \strtolower(\trim((string)($lastAck['gateway_epoch'] ?? '')))
            : '';
        if ($desired === []
            || \array_keys($desired) !== \array_keys($observed)
            || !\is_array($lastAck)
            || !\hash_equals($epoch, $ackEpoch)
            || !\hash_equals(
                (string)$intent['non_certificate_desired_digest'],
                (string)($lastAck['non_certificate_desired_digest'] ?? ''),
            )
        ) {
            return [
                'action' => 'register',
                'gateway_epoch' => $epoch,
                'expected_route_generations' => [],
            ];
        }
        $expected = [];
        foreach ($desired as $routeId => $route) {
            $current = $observed[$routeId] ?? null;
            if (!\is_array($current)
                || !\hash_equals((string)$route['domain'], (string)$current['domain'])
                || (int)($current['route_generation'] ?? 0) < 1
            ) {
                return [
                    'action' => 'register',
                    'gateway_epoch' => $epoch,
                    'expected_route_generations' => [],
                ];
            }
            $expected[$routeId] = (int)$current['route_generation'];
        }
        return [
            'action' => 'renew',
            'gateway_epoch' => $epoch,
            'expected_route_generations' => $expected,
        ];
    }

    /**
     * @param array<string,mixed> $registration
     * @param array{action:string,gateway_epoch:string,expected_route_generations:array<string,int>} $plan
     * @return string|null Exact intent ID whose attempt was recorded, or null
     *         when there is no pending renewal intent.
     */
    public function recordAttempt(
        array $registration,
        string $instanceName,
        array $plan,
    ): ?string {
        $facts = $this->registrationFacts($registration);
        $this->assertInstanceName($instanceName);
        $action = (string)($plan['action'] ?? '');
        $epoch = \strtolower(\trim((string)($plan['gateway_epoch'] ?? '')));
        $expected = \is_array($plan['expected_route_generations'] ?? null)
            ? $plan['expected_route_generations']
            : [];
        if (!\in_array($action, ['register', 'renew'], true)
            || \preg_match('/\A[a-f0-9]{32}\z/D', $epoch) !== 1
        ) {
            throw new \InvalidArgumentException('Certificate renewal replay plan is invalid.');
        }
        $expected = $this->normalizeRouteGenerations($expected, $action === 'renew');
        if ($action === 'register' && $expected !== []) {
            throw new \InvalidArgumentException(
                'Full certificate registration must not carry renew route generations.'
            );
        }
        return $this->withLock(function () use (
            $facts,
            $instanceName,
            $action,
            $epoch,
            $expected,
        ): ?string {
            $state = $this->readState((string)$facts['project_uuid']);
            $pending = \is_array($state['pending'] ?? null)
                ? $state['pending']
                : [];
            if ($pending === []) {
                return null;
            }
            if (!\hash_equals(
                (string)$facts['intent_id'],
                (string)($pending['intent_id'] ?? ''),
            )) {
                throw new \RuntimeException(
                    'Certificate renewal intent changed before replay attempt.'
                );
            }
            $previous = \is_array($pending['last_attempt'] ?? null)
                ? $pending['last_attempt']
                : [];
            $pending['last_attempt'] = [
                'count' => \max(0, (int)($previous['count'] ?? 0)) + 1,
                'attempted_at' => \time(),
                'instance_id' => $instanceName,
                'action' => $action,
                'gateway_epoch' => $epoch,
                'expected_route_generations' => $expected,
                'failed_at' => 0,
                'error' => '',
            ];
            $state['pending'] = $pending;
            $state['updated_at'] = \time();
            $this->writeState($state);
            return (string)$pending['intent_id'];
        });
    }

    public function recordFailure(string $intentId, string $message): void
    {
        $intentId = \strtolower(\trim($intentId));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $intentId) !== 1) {
            return;
        }
        $message = GatewayBoundedText::singleLine(
            $message,
            2048,
            'Certificate renewal replay failed.',
        );
        $this->withLock(function () use ($intentId, $message): void {
            $state = $this->readState();
            $pending = \is_array($state['pending'] ?? null)
                ? $state['pending']
                : [];
            if (!\hash_equals($intentId, (string)($pending['intent_id'] ?? ''))) {
                return;
            }
            $attempt = \is_array($pending['last_attempt'] ?? null)
                ? $pending['last_attempt']
                : [];
            $attempt['failed_at'] = \time();
            $attempt['error'] = $message;
            $pending['last_attempt'] = $attempt;
            $state['pending'] = $pending;
            $state['updated_at'] = \time();
            $this->writeState($state);
        });
    }

    /**
     * Clear pending only after a signed lease receipt and authenticated
     * own-status prove the exact desired certificate and route generations.
     *
     * @param array<string,mixed> $registration
     * @param array<string,mixed> $status authenticated project own-status
     */
    public function acknowledge(
        array $registration,
        string $instanceName,
        string $action,
        array $status,
    ): bool {
        $facts = $this->registrationFacts($registration);
        $this->assertInstanceName($instanceName);
        if (!\in_array($action, ['register', 'renew'], true)) {
            throw new \InvalidArgumentException(
                'Certificate renewal acknowledgement action is invalid.'
            );
        }
        return $this->withLock(function () use (
            $facts,
            $instanceName,
            $action,
            $status,
        ): bool {
            $state = $this->readState((string)$facts['project_uuid']);
            $pending = \is_array($state['pending'] ?? null)
                ? $state['pending']
                : null;
            if ($pending === null) {
                return false;
            }
            if (!\hash_equals(
                (string)$facts['intent_id'],
                (string)($pending['intent_id'] ?? ''),
            )) {
                if ((int)($pending['project_generation'] ?? 0)
                    > (int)$facts['project_generation']
                ) {
                    // A newer complete certificate desired state superseded
                    // this in-flight publication. It remains pending and must
                    // not turn the already committed older publication into a
                    // false transaction failure.
                    return false;
                }
                throw new \RuntimeException(
                    'Certificate renewal intent changed before acknowledgement.'
                );
            }
            $attempt = \is_array($pending['last_attempt'] ?? null)
                ? $pending['last_attempt']
                : [];
            if (!\hash_equals($action, (string)($attempt['action'] ?? ''))
                || !\hash_equals($instanceName, (string)($attempt['instance_id'] ?? ''))
            ) {
                throw new \RuntimeException(
                    'Certificate renewal acknowledgement does not match its replay attempt.'
                );
            }
            $epoch = \strtolower(\trim((string)($status['epoch'] ?? '')));
            if (!GatewayHostManager::controlPlaneAcceptsRegistration($status)
                || !\hash_equals(
                    (string)$facts['project_uuid'],
                    \strtolower(\trim((string)($status['project_uuid'] ?? ''))),
                )
                || \preg_match('/\A[a-f0-9]{32}\z/D', $epoch) !== 1
            ) {
                throw new \RuntimeException(
                    'Certificate renewal acknowledgement requires authenticated own-status.'
                );
            }
            // A hex-looking signature in endpoint JSON is not authority.
            // HostManager verifies credential HMAC, receipt freshness, exact
            // endpoint launch/generation and authenticated current gateway
            // epoch/route closure before this intent may be acknowledged.
            $receipt = (new GatewayHostManager())
                ->validatedLeaseReceiptForInstance($instanceName);
            $routeGenerations = $this->assertReceiptMatchesFacts(
                $receipt,
                $facts,
                $instanceName,
                $epoch,
            );
            $this->assertStatusMatchesFacts(
                $status,
                $facts,
                $routeGenerations,
                $receipt,
            );
            if ($action === 'renew') {
                $expected = $this->normalizeRouteGenerations(
                    \is_array($attempt['expected_route_generations'] ?? null)
                        ? $attempt['expected_route_generations']
                        : [],
                    true,
                );
                if (!self::routeGenerationFencesMatch($expected, $routeGenerations)
                    || !\hash_equals(
                        $epoch,
                        (string)($attempt['gateway_epoch'] ?? ''),
                    )
                ) {
                    throw new \RuntimeException(
                        'Certificate renew acknowledgement lost its expected route-generation fence.'
                    );
                }
            }
            $acknowledgedAt = \time();
            $state['last_ack'] = [
                'intent_id' => (string)$facts['intent_id'],
                'project_generation' => (int)$facts['project_generation'],
                'request_digest' => (string)$facts['request_digest'],
                'non_certificate_desired_digest' =>
                    (string)$facts['non_certificate_desired_digest'],
                'route_set_digest' => (string)$facts['route_set_digest'],
                'certificate_set_digest' => (string)$facts['certificate_set_digest'],
                'gateway_epoch' => $epoch,
                'route_generations' => $routeGenerations,
                'action' => $action,
                'instance_id' => $instanceName,
                'acknowledged_at' => $acknowledgedAt,
            ];
            $state['pending'] = null;
            $state['updated_at'] = $acknowledgedAt;
            $this->writeState($state);
            return true;
        });
    }

    /** @param array<string,mixed> $registration @return array<string,mixed> */
    private function registrationFacts(array $registration): array
    {
        $projectUuid = \strtolower(\trim((string)(
            $registration['project_uuid'] ?? ''
        )));
        $instanceName = \trim((string)($registration['instance_id'] ?? ''));
        $projectGeneration = (int)($registration['project_generation'] ?? 0);
        $requestDigest = \strtolower(\trim((string)(
            $registration['request_digest'] ?? ''
        )));
        $nonCertificateDesiredDigest = \strtolower(\trim((string)(
            $registration['non_certificate_desired_digest'] ?? ''
        )));
        if (\preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            $projectUuid,
        ) !== 1
            || $projectGeneration < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $requestDigest) !== 1
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                $nonCertificateDesiredDigest,
            ) !== 1
        ) {
            throw new \RuntimeException(
                'Gateway registration has no valid certificate desired-state identity.'
            );
        }
        $this->assertInstanceName($instanceName);
        $rawRoutes = \is_array($registration['routes'] ?? null)
            ? $registration['routes']
            : [];
        if ($rawRoutes === []
            || !\array_is_list($rawRoutes)
            || \count($rawRoutes) > self::MAX_ROUTES
        ) {
            throw new \RuntimeException(
                'Gateway registration route set is invalid for certificate replay.'
            );
        }
        $routes = [];
        foreach ($rawRoutes as $route) {
            if (!\is_array($route)) {
                throw new \RuntimeException('Gateway registration contains an invalid route.');
            }
            $routeId = \strtolower(\trim((string)($route['route_id'] ?? '')));
            $rawDomain = (string)($route['domain'] ?? '');
            try {
                $domain = ProjectServingManifestStore::normalizeHost($rawDomain);
            } catch (\Throwable $throwable) {
                throw new \RuntimeException(
                    'Gateway certificate replay route domain is invalid.',
                    0,
                    $throwable,
                );
            }
            $certificate = \is_array($route['certificate'] ?? null)
                ? $route['certificate']
                : [];
            $generation = (int)($certificate['generation'] ?? -1);
            $sourceDigest = \strtolower(\trim((string)(
                $certificate['source_digest'] ?? ''
            )));
            $pending = $certificate['pending'] ?? $generation === 0;
            $state = \strtolower(\trim((string)(
                $certificate['state']
                    ?? (($generation > 0 && $pending === false) ? 'active' : 'pending')
            )));
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || !\hash_equals($domain, $rawDomain)
                || !\hash_equals(
                    \substr(\hash('sha256', $projectUuid . "\0" . $domain), 0, 32),
                    $routeId,
                )
                || !\is_bool($pending)
                || !\in_array($state, ['active', 'pending', 'disabled'], true)
                || ($state === 'active' && ($generation < 1 || $pending !== false))
                || ($state === 'pending'
                    && ($generation !== 0
                        || $pending !== true
                        || !\hash_equals(
                            \hash('sha256', "wls-pending-certificate\0" . $domain),
                            $sourceDigest,
                        )))
                || ($state === 'disabled'
                    && ($generation < 1
                        || $pending !== true
                        || \str_starts_with($domain, '*.')))
                || \preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1
                || isset($routes[$routeId])
            ) {
                throw new \RuntimeException(
                    'Gateway certificate replay route fact is malformed or duplicated.'
                );
            }
            $routes[$routeId] = [
                'route_id' => $routeId,
                'domain' => $domain,
                'state' => $state,
                'certificate_generation' => $generation,
                'source_digest' => $sourceDigest,
                'pending' => $pending,
            ];
        }
        \ksort($routes, SORT_STRING);
        $routes = \array_values($routes);
        $routeSet = \array_map(
            static fn (array $route): array => [
                'route_id' => (string)$route['route_id'],
                'domain' => (string)$route['domain'],
            ],
            $routes,
        );
        $routeSetDigest = \hash(
            'sha256',
            GatewayClient::canonicalJson($routeSet),
        );
        $certificateSetDigest = \hash(
            'sha256',
            GatewayClient::canonicalJson($routes),
        );
        $intentId = \hash('sha256', GatewayClient::canonicalJson([
            'project_uuid' => $projectUuid,
            'project_generation' => $projectGeneration,
            'request_digest' => $requestDigest,
            'non_certificate_desired_digest' => $nonCertificateDesiredDigest,
            'route_set_digest' => $routeSetDigest,
            'certificate_set_digest' => $certificateSetDigest,
        ]));
        return [
            'intent_id' => $intentId,
            'project_uuid' => $projectUuid,
            'instance_id' => $instanceName,
            'project_generation' => $projectGeneration,
            'request_digest' => $requestDigest,
            'non_certificate_desired_digest' => $nonCertificateDesiredDigest,
            'route_set_digest' => $routeSetDigest,
            'certificate_set_digest' => $certificateSetDigest,
            'routes' => $routes,
        ];
    }

    /** @param array<string,mixed> $intent */
    private function assertIntent(array $intent): void
    {
        $registration = [
            'project_uuid' => $intent['project_uuid'] ?? '',
            'instance_id' => $intent['source_instance_id'] ?? '',
            'project_generation' => $intent['project_generation'] ?? 0,
            'request_digest' => $intent['request_digest'] ?? '',
            'non_certificate_desired_digest' =>
                $intent['non_certificate_desired_digest'] ?? '',
            'routes' => \array_map(
                static fn (mixed $route): array => \is_array($route) ? [
                    'route_id' => $route['route_id'] ?? '',
                    'domain' => $route['domain'] ?? '',
                    'certificate' => [
                        'state' => $route['state'] ?? '',
                        'generation' => $route['certificate_generation'] ?? -1,
                        'source_digest' => $route['source_digest'] ?? '',
                        'pending' => $route['pending'] ?? null,
                    ],
                ] : [],
                \is_array($intent['routes'] ?? null) ? $intent['routes'] : [],
            ),
        ];
        $facts = $this->registrationFacts($registration);
        if (!\hash_equals((string)$facts['intent_id'], (string)($intent['intent_id'] ?? ''))
            || !\hash_equals(
                (string)$facts['route_set_digest'],
                (string)($intent['route_set_digest'] ?? ''),
            )
            || !\hash_equals(
                (string)$facts['certificate_set_digest'],
                (string)($intent['certificate_set_digest'] ?? ''),
            )
            || (int)($intent['sequence'] ?? 0) < 1
            || (int)($intent['created_at'] ?? 0) < 1
        ) {
            throw new \RuntimeException('Certificate renewal intent integrity check failed.');
        }
        if (\is_array($intent['last_attempt'] ?? null)) {
            $attempt = $intent['last_attempt'];
            $action = (string)($attempt['action'] ?? '');
            $expected = $this->normalizeRouteGenerations(
                \is_array($attempt['expected_route_generations'] ?? null)
                    ? $attempt['expected_route_generations']
                    : [],
                $action === 'renew',
            );
            if (!\in_array($action, ['register', 'renew'], true)
                || (int)($attempt['count'] ?? 0) < 1
                || (int)($attempt['attempted_at'] ?? 0) < 1
                || \preg_match(
                    '/\A[a-f0-9]{32}\z/D',
                    (string)($attempt['gateway_epoch'] ?? ''),
                ) !== 1
                || ($action === 'register' && $expected !== [])
                || (int)($attempt['failed_at'] ?? 0) < 0
                || \strlen((string)($attempt['error'] ?? '')) > 2048
            ) {
                throw new \RuntimeException('Certificate renewal attempt state is invalid.');
            }
            $this->assertInstanceName((string)($attempt['instance_id'] ?? ''));
        }
    }

    /** @param array<string,mixed> $intent @return array<string,array<string,mixed>> */
    private function intentRouteMap(array $intent): array
    {
        $routes = [];
        foreach ((array)($intent['routes'] ?? []) as $route) {
            if (!\is_array($route)) {
                continue;
            }
            $routes[(string)$route['route_id']] = $route;
        }
        \ksort($routes, SORT_STRING);
        return $routes;
    }

    /**
     * @param array<string,mixed> $status
     * @return array<string,array{domain:string,status:string,state:string,route_generation:int,certificate_generation:int,source_digest:string}>
     */
    private function authenticatedRouteMap(array $status, string $projectUuid): array
    {
        $published = $status['active_routes'] ?? null;
        if (!\is_array($published)
            || !\array_is_list($published)
            || \count($published) > self::MAX_ROUTES
        ) {
            throw new \RuntimeException(
                'Authenticated gateway active route publication is missing or invalid.'
            );
        }
        $routes = [];
        foreach ($published as $route) {
            if (!\is_array($route)
            ) {
                throw new \RuntimeException(
                    'Authenticated gateway active route publication is malformed.'
                );
            }
            $routeStatus = \strtoupper(\trim((string)($route['status'] ?? '')));
            if (!\hash_equals($projectUuid, (string)($route['project_uuid'] ?? ''))
                || !\in_array($routeStatus, ['ACTIVE', 'PENDING_CERTIFICATE'], true)
            ) {
                throw new \RuntimeException(
                    'Certificate publication acknowledgement requires exact ACTIVE or challenge-only PENDING_CERTIFICATE routes.'
                );
            }
            $routeId = \strtolower(\trim((string)($route['route_id'] ?? '')));
            $certificate = \is_array($route['certificate'] ?? null)
                ? $route['certificate']
                : [];
            $certificateGeneration = (int)($certificate['generation'] ?? -1);
            $pending = ($certificate['pending'] ?? $certificateGeneration === 0) === true;
            $certificateState = \strtolower(\trim((string)(
                $certificate['state']
                    ?? (($certificateGeneration > 0 && !$pending) ? 'active' : 'pending')
            )));
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || isset($routes[$routeId])
                || !\in_array($certificateState, ['active', 'pending', 'disabled'], true)
            ) {
                throw new \RuntimeException(
                    'Authenticated gateway route set is malformed or duplicated.'
                );
            }
            $routes[$routeId] = [
                'domain' => \strtolower(\rtrim(\trim((string)($route['domain'] ?? '')), '.')),
                'status' => $routeStatus,
                'state' => $certificateState,
                'route_generation' => (int)($route['route_generation'] ?? 0),
                'certificate_generation' => $certificateGeneration,
                'source_digest' => \strtolower(\trim((string)(
                    $certificate['source_digest'] ?? ''
                ))),
            ];
        }
        \ksort($routes, SORT_STRING);
        return $routes;
    }

    /**
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $facts
     * @return array<string,int>
     */
    private function assertReceiptMatchesFacts(
        array $receipt,
        array $facts,
        string $instanceName,
        string $epoch,
    ): array {
        // Do not maintain a second receipt schema here. HostManager is the
        // authority for the exact wls-edge/2 envelope and has already checked
        // credential HMAC, freshness, endpoint launch and own-status closure
        // before returning this receipt.
        GatewayHostManager::assertLeaseReceiptContract($receipt);
        $rawRouteGenerations = \is_array($receipt['route_generations'] ?? null)
            ? $receipt['route_generations']
            : [];
        $routeGenerations = $this->normalizeRouteGenerations(
            $rawRouteGenerations,
            true,
        );
        $desiredRouteIds = \array_keys($this->factsRouteMap($facts));
        $issuedMonotonic = $receipt['issued_monotonic'] ?? null;
        if (!\hash_equals((string)$facts['project_uuid'], (string)($receipt['project_uuid'] ?? ''))
            || !\hash_equals($instanceName, (string)($receipt['instance_id'] ?? ''))
            || !\hash_equals($epoch, (string)($receipt['gateway_epoch'] ?? ''))
            || !\is_int($receipt['instance_generation'] ?? null)
            || (int)$receipt['instance_generation'] < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $receipt['instance_digest'] ?? ''
            )) !== 1
            || !\is_int($receipt['master_epoch'] ?? null)
            || (int)$receipt['master_epoch'] < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                $receipt['launch_id'] ?? ''
            )) !== 1
            || !\is_int($receipt['active_config_generation'] ?? null)
            || (int)$receipt['active_config_generation'] < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $receipt['active_config_digest'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $receipt['host_boot_id'] ?? ''
            )) !== 1
            || !(\is_int($issuedMonotonic) || \is_float($issuedMonotonic))
            || !\is_finite((float)$issuedMonotonic)
            || (float)$issuedMonotonic < 0.0
            || !\is_int($receipt['lease_sequence'] ?? null)
            || (int)$receipt['lease_sequence'] < 1
            || (int)($receipt['project_generation'] ?? 0)
                !== (int)$facts['project_generation']
            || !\hash_equals(
                (string)$facts['request_digest'],
                (string)($receipt['request_digest'] ?? ''),
            )
            || !\hash_equals(
                \hash('sha256', GatewayClient::canonicalJson($rawRouteGenerations)),
                (string)($receipt['routes_digest'] ?? ''),
            )
            || \array_keys($routeGenerations) !== $desiredRouteIds
        ) {
            throw new \RuntimeException(
                'Signed gateway lease receipt does not acknowledge the pending certificate intent.'
            );
        }
        return $routeGenerations;
    }

    /**
     * @param array<string,mixed> $status
     * @param array<string,mixed> $facts
     * @param array<string,int> $routeGenerations
     * @param array<string,mixed> $receipt
     */
    private function assertStatusMatchesFacts(
        array $status,
        array $facts,
        array $routeGenerations,
        array $receipt,
    ): void {
        GatewayHostManager::assertLeaseReceiptMatchesAuthenticatedStatus(
            $receipt,
            $status,
        );
        if (($status['publication_exact'] ?? false) !== true
            || (int)($status['project_generation'] ?? 0)
                !== (int)$facts['project_generation']
            || !\hash_equals(
                (string)$facts['request_digest'],
                \strtolower(\trim((string)($status['request_digest'] ?? ''))),
            )
            || !\hash_equals(
                (string)$facts['non_certificate_desired_digest'],
                \strtolower(\trim((string)(
                    $status['non_certificate_desired_digest'] ?? ''
                ))),
            )
            || (int)($status['active_config_generation'] ?? 0)
                !== (int)($receipt['active_config_generation'] ?? -1)
            || !\hash_equals(
                (string)($receipt['active_config_digest'] ?? ''),
                \strtolower(\trim((string)(
                    $status['active_config_digest'] ?? ''
                ))),
            )
        ) {
            throw new \RuntimeException(
                'Authenticated gateway project publication does not match the pending intent.'
            );
        }
        $desired = $this->factsRouteMap($facts);
        $observed = $this->authenticatedRouteMap(
            $status,
            (string)$facts['project_uuid'],
        );
        if (\array_keys($desired) !== \array_keys($observed)) {
            throw new \RuntimeException(
                'Authenticated gateway route set does not acknowledge the certificate intent.'
            );
        }
        foreach ($desired as $routeId => $route) {
            $current = $observed[$routeId] ?? [];
            $state = (string)($route['state'] ?? '');
            $expectedStatus = $state === 'active' ? 'ACTIVE' : 'PENDING_CERTIFICATE';
            if (!\hash_equals((string)$route['domain'], (string)($current['domain'] ?? ''))
                || !\hash_equals($expectedStatus, (string)($current['status'] ?? ''))
                || !\hash_equals($state, (string)($current['state'] ?? ''))
                || (int)$route['certificate_generation']
                    !== (int)($current['certificate_generation'] ?? -1)
                || !\hash_equals(
                    (string)$route['source_digest'],
                    (string)($current['source_digest'] ?? ''),
                )
                || (int)$routeGenerations[$routeId]
                    !== (int)($current['route_generation'] ?? 0)
            ) {
                throw new \RuntimeException(
                    'Authenticated gateway certificate facts differ from the pending intent.'
                );
            }
        }
    }

    /**
     * A renew receipt is fenced by both the route identity set and every exact
     * Controller route generation. Comparing only keys would allow a delayed
     * receipt to clear a newer pending certificate publication.
     *
     * @param array<string,int> $expected
     * @param array<string,int> $observed
     */
    private static function routeGenerationFencesMatch(
        array $expected,
        array $observed,
    ): bool {
        return $expected === $observed;
    }

    /** @param array<string,mixed> $facts @return array<string,array<string,mixed>> */
    private function factsRouteMap(array $facts): array
    {
        $routes = [];
        foreach ((array)($facts['routes'] ?? []) as $route) {
            if (\is_array($route)) {
                $routes[(string)$route['route_id']] = $route;
            }
        }
        \ksort($routes, SORT_STRING);
        return $routes;
    }

    /** @param array<mixed,mixed> $routeGenerations @return array<string,int> */
    private function normalizeRouteGenerations(
        array $routeGenerations,
        bool $required,
    ): array {
        if (\count($routeGenerations) > self::MAX_ROUTES) {
            throw new \RuntimeException('Certificate replay route-generation set is too large.');
        }
        $normalized = [];
        foreach ($routeGenerations as $routeId => $generation) {
            if (!\is_string($routeId)
                || \preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || !\is_int($generation)
                || $generation < 1
                || isset($normalized[$routeId])
            ) {
                throw new \RuntimeException(
                    'Certificate replay route-generation fence is invalid.'
                );
            }
            $normalized[$routeId] = $generation;
        }
        \ksort($normalized, SORT_STRING);
        if ($required && $normalized === []) {
            throw new \RuntimeException(
                'Certificate renew requires an exact route-generation fence.'
            );
        }
        return $normalized;
    }

    /** @return array<string,mixed> */
    private function readState(string $expectedProjectUuid = ''): array
    {
        $encoded = GatewayProjectStateFilesystem::readOptional(
            $this->stateFile(),
            self::MAX_STATE_BYTES,
            'project certificate renewal intent state',
        );
        if ($encoded === null) {
            if ($expectedProjectUuid === '') {
                return [
                    'schema_version' => self::SCHEMA_VERSION,
                    'project_uuid' => '',
                    'sequence' => 0,
                    'pending' => null,
                    'last_ack' => null,
                    'updated_at' => 0,
                ];
            }
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'project_uuid' => $expectedProjectUuid,
                'sequence' => 0,
                'pending' => null,
                'last_ack' => null,
                'updated_at' => 0,
            ];
        }
        $envelope = \json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        $state = \is_array($envelope['payload'] ?? null)
            ? $envelope['payload']
            : null;
        $digest = \strtolower(\trim((string)($envelope['sha256'] ?? '')));
        if (!\is_array($state)
            || ($state['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || \preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                (string)($state['project_uuid'] ?? ''),
            ) !== 1
            || ($expectedProjectUuid !== ''
                && !\hash_equals($expectedProjectUuid, (string)$state['project_uuid']))
            || (int)($state['sequence'] ?? -1) < 0
            || (int)($state['updated_at'] ?? -1) < 0
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\hash_equals(
                $digest,
                \hash('sha256', GatewayClient::canonicalJson($state)),
            )
        ) {
            throw new \RuntimeException(
                'Project certificate renewal intent state integrity check failed.'
            );
        }
        if (\is_array($state['pending'] ?? null)) {
            $this->assertIntent($state['pending']);
        } elseif (($state['pending'] ?? null) !== null) {
            throw new \RuntimeException('Project certificate pending intent is invalid.');
        }
        if (\is_array($state['last_ack'] ?? null)) {
            $this->assertAcknowledgement($state['last_ack']);
        } elseif (($state['last_ack'] ?? null) !== null) {
            throw new \RuntimeException('Project certificate acknowledgement is invalid.');
        }
        return $state;
    }

    /** @param array<string,mixed> $ack */
    private function assertAcknowledgement(array $ack): void
    {
        if (\preg_match('/\A[a-f0-9]{64}\z/D', (string)($ack['intent_id'] ?? '')) !== 1
            || (int)($ack['project_generation'] ?? 0) < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($ack['request_digest'] ?? '')) !== 1
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($ack['non_certificate_desired_digest'] ?? ''),
            ) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($ack['route_set_digest'] ?? '')) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($ack['certificate_set_digest'] ?? '')) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($ack['gateway_epoch'] ?? '')) !== 1
            || !\in_array((string)($ack['action'] ?? ''), ['register', 'renew'], true)
            || (int)($ack['acknowledged_at'] ?? 0) < 1
        ) {
            throw new \RuntimeException('Project certificate acknowledgement is malformed.');
        }
        $this->assertInstanceName((string)($ack['instance_id'] ?? ''));
        $this->normalizeRouteGenerations(
            \is_array($ack['route_generations'] ?? null)
                ? $ack['route_generations']
                : [],
            true,
        );
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void
    {
        $payload = GatewayClient::canonicalJson($state);
        $encoded = \json_encode(
            [
                'payload' => $state,
                'sha256' => \hash('sha256', $payload),
            ],
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR,
        ) . "\n";
        if (\strlen($encoded) > self::MAX_STATE_BYTES
            || \stripos($encoded, 'PRIVATE KEY') !== false
            || \preg_match('/["\'](?:key|key_path|private_key)["\']\s*:/i', $encoded) === 1
        ) {
            throw new \RuntimeException(
                'Project certificate renewal intent state violates its fixed data boundary.'
            );
        }
        GatewayProjectStateFilesystem::atomicWrite(
            $this->stateFile(),
            $encoded,
            0600,
            fn ($handle, string $path): mixed => $this->preserveProjectOwnership(
                $path,
                $handle,
            ),
        );
    }

    /** @template TResult @param \Closure():TResult $callback @return TResult */
    private function withLock(\Closure $callback): mixed
    {
        $this->ensureDirectory();
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $this->directory . DIRECTORY_SEPARATOR . self::LOCK_FILE,
            $callback,
            fn ($handle, string $path): mixed => $this->preserveProjectOwnership(
                $path,
                $handle,
            ),
        );
    }

    private function ensureDirectory(): void
    {
        $parent = \dirname($this->directory);
        $parentReal = \realpath($parent);
        $parentStatus = @\lstat($parent);
        if (!\is_string($parentReal)
            || !\hash_equals($this->pathKey($parent), $this->pathKey($parentReal))
            || !\is_array($parentStatus)
            || \is_link($parent)
            || ((((int)($parentStatus['mode'] ?? 0)) & 0170000) !== 0040000)
            || !$this->pathInside($parentReal, $this->projectRoot)
        ) {
            throw new \RuntimeException(
                'Project certificate renewal intent parent directory is unsafe.'
            );
        }
        $status = @\lstat($this->directory);
        if (!\is_array($status)) {
            if (\file_exists($this->directory)
                || \is_link($this->directory)
                || !@\mkdir($this->directory, 0700)
            ) {
                throw new \RuntimeException(
                    'Unable to create the project certificate renewal intent directory.'
                );
            }
            $this->preserveCreatedDirectory($this->directory);
            $status = @\lstat($this->directory);
        }
        if (!\is_array($status)
            || \is_link($this->directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)($status['mode'] ?? 0)) & 0077) !== 0))
        ) {
            throw new \RuntimeException(
                'Project certificate renewal intent directory is unsafe.'
            );
        }
    }

    /** @param resource|null $handle */
    private function preserveProjectOwnership(string $path, mixed $handle = null): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || $this->projectOwner < 0
            || $this->projectGroup < 0
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
        ) {
            return;
        }
        $ownerApplied = \is_resource($handle)
            && \function_exists('fchown')
            && @\fchown($handle, $this->projectOwner);
        if (!$ownerApplied) {
            $ownerApplied = @\chown($path, $this->projectOwner);
        }
        $groupApplied = \is_resource($handle)
            && \function_exists('fchgrp')
            && @\fchgrp($handle, $this->projectGroup);
        if (!$groupApplied) {
            $groupApplied = @\chgrp($path, $this->projectGroup);
        }
        if (!$ownerApplied || !$groupApplied) {
            throw new \RuntimeException(
                'Unable to preserve project ownership on certificate renewal state.'
            );
        }
    }

    private function preserveCreatedDirectory(string $directory): void
    {
        if (\PHP_OS_FAMILY !== 'Windows'
            && $this->projectOwner >= 0
            && $this->projectGroup >= 0
            && \function_exists('posix_geteuid')
            && \posix_geteuid() === 0
            && (!@\chown($directory, $this->projectOwner)
                || !@\chgrp($directory, $this->projectGroup))
        ) {
            throw new \RuntimeException(
                'Unable to preserve project ownership on certificate renewal directory.'
            );
        }
    }

    private function stateFile(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . self::STATE_FILE;
    }

    private function assertInstanceName(string $instanceName): void
    {
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceName) !== 1) {
            throw new \InvalidArgumentException(
                'Certificate renewal intent instance ID is invalid.'
            );
        }
    }

    private function pathInside(string $path, string $root): bool
    {
        $path = $this->pathKey($path);
        $root = $this->pathKey($root);
        return $path === $root || \str_starts_with($path, $root . '/');
    }

    private function pathKey(string $path): string
    {
        $path = \str_replace('\\', '/', \rtrim($path, '/\\'));
        return \PHP_OS_FAMILY === 'Windows' ? \strtolower($path) : $path;
    }

    private function isFilesystemRoot(string $path): bool
    {
        $normalized = \str_replace('\\', '/', \trim($path));
        if (\preg_match('#\A/+\z#D', $normalized) === 1) {
            return true;
        }
        $normalized = \rtrim($normalized, '/');
        return \preg_match('/\A[A-Za-z]:\z/D', $normalized) === 1
            || \preg_match('#\A//(?![?.](?:/|\z))[^/]+(?:/[^/]+)?\z#D', $normalized) === 1
            || \preg_match('#\A//[?.]/[A-Za-z]:\z#Di', $normalized) === 1
            || \preg_match('#\A//[?.]/UNC(?:/[^/]+(?:/[^/]+)?)?\z#Di', $normalized) === 1
            || \preg_match(
                '#\A//[?.]/Volume\{[0-9A-Fa-f-]+\}\z#Di',
                $normalized,
            ) === 1;
    }
}
