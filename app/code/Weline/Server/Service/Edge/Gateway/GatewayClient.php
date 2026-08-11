<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Authenticated single-request client for WLS Edge Protocol 2.
 */
final class GatewayClient
{
    public const UNPROVEN_RESPONSE_ERROR =
        'WLS Gateway returned an empty, oversized, or incomplete response.';

    private const MAX_FRAME_BYTES = 4 * 1024 * 1024;
    private const MAX_ADMIN_ROUTE_ROWS = 2048;
    private const MAX_PROJECT_ROUTE_ROWS = 512;
    private const MAX_ROUTE_BACKENDS = 16;
    private const LONG_ADMIN_RESPONSE_TIMEOUT_SECONDS = 90.0;
    private const LONG_PROJECT_MUTATION_RESPONSE_TIMEOUT_SECONDS = 90.0;

    public function __construct(
        private readonly GatewayPaths $paths = new GatewayPaths(),
        private readonly float $timeoutSeconds = 2.0,
        private readonly GatewayCredentialStore $credentials = new GatewayCredentialStore(),
        private readonly GatewayWindowsNamedPipeTransport $windowsPipeTransport =
            new GatewayWindowsNamedPipeTransport(),
    ) {
        if (!\is_finite($this->timeoutSeconds) || $this->timeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException(
                'WLS Gateway client timeout must be a positive finite number.',
            );
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function request(
        string $operation,
        array $payload = [],
        ?float $deadlineMonotonic = null,
    ): array
    {
        return $this->requestWithChannel(
            'admin',
            $operation,
            $payload,
            $deadlineMonotonic,
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function projectRequest(
        string $operation,
        array $payload = [],
        ?float $deadlineMonotonic = null,
    ): array
    {
        return $this->requestWithChannel(
            'project',
            $operation,
            $payload,
            $deadlineMonotonic,
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function requestWithChannel(
        string $channel,
        string $operation,
        array $payload,
        ?float $deadlineMonotonic,
    ): array
    {
        $normalizedOperation = \strtolower(\trim($operation));
        // A nullable caller deadline is a convenience API, not permission for
        // connect, partial writes, response reads and pagination to each open
        // a fresh timeout window. Materialize one absolute budget before the
        // first credential or endpoint read and carry it through every page.
        $deadlineMonotonic ??= (\hrtime(true) / 1_000_000_000)
            + \max(
                0.001,
                $this->responseTimeoutSeconds($channel, $normalizedOperation),
            );
        $response = $this->requestSingleWithChannel(
            $channel,
            $normalizedOperation,
            $payload,
            $deadlineMonotonic,
        );
        return $this->collectPaginatedResponse(
            $channel,
            $normalizedOperation,
            $payload,
            $response,
            $deadlineMonotonic,
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function requestSingleWithChannel(
        string $channel,
        string $operation,
        array $payload,
        ?float $deadlineMonotonic,
    ): array
    {
        $this->remainingDeadlineSeconds($deadlineMonotonic);
        if ($channel === 'admin') {
            $hostId = $this->trustedHostId();
            $secret = \strtolower(\trim($this->readStableRegularFile(
                $this->paths->adminTokenFile(),
                65,
                'Trusted WLS Gateway administrator credential',
            )));
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $secret) !== 1) {
                throw new \RuntimeException('Trusted WLS Gateway administrator credential is unavailable.');
            }
            $credentialId = 'admin';
        } else {
            $credential = $this->credentials->load(
                isset($payload['project_uuid']) ? (string)$payload['project_uuid'] : null,
            );
            $hostId = (string)$credential['host_id'];
            $secret = (string)$credential['secret'];
            $credentialId = (string)$credential['credential_id'];
            $payload['project_uuid'] ??= (string)$credential['project_uuid'];
        }
        $operation = \strtolower(\trim($operation));
        if (\preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $operation) !== 1) {
            throw new \InvalidArgumentException('WLS Gateway operation is invalid.');
        }
        try {
            $request = [
            'protocol' => GatewayPaths::PROTOCOL,
            'channel' => $channel,
            'host_id' => $hostId,
            'credential_id' => $credentialId,
            'operation' => $operation,
            'request_id' => \bin2hex(\random_bytes(16)),
            'timestamp' => \time(),
            'monotonic_timestamp' => \hrtime(true) / 1_000_000_000,
            'nonce' => \bin2hex(\random_bytes(16)),
            'payload' => $payload,
        ];
            $request['request_digest'] = \hash('sha256', self::canonicalJson([
                'operation' => $request['operation'],
                'payload' => $payload,
            ]));
            $request['signature'] = \hash_hmac(
                'sha256',
                self::canonicalJson($request),
                $secret,
            );
            $encoded = \json_encode(
                $request,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION,
            );
            if (!\is_string($encoded)
                || \strlen($encoded) + 1 > self::MAX_FRAME_BYTES
            ) {
                throw new \RuntimeException('WLS Gateway request exceeds its fixed frame limit.');
            }

            $endpoint = $this->paths->endpoint($channel);
            $errno = 0;
            $error = '';
            $connectTimeout = \min(
                $this->timeoutSeconds,
                $this->remainingDeadlineSeconds($deadlineMonotonic),
            );
            $responseTimeout = \min(
                $this->responseTimeoutSeconds($channel, $request['operation']),
                $this->remainingDeadlineSeconds($deadlineMonotonic),
            );
            if ($endpoint['transport'] === 'pipe') {
                $transportStarted = \hrtime(true) / 1_000_000_000;
                $transportDeadline = $transportStarted + $responseTimeout;
                if ($deadlineMonotonic !== null) {
                    $transportDeadline = \min(
                        $transportDeadline,
                        $deadlineMonotonic,
                    );
                }
                try {
                    $line = $this->windowsPipeTransport->exchange(
                        $channel,
                        $encoded . "\n",
                        self::MAX_FRAME_BYTES,
                        $transportDeadline,
                        $connectTimeout,
                    );
                } catch (GatewayWindowsNamedPipeTransportException $exception) {
                    if (!$exception->retryable()) {
                        throw $exception;
                    }
                    throw new \RuntimeException(
                        'WLS Gateway ' . $channel . ' endpoint unavailable: '
                            . 'native named-pipe transport did not return a frame.',
                        0,
                        $exception,
                    );
                }
            } else {
                $socket = @\stream_socket_client(
                    $endpoint['address'],
                    $errno,
                    $error,
                    $connectTimeout,
                    \STREAM_CLIENT_CONNECT,
                );
                if (!\is_resource($socket)) {
                    throw new \RuntimeException(
                        'WLS Gateway ' . $channel . ' endpoint unavailable: '
                        . ($error !== '' ? $error : (string)$errno)
                    );
                }
                try {
                    $this->setStreamDeadlineTimeout($socket, $responseTimeout);
                    if (!$this->writeAll(
                        $socket,
                        $encoded . "\n",
                        $deadlineMonotonic,
                        $this->responseTimeoutSeconds($channel, $request['operation']),
                    )) {
                        throw new \RuntimeException('Unable to send WLS Gateway request.');
                    }
                    $this->setStreamDeadlineTimeout(
                        $socket,
                        \min(
                            $this->responseTimeoutSeconds($channel, $request['operation']),
                            $this->remainingDeadlineSeconds($deadlineMonotonic),
                        ),
                    );
                    $line = @\fgets($socket, self::MAX_FRAME_BYTES + 1);
                } finally {
                    @\fclose($socket);
                }
            }
            if (!\is_string($line)
                || $line === ''
                || !\str_ends_with($line, "\n")
                || \strlen($line) > self::MAX_FRAME_BYTES
                || \trim($line) === ''
            ) {
                throw new \RuntimeException(self::UNPROVEN_RESPONSE_ERROR);
            }
            $response = \json_decode($line, true);
            if (!\is_array($response)
                || (string)($response['protocol'] ?? '') !== GatewayPaths::PROTOCOL
                || !\hash_equals((string)$request['request_id'], (string)($response['request_id'] ?? ''))
            ) {
                throw new \RuntimeException('WLS Gateway returned an invalid protocol response.');
            }
            $signature = \strtolower((string)($response['signature'] ?? ''));
            unset($response['signature']);
            $expected = \hash_hmac('sha256', self::canonicalJson($response), $secret);
            $authenticated = \preg_match('/\A[a-f0-9]{64}\z/D', $signature) === 1
                && \hash_equals($expected, $signature);
            if (!$authenticated && \preg_match('/\A[a-f0-9]{64}\z/D', $signature) === 1) {
                try {
                    // A host slot and a project can legitimately run adjacent
                    // PHP patch versions. Re-decoding a signed JSON float and
                    // encoding it with the other runtime can change its
                    // shortest decimal representation, even though the value
                    // is unchanged. Preserve numeric lexemes from the wire for
                    // this compatibility verification; all object keys are
                    // still recursively sorted and the HMAC remains mandatory.
                    $wireExpected = \hash_hmac(
                        'sha256',
                        self::canonicalResponseFromWire($line, $signature),
                        $secret,
                    );
                    $authenticated = \hash_equals($wireExpected, $signature);
                } catch (\Throwable) {
                    $authenticated = false;
                }
            }
            if (!$authenticated) {
                throw new \RuntimeException('WLS Gateway response authentication failed.');
            }
            $response['signature'] = $signature;
            return self::sanitizeAuthenticatedResponse($response, $channel, $request);
        } finally {
            if (isset($secret) && \function_exists('sodium_memzero')) {
                \sodium_memzero($secret);
            }
        }
    }

    /**
     * @param array<string,mixed> $requestPayload
     * @param array<string,mixed> $first
     * @return array<string,mixed>
     */
    private function collectPaginatedResponse(
        string $channel,
        string $operation,
        array $requestPayload,
        array $first,
        ?float $deadlineMonotonic,
    ): array {
        $payload = \is_array($first['payload'] ?? null) ? $first['payload'] : [];
        $page = \is_array($payload['page'] ?? null) ? $payload['page'] : null;
        if (($first['ok'] ?? false) !== true) {
            return $first;
        }
        $pageable = ($channel === 'admin' && $operation === 'routes')
            || ($channel === 'project' && $operation === 'own-status');
        if (!$pageable) {
            if ($page === null) {
                return $first;
            }
            throw new \RuntimeException(
                'WLS Gateway returned pagination metadata for a non-pageable operation.',
            );
        }
        if ($page === null) {
            // A bounded response-too-large receipt can legitimately preserve
            // the commit result of a mutation. Read operations have no such
            // alternate success shape: accepting an `ok=true` routes/status
            // receipt without its fenced page would turn an unavailable
            // projection into authenticated empty state.
            throw new \RuntimeException(
                'WLS Gateway omitted the mandatory fenced route page.',
            );
        }

        $collections = $operation === 'routes'
            ? ['routes']
            : ['active_routes', 'desired_routes'];
        $base = $payload;
        $initialCollections = $this->validatedPageCollections($payload, $collections);
        foreach ($initialCollections as $collection => $items) {
            $base[$collection] = $items;
        }
        $expectedScope = $operation === 'routes' ? 'admin-routes' : 'project-status';
        $expectedPrincipal = $operation === 'routes'
            ? 'admin'
            : (string)($payload['project_uuid'] ?? '');
        $expectedFence = $this->validatedPageFence(
            $first,
            $page,
            0,
            $expectedScope,
            $expectedPrincipal,
        );
        $expectedTotal = (int)($page['total'] ?? -1);
        $expectedPageLimit = (int)($page['limit'] ?? 0);
        $maximumRows = $operation === 'routes'
            ? self::MAX_ADMIN_ROUTE_ROWS
            : self::MAX_PROJECT_ROUTE_ROWS;
        if ($expectedTotal < 0 || $expectedTotal > $maximumRows) {
            throw new \RuntimeException('WLS Gateway route page total exceeds its protocol bound.');
        }
        $expectedOffset = $this->pageCollectionCount($initialCollections);
        $this->assertPageSliceContract($page, $expectedOffset);
        $cursor = (string)($page['next_cursor'] ?? '');
        $iterations = 0;
        while (($page['complete'] ?? false) !== true) {
            if ($cursor === '' || ++$iterations > 4096) {
                throw new \RuntimeException('WLS Gateway pagination did not terminate safely.');
            }
            $nextPayload = $requestPayload;
            $nextPayload['page_cursor'] = $cursor;
            $next = $this->requestSingleWithChannel(
                $channel,
                $operation,
                $nextPayload,
                $deadlineMonotonic,
            );
            if (($next['ok'] ?? false) !== true
                || !\is_array($next['payload'] ?? null)
                || !\is_array($next['payload']['page'] ?? null)
            ) {
                throw new \RuntimeException('WLS Gateway route pagination failed mid-generation.');
            }
            $nextPage = $next['payload']['page'];
            $fence = $this->validatedPageFence(
                $next,
                $nextPage,
                $expectedOffset,
                $expectedScope,
                $expectedPrincipal,
            );
            if (!\hash_equals(
                self::canonicalJson($expectedFence),
                self::canonicalJson($fence),
            )) {
                throw new \RuntimeException(
                    'WLS Gateway route pages crossed epoch, config, or project generation.',
                );
            }
            if ((int)($nextPage['total'] ?? -1) !== $expectedTotal
                || (int)($nextPage['limit'] ?? 0) !== $expectedPageLimit
            ) {
                throw new \RuntimeException(
                    'WLS Gateway route page total or limit changed within one result.',
                );
            }
            // Status pages intentionally contain live clock, lease counters,
            // operation progress and data-plane health. Those can change while
            // a large, generation-fenced route result is being collected. Only
            // compare the immutable protocol/project publication identity here;
            // the signed page fence already binds every route byte, host boot,
            // epoch and active configuration generation.
            $comparison = $this->paginationTrustMetadata($base);
            $nextBase = $this->paginationTrustMetadata($next['payload']);
            if (!\hash_equals(
                self::canonicalJson($comparison),
                self::canonicalJson($nextBase),
            )) {
                throw new \RuntimeException(
                    'WLS Gateway route page metadata changed within one result.',
                );
            }
            $nextCollections = $this->validatedPageCollections(
                $next['payload'],
                $collections,
            );
            $pageItemCount = $this->pageCollectionCount($nextCollections);
            $this->assertPageSliceContract($nextPage, $pageItemCount);
            foreach ($nextCollections as $collection => $items) {
                $base[$collection] = [...$base[$collection], ...$items];
            }
            $expectedOffset += $pageItemCount;
            $page = $nextPage;
            $nextCursor = (string)($page['next_cursor'] ?? '');
            if (($page['complete'] ?? false) !== true
                && ($nextCursor === '' || \hash_equals($cursor, $nextCursor))
            ) {
                throw new \RuntimeException('WLS Gateway route cursor did not advance.');
            }
            $cursor = $nextCursor;
        }
        if ($expectedOffset !== (int)($page['total'] ?? -1)) {
            throw new \RuntimeException('WLS Gateway route page total does not match collected rows.');
        }
        $collectedProjection = [];
        foreach ($collections as $collection) {
            $collectedProjection[$collection] = $base[$collection];
        }
        if (!\hash_equals(
            (string)$expectedFence['routes_digest'],
            \hash('sha256', self::canonicalJson($collectedProjection)),
        )) {
            throw new \RuntimeException('WLS Gateway collected routes do not match the page fence.');
        }
        if ((string)($page['next_cursor'] ?? '') !== '') {
            throw new \RuntimeException('WLS Gateway completed page retained a continuation cursor.');
        }
        $this->assertRouteProjectionCollections(
            $base,
            $collections,
            $operation === 'own-status' ? $expectedPrincipal : '',
        );
        // The returned collections are a client-local aggregation, not any
        // single signed wire page. Do not retain a page contract whose slice
        // no longer matches its limit, or the first page signature after its
        // payload has been changed. Every source page and the complete fenced
        // projection were authenticated above.
        unset($base['page'], $first['signature']);
        if ($operation === 'own-status') {
            // Compatibility is local-only. `routes` historically represented
            // the complete current project route state (including
            // PENDING_CERTIFICATE), while `active_routes` is the exact active
            // publication used by runtime/renewal acknowledgement consumers.
            $base['routes'] = $base['desired_routes'];
        }
        $first['payload'] = $base;
        return $first;
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $collections
     * @return array<string,list<array<string,mixed>>>
     */
    private function validatedPageCollections(array $payload, array $collections): array
    {
        $result = [];
        foreach ($collections as $collection) {
            $items = $payload[$collection] ?? null;
            if (!\is_array($items) || !\array_is_list($items)) {
                throw new \RuntimeException(
                    'WLS Gateway route page collection is missing or malformed.',
                );
            }
            foreach ($items as $item) {
                if (!\is_array($item) || \array_is_list($item)) {
                    throw new \RuntimeException(
                        'WLS Gateway route page contains a non-object route.',
                    );
                }
            }
            $result[$collection] = $items;
        }
        return $result;
    }

    /** @param array<string,list<array<string,mixed>>> $collections */
    private function pageCollectionCount(array $collections): int
    {
        $count = 0;
        foreach ($collections as $items) {
            $count += \count($items);
        }
        return $count;
    }

    /** @param array<string,mixed> $page */
    private function assertPageSliceContract(array $page, int $itemCount): void
    {
        $offset = (int)($page['offset'] ?? -1);
        $limit = (int)($page['limit'] ?? 0);
        $total = (int)($page['total'] ?? -1);
        $remaining = $total - $offset;
        $expectedCount = $remaining >= 0 ? \min($limit, $remaining) : -1;
        if ($offset < 0
            || $limit < 1
            || $total < 0
            || $itemCount !== $expectedCount
            || (($page['complete'] ?? null) !== ($offset + $itemCount === $total))
        ) {
            throw new \RuntimeException('WLS Gateway route page slice contract is invalid.');
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $collections
     */
    private function assertRouteProjectionCollections(
        array $payload,
        array $collections,
        string $expectedProjectUuid,
    ): void {
        foreach ($collections as $collection) {
            $routes = $payload[$collection] ?? null;
            if (!\is_array($routes) || !\array_is_list($routes)) {
                throw new \RuntimeException('WLS Gateway route projection is malformed.');
            }
            $byProjectDomain = [];
            $lastRouteId = '';
            foreach ($routes as $route) {
                if (!\is_array($route) || \array_is_list($route)) {
                    throw new \RuntimeException('WLS Gateway route projection is malformed.');
                }
                $this->assertExactProjectionFields($route, [
                    'route_id',
                    'project_uuid',
                    'instance_id',
                    'domain',
                    'backends',
                    'backend_instances',
                    'backend_identity',
                    'reseal_required',
                    'snapshot_receipt_schema_current',
                    'snapshot_receipt_schema_target',
                    're_register_required',
                    'certificate',
                    'route_generation',
                    'domain_security_generation',
                    'status',
                    'last_heartbeat',
                    'last_backend_probe',
                    'stale_since',
                    'drain_until',
                    'force_https',
                    'force_root_to_www',
                    'root_to_www_target',
                    'root_to_www_target_ready',
                    'updated_at',
                ], 'route');
                $routeId = (string)($route['route_id'] ?? '');
                $projectUuid = (string)($route['project_uuid'] ?? '');
                $instanceId = (string)($route['instance_id'] ?? '');
                $domain = (string)($route['domain'] ?? '');
                $status = (string)($route['status'] ?? '');
                if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                    || ($lastRouteId !== '' && \strcmp($lastRouteId, $routeId) >= 0)
                    || \preg_match(
                        '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                        $projectUuid,
                    ) !== 1
                    || ($expectedProjectUuid !== ''
                        && !\hash_equals($expectedProjectUuid, $projectUuid))
                    || ($instanceId !== '' && \preg_match(
                        '/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}\z/D',
                        $instanceId,
                    ) !== 1)
                    || !self::validWireDomain($domain)
                    || !\in_array($status, [
                        'PENDING_BACKEND',
                        'PENDING_CERTIFICATE',
                        'ACTIVE',
                        'DRAINING',
                        'STALE',
                        'REMOVED',
                    ], true)
                    || !\is_int($route['route_generation'] ?? null)
                    || (int)$route['route_generation'] < 1
                    || !\is_int($route['domain_security_generation'] ?? null)
                    || (int)$route['domain_security_generation'] < 0
                    || !\is_int($route['last_heartbeat'] ?? null)
                    || (int)$route['last_heartbeat'] < 0
                    || !\is_int($route['last_backend_probe'] ?? null)
                    || (int)$route['last_backend_probe'] < 0
                    || (($route['stale_since'] ?? null) !== null
                        && (!\is_int($route['stale_since'])
                            || (int)$route['stale_since'] < 0))
                    || (($route['drain_until'] ?? null) !== null
                        && (!\is_int($route['drain_until'])
                            || (int)$route['drain_until'] < 0))
                    || !\is_bool($route['force_https'] ?? null)
                    || !\is_bool($route['reseal_required'] ?? null)
                    || !\is_int($route['snapshot_receipt_schema_current'] ?? null)
                    || (int)$route['snapshot_receipt_schema_current'] < 0
                    || !\is_int($route['snapshot_receipt_schema_target'] ?? null)
                    || (int)$route['snapshot_receipt_schema_target'] < 1
                    || !\is_bool($route['re_register_required'] ?? null)
                    || !\is_bool($route['force_root_to_www'] ?? null)
                    || !\is_string($route['root_to_www_target'] ?? null)
                    || !\is_bool($route['root_to_www_target_ready'] ?? null)
                    || !\is_string($route['updated_at'] ?? null)
                ) {
                    throw new \RuntimeException('WLS Gateway route projection identity is invalid.');
                }
                $lastRouteId = $routeId;
                $backends = $this->assertWireBackends($route['backends']);
                $backendInstances = $this->assertWireBackendInstances(
                    $route['backend_instances'],
                    $projectUuid,
                );
                $identity = $route['backend_identity'];
                if (!\is_array($identity)
                    || (\array_is_list($identity) && $identity !== [])
                ) {
                    throw new \RuntimeException('WLS Gateway route listener identity is invalid.');
                }
                if ($identity !== []) {
                    $this->assertWireBackendIdentity($identity, $projectUuid, $instanceId);
                }
                $flattened = [];
                foreach ($backendInstances as $backendInstance) {
                    foreach ($backendInstance['backends'] as $backend) {
                        $flattened[] = $backend;
                    }
                }
                $preferredInstance = $backendInstances[
                    'instance:' . $instanceId
                ] ?? null;
                if (!\hash_equals(
                    self::canonicalJson($backends),
                    self::canonicalJson($flattened),
                ) || ($backendInstances !== []
                    && (!\is_array($preferredInstance)
                        || $identity === []
                        || !\hash_equals(
                            self::canonicalJson($identity),
                            self::canonicalJson(
                                $preferredInstance['backend_identity'] ?? null,
                            ),
                        )))
                ) {
                    throw new \RuntimeException(
                        'WLS Gateway route listener projection is not one exact transport closure.',
                    );
                }
                $certificate = $this->assertWireCertificate(
                    $route['certificate'],
                    $domain,
                );
                foreach ([
                    'reseal_required',
                    'snapshot_receipt_schema_current',
                    'snapshot_receipt_schema_target',
                ] as $receiptField) {
                    if (($route[$receiptField] ?? null)
                        !== ($certificate[$receiptField] ?? null)
                    ) {
                        throw new \RuntimeException(
                            'WLS Gateway route and certificate receipt projections differ.',
                        );
                    }
                }
                if ($status === 'ACTIVE'
                    && ($backends === []
                        || $backendInstances === []
                        || $identity === []
                        || ($certificate['valid'] ?? false) !== true)
                ) {
                    throw new \RuntimeException(
                        'WLS Gateway ACTIVE route projection has no complete serving closure.',
                    );
                }
                $forceRootToWww = (bool)$route['force_root_to_www'];
                $target = (string)$route['root_to_www_target'];
                if ((!$forceRootToWww
                        && ($target !== ''
                            || $route['root_to_www_target_ready'] !== true))
                    || ($forceRootToWww
                        && (\str_starts_with($domain, '*.')
                            || \str_starts_with($domain, 'www.')
                            || !\hash_equals('www.' . $domain, $target)))
                ) {
                    throw new \RuntimeException(
                        'WLS Gateway route redirect projection is malformed.',
                    );
                }
                $key = $projectUuid . "\0" . $domain;
                if (isset($byProjectDomain[$key])) {
                    throw new \RuntimeException(
                        'WLS Gateway route projection duplicates a project domain.',
                    );
                }
                $byProjectDomain[$key] = $route;
            }
            foreach ($routes as $route) {
                if (($route['force_root_to_www'] ?? false) !== true) {
                    continue;
                }
                $target = $byProjectDomain[
                    (string)$route['project_uuid'] . "\0"
                        . (string)$route['root_to_www_target']
                ] ?? null;
                $ready = \is_array($target)
                    && (string)($target['status'] ?? '') === 'ACTIVE'
                    && (($target['certificate']['valid'] ?? false) === true)
                    && (array)($target['backends'] ?? []) !== []
                    && (array)($target['backend_instances'] ?? []) !== [];
                if (($route['root_to_www_target_ready'] ?? null) !== $ready) {
                    throw new \RuntimeException(
                        'WLS Gateway redirect target is not ready in the same signed route collection.',
                    );
                }
            }
        }
    }

    /** @param array<string,mixed> $payload @param list<string> $expected */
    private function assertExactProjectionFields(
        array $payload,
        array $expected,
        string $label,
    ): void {
        $actual = \array_keys($payload);
        \sort($actual, SORT_STRING);
        \sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException('WLS Gateway ' . $label . ' projection fields changed.');
        }
    }

    private static function validWireDomain(string $domain): bool
    {
        if ($domain === ''
            || \strlen($domain) > 253
            || !\hash_equals($domain, \strtolower($domain))
            || \str_contains($domain, '..')
            || \preg_match('/\A(?:\*\.)?[a-z0-9.-]+\z/D', $domain) !== 1
        ) {
            return false;
        }
        $labels = \explode('.', \str_starts_with($domain, '*.')
            ? \substr($domain, 2)
            : $domain);
        foreach ($labels as $label) {
            if ($label === ''
                || \strlen($label) > 63
                || $label[0] === '-'
                || \str_ends_with($label, '-')
            ) {
                return false;
            }
        }
        return true;
    }

    /** @return list<array{host:string,port:int,weight:int}> */
    private function assertWireBackends(mixed $value): array
    {
        if (!\is_array($value)
            || !\array_is_list($value)
            || \count($value) > self::MAX_ROUTE_BACKENDS
        ) {
            throw new \RuntimeException('WLS Gateway backend projection is malformed.');
        }
        $seen = [];
        foreach ($value as $backend) {
            if (!\is_array($backend) || \array_is_list($backend)) {
                throw new \RuntimeException('WLS Gateway backend projection is malformed.');
            }
            $this->assertExactProjectionFields(
                $backend,
                ['host', 'port', 'weight'],
                'backend',
            );
            $host = $backend['host'] ?? null;
            $port = $backend['port'] ?? null;
            $weight = $backend['weight'] ?? null;
            $key = (string)$host . ':' . (string)$port;
            if (!\is_string($host)
                || !\in_array($host, ['127.0.0.1', '::1'], true)
                || !\is_int($port)
                || $port < 1
                || $port > 65535
                || !\is_int($weight)
                || $weight < 1
                || $weight > 1000
                || isset($seen[$key])
            ) {
                throw new \RuntimeException('WLS Gateway backend projection is invalid.');
            }
            $seen[$key] = true;
        }
        return $value;
    }

    /**
     * @return array<string,array{instance_id:string,backends:list<array{host:string,port:int,weight:int}>,backend_identity:array<string,mixed>}>
     */
    private function assertWireBackendInstances(
        mixed $value,
        string $projectUuid,
    ): array {
        if (!\is_array($value)
            || !\array_is_list($value)
            || \count($value) > self::MAX_ROUTE_BACKENDS
        ) {
            throw new \RuntimeException('WLS Gateway backend-instance projection is malformed.');
        }
        $indexed = [];
        $backendCount = 0;
        $lastInstanceId = '';
        foreach ($value as $instance) {
            if (!\is_array($instance)
                || \array_is_list($instance)
            ) {
                throw new \RuntimeException('WLS Gateway backend-instance projection is invalid.');
            }
            $this->assertExactProjectionFields(
                $instance,
                ['instance_id', 'backends', 'backend_identity'],
                'backend-instance',
            );
            $instanceId = $instance['instance_id'] ?? null;
            if (!\is_string($instanceId)
                || \preg_match(
                    '/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}\z/D',
                    $instanceId,
                ) !== 1
                || ($lastInstanceId !== '' && \strcmp($lastInstanceId, $instanceId) >= 0)
            ) {
                throw new \RuntimeException('WLS Gateway backend-instance projection is invalid.');
            }
            $backends = $this->assertWireBackends($instance['backends'] ?? null);
            $identity = $instance['backend_identity'] ?? null;
            $backendCount += \count($backends);
            if ($backends === []
                || $backendCount > self::MAX_ROUTE_BACKENDS
                || !\is_array($identity)
                || (\array_is_list($identity) && $identity !== [])
                || $identity === []
            ) {
                throw new \RuntimeException('WLS Gateway backend-instance closure is invalid.');
            }
            $this->assertWireBackendIdentity($identity, $projectUuid, $instanceId);
            $indexed['instance:' . $instanceId] = $instance;
            $lastInstanceId = $instanceId;
        }
        return $indexed;
    }

    /** @param array<string,mixed> $identity */
    private function assertWireBackendIdentity(
        array $identity,
        string $projectUuid,
        string $instanceId,
    ): void {
        $expectedFields = [
            'schema',
            'project_uuid',
            'instance_id',
            'generation',
            'master_pid',
            'master_epoch',
            'launch_id',
            'listener_lease_id',
            'edge_capability_digest',
            'session_capability',
            'public_digest',
        ];
        $mode = (string)($identity['session_capability'] ?? '');
        $evidencePresent = \array_key_exists('session_capability_evidence', $identity);
        $evidenceDigestPresent = \array_key_exists(
            'session_capability_evidence_digest',
            $identity,
        );
        if ($mode !== 'isolated') {
            $expectedFields[] = 'session_capability_evidence';
            $expectedFields[] = 'session_capability_evidence_digest';
        }
        $this->assertExactProjectionFields(
            $identity,
            $expectedFields,
            'public-listener-identity',
        );
        $publicDigest = (string)($identity['public_digest'] ?? '');
        $digestFacts = $identity;
        unset($digestFacts['public_digest']);
        if (!\hash_equals('wls-backend-listener-identity/2', (string)($identity['schema'] ?? ''))
            || !\hash_equals($projectUuid, (string)($identity['project_uuid'] ?? ''))
            || !\hash_equals($instanceId, (string)($identity['instance_id'] ?? ''))
            || !\is_int($identity['generation'] ?? null)
            || (int)$identity['generation'] < 1
            || !\is_int($identity['master_pid'] ?? null)
            || (int)$identity['master_pid'] < 1
            || !\is_int($identity['master_epoch'] ?? null)
            || (int)$identity['master_epoch'] < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($identity['launch_id'] ?? '')) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($identity['listener_lease_id'] ?? '')) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($identity['edge_capability_digest'] ?? '')) !== 1
            || !\in_array($mode, ['isolated', 'stateless', 'shared_session'], true)
            || ($mode === 'isolated' && ($evidencePresent || $evidenceDigestPresent))
            || ($mode !== 'isolated' && (!$evidencePresent || !$evidenceDigestPresent))
            || ($evidencePresent && !\is_array($identity['session_capability_evidence']))
            || ($evidenceDigestPresent && \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)$identity['session_capability_evidence_digest'],
            ) !== 1)
            || ($evidencePresent && !\hash_equals(
                (string)$identity['session_capability_evidence_digest'],
                \hash('sha256', self::canonicalJson($identity['session_capability_evidence'])),
            ))
            || \preg_match('/\A[a-f0-9]{64}\z/D', $publicDigest) !== 1
            || !\hash_equals(
                $publicDigest,
                \hash('sha256', self::canonicalJson($digestFacts)),
            )
        ) {
            throw new \RuntimeException('WLS Gateway public listener identity is invalid.');
        }
        if ($mode !== 'isolated') {
            $this->assertWireCapabilityEvidence(
                $identity['session_capability_evidence'],
                $mode,
                (int)$identity['generation'],
            );
        }
    }

    /** @param 'stateless'|'shared_session' $mode */
    private function assertWireCapabilityEvidence(
        array $evidence,
        string $mode,
        int $instanceGeneration,
    ): void {
        if ($mode === 'stateless') {
            $this->assertExactProjectionFields($evidence, [
                'schema',
                'runtime_source',
                'runtime_declared',
                'instance_generation',
                'reason',
            ], 'stateless-capability-evidence');
            if (!\hash_equals(
                    'wls-stateless-capability/1',
                    (string)($evidence['schema'] ?? ''),
                )
                || !\hash_equals(
                    'project_endpoint',
                    (string)($evidence['runtime_source'] ?? ''),
                )
                || ($evidence['runtime_declared'] ?? null) !== true
                || !\is_int($evidence['instance_generation'] ?? null)
                || (int)$evidence['instance_generation'] !== $instanceGeneration
                || !\hash_equals(
                    'declared_stateless_runtime',
                    (string)($evidence['reason'] ?? ''),
                )
            ) {
                throw new \RuntimeException(
                    'WLS Gateway stateless capability evidence is invalid.',
                );
            }
            return;
        }

        $this->assertExactProjectionFields($evidence, [
            'schema',
            'storage',
            'runtime_source',
            'runtime_registered',
            'runtime_shared_service',
            'host',
            'port',
            'token_scope_digest',
            'probe',
            'reason',
        ], 'shared-session-capability-evidence');
        if (!\hash_equals(
                'wls-session-capability/1',
                (string)($evidence['schema'] ?? ''),
            )
            || !\hash_equals('wls', (string)($evidence['storage'] ?? ''))
            || !\hash_equals(
                'project_shared_state',
                (string)($evidence['runtime_source'] ?? ''),
            )
            || ($evidence['runtime_registered'] ?? null) !== true
            || ($evidence['runtime_shared_service'] ?? null) !== true
            || !\in_array(
                \strtolower(\trim((string)($evidence['host'] ?? ''))),
                ['127.0.0.1', '::1', 'localhost'],
                true,
            )
            || !\is_int($evidence['port'] ?? null)
            || (int)$evidence['port'] < 1
            || (int)$evidence['port'] > 65535
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($evidence['token_scope_digest'] ?? ''),
            ) !== 1
            || !\hash_equals('healthy', (string)($evidence['probe'] ?? ''))
            || !\hash_equals(
                'authenticated_session_runtime',
                (string)($evidence['reason'] ?? ''),
            )
        ) {
            throw new \RuntimeException(
                'WLS Gateway shared-session capability evidence is invalid.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function assertWireCertificate(mixed $value, string $domain): array
    {
        if (!\is_array($value) || \array_is_list($value)) {
            throw new \RuntimeException('WLS Gateway certificate projection is malformed.');
        }
        $this->assertExactProjectionFields($value, [
            'state',
            'valid',
            'pending',
            'source_digest',
            'trust_profile',
            'provider',
            'material_class',
            'provenance_digest',
            'snapshot_digest',
            'snapshot_manifest_schema',
            'snapshot_manifest_sha256',
            'snapshot_receipt_schema_current',
            'snapshot_receipt_schema_target',
            'reseal_required',
            'leaf_fingerprint_sha256',
            'san_names',
            'generation',
            'not_before',
            'not_after',
        ], 'certificate');
        $sourceDigest = $value['source_digest'] ?? null;
        $snapshotDigest = $value['snapshot_digest'] ?? null;
        $state = $value['state'] ?? null;
        $trustProfile = \strtolower(\trim((string)($value['trust_profile'] ?? '')));
        $provider = \strtolower(\trim((string)($value['provider'] ?? '')));
        $materialClass = \strtolower(\trim((string)(
            $value['material_class'] ?? ''
        )));
        $provenanceDigest = \strtolower(\trim((string)(
            $value['provenance_digest'] ?? ''
        )));
        $generation = (int)($value['generation'] ?? -1);
        $activeProvenanceValid = false;
        $inactiveProvenanceValid = false;
        try {
            $trustProfile = ProjectCertificateGenerationStore::normalizeTrustProfile(
                $trustProfile,
            );
            if ($state === 'active') {
                $provider = ProjectCertificateGenerationStore::normalizeProvider($provider);
                $activeProvenanceValid = \hash_equals(
                    ProjectCertificateGenerationStore::provenanceDigest(
                        $domain,
                        (string)$sourceDigest,
                        $trustProfile,
                        $provider,
                        $materialClass,
                    ),
                    $provenanceDigest,
                ) && ($trustProfile
                        !== ProjectCertificateGenerationStore::TRUST_PROFILE_PRODUCTION
                    || $materialClass
                        === ProjectCertificateGenerationStore::MATERIAL_CLASS_PUBLIC_TRUST);
            } elseif (\in_array($state, ['pending', 'disabled'], true)
                && $provider === 'none'
                && $materialClass === 'none'
            ) {
                $inactiveProvenanceValid = \hash_equals(
                    ProjectCertificateGenerationStore::inactiveProvenanceDigest(
                        $domain,
                        (string)$state,
                        (string)$sourceDigest,
                        $generation,
                        $trustProfile,
                    ),
                    $provenanceDigest,
                );
            }
        } catch (\Throwable) {
            $activeProvenanceValid = false;
            $inactiveProvenanceValid = false;
        }
        if (!\is_string($state)
            || !\in_array($state, ['active', 'pending', 'disabled'], true)
            || !\is_bool($value['valid'] ?? null)
            || !\is_bool($value['pending'] ?? null)
            || !\is_string($sourceDigest)
            || ($sourceDigest !== ''
                && \preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1)
            || !\is_string($snapshotDigest)
            || ($snapshotDigest !== ''
                && \preg_match('/\A[a-f0-9]{64}\z/D', $snapshotDigest) !== 1)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $provenanceDigest) !== 1
            || ($state === 'active' ? !$activeProvenanceValid : !$inactiveProvenanceValid)
            || !\is_int($value['snapshot_manifest_schema'] ?? null)
            || (int)$value['snapshot_manifest_schema'] < 0
            || !\is_string($value['snapshot_manifest_sha256'] ?? null)
            || ((string)$value['snapshot_manifest_sha256'] !== ''
                && \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    (string)$value['snapshot_manifest_sha256'],
                ) !== 1)
            || !\is_int($value['snapshot_receipt_schema_current'] ?? null)
            || (int)$value['snapshot_receipt_schema_current'] < 0
            || !\is_int($value['snapshot_receipt_schema_target'] ?? null)
            || (int)$value['snapshot_receipt_schema_target'] < 1
            || !\is_bool($value['reseal_required'] ?? null)
            || !\is_string($value['leaf_fingerprint_sha256'] ?? null)
            || ((string)$value['leaf_fingerprint_sha256'] !== ''
                && \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    (string)$value['leaf_fingerprint_sha256'],
                ) !== 1)
            || !\is_array($value['san_names'] ?? null)
            || !\array_is_list($value['san_names'])
            || !\is_int($value['generation'] ?? null)
            || $generation < 0
            || !\is_int($value['not_before'] ?? null)
            || (int)$value['not_before'] < 0
            || !\is_int($value['not_after'] ?? null)
            || (int)$value['not_after'] < 0
            || ($state === 'active'
                && (($value['valid'] ?? false) !== true
                    || ($value['pending'] ?? true) !== false
                    || $generation < 1
                    || (int)$value['snapshot_manifest_schema'] < 1
                    || (string)$value['snapshot_manifest_sha256'] === ''
                    || (string)$value['leaf_fingerprint_sha256'] === ''
                    || (int)$value['not_before'] < 1))
            || ($state === 'pending'
                && (($value['valid'] ?? true) !== false
                    || ($value['pending'] ?? false) !== true
                    || (int)$value['generation'] !== 0
                    || $snapshotDigest !== ''
                    || (int)$value['snapshot_manifest_schema'] !== 0
                    || (string)$value['snapshot_manifest_sha256'] !== ''
                    || (string)$value['leaf_fingerprint_sha256'] !== ''
                    || $value['san_names'] !== []
                    || (int)$value['not_before'] !== 0
                    || (int)$value['not_after'] !== 0))
            || ($state === 'disabled'
                && (($value['valid'] ?? true) !== false
                    || ($value['pending'] ?? false) !== true
                    || (int)$value['generation'] < 1
                    || $snapshotDigest !== ''
                    || (int)$value['snapshot_manifest_schema'] !== 0
                    || (string)$value['snapshot_manifest_sha256'] !== ''
                    || (string)$value['leaf_fingerprint_sha256'] !== ''
                    || $value['san_names'] !== []
                    || (int)$value['not_before'] !== 0
                    || (int)$value['not_after'] !== 0))
            || (($value['valid'] ?? false) === true
                && (\preg_match('/\A[a-f0-9]{64}\z/D', $sourceDigest) !== 1
                    || \preg_match('/\A[a-f0-9]{64}\z/D', $snapshotDigest) !== 1
                    || (int)$value['generation'] < 1
                    || (int)$value['not_after'] < 1))
        ) {
            throw new \RuntimeException('WLS Gateway certificate projection is invalid.');
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $response
     * @param array<string,mixed> $page
     * @return array<string,mixed>
     */
    private function validatedPageFence(
        array $response,
        array $page,
        int $expectedOffset,
        string $expectedScope,
        string $expectedPrincipal,
    ): array {
        $fence = \is_array($page['fence'] ?? null) ? $page['fence'] : [];
        if (\array_is_list($page) || \array_is_list($fence)) {
            throw new \RuntimeException('WLS Gateway returned invalid route page fencing.');
        }
        $this->assertExactProjectionFields($page, [
            'schema',
            'fence',
            'fence_digest',
            'offset',
            'limit',
            'total',
            'complete',
            'next_cursor',
        ], 'route-page');
        $expectedFenceFields = [
            'host_boot_id',
            'epoch',
            'generation',
            'active_config_generation',
            'active_config_digest',
            'principal',
            'scope',
            'routes_digest',
        ];
        if (!\hash_equals('admin', $expectedPrincipal)) {
            $expectedFenceFields[] = 'project_generation';
            $expectedFenceFields[] = 'project_digest';
        }
        $this->assertExactProjectionFields($fence, $expectedFenceFields, 'route-page-fence');
        $digest = $page['fence_digest'] ?? null;
        $payload = \is_array($response['payload'] ?? null)
            ? $response['payload']
            : [];
        $payloadHostBootId = $payload['host_boot_id'] ?? null;
        $responseEpoch = $response['epoch'] ?? null;
        $fenceEpoch = $fence['epoch'] ?? null;
        $nextCursor = $page['next_cursor'] ?? null;
        if (($page['schema'] ?? null) !== 1
            || !\is_int($page['offset'] ?? null)
            || $page['offset'] !== $expectedOffset
            || !\is_int($page['limit'] ?? null)
            || $page['limit'] < 1
            || $page['limit'] > 128
            || !\is_int($page['total'] ?? null)
            || $page['total'] < 0
            || !\is_bool($page['complete'] ?? null)
            || !\is_string($nextCursor)
            || (($page['complete'] ?? false) === true
                ? $nextCursor !== ''
                : \preg_match('/\A[A-Za-z0-9_-]{1,1024}\z/D', $nextCursor) !== 1)
            || !\is_string($digest)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\hash_equals($digest, \hash('sha256', self::canonicalJson($fence)))
            || !\is_string($responseEpoch)
            || \preg_match('/\A[a-f0-9]{32}\z/D', $responseEpoch) !== 1
            || !\is_string($fenceEpoch)
            || \preg_match('/\A[a-f0-9]{32}\z/D', $fenceEpoch) !== 1
            || !\hash_equals($responseEpoch, $fenceEpoch)
            || !\is_string($fence['scope'] ?? null)
            || !\hash_equals($expectedScope, $fence['scope'])
            || !\is_string($fence['principal'] ?? null)
            || !\hash_equals($expectedPrincipal, $fence['principal'])
            || !\is_string($payloadHostBootId)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $payloadHostBootId) !== 1
            || !\is_string($fence['host_boot_id'] ?? null)
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                $fence['host_boot_id'],
            ) !== 1
            || !\hash_equals(
                $payloadHostBootId,
                (string)$fence['host_boot_id'],
            )
            || !\is_int($fence['generation'] ?? null)
            || (int)$fence['generation'] < 0
            || !\is_int($fence['active_config_generation'] ?? null)
            || (int)$fence['active_config_generation'] < 0
            || !\is_string($fence['active_config_digest'] ?? null)
            || ((int)$fence['active_config_generation'] === 0
                ? $fence['active_config_digest'] !== ''
                : \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    $fence['active_config_digest'],
                ) !== 1)
            || !\is_string($fence['routes_digest'] ?? null)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $fence['routes_digest']) !== 1
            || (!\hash_equals('admin', $expectedPrincipal)
                && (!\is_int($fence['project_generation'] ?? null)
                    || (int)$fence['project_generation'] < 0
                    || !\is_string($fence['project_digest'] ?? null)
                    || ((int)$fence['project_generation'] === 0
                        ? $fence['project_digest'] !== ''
                        : \preg_match(
                            '/\A[a-f0-9]{64}\z/D',
                            $fence['project_digest'],
                        ) !== 1)))
        ) {
            throw new \RuntimeException('WLS Gateway returned invalid route page fencing.');
        }
        return $fence;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function paginationTrustMetadata(array $payload): array
    {
        $metadata = [];
        foreach ([
            'protocol',
            'host_boot_id',
            'protocol_min',
            'protocol_max',
            'implementation_level',
            'security_profile',
            'release_ready',
            'epoch',
            'generation',
            'active_config_generation',
            'active_config_digest',
            'public_http',
            'public_https',
            'project_uuid',
            'project_generation',
            'request_digest',
            'non_certificate_desired_digest',
            'idempotency_key',
        ] as $field) {
            if (\array_key_exists($field, $payload)) {
                $metadata[$field] = $payload[$field];
            }
        }
        return $metadata;
    }

    /** @param resource $stream */
    private function writeAll(
        $stream,
        string $contents,
        ?float $deadlineMonotonic,
        float $maximumIoSeconds,
    ): bool
    {
        $offset = 0;
        $length = \strlen($contents);
        while ($offset < $length) {
            $this->setStreamDeadlineTimeout(
                $stream,
                \min(
                    $maximumIoSeconds,
                    $this->remainingDeadlineSeconds($deadlineMonotonic),
                ),
            );
            $written = @\fwrite($stream, \substr($contents, $offset));
            if (!\is_int($written) || $written < 1) {
                return false;
            }
            $offset += $written;
        }
        return true;
    }

    private function remainingDeadlineSeconds(?float $deadlineMonotonic): float
    {
        if ($deadlineMonotonic === null) {
            return \max(
                0.001,
                $this->timeoutSeconds,
                self::LONG_ADMIN_RESPONSE_TIMEOUT_SECONDS,
                self::LONG_PROJECT_MUTATION_RESPONSE_TIMEOUT_SECONDS,
            );
        }
        if (!\is_finite($deadlineMonotonic)) {
            throw new \RuntimeException('WLS Gateway request deadline is invalid.');
        }
        $remaining = $deadlineMonotonic - (\hrtime(true) / 1_000_000_000);
        if ($remaining <= 0.0) {
            throw new \RuntimeException('WLS Gateway request deadline was exhausted.');
        }
        return $remaining;
    }

    /** @param resource $stream */
    private function setStreamDeadlineTimeout($stream, float $seconds): void
    {
        if (!\is_finite($seconds) || $seconds <= 0.0) {
            throw new \RuntimeException('WLS Gateway stream deadline is invalid.');
        }
        $wholeSeconds = (int)\floor($seconds);
        $microseconds = (int)\floor(($seconds - $wholeSeconds) * 1_000_000);
        if ($wholeSeconds === 0 && $microseconds === 0) {
            $microseconds = 1;
        }
        if (!@\stream_set_timeout($stream, $wholeSeconds, $microseconds)) {
            throw new \RuntimeException('Unable to apply the WLS Gateway stream deadline.');
        }
    }

    private function responseTimeoutSeconds(string $channel, string $operation): float
    {
        if ($channel === 'admin'
            && \in_array($operation, ['repair', 'revoke', 'transfer', 'upgrade'], true)
        ) {
            // These administrator mutations may synchronously validate a
            // candidate, run the full activation probe window, and publish or
            // roll back before replying. Keep endpoint connection failures
            // bounded by timeoutSeconds, but preserve the authenticated result
            // across the complete publication transaction.
            return \max($this->timeoutSeconds, self::LONG_ADMIN_RESPONSE_TIMEOUT_SECONDS);
        }
        if ($channel === 'project'
            && \in_array($operation, [
                'register',
                'renew',
                'drain',
                'unregister',
                'rotate-prepare',
                'rotate-commit',
                'rotate-finalize',
                'rotate-abort',
                'rotate-status',
            ], true)
        ) {
            // Project mutations may synchronously publish and validate a new
            // Nginx generation. A two-second read timeout caused the caller to
            // replay the same envelope while the first transaction was still
            // running, filling the Broker handler pool and starving heartbeat
            // and subsequent registration requests. Keep connect failures
            // short, but wait for one authoritative authenticated result.
            return \max(
                $this->timeoutSeconds,
                self::LONG_PROJECT_MUTATION_RESPONSE_TIMEOUT_SECONDS,
            );
        }

        return $this->timeoutSeconds;
    }

    /**
     * @return array<string,mixed>
     */
    public function status(?float $deadlineMonotonic = null): array
    {
        return $this->projectRequest('own-status', [], $deadlineMonotonic);
    }

    /**
     * @return array<string,mixed>
     */
    public function administratorStatus(?float $deadlineMonotonic = null): array
    {
        return $this->request('status', [], $deadlineMonotonic);
    }

    /**
     * Recursively key-sort JSON objects so the client and standalone
     * controller sign exactly the same bytes.
     */
    public static function canonicalJson(mixed $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!\is_array($item)) {
                return $item;
            }
            if (!\array_is_list($item)) {
                \ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }
            return $item;
        };
        $encoded = \json_encode(
            $normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
        if (!\is_string($encoded)) {
            throw new \RuntimeException('Unable to canonicalize WLS Gateway request.');
        }
        return $encoded;
    }

    /**
     * Canonicalize a controller response without changing JSON number
     * spellings. This is a verification-only compatibility path for signed
     * responses produced by another PHP patch version.
     */
    private static function canonicalResponseFromWire(
        string $encodedResponse,
        string $expectedSignature,
    ): string {
        $marker = '';
        do {
            $marker = '__wls_edge_number_' . \bin2hex(\random_bytes(12)) . '_';
        } while (\str_contains($encodedResponse, $marker));

        $masked = '';
        $replacements = [];
        $length = \strlen($encodedResponse);
        $insideString = false;
        $escaped = false;
        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $encodedResponse[$offset];
            if ($insideString) {
                $masked .= $character;
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $insideString = false;
                }
                continue;
            }
            if ($character === '"') {
                $insideString = true;
                $masked .= $character;
                continue;
            }
            if ($character !== '-' && ($character < '0' || $character > '9')) {
                $masked .= $character;
                continue;
            }
            if (\preg_match(
                '/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+\-]?[0-9]+)?/',
                \substr($encodedResponse, $offset),
                $match,
            ) !== 1) {
                throw new \RuntimeException('WLS Gateway response contains an invalid number.');
            }
            $number = (string)$match[0];
            $placeholder = $marker . \count($replacements);
            $encodedPlaceholder = '"' . $placeholder . '"';
            $masked .= $encodedPlaceholder;
            $replacements[$encodedPlaceholder] = $number;
            $offset += \strlen($number) - 1;
        }

        $document = \json_decode($masked, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($document)
            || !\hash_equals($expectedSignature, (string)($document['signature'] ?? ''))
        ) {
            throw new \RuntimeException('WLS Gateway wire response signature is invalid.');
        }
        unset($document['signature']);
        return \strtr(self::canonicalJson($document), $replacements);
    }

    /**
     * @param array<string,mixed> $response
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private static function sanitizeAuthenticatedResponse(
        array $response,
        string $channel,
        array $request,
    ): array {
        $enrollmentCredential = null;
        $operation = (string)($request['operation'] ?? '');
        $requestPayload = \is_array($request['payload'] ?? null) ? $request['payload'] : [];
        $responsePayload = \is_array($response['payload'] ?? null) ? $response['payload'] : [];
        $credential = \is_array($responsePayload['credential'] ?? null)
            ? $responsePayload['credential']
            : [];
        $projectUuid = \strtolower(\trim((string)($requestPayload['project_uuid'] ?? '')));
        $credentialProjectUuid = $channel === 'project'
            && $operation === 'rotate-prepare'
                ? \strtolower(\trim((string)(
                    $requestPayload['new_project_uuid'] ?? ''
                )))
                : $projectUuid;
        $oneTimeCredentialResponse = ($channel === 'admin' && $operation === 'enroll')
            || ($channel === 'project' && $operation === 'rotate-prepare');
        if ($oneTimeCredentialResponse
            && ($response['ok'] ?? false) === true
            && (int)($credential['schema_version'] ?? 0) === 1
            && \hash_equals(GatewayPaths::PROTOCOL, (string)($credential['protocol'] ?? ''))
            && \hash_equals((string)($request['host_id'] ?? ''), (string)($credential['host_id'] ?? ''))
            && \preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $credentialProjectUuid,
            ) === 1
            && \hash_equals(
                $credentialProjectUuid,
                (string)($credential['project_uuid'] ?? ''),
            )
            && \preg_match('/\A[a-f0-9]{32}\z/D', (string)($credential['credential_id'] ?? '')) === 1
            && \is_int($credential['credential_generation'] ?? null)
            && (int)$credential['credential_generation'] >= 1
            && \preg_match('/\A[a-f0-9]{64}\z/D', (string)($credential['secret'] ?? '')) === 1
        ) {
            // Preserve only the exact one-time enrollment/rotation structure
            // required by GatewayCredentialStore. Unknown fields from an
            // older slot never cross the authenticated client boundary.
            $enrollmentCredential = [
                'schema_version' => 1,
                'protocol' => GatewayPaths::PROTOCOL,
                'host_id' => (string)$credential['host_id'],
                'project_uuid' => $credentialProjectUuid,
                'credential_id' => (string)$credential['credential_id'],
                'credential_generation' => (int)$credential['credential_generation'],
                'secret' => (string)$credential['secret'],
                'issued_at' => (string)($credential['issued_at'] ?? ''),
            ];
        }

        $sanitized = GatewaySensitivePayloadSanitizer::sanitize($response);
        if (!\is_array($sanitized)) {
            throw new \RuntimeException('WLS Gateway response sanitization failed.');
        }
        if ($enrollmentCredential !== null) {
            $sanitized['payload'] = \is_array($sanitized['payload'] ?? null)
                ? $sanitized['payload']
                : [];
            $sanitized['payload']['credential'] = $enrollmentCredential;
        }
        return $sanitized;
    }

    private function trustedHostId(): string
    {
        return $this->credentials->hostId();
    }

    private function readStableRegularFile(
        string $path,
        int $maximumBytes,
        string $label,
    ): string {
        $before = @\lstat($path);
        if (!\is_array($before)
            || \is_link($path)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($before['nlink'] ?? 0) !== 1
            || (int)($before['size'] ?? -1) < 1
            || (int)($before['size'] ?? -1) > $maximumBytes
        ) {
            throw new \RuntimeException($label . ' is unavailable or unsafe.');
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open ' . $label . '.');
        }
        try {
            $opened = @\fstat($handle);
            $contents = @\stream_get_contents($handle, $maximumBytes + 1);
            $after = @\fstat($handle);
            $pathAfter = @\lstat($path);
            if (!\is_array($opened)
                || !\is_string($contents)
                || \strlen($contents) > $maximumBytes
                || !\is_array($after)
                || !\is_array($pathAfter)
                || !$this->sameFileState($before, $opened)
                || !$this->sameFileState($opened, $after)
                || !$this->sameFileState($after, $pathAfter)
            ) {
                throw new \RuntimeException($label . ' changed while being read.');
            }
            return $contents;
        } finally {
            @\fclose($handle);
        }
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function sameFileState(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
            if (!\array_key_exists($key, $before)
                || !\array_key_exists($key, $after)
                || (int)$before[$key] !== (int)$after[$key]
            ) {
                return false;
            }
        }
        return true;
    }
}
