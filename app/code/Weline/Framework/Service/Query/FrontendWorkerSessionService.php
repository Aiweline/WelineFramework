<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Adapter\RedisAdapter;
use Weline\Framework\Cache\AdapterFactory;
use Weline\Framework\Cache\Contract\AtomicCacheAdapterInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Service\Query\Store\ArrayFrontendWorkerCredentialTransaction;
use Weline\Framework\Service\Query\Store\AtomicCacheFrontendWorkerStateStore;
use Weline\Framework\Service\Query\Store\DatabaseFrontendWorkerCredentialStore;
use Weline\Framework\Service\Query\Store\FrontendWorkerCredentialStoreInterface;
use Weline\Framework\Service\Query\Store\FrontendWorkerCredentialTransactionInterface;
use Weline\Framework\Service\Query\Store\FrontendWorkerCredentialType;
use Weline\Framework\Service\Query\Store\FrontendWorkerStateStoreInterface;
use Weline\Framework\Service\Query\Value\FrontendWorkerBackendBinding;
use Weline\Framework\Service\Query\Value\FrontendWorkerExecutionContext;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeBinding;

final class FrontendWorkerSessionService
{
    private const SESSION_KEY = 'weline_frontend_worker_sessions';
    private const NONCE_KEY = 'weline_frontend_worker_nonces';
    private const STREAM_TICKET_KEY = 'weline_frontend_worker_stream_tickets';
    private const SCOPE_BOOTSTRAP_KEY = 'weline_frontend_worker_scope_bootstraps';
    private const BACKEND_BOOTSTRAP_KEY = 'weline_frontend_worker_backend_bootstraps';
    // Backend pages (AI workbench SSE) stay open far longer than unbound
    // storefront handshakes. Keep this aligned with Backend BINDING_TTL so
    // runtime_rotate ticket refresh does not strand a live detached task.
    private const SESSION_TTL = 7200;
    private const NONCE_TTL = 180;
    private const STREAM_TICKET_TTL = 60;
    private const SCOPE_BOOTSTRAP_TTL = 120;
    private const MAX_SCOPE_BINDING_TTL = 1800;
    private const CLEANUP_INTERVAL_SECONDS = 15;
    private const STORE_FORCE_CLEANUP_BYTES = 7340032;
    private const MAX_STORE_BYTES = 8388608;
    private const MAX_STREAM_TICKET_BYTES = 262144;
    private const DEFAULT_LOCK_TIMEOUT_MS = 120;
    private const MAX_ACTIVE_SESSIONS = 4096;
    private const MAX_NONCES_PER_SCOPE = 4096;
    private const MAX_NONCES_PER_CAPACITY_BUCKET = 32768;
    private const MAX_ACTIVE_STREAM_TICKETS = 4096;
    private const MAX_ACTIVE_SCOPE_BOOTSTRAPS = 4096;
    private const MAX_ACTIVE_BACKEND_BOOTSTRAPS = 4096;
    private const STORE_DIRECTORY = BP . 'var' . DS . 'cache' . DS . 'frontend_worker' . DS;
    private const STORE_FILE = self::STORE_DIRECTORY . 'store.json';
    private const LOCK_FILE = self::STORE_DIRECTORY . 'store.lock';
    private const META_KEY = '_meta';
    public const SCOPE_BOOTSTRAP_COOKIE_PREFIX = '__Host-Weline-Worker-Scope-Bootstrap-';
    public const BACKEND_BOOTSTRAP_SECURE_COOKIE_PREFIX = '__Host-Weline-Worker-Backend-Bootstrap-';
    public const BACKEND_BOOTSTRAP_DEV_COOKIE_PREFIX = 'Weline-Worker-Backend-Bootstrap-';
    private const OPAQUE_ID_PATTERN = '/^[A-Za-z0-9_-]{43}$/D';
    private const SHA256_PATTERN = '/^[a-f0-9]{64}$/D';
    private const RUNTIME_IDENTIFIER_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:+-]{0,127}$/D';

    private FrontendWorkerStateStoreInterface|FrontendWorkerCredentialStoreInterface|null $stateStore;

    public function __construct(
        FrontendWorkerStateStoreInterface|FrontendWorkerCredentialStoreInterface|null $stateStore = null,
    )
    {
        $this->stateStore = $stateStore ?? $this->createConfiguredStateStore();
    }

    public function stateStoreDriver(): string
    {
        return isset($this->stateStore) && $this->stateStore !== null
            ? $this->stateStore->driver()
            : 'local';
    }

    public function usesSharedStateStore(): bool
    {
        return isset($this->stateStore)
            && $this->stateStore !== null
            && $this->stateStore->isShared();
    }

    /** @return array<string, int|string|bool> */
    public function stateStoreDiagnostics(): array
    {
        if ($this->stateStore instanceof DatabaseFrontendWorkerCredentialStore) {
            return $this->stateStore->diagnostics();
        }
        return [
            'driver' => $this->stateStoreDriver(),
            'shared' => $this->usesSharedStateStore(),
        ];
    }

    /** @return array<string, int|string|bool> */
    public function assertStateStoreReady(): array
    {
        $this->cleanupExpired();
        return $this->stateStoreDiagnostics();
    }

    /**
     * @return array{worker_session_token:string, signing_secret:string, expires_at:int, deploy_version:string, worker_build_id:string, scope_bound:bool}
     */
    public function createSession(
        string $deployVersion,
        string $workerBuildId,
        ?FrontendWorkerScopeBinding $scopeBinding = null,
    ): array
    {
        $this->assertRuntimeIdentifier($deployVersion, 'deploy version');
        $this->assertRuntimeIdentifier($workerBuildId, 'worker build ID');
        $token = $this->randomToken(32);
        $secret = $this->randomToken(32);

        return $this->withCredentialTransaction(function (FrontendWorkerCredentialTransactionInterface $store) use (
            $deployVersion,
            $workerBuildId,
            $scopeBinding,
            $token,
            $secret,
        ): array {
            $now = $store->now();
            $this->assertCredentialCapacity(
                $store,
                FrontendWorkerCredentialType::SESSION,
                null,
                $now,
                self::MAX_ACTIVE_SESSIONS,
                'Worker session capacity is exhausted.',
            );
            $store->deleteExpired($now, FrontendWorkerCredentialType::SESSION);
            $session = $this->buildSession(
                $token,
                $secret,
                $deployVersion,
                $workerBuildId,
                $scopeBinding,
                null,
                $now,
            );
            $store->insert(
                FrontendWorkerCredentialType::SESSION,
                $token,
                null,
                $session['stored'],
                $now,
                $session['stored']['expires_at'],
            );
            return $session['public'];
        });
    }

    /**
     * Create a short-lived, one-time bridge between the HttpOnly Scope Token
     * verification result and a subsequent worker handshake.
     *
     * @return array{bootstrap_id:string,cookie_name:string,expires_at:int}
     */
    public function createScopeBootstrap(FrontendWorkerScopeBinding $binding): array
    {
        $bootstrapId = $this->randomToken(32);
        $cookieName = self::scopeBootstrapCookieName($bootstrapId);

        return $this->withCredentialTransaction(function (FrontendWorkerCredentialTransactionInterface $store) use (
            $binding,
            $bootstrapId,
            $cookieName,
        ): array {
            $now = $store->now();
            $this->assertBindingUsable($binding, $now);
            $expiresAt = \min($now + self::SCOPE_BOOTSTRAP_TTL, $binding->tokenExpiresAt);
            $this->assertCredentialCapacity(
                $store,
                FrontendWorkerCredentialType::SCOPE_BOOTSTRAP,
                null,
                $now,
                self::MAX_ACTIVE_SCOPE_BOOTSTRAPS,
                'Worker Scope bootstrap capacity is exhausted.',
            );
            $store->deleteExpired($now, FrontendWorkerCredentialType::SCOPE_BOOTSTRAP);
            $payload = [
                'cookie_name' => $cookieName,
                'scope_binding' => $binding->toArray(),
                'binding_digest' => $binding->digest(),
                'created_at' => $now,
                'expires_at' => $expiresAt,
            ];
            $store->insert(
                FrontendWorkerCredentialType::SCOPE_BOOTSTRAP,
                $bootstrapId,
                null,
                $payload,
                $now,
                $expiresAt,
            );

            return [
                'bootstrap_id' => $bootstrapId,
                'cookie_name' => $cookieName,
                'expires_at' => $expiresAt,
            ];
        });
    }

    public function peekScopeBootstrap(
        string $bootstrapId,
        string $tokenFingerprint,
    ): FrontendWorkerScopeBinding {
        $this->assertOpaqueId($bootstrapId, 'Invalid worker Scope bootstrap ID.');
        $this->assertSha256($tokenFingerprint, 'Invalid worker Scope Token fingerprint.');
        return $this->withCredentialTransaction(function (FrontendWorkerCredentialTransactionInterface $store) use ($bootstrapId, $tokenFingerprint): FrontendWorkerScopeBinding {
            $now = $store->now();
            return $this->assertScopeBootstrap(
                $store->find(FrontendWorkerCredentialType::SCOPE_BOOTSTRAP, $bootstrapId, null, $now),
                self::scopeBootstrapCookieName($bootstrapId),
                $tokenFingerprint,
                null,
                $now,
            );
        });
    }

    /**
     * Atomically consume a Scope bootstrap and create its bound worker
     * session under the same store lock. Failed proof checks never consume it.
     *
     * @return array{worker_session_token:string, signing_secret:string, expires_at:int, deploy_version:string, worker_build_id:string, scope_bound:bool}
     */
    public function createSessionFromScopeBootstrap(
        string $deployVersion,
        string $workerBuildId,
        string $bootstrapId,
        string $tokenFingerprint,
        string $expectedBindingDigest,
    ): array {
        $this->assertRuntimeIdentifier($deployVersion, 'deploy version');
        $this->assertRuntimeIdentifier($workerBuildId, 'worker build ID');
        $this->assertOpaqueId($bootstrapId, 'Invalid worker Scope bootstrap ID.');
        $this->assertSha256($tokenFingerprint, 'Invalid worker Scope Token fingerprint.');
        $this->assertSha256($expectedBindingDigest, 'Invalid worker Scope binding digest.');
        $token = $this->randomToken(32);
        $secret = $this->randomToken(32);

        return $this->withCredentialTransaction(function (FrontendWorkerCredentialTransactionInterface $store) use (
            $deployVersion,
            $workerBuildId,
            $bootstrapId,
            $tokenFingerprint,
            $expectedBindingDigest,
            $token,
            $secret,
        ): array {
            $now = $store->now();
            $retainedSessions = $store->countRetained(
                FrontendWorkerCredentialType::SESSION,
                null,
                $now,
            );
            $store->deleteExpired($now, FrontendWorkerCredentialType::SESSION);
            $binding = $this->assertScopeBootstrap(
                $store->find(FrontendWorkerCredentialType::SCOPE_BOOTSTRAP, $bootstrapId, null, $now),
                self::scopeBootstrapCookieName($bootstrapId),
                $tokenFingerprint,
                $expectedBindingDigest,
                $now,
            );
            $this->assertCapacityCount(
                $retainedSessions,
                self::MAX_ACTIVE_SESSIONS,
                'Worker session capacity is exhausted.',
            );
            $session = $this->buildSession(
                $token,
                $secret,
                $deployVersion,
                $workerBuildId,
                $binding,
                null,
                $now,
            );
            $store->insert(
                FrontendWorkerCredentialType::SESSION,
                $token,
                null,
                $session['stored'],
                $now,
                $session['stored']['expires_at'],
            );
            if (!$store->consume(FrontendWorkerCredentialType::SCOPE_BOOTSTRAP, $bootstrapId, null)) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Worker Scope bootstrap consumption failed.',
                    503,
                );
            }
            return $session['public'];
        });
    }

    /**
     * Create a short-lived backend page proof. The proof value is returned only
     * to the response decorator for an HttpOnly cookie; the store retains only
     * its SHA-256 fingerprint.
     *
     * @return array{bootstrap_id:string,cookie_name:string,cookie_value:string,expires_at:int}
     */
    public function createBackendBootstrap(
        FrontendWorkerBackendBinding $binding,
        bool $secureCookie,
    ): array {
        $bootstrapId = $this->randomToken(32);
        $cookieValue = $this->randomToken(32);
        $cookieName = self::backendBootstrapCookieName($bootstrapId, $secureCookie);

        return $this->withCredentialTransaction(function (FrontendWorkerCredentialTransactionInterface $store) use (
            $binding,
            $bootstrapId,
            $cookieValue,
            $cookieName,
        ): array {
            $now = $store->now();
            $this->assertBackendBindingUsable($binding, $now);
            $expiresAt = \min($now + self::SCOPE_BOOTSTRAP_TTL, $binding->expiresAt);
            $this->assertCredentialCapacity(
                $store,
                FrontendWorkerCredentialType::BACKEND_BOOTSTRAP,
                null,
                $now,
                self::MAX_ACTIVE_BACKEND_BOOTSTRAPS,
                'Worker backend bootstrap capacity is exhausted.',
            );
            $store->deleteExpired($now, FrontendWorkerCredentialType::BACKEND_BOOTSTRAP);
            $payload = [
                'cookie_name' => $cookieName,
                'proof_fingerprint' => $this->hash($cookieValue),
                'backend_binding' => $binding->toArray(),
                'binding_digest' => $binding->digest(),
                'created_at' => $now,
                'expires_at' => $expiresAt,
            ];
            $store->insert(
                FrontendWorkerCredentialType::BACKEND_BOOTSTRAP,
                $bootstrapId,
                null,
                $payload,
                $now,
                $expiresAt,
            );

            return [
                'bootstrap_id' => $bootstrapId,
                'cookie_name' => $cookieName,
                'cookie_value' => $cookieValue,
                'expires_at' => $expiresAt,
            ];
        });
    }

    public function peekBackendBootstrap(
        string $bootstrapId,
        string $cookieProof,
        bool $secureCookie,
    ): FrontendWorkerBackendBinding {
        $this->assertOpaqueId($bootstrapId, 'Invalid worker backend bootstrap ID.');
        $this->assertOpaqueId($cookieProof, 'Invalid worker backend bootstrap proof.');
        return $this->withCredentialTransaction(function (FrontendWorkerCredentialTransactionInterface $store) use (
            $bootstrapId,
            $cookieProof,
            $secureCookie,
        ): FrontendWorkerBackendBinding {
            $now = $store->now();
            return $this->assertBackendBootstrap(
                $store->find(FrontendWorkerCredentialType::BACKEND_BOOTSTRAP, $bootstrapId, null, $now),
                self::backendBootstrapCookieName($bootstrapId, $secureCookie),
                $this->hash($cookieProof),
                null,
                $now,
            );
        });
    }

    /**
     * Atomically consume a verified backend page proof and create its
     * backend-authoritative Worker session under the same store lock.
     *
     * @return array{worker_session_token:string,signing_secret:string,expires_at:int,deploy_version:string,worker_build_id:string,scope_bound:bool,attested_area:string}
     */
    public function createSessionFromBackendBootstrap(
        string $deployVersion,
        string $workerBuildId,
        string $bootstrapId,
        string $cookieProof,
        bool $secureCookie,
        string $expectedBindingDigest,
    ): array {
        $this->assertRuntimeIdentifier($deployVersion, 'deploy version');
        $this->assertRuntimeIdentifier($workerBuildId, 'worker build ID');
        $this->assertOpaqueId($bootstrapId, 'Invalid worker backend bootstrap ID.');
        $this->assertOpaqueId($cookieProof, 'Invalid worker backend bootstrap proof.');
        $this->assertSha256($expectedBindingDigest, 'Invalid worker backend binding digest.');
        $token = $this->randomToken(32);
        $secret = $this->randomToken(32);

        return $this->withCredentialTransaction(function (FrontendWorkerCredentialTransactionInterface $store) use (
            $deployVersion,
            $workerBuildId,
            $bootstrapId,
            $cookieProof,
            $secureCookie,
            $expectedBindingDigest,
            $token,
            $secret,
        ): array {
            $now = $store->now();
            $retainedSessions = $store->countRetained(
                FrontendWorkerCredentialType::SESSION,
                null,
                $now,
            );
            $store->deleteExpired($now, FrontendWorkerCredentialType::SESSION);
            $binding = $this->assertBackendBootstrap(
                $store->find(FrontendWorkerCredentialType::BACKEND_BOOTSTRAP, $bootstrapId, null, $now),
                self::backendBootstrapCookieName($bootstrapId, $secureCookie),
                $this->hash($cookieProof),
                $expectedBindingDigest,
                $now,
            );
            $this->assertCapacityCount(
                $retainedSessions,
                self::MAX_ACTIVE_SESSIONS,
                'Worker session capacity is exhausted.',
            );
            $session = $this->buildSession(
                $token,
                $secret,
                $deployVersion,
                $workerBuildId,
                null,
                $binding,
                $now,
            );
            $store->insert(
                FrontendWorkerCredentialType::SESSION,
                $token,
                null,
                $session['stored'],
                $now,
                $session['stored']['expires_at'],
            );
            if (!$store->consume(FrontendWorkerCredentialType::BACKEND_BOOTSTRAP, $bootstrapId, null)) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Worker backend bootstrap consumption failed.',
                    503,
                );
            }
            return $session['public'];
        });
    }

    /**
     * @return array{secret:string, deploy_version:string, worker_build_id:string, created_at:int, expires_at:int, scope_binding?:FrontendWorkerScopeBinding}
     */
    public function validateSession(string $token, string $deployVersion, string $workerBuildId): array
    {
        $this->assertRuntimeIdentifier($deployVersion, 'deploy version');
        $this->assertRuntimeIdentifier($workerBuildId, 'worker build ID');
        if ($token === '') {
            throw new FrontendQueryException('auth_error', 'Missing worker session token.', 401);
        }

        return $this->withCredentialTransaction(function (FrontendWorkerCredentialTransactionInterface $store) use ($token, $deployVersion, $workerBuildId): array {
            $now = $store->now();
            return $this->assertSession(
                $store->find(FrontendWorkerCredentialType::SESSION, $token, null, $now),
                $deployVersion,
                $workerBuildId,
                $now,
            );
        });
    }

    /**
     * Persist a freshly revalidated backend page attestation onto the existing
     * worker session so runtime_rotate ticket refresh does not require reload.
     */
    public function slideBackendSession(
        string $token,
        FrontendWorkerBackendBinding $binding,
    ): void {
        if ($token === '') {
            throw new FrontendQueryException('auth_error', 'Missing worker session token.', 401);
        }

        $this->withCredentialTransaction(function (FrontendWorkerCredentialTransactionInterface $store) use (
            $token,
            $binding,
        ): void {
            $now = $store->now();
            $this->assertBackendBindingUsable($binding, $now);
            $payload = $store->find(FrontendWorkerCredentialType::SESSION, $token, null, $now);
            if (!\is_array($payload)) {
                throw new FrontendQueryException('auth_error', 'Invalid worker session token.', 401);
            }
            $attestedArea = $payload['attested_area'] ?? null;
            if ($attestedArea !== FrontendWorkerExecutionContext::AREA_BACKEND
                || !\array_key_exists('backend_binding', $payload)
                || \array_key_exists('scope_binding', $payload)) {
                throw new FrontendQueryException('auth_error', 'Worker session authority is invalid.', 401);
            }
            $expiresAt = \min($now + self::SESSION_TTL, $binding->expiresAt);
            $payload['backend_binding'] = $binding->toArray();
            $payload['expires_at'] = $expiresAt;
            $store->replaceActive(
                FrontendWorkerCredentialType::SESSION,
                $token,
                null,
                $payload,
                $expiresAt,
            );
        });
    }

    public function consumeNonce(string $token, string $nonce): void
    {
        if ($token === '') {
            throw new FrontendQueryException('auth_error', 'Missing worker session token.', 401);
        }
        if ($nonce === '' || \strlen($nonce) > 128) {
            throw new FrontendQueryException('auth_error', 'Invalid worker nonce.', 401);
        }

        $this->withCredentialTransaction(function (FrontendWorkerCredentialTransactionInterface $store) use ($token, $nonce): void {
            $now = $store->now();
            $retainedNonceBucket = $store->countRetainedInCapacityBucket(
                FrontendWorkerCredentialType::NONCE,
                $token,
                $now,
            );
            // Database stores first lock a deterministic nonce shard, then use
            // the Session row as the per-scope mutex. Every nonce path follows
            // shard guard -> Session -> nonce lock order.
            $retainedNonces = $store->countRetained(
                FrontendWorkerCredentialType::NONCE,
                $token,
                $now,
            );
            if ($store->find(FrontendWorkerCredentialType::NONCE, $nonce, $token, $now) !== null) {
                throw new FrontendQueryException('auth_error', 'Worker nonce has already been used.', 401);
            }
            $this->assertCapacityCount(
                $retainedNonceBucket,
                self::MAX_NONCES_PER_CAPACITY_BUCKET,
                'Worker nonce shard capacity is exhausted.',
            );
            $this->assertCapacityCount(
                $retainedNonces,
                self::MAX_NONCES_PER_SCOPE,
                'Worker nonce capacity is exhausted.',
            );
            $expiresAt = $now + self::NONCE_TTL;
            $store->insert(
                FrontendWorkerCredentialType::NONCE,
                $nonce,
                $token,
                ['expires_at' => $expiresAt],
                $now,
                $expiresAt,
            );
        });
    }

    public function cleanupExpired(): void
    {
        $this->withCredentialTransaction(function (FrontendWorkerCredentialTransactionInterface $store): void {
            $store->deleteExpired($store->now());
        });
    }

    /**
     * Validate the worker session and consume the nonce under a single store lock.
     *
     * @return array{secret:string, deploy_version:string, worker_build_id:string, created_at:int, expires_at:int, scope_binding?:FrontendWorkerScopeBinding}
     */
    public function validateSessionAndConsumeNonce(
        string $token,
        string $deployVersion,
        string $workerBuildId,
        string $nonce
    ): array {
        $this->assertRuntimeIdentifier($deployVersion, 'deploy version');
        $this->assertRuntimeIdentifier($workerBuildId, 'worker build ID');
        if ($token === '') {
            throw new FrontendQueryException('auth_error', 'Missing worker session token.', 401);
        }
        if ($nonce === '' || \strlen($nonce) > 128) {
            throw new FrontendQueryException('auth_error', 'Invalid worker nonce.', 401);
        }

        return $this->withCredentialTransaction(function (FrontendWorkerCredentialTransactionInterface $store) use ($token, $deployVersion, $workerBuildId, $nonce): array {
            $now = $store->now();
            $retainedNonceBucket = $store->countRetainedInCapacityBucket(
                FrontendWorkerCredentialType::NONCE,
                $token,
                $now,
            );
            $session = $this->assertSession(
                $store->find(FrontendWorkerCredentialType::SESSION, $token, null, $now),
                $deployVersion,
                $workerBuildId,
                $now,
            );
            if ($store->find(FrontendWorkerCredentialType::NONCE, $nonce, $token, $now) !== null) {
                throw new FrontendQueryException('auth_error', 'Worker nonce has already been used.', 401);
            }
            $this->assertCapacityCount(
                $retainedNonceBucket,
                self::MAX_NONCES_PER_CAPACITY_BUCKET,
                'Worker nonce shard capacity is exhausted.',
            );
            $this->assertCredentialCapacity(
                $store,
                FrontendWorkerCredentialType::NONCE,
                $token,
                $now,
                self::MAX_NONCES_PER_SCOPE,
                'Worker nonce capacity is exhausted.',
            );
            $expiresAt = $now + self::NONCE_TTL;
            $store->insert(
                FrontendWorkerCredentialType::NONCE,
                $nonce,
                $token,
                ['expires_at' => $expiresAt],
                $now,
                $expiresAt,
            );

            return $session;
        });
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed>|null $owner
     * @return array{ticket:string, channel:string, params:array<string, mixed>, expires_at:int, url:string, owner?:array<string, mixed>|null}
     */
    public function createStreamTicket(
        string $channel,
        array $params = [],
        ?array $owner = null,
        ?FrontendWorkerScopeBinding $scopeBinding = null,
        ?FrontendWorkerExecutionContext $executionContext = null,
    ): array
    {
        $ticket = $this->randomToken(24);
        $executionContext ??= FrontendWorkerExecutionContext::frontend($scopeBinding);
        if ($scopeBinding !== null
            && ($executionContext->scopeBinding === null
                || !\hash_equals($scopeBinding->digest(), $executionContext->scopeBinding->digest()))) {
            throw new FrontendQueryException('auth_error', 'Worker stream authority context is inconsistent.', 401);
        }
        $scopeBinding = $executionContext->scopeBinding;

        return $this->withCredentialTransaction(function (FrontendWorkerCredentialTransactionInterface $store) use (
            $channel,
            $params,
            $owner,
            $scopeBinding,
            $executionContext,
            $ticket,
        ): array {
            $now = $store->now();
            if ($scopeBinding !== null) {
                $this->assertBindingUsable($scopeBinding, $now);
            }
            if ($executionContext->backendBinding !== null) {
                $this->assertBackendBindingUsable($executionContext->backendBinding, $now);
            }
            $expiresAt = $now + self::STREAM_TICKET_TTL;
            if ($scopeBinding !== null) {
                $expiresAt = \min($expiresAt, $scopeBinding->tokenExpiresAt);
            }
            if ($executionContext->backendBinding !== null) {
                $expiresAt = \min($expiresAt, $executionContext->backendBinding->expiresAt);
            }
            $this->assertCredentialCapacity(
                $store,
                FrontendWorkerCredentialType::STREAM_TICKET,
                null,
                $now,
                self::MAX_ACTIVE_STREAM_TICKETS,
                'Worker stream ticket capacity is exhausted.',
            );
            $store->deleteExpired($now, FrontendWorkerCredentialType::STREAM_TICKET);
            $storedPayload = [
                'channel' => $channel,
                'params' => $params,
                'owner' => $owner,
                'expires_at' => $expiresAt,
                'execution_context' => $executionContext->toArray(),
            ];
            if ($scopeBinding !== null) {
                $storedPayload['scope_binding'] = $scopeBinding->toArray();
            }
            try {
                $serializedPayload = \json_encode(
                    $storedPayload,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );
            } catch (\Throwable $exception) {
                throw new FrontendQueryException(
                    'validation_error',
                    'Worker stream ticket payload is not serializable.',
                    422,
                    $exception,
                );
            }
            if (\strlen($serializedPayload) > self::MAX_STREAM_TICKET_BYTES) {
                throw new FrontendQueryException(
                    'validation_error',
                    'Worker stream ticket payload exceeds the storage limit.',
                    422,
                );
            }
            $store->insert(
                FrontendWorkerCredentialType::STREAM_TICKET,
                $ticket,
                null,
                $storedPayload,
                $now,
                $expiresAt,
            );

            return [
                'ticket' => $ticket,
                'channel' => $channel,
                'params' => $params,
                'owner' => $owner,
                'expires_at' => $expiresAt,
                'url' => $this->buildStreamUrl($ticket),
            ];
        });
    }

    /**
     * @return array{channel:string,params:array<string,mixed>,expires_at:int,owner:?array<string,mixed>,execution_context:FrontendWorkerExecutionContext,scope_binding?:FrontendWorkerScopeBinding}
     */
    public function consumeStreamTicket(string $ticket): array
    {
        if ($ticket === '') {
            throw new FrontendQueryException('auth_error', 'Missing worker stream ticket.', 401);
        }

        return $this->withCredentialTransaction(function (FrontendWorkerCredentialTransactionInterface $store) use ($ticket): array {
            $now = $store->now();
            $payload = $store->find(FrontendWorkerCredentialType::STREAM_TICKET, $ticket, null, $now);
            if (!\is_array($payload)) {
                throw new FrontendQueryException('auth_error', 'Invalid or expired worker stream ticket.', 401);
            }

            $channel = (string)($payload['channel'] ?? '');
            $params = $payload['params'] ?? [];
            if ($channel === '' || !\is_array($params)) {
                throw new FrontendQueryException('protocol_error', 'Invalid worker stream ticket payload.', 400);
            }

            $owner = $payload['owner'] ?? null;
            if ($owner !== null && !\is_array($owner)) {
                throw new FrontendQueryException('protocol_error', 'Invalid worker stream ticket owner.', 400);
            }

            $result = [
                'channel' => $channel,
                'params' => $params,
                'owner' => $owner,
                'expires_at' => (int)($payload['expires_at'] ?? $now),
            ];
            if (array_key_exists('scope_binding', $payload)) {
                try {
                    if (!is_array($payload['scope_binding'])) {
                        throw new \InvalidArgumentException('Invalid Scope binding payload.');
                    }
                    $binding = FrontendWorkerScopeBinding::fromArray($payload['scope_binding']);
                    $this->assertBindingUsable($binding, $now);
                } catch (\Throwable) {
                    throw new FrontendQueryException(
                        'auth_error',
                        'Invalid or expired worker stream Scope binding.',
                        401,
                    );
                }
                $result['scope_binding'] = $binding;
            }

            try {
                if (\array_key_exists('execution_context', $payload)) {
                    if (!\is_array($payload['execution_context'])) {
                        throw new \InvalidArgumentException('Invalid Worker execution context payload.');
                    }
                    $executionContext = FrontendWorkerExecutionContext::fromArray($payload['execution_context']);
                } else {
                    $executionContext = FrontendWorkerExecutionContext::frontend($result['scope_binding'] ?? null);
                }
                if ($executionContext->scopeBinding !== null) {
                    $this->assertBindingUsable($executionContext->scopeBinding, $now);
                }
                if ($executionContext->backendBinding !== null) {
                    $this->assertBackendBindingUsable($executionContext->backendBinding, $now);
                }
                if (isset($result['scope_binding'])
                    && ($executionContext->scopeBinding === null
                        || !\hash_equals(
                            $result['scope_binding']->digest(),
                            $executionContext->scopeBinding->digest(),
                        ))) {
                    throw new \InvalidArgumentException('Worker stream Scope context mismatch.');
                }
            } catch (\Throwable) {
                throw new FrontendQueryException(
                    'auth_error',
                    'Invalid or expired worker stream execution context.',
                    401,
                );
            }
            $result['execution_context'] = $executionContext;

            if (!$store->consume(FrontendWorkerCredentialType::STREAM_TICKET, $ticket, null)) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Worker stream ticket consumption failed.',
                    503,
                );
            }

            return $result;
        });
    }

    /**
     * @return array{
     *   stored:array<string,mixed>,
     *   public:array{worker_session_token:string,signing_secret:string,expires_at:int,deploy_version:string,worker_build_id:string,scope_bound:bool,attested_area:string}
     * }
     */
    private function buildSession(
        string $token,
        string $secret,
        string $deployVersion,
        string $workerBuildId,
        ?FrontendWorkerScopeBinding $scopeBinding,
        ?FrontendWorkerBackendBinding $backendBinding,
        int $now,
    ): array {
        $this->assertRuntimeIdentifier($deployVersion, 'deploy version');
        $this->assertRuntimeIdentifier($workerBuildId, 'worker build ID');
        if ($scopeBinding !== null && $backendBinding !== null) {
            throw new FrontendQueryException('auth_error', 'Worker session authority is ambiguous.', 401);
        }
        $attestedArea = $backendBinding === null
            ? FrontendWorkerExecutionContext::AREA_FRONTEND
            : FrontendWorkerExecutionContext::AREA_BACKEND;
        $expiresAt = $now + self::SESSION_TTL;
        if ($scopeBinding !== null) {
            $this->assertBindingUsable($scopeBinding, $now);
            // The one-time bootstrap is already consumed at this point. A
            // bound worker session therefore remains usable for the verified
            // Scope Token window instead of attempting an impossible legacy
            // re-handshake after the unbound ten-minute TTL.
            $expiresAt = $scopeBinding->tokenExpiresAt;
        }
        if ($backendBinding !== null) {
            $this->assertBackendBindingUsable($backendBinding, $now);
            $expiresAt = \min($expiresAt, $backendBinding->expiresAt);
        }

        $storedSession = [
            'secret' => $secret,
            'deploy_version' => $deployVersion,
            'worker_build_id' => $workerBuildId,
            'attested_area' => $attestedArea,
            'created_at' => $now,
            'expires_at' => $expiresAt,
        ];
        if ($scopeBinding !== null) {
            $storedSession['scope_binding'] = $scopeBinding->toArray();
        }
        if ($backendBinding !== null) {
            $storedSession['backend_binding'] = $backendBinding->toArray();
        }
        return [
            'stored' => $storedSession,
            'public' => [
                'worker_session_token' => $token,
                'signing_secret' => $secret,
                'expires_at' => $expiresAt,
                'deploy_version' => $deployVersion,
                'worker_build_id' => $workerBuildId,
                'scope_bound' => $scopeBinding !== null,
                'attested_area' => $attestedArea,
            ],
        ];
    }

    private function assertScopeBootstrap(
        mixed $payload,
        string $expectedCookieName,
        string $tokenFingerprint,
        ?string $expectedBindingDigest,
        int $now,
    ): FrontendWorkerScopeBinding {
        if (!is_array($payload)
            || !$this->hasExactKeys(
                $payload,
                ['binding_digest', 'cookie_name', 'created_at', 'expires_at', 'scope_binding']
            )
            || $payload['cookie_name'] !== $expectedCookieName
            || !is_array($payload['scope_binding'])
            || !is_string($payload['binding_digest'])
            || preg_match(self::SHA256_PATTERN, $payload['binding_digest']) !== 1
            || !is_int($payload['created_at'])
            || !is_int($payload['expires_at'])
            || $payload['created_at'] < 1
            || $payload['expires_at'] <= $now
            || $payload['expires_at'] <= $payload['created_at']
            || $payload['expires_at'] > $payload['created_at'] + self::SCOPE_BOOTSTRAP_TTL) {
            throw new FrontendQueryException('auth_error', 'Invalid or expired worker Scope bootstrap.', 401);
        }

        try {
            $binding = FrontendWorkerScopeBinding::fromArray($payload['scope_binding']);
            $this->assertBindingUsable($binding, $now);
        } catch (\Throwable) {
            throw new FrontendQueryException('auth_error', 'Invalid or expired worker Scope bootstrap binding.', 401);
        }
        $digest = $binding->digest();
        if (!hash_equals($digest, $payload['binding_digest'])
            || !hash_equals($binding->tokenFingerprint, $tokenFingerprint)
            || ($expectedBindingDigest !== null && !hash_equals($digest, $expectedBindingDigest))) {
            throw new FrontendQueryException('auth_error', 'Worker Scope bootstrap proof mismatch.', 401);
        }

        return $binding;
    }

    private function assertBindingUsable(FrontendWorkerScopeBinding $binding, int $now): void
    {
        if ($binding->tokenIssuedAt > $now + FrontendWorkerScopeBinding::CLOCK_SKEW_SECONDS
            || $binding->tokenExpiresAt <= $now
            || $binding->tokenExpiresAt - $binding->tokenIssuedAt !== self::MAX_SCOPE_BINDING_TTL) {
            throw new FrontendQueryException('auth_error', 'Worker Scope binding has expired.', 401);
        }
    }

    private function assertBackendBootstrap(
        mixed $payload,
        string $expectedCookieName,
        string $proofFingerprint,
        ?string $expectedBindingDigest,
        int $now,
    ): FrontendWorkerBackendBinding {
        if (!\is_array($payload)
            || !$this->hasExactKeys($payload, [
                'backend_binding',
                'binding_digest',
                'cookie_name',
                'created_at',
                'expires_at',
                'proof_fingerprint',
            ])
            || $payload['cookie_name'] !== $expectedCookieName
            || !\is_array($payload['backend_binding'])
            || !\is_string($payload['binding_digest'])
            || \preg_match(self::SHA256_PATTERN, $payload['binding_digest']) !== 1
            || !\is_string($payload['proof_fingerprint'])
            || \preg_match(self::SHA256_PATTERN, $payload['proof_fingerprint']) !== 1
            || !\is_int($payload['created_at'])
            || !\is_int($payload['expires_at'])
            || $payload['created_at'] < 1
            || $payload['expires_at'] <= $now
            || $payload['expires_at'] <= $payload['created_at']
            || $payload['expires_at'] > $payload['created_at'] + self::SCOPE_BOOTSTRAP_TTL) {
            throw new FrontendQueryException('auth_error', 'Invalid or expired worker backend bootstrap.', 401);
        }

        try {
            $binding = FrontendWorkerBackendBinding::fromArray($payload['backend_binding']);
            $this->assertBackendBindingUsable($binding, $now);
        } catch (\Throwable) {
            throw new FrontendQueryException('auth_error', 'Invalid or expired worker backend binding.', 401);
        }
        $digest = $binding->digest();
        if (!\hash_equals($digest, $payload['binding_digest'])
            || !\hash_equals($payload['proof_fingerprint'], $proofFingerprint)
            || ($expectedBindingDigest !== null && !\hash_equals($digest, $expectedBindingDigest))) {
            throw new FrontendQueryException('auth_error', 'Worker backend bootstrap proof mismatch.', 401);
        }

        return $binding;
    }

    private function assertBackendBindingUsable(FrontendWorkerBackendBinding $binding, int $now): void
    {
        if ($binding->issuedAt > $now
            || $binding->expiresAt <= $now
            || $binding->expiresAt - $binding->issuedAt > self::SESSION_TTL) {
            throw new FrontendQueryException('auth_error', 'Worker backend binding has expired.', 401);
        }
    }

    private function assertOpaqueId(string $value, string $message): void
    {
        if (preg_match(self::OPAQUE_ID_PATTERN, $value) !== 1) {
            throw new FrontendQueryException('auth_error', $message, 401);
        }
    }

    public static function scopeBootstrapCookieName(string $bootstrapId): string
    {
        if (preg_match(self::OPAQUE_ID_PATTERN, $bootstrapId) !== 1) {
            throw new FrontendQueryException('auth_error', 'Invalid worker Scope bootstrap ID.', 401);
        }

        return self::SCOPE_BOOTSTRAP_COOKIE_PREFIX . $bootstrapId;
    }

    public static function backendBootstrapCookieName(string $bootstrapId, bool $secureCookie): string
    {
        if (\preg_match(self::OPAQUE_ID_PATTERN, $bootstrapId) !== 1) {
            throw new FrontendQueryException('auth_error', 'Invalid worker backend bootstrap ID.', 401);
        }

        return ($secureCookie
            ? self::BACKEND_BOOTSTRAP_SECURE_COOKIE_PREFIX
            : self::BACKEND_BOOTSTRAP_DEV_COOKIE_PREFIX) . $bootstrapId;
    }

    private function assertSha256(string $value, string $message): void
    {
        if (preg_match(self::SHA256_PATTERN, $value) !== 1) {
            throw new FrontendQueryException('auth_error', $message, 401);
        }
    }

    private function assertRuntimeIdentifier(string $value, string $label): void
    {
        if (\preg_match(self::RUNTIME_IDENTIFIER_PATTERN, $value) !== 1) {
            throw new FrontendQueryException(
                'protocol_error',
                'Invalid Worker ' . $label . '.',
                400,
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $expected
     */
    private function hasExactKeys(array $data, array $expected): bool
    {
        if (array_is_list($data)) {
            return false;
        }
        $actual = array_keys($data);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }

    private function cleanupScopeBootstraps(array &$bootstraps, int $now): void
    {
        foreach ($bootstraps as $key => $bootstrap) {
            if (!is_array($bootstrap) || (int)($bootstrap['expires_at'] ?? 0) <= $now) {
                unset($bootstraps[$key]);
            }
        }
    }

    private function cleanupBackendBootstraps(array &$bootstraps, int $now): void
    {
        foreach ($bootstraps as $key => $bootstrap) {
            if (!\is_array($bootstrap) || (int)($bootstrap['expires_at'] ?? 0) <= $now) {
                unset($bootstraps[$key]);
            }
        }
    }

    private function cleanupStreamTickets(array &$tickets, int $now): void
    {
        foreach ($tickets as $key => $ticket) {
            if (!\is_array($ticket) || (int)($ticket['expires_at'] ?? 0) <= $now) {
                unset($tickets[$key]);
            }
        }
    }

    private function cleanupSessions(array &$sessions, int $now): void
    {
        foreach ($sessions as $key => $session) {
            if (!\is_array($session) || (int)($session['expires_at'] ?? 0) <= $now) {
                unset($sessions[$key]);
            }
        }
    }

    /**
     * @return array{secret:string,deploy_version:string,worker_build_id:string,attested_area:string,created_at:int,expires_at:int,scope_binding?:FrontendWorkerScopeBinding,backend_binding?:FrontendWorkerBackendBinding}
     */
    private function assertSession(mixed $session, string $deployVersion, string $workerBuildId, int $now): array
    {
        if (!\is_array($session)) {
            throw new FrontendQueryException('auth_error', 'Invalid worker session token.', 401);
        }
        if ((int)($session['expires_at'] ?? 0) <= $now) {
            throw new FrontendQueryException('auth_error', 'Expired worker session token.', 401);
        }
        if ((string)($session['deploy_version'] ?? '') !== $deployVersion) {
            throw new FrontendQueryException('auth_error', 'Worker session deployment mismatch.', 401);
        }
        if ((string)($session['worker_build_id'] ?? '') !== $workerBuildId) {
            throw new FrontendQueryException('auth_error', 'Worker build mismatch.', 401);
        }
        $attestedArea = $session['attested_area'] ?? FrontendWorkerExecutionContext::AREA_FRONTEND;
        if (!\is_string($attestedArea)
            || !\in_array($attestedArea, [
                FrontendWorkerExecutionContext::AREA_FRONTEND,
                FrontendWorkerExecutionContext::AREA_BACKEND,
            ], true)) {
            throw new FrontendQueryException('auth_error', 'Worker session area is invalid.', 401);
        }

        $result = [
            'secret' => (string)($session['secret'] ?? ''),
            'deploy_version' => (string)($session['deploy_version'] ?? ''),
            'worker_build_id' => (string)($session['worker_build_id'] ?? ''),
            'attested_area' => $attestedArea,
            'created_at' => (int)($session['created_at'] ?? 0),
            'expires_at' => (int)($session['expires_at'] ?? 0),
        ];
        if (array_key_exists('scope_binding', $session)) {
            if ($attestedArea !== FrontendWorkerExecutionContext::AREA_FRONTEND) {
                throw new FrontendQueryException('auth_error', 'Backend Worker session contains storefront Scope.', 401);
            }
            try {
                if (!is_array($session['scope_binding'])) {
                    throw new \InvalidArgumentException('Invalid Scope binding payload.');
                }
                $binding = FrontendWorkerScopeBinding::fromArray($session['scope_binding']);
                $this->assertBindingUsable($binding, $now);
            } catch (\Throwable) {
                throw new FrontendQueryException('auth_error', 'Invalid or expired worker session Scope binding.', 401);
            }
            $result['scope_binding'] = $binding;
        }
        if (\array_key_exists('backend_binding', $session)) {
            if ($attestedArea !== FrontendWorkerExecutionContext::AREA_BACKEND) {
                throw new FrontendQueryException('auth_error', 'Frontend Worker session contains backend authority.', 401);
            }
            try {
                if (!\is_array($session['backend_binding'])) {
                    throw new \InvalidArgumentException('Invalid backend binding payload.');
                }
                $backendBinding = FrontendWorkerBackendBinding::fromArray($session['backend_binding']);
                // Live PHP Session revalidation + sliding renew happens in
                // QueryBin via restoreBinding()/slideBackendSession(). Here we
                // only reject structurally impossible windows so a near-expiry
                // rotate can still refresh the attestation.
                if ($backendBinding->issuedAt > $now
                    || $backendBinding->expiresAt - $backendBinding->issuedAt > self::SESSION_TTL) {
                    throw new FrontendQueryException('auth_error', 'Invalid or expired worker backend binding.', 401);
                }
            } catch (\Throwable) {
                throw new FrontendQueryException('auth_error', 'Invalid or expired worker backend binding.', 401);
            }
            $result['backend_binding'] = $backendBinding;
        } elseif ($attestedArea === FrontendWorkerExecutionContext::AREA_BACKEND) {
            throw new FrontendQueryException('auth_error', 'Backend Worker session has no attestation binding.', 401);
        }

        return $result;
    }

    private function cleanupStoreIfNeeded(array &$store, int $now): void
    {
        $meta = $this->getStoreArray($store, self::META_KEY);
        $lastCleanup = (int)($meta['last_cleanup_at'] ?? 0);
        $forceCleanup = \is_file(self::STORE_FILE) && \filesize(self::STORE_FILE) > self::STORE_FORCE_CLEANUP_BYTES;
        if (!$forceCleanup && $lastCleanup > 0 && ($now - $lastCleanup) < self::CLEANUP_INTERVAL_SECONDS) {
            return;
        }

        $sessions = $this->getStoreArray($store, self::SESSION_KEY);
        $this->cleanupSessions($sessions, $now);
        $store[self::SESSION_KEY] = $sessions;

        $allNonces = $this->getStoreArray($store, self::NONCE_KEY);
        foreach ($allNonces as $scope => $nonces) {
            if (!isset($sessions[$scope]) || !\is_array($nonces)) {
                unset($allNonces[$scope]);
                continue;
            }

            $this->cleanupNonceScope($nonces, $now);
            if ($nonces === []) {
                unset($allNonces[$scope]);
                continue;
            }

            $allNonces[$scope] = $nonces;
        }
        $store[self::NONCE_KEY] = $allNonces;

        $bootstraps = $this->getStoreArray($store, self::SCOPE_BOOTSTRAP_KEY);
        $this->cleanupScopeBootstraps($bootstraps, $now);
        $store[self::SCOPE_BOOTSTRAP_KEY] = $bootstraps;

        $backendBootstraps = $this->getStoreArray($store, self::BACKEND_BOOTSTRAP_KEY);
        $this->cleanupBackendBootstraps($backendBootstraps, $now);
        $store[self::BACKEND_BOOTSTRAP_KEY] = $backendBootstraps;

        $streamTickets = $this->getStoreArray($store, self::STREAM_TICKET_KEY);
        $this->cleanupStreamTickets($streamTickets, $now);
        $store[self::STREAM_TICKET_KEY] = $streamTickets;

        $meta['last_cleanup_at'] = $now;
        $store[self::META_KEY] = $meta;
    }

    private function cleanupNonceScope(array &$nonces, int $now): void
    {
        foreach ($nonces as $storedNonce => $expiresAt) {
            if ((int)$expiresAt <= $now) {
                unset($nonces[$storedNonce]);
            }
        }
    }

    private function getStoreArray(array $store, string $key): array
    {
        $value = $store[$key] ?? [];
        return \is_array($value) ? $value : [];
    }

    private function assertCredentialCapacity(
        FrontendWorkerCredentialTransactionInterface $store,
        string $type,
        ?string $scope,
        int $now,
        int $limit,
        string $message,
    ): void {
        $this->assertCapacityCount($store->countRetained($type, $scope, $now), $limit, $message);
    }

    private function assertCapacityCount(int $count, int $limit, string $message): void
    {
        if ($count >= $limit) {
            throw new FrontendQueryException('worker_capacity_exhausted', $message, 503);
        }
    }

    /**
     * @template T
     * @param callable(FrontendWorkerCredentialTransactionInterface):T $callback
     * @return T
     */
    private function withCredentialTransaction(callable $callback): mixed
    {
        if ($this->stateStore instanceof FrontendWorkerCredentialStoreInterface) {
            return $this->stateStore->transaction($callback);
        }

        return $this->withStore(
            static function (array &$store) use ($callback): mixed {
                return $callback(new ArrayFrontendWorkerCredentialTransaction($store));
            },
        );
    }

    /**
     * @template T
     * @param callable(array<string, mixed>&):T $callback
     * @return T
     */
    private function withStore(callable $callback): mixed
    {
        if ($this->stateStore instanceof FrontendWorkerStateStoreInterface) {
            return $this->stateStore->transaction($callback);
        }

        $this->ensureStoreDirectory();

        // Ops scripts sometimes widen var/cache modes (e.g. `find … chmod ug+rw`).
        // Repair to 0600 after open; never hard-fail on a pre-existing 0660/0664 lock.
        if (\file_exists(self::LOCK_FILE) && \is_link(self::LOCK_FILE)) {
            throw new FrontendQueryException('worker_store_unavailable', 'Worker session store lock is unsafe.', 503);
        }
        $lock = \fopen(self::LOCK_FILE, 'c+b');
        if ($lock === false) {
            throw new FrontendQueryException('auth_error', 'Worker session store lock is unavailable.', 503);
        }

        try {
            if (!\chmod(self::LOCK_FILE, 0600)) {
                throw new \RuntimeException('Unable to secure worker store lock permissions.');
            }
            $this->assertPrivateOpenFile($lock, self::LOCK_FILE);
        } catch (\Throwable $exception) {
            \fclose($lock);
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker session store lock is not private.',
                503,
                $exception,
            );
        }

        $locked = false;
        try {
            $locked = $this->acquireStoreLock($lock);

            $store = $this->readStore();
            $result = $callback($store);
            $this->writeStore($store);

            return $result;
        } finally {
            if ($locked) {
                \flock($lock, LOCK_UN);
            }
            \fclose($lock);
        }
    }

    private function createConfiguredStateStore(): FrontendWorkerStateStoreInterface|FrontendWorkerCredentialStoreInterface|null
    {
        try {
            $driver = \strtolower(\trim((string)Env::get(
                'wls.frontend_worker_session_store_driver',
                'local',
            )));
        } catch (\Throwable $exception) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker session store configuration is unavailable.',
                503,
                $exception,
            );
        }

        if ($driver === 'local') {
            return null;
        }
        if ($driver === 'database') {
            try {
                $store = ObjectManager::getInstance(DatabaseFrontendWorkerCredentialStore::class);
                if (!$store instanceof FrontendWorkerCredentialStoreInterface) {
                    throw new \RuntimeException('Invalid database Worker credential store.');
                }
                if ((string)Env::system('deploy', 'prod') === 'prod' && !$store->isShared()) {
                    throw new \RuntimeException(
                        'SQLite is not eligible for a production shared Worker credential store.',
                    );
                }
                if ((string)Env::system('deploy', 'prod') === 'prod') {
                    $this->assertProductionDatabaseDurabilityAttestation();
                }
                return $store;
            } catch (FrontendQueryException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                throw new FrontendQueryException(
                    'worker_store_unavailable',
                    'Database worker credential store is unavailable.',
                    503,
                    $exception,
                );
            }
        }
        if ($driver !== 'redis') {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Unsupported worker session store driver.',
                503,
            );
        }

        try {
            $adapterFactory = new AdapterFactory();
            $redisConfig = $adapterFactory->getDriverConfig('redis');
            if (!isset($redisConfig['host']) && isset($redisConfig['server'])) {
                $redisConfig['host'] = $redisConfig['server'];
            }
            $sharedTopology = $this->assertSharedRedisConfiguration($redisConfig);
            $redisConfig['unserialize_allowed_classes'] = false;
            $adapter = new RedisAdapter('frontend_worker_session', $redisConfig);
            if (!$adapter instanceof AtomicCacheAdapterInterface) {
                throw new \RuntimeException('Configured Redis adapter does not support compare-and-set.');
            }
            $ttl = (int)Env::get('wls.frontend_worker_session_shared_store_ttl_seconds', 86400);

            return new AtomicCacheFrontendWorkerStateStore($adapter, 'redis', $ttl, $sharedTopology);
        } catch (FrontendQueryException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Shared worker session store is unavailable.',
                503,
                $exception,
            );
        }
    }

    private function assertProductionDatabaseDurabilityAttestation(): void
    {
        $config = Env::get('wls.frontend_worker_credential_store', []);
        if (!\is_array($config)
            || ($config['production_rpo_zero_attested'] ?? null) !== true
            || !\is_string($config['production_topology_id'] ?? null)
            || \preg_match(
                self::RUNTIME_IDENTIFIER_PATTERN,
                (string)$config['production_topology_id'],
            ) !== 1) {
            throw new \RuntimeException(
                'Production Worker credential database requires an explicit RPO0 topology attestation.',
            );
        }
    }

    /** @param array<string, mixed> $config */
    private function assertSharedRedisConfiguration(array $config): bool
    {
        if (!\extension_loaded('redis')) {
            throw new \RuntimeException('The Redis extension is required for the shared Worker session store.');
        }

        $connectTimeout = (float)($config['timeout'] ?? 0.0);
        $readTimeout = (float)($config['read_timeout'] ?? $connectTimeout);
        if ($connectTimeout <= 0.0 || $connectTimeout > 0.5
            || $readTimeout <= 0.0 || $readTimeout > 0.5) {
            throw new \RuntimeException('Shared Worker Redis timeouts must be within (0, 0.5] seconds.');
        }

        $host = \strtolower(\trim((string)($config['host'] ?? '')));
        $hostWithoutScheme = \preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host) ?? '';
        $hostWithoutScheme = \trim($hostWithoutScheme, '[]');
        $loopback = $hostWithoutScheme === 'localhost'
            || $hostWithoutScheme === '::1'
            || \str_starts_with($hostWithoutScheme, '127.');
        $sharedTopology = $hostWithoutScheme !== '' && !$loopback;

        if ((string)Env::system('deploy', 'prod') !== 'prod') {
            return $sharedTopology;
        }

        throw new \RuntimeException(
            'The snapshot-CAS Redis Worker store is not eligible for production rollout; '
            . 'use the dedicated durable credential store.',
        );
    }

    /**
     * @param resource $lock
     */
    private function acquireStoreLock(mixed $lock): bool
    {
        $timeoutMs = $this->resolveLockTimeoutMs();
        $deadline = \microtime(true) + ($timeoutMs / 1000);

        do {
            if (\flock($lock, LOCK_EX | LOCK_NB)) {
                return true;
            }

            SchedulerSystem::usleep(1000);
        } while (\microtime(true) < $deadline);

        throw new FrontendQueryException(
            'auth_error',
            'Worker session store is busy.',
            503
        );
    }

    private function resolveLockTimeoutMs(): int
    {
        $configured = (int)Env::get('wls.frontend_worker_session_lock_timeout_ms', self::DEFAULT_LOCK_TIMEOUT_MS);
        if ($configured <= 0) {
            return self::DEFAULT_LOCK_TIMEOUT_MS;
        }

        return \max(1, \min(1000, $configured));
    }

    private function ensureStoreDirectory(): void
    {
        $dir = self::STORE_DIRECTORY;
        if (\is_link($dir)) {
            throw new FrontendQueryException('worker_store_unavailable', 'Worker session store directory is unsafe.', 503);
        }
        if (!\is_dir($dir) && !\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            throw new FrontendQueryException('auth_error', 'Worker session store directory is unavailable.', 503);
        }
        if (!\chmod($dir, 0700)) {
            throw new FrontendQueryException('worker_store_unavailable', 'Worker session store directory is not private.', 503);
        }

        \clearstatcache(true, $dir);
        $stat = @\lstat($dir);
        if (!\is_array($stat)
            || (($stat['mode'] ?? 0) & 0170000) !== 0040000
            || (($stat['mode'] ?? 0) & 0077) !== 0
            || !$this->isOwnedByCurrentProcess($stat)) {
            throw new FrontendQueryException('worker_store_unavailable', 'Worker session store directory is unsafe.', 503);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readStore(): array
    {
        if (!\is_file(self::STORE_FILE)) {
            return [];
        }

        $this->repairOwnedPrivateRegularFile(self::STORE_FILE);
        $this->assertPrivateRegularFile(self::STORE_FILE);

        $content = \file_get_contents(self::STORE_FILE);
        if ($content === false) {
            throw new FrontendQueryException('worker_store_unavailable', 'Worker session store read failed.', 503);
        }
        if (\trim($content) === '') {
            return [];
        }

        try {
            $decoded = \json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker session store is corrupted.',
                503,
                $exception,
            );
        }

        if (!\is_array($decoded)) {
            throw new FrontendQueryException('worker_store_unavailable', 'Worker session store is invalid.', 503);
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $store
     */
    private function writeStore(array $store): void
    {
        $json = \json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (\strlen($json) > self::MAX_STORE_BYTES) {
            throw new FrontendQueryException(
                'worker_capacity_exhausted',
                'Worker session state exceeds the storage limit.',
                503,
            );
        }
        $temporary = self::STORE_FILE . '.tmp.' . $this->randomToken(12);
        $handle = @\fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker session store temporary file is unavailable.',
                503,
            );
        }

        $renamed = false;
        try {
            if (!\chmod($temporary, 0600)) {
                throw new \RuntimeException('Unable to secure worker store temporary file.');
            }
            $this->assertPrivateOpenFile($handle, $temporary);

            $remaining = $json;
            while ($remaining !== '') {
                $written = \fwrite($handle, $remaining);
                if (!\is_int($written) || $written < 1) {
                    throw new \RuntimeException('Unable to write worker store temporary file.');
                }
                $remaining = (string)\substr($remaining, $written);
            }
            if (!\fflush($handle)) {
                throw new \RuntimeException('Unable to flush worker store temporary file.');
            }
            if (\function_exists('fsync') && !\fsync($handle)) {
                throw new \RuntimeException('Unable to sync worker store temporary file.');
            }
            \fclose($handle);
            $handle = null;

            if (!\rename($temporary, self::STORE_FILE)) {
                throw new \RuntimeException('Unable to atomically replace worker store.');
            }
            $renamed = true;
            if (!\chmod(self::STORE_FILE, 0600)) {
                throw new \RuntimeException('Unable to secure worker store file permissions.');
            }
            $this->assertPrivateRegularFile(self::STORE_FILE);
        } catch (\Throwable $exception) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Worker session store write failed.',
                503,
                $exception,
            );
        } finally {
            if (\is_resource($handle)) {
                \fclose($handle);
            }
            if (!$renamed && \is_file($temporary) && !\is_link($temporary)) {
                @\unlink($temporary);
            }
        }
    }

    private function assertPrivateRegularFile(string $path, bool $allowMissing = false): void
    {
        \clearstatcache(true, $path);
        if (!\file_exists($path)) {
            if ($allowMissing) {
                return;
            }
            throw new FrontendQueryException('worker_store_unavailable', 'Worker session store file is missing.', 503);
        }
        if (\is_link($path)) {
            throw new FrontendQueryException('worker_store_unavailable', 'Worker session store file is unsafe.', 503);
        }

        $stat = @\lstat($path);
        if (!\is_array($stat)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || (($stat['mode'] ?? 0) & 0077) !== 0
            || !$this->isOwnedByCurrentProcess($stat)) {
            throw new FrontendQueryException('worker_store_unavailable', 'Worker session store file is unsafe.', 503);
        }
    }

    /**
     * Re-tighten owner-only modes after bulk `chmod ug+rw` / deploy permission sweeps.
     */
    private function repairOwnedPrivateRegularFile(string $path, int $mode = 0600): void
    {
        \clearstatcache(true, $path);
        if (!\is_file($path) || \is_link($path)) {
            return;
        }

        $stat = @\lstat($path);
        if (!\is_array($stat)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || !$this->isOwnedByCurrentProcess($stat)) {
            return;
        }
        if ((($stat['mode'] ?? 0) & 0077) === 0) {
            return;
        }
        if (!\chmod($path, $mode)) {
            throw new FrontendQueryException(
                'worker_store_unavailable',
                'Unable to restore private worker store file permissions.',
                503,
            );
        }
        \clearstatcache(true, $path);
    }

    /** @param resource $handle */
    private function assertPrivateOpenFile(mixed $handle, string $path): void
    {
        \clearstatcache(true, $path);
        $opened = @\fstat($handle);
        $linked = @\lstat($path);
        if (!\is_array($opened)
            || !\is_array($linked)
            || \is_link($path)
            || (($opened['mode'] ?? 0) & 0170000) !== 0100000
            || (($opened['mode'] ?? 0) & 0077) !== 0
            || ($opened['dev'] ?? null) !== ($linked['dev'] ?? null)
            || ($opened['ino'] ?? null) !== ($linked['ino'] ?? null)
            || !$this->isOwnedByCurrentProcess($opened)) {
            throw new \RuntimeException('Worker session store file identity or permissions are unsafe.');
        }
    }

    /** @param array<string|int, mixed> $stat */
    private function isOwnedByCurrentProcess(array $stat): bool
    {
        if (!\function_exists('posix_geteuid')) {
            return true;
        }
        return (int)($stat['uid'] ?? -1) === (int)\posix_geteuid();
    }

    private function randomToken(int $bytes): string
    {
        return \rtrim(\strtr(\base64_encode(\random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function hash(string $value): string
    {
        return \hash('sha256', $value);
    }

    private function buildStreamUrl(string $ticket): string
    {
        try {
            $prefix = \trim((string)(Env::getAreaRoutePrefix('rest_frontend') ?: 'api'), '/');
        } catch (\Throwable) {
            $prefix = 'api';
        }

        if ($prefix === '') {
            $prefix = 'api';
        }

        return '/' . $prefix . '/framework/stream?ticket=' . \rawurlencode($ticket);
    }
}
