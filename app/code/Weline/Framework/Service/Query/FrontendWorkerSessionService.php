<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;

final class FrontendWorkerSessionService
{
    private const SESSION_KEY = 'weline_frontend_worker_sessions';
    private const NONCE_KEY = 'weline_frontend_worker_nonces';
    private const STREAM_TICKET_KEY = 'weline_frontend_worker_stream_tickets';
    private const SESSION_TTL = 600;
    private const NONCE_TTL = 180;
    private const STREAM_TICKET_TTL = 60;
    private const CLEANUP_INTERVAL_SECONDS = 15;
    private const STORE_FORCE_CLEANUP_BYTES = 131072;
    private const DEFAULT_LOCK_TIMEOUT_MS = 120;
    private const MAX_ACTIVE_SESSIONS = 256;
    private const MAX_NONCES_PER_SCOPE = 128;
    private const STORE_FILE = BP . 'var' . DS . 'cache' . DS . 'frontend_worker_store.json';
    private const LOCK_FILE = BP . 'var' . DS . 'cache' . DS . 'frontend_worker_store.lock';
    private const META_KEY = '_meta';

    /**
     * @return array{worker_session_token:string, signing_secret:string, expires_at:int, deploy_version:string, worker_build_id:string}
     */
    public function createSession(string $deployVersion, string $workerBuildId): array
    {
        $now = \time();
        $token = $this->randomToken(32);
        $secret = $this->randomToken(32);

        return $this->withStore(function (array &$store) use ($deployVersion, $workerBuildId, $now, $token, $secret): array {
            $sessions = $this->getStoreArray($store, self::SESSION_KEY);
            $this->cleanupSessions($sessions, $now);
            $this->trimSessions($sessions);

            $sessions[$this->hash($token)] = [
                'secret' => $secret,
                'deploy_version' => $deployVersion,
                'worker_build_id' => $workerBuildId,
                'created_at' => $now,
                'expires_at' => $now + self::SESSION_TTL,
            ];
            $store[self::SESSION_KEY] = $sessions;

            return [
                'worker_session_token' => $token,
                'signing_secret' => $secret,
                'expires_at' => $now + self::SESSION_TTL,
                'deploy_version' => $deployVersion,
                'worker_build_id' => $workerBuildId,
            ];
        });
    }

    /**
     * @return array{secret:string, deploy_version:string, worker_build_id:string, created_at:int, expires_at:int}
     */
    public function validateSession(string $token, string $deployVersion, string $workerBuildId): array
    {
        if ($token === '') {
            throw new FrontendQueryException('auth_error', 'Missing worker session token.', 401);
        }

        $now = \time();

        return $this->withStore(function (array &$store) use ($token, $deployVersion, $workerBuildId, $now): array {
            $sessions = $this->getStoreArray($store, self::SESSION_KEY);
            $this->cleanupSessions($sessions, $now);
            $store[self::SESSION_KEY] = $sessions;

            return $this->assertSession($sessions[$this->hash($token)] ?? null, $deployVersion, $workerBuildId, $now);
        });
    }

    public function consumeNonce(string $token, string $nonce): void
    {
        if ($nonce === '' || \strlen($nonce) > 128) {
            throw new FrontendQueryException('auth_error', 'Invalid worker nonce.', 401);
        }

        $now = \time();
        $scope = $this->hash($token);
        $this->withStore(function (array &$store) use ($scope, $nonce, $now): void {
            $allNonces = $this->getStoreArray($store, self::NONCE_KEY);
            $nonces = \is_array($allNonces[$scope] ?? null) ? $allNonces[$scope] : [];

            $this->cleanupNonceScope($nonces, $now);

            if (isset($nonces[$nonce])) {
                $allNonces[$scope] = $nonces;
                $store[self::NONCE_KEY] = $allNonces;
                throw new FrontendQueryException('auth_error', 'Worker nonce has already been used.', 401);
            }

            $nonces[$nonce] = $now + self::NONCE_TTL;
            $this->trimNonceScope($nonces);
            $allNonces[$scope] = $nonces;
            $store[self::NONCE_KEY] = $allNonces;
        });
    }

    public function cleanupExpired(): void
    {
        $now = \time();
        $this->withStore(function (array &$store) use ($now): void {
            $this->cleanupStoreIfNeeded($store, $now);
        });
    }

    /**
     * Validate the worker session and consume the nonce under a single store lock.
     *
     * @return array{secret:string, deploy_version:string, worker_build_id:string, created_at:int, expires_at:int}
     */
    public function validateSessionAndConsumeNonce(
        string $token,
        string $deployVersion,
        string $workerBuildId,
        string $nonce
    ): array {
        if ($token === '') {
            throw new FrontendQueryException('auth_error', 'Missing worker session token.', 401);
        }
        if ($nonce === '' || \strlen($nonce) > 128) {
            throw new FrontendQueryException('auth_error', 'Invalid worker nonce.', 401);
        }

        $now = \time();
        $scope = $this->hash($token);

        return $this->withStore(function (array &$store) use ($scope, $deployVersion, $workerBuildId, $nonce, $now): array {
            $this->cleanupStoreIfNeeded($store, $now);

            $sessions = $this->getStoreArray($store, self::SESSION_KEY);
            $session = $this->assertSession($sessions[$scope] ?? null, $deployVersion, $workerBuildId, $now);

            $allNonces = $this->getStoreArray($store, self::NONCE_KEY);
            $nonces = \is_array($allNonces[$scope] ?? null) ? $allNonces[$scope] : [];
            $this->cleanupNonceScope($nonces, $now);

            if (isset($nonces[$nonce])) {
                $allNonces[$scope] = $nonces;
                $store[self::NONCE_KEY] = $allNonces;
                throw new FrontendQueryException('auth_error', 'Worker nonce has already been used.', 401);
            }

            $nonces[$nonce] = $now + self::NONCE_TTL;
            $this->trimNonceScope($nonces);
            $allNonces[$scope] = $nonces;
            $store[self::NONCE_KEY] = $allNonces;

            return $session;
        });
    }

    /**
     * @param array<string, mixed> $params
     * @return array{ticket:string, channel:string, params:array<string, mixed>, expires_at:int, url:string}
     */
    public function createStreamTicket(string $channel, array $params = []): array
    {
        $now = \time();
        $ticket = $this->randomToken(24);

        return $this->withStore(function (array &$store) use ($channel, $params, $now, $ticket): array {
            $tickets = $this->getStoreArray($store, self::STREAM_TICKET_KEY);
            foreach ($tickets as $storedTicket => $payload) {
                if (!\is_array($payload) || (int)($payload['expires_at'] ?? 0) < $now) {
                    unset($tickets[$storedTicket]);
                }
            }

            $tickets[$this->hash($ticket)] = [
                'channel' => $channel,
                'params' => $params,
                'expires_at' => $now + self::STREAM_TICKET_TTL,
            ];
            $store[self::STREAM_TICKET_KEY] = $tickets;

            return [
                'ticket' => $ticket,
                'channel' => $channel,
                'params' => $params,
                'expires_at' => $now + self::STREAM_TICKET_TTL,
                'url' => $this->buildStreamUrl($ticket),
            ];
        });
    }

    /**
     * @return array{channel:string, params:array<string, mixed>, expires_at:int}
     */
    public function consumeStreamTicket(string $ticket): array
    {
        if ($ticket === '') {
            throw new FrontendQueryException('auth_error', 'Missing worker stream ticket.', 401);
        }

        $now = \time();
        $ticketHash = $this->hash($ticket);

        return $this->withStore(function (array &$store) use ($ticketHash, $now): array {
            $tickets = $this->getStoreArray($store, self::STREAM_TICKET_KEY);
            $payload = null;

            foreach ($tickets as $storedTicket => $storedPayload) {
                if (!\is_array($storedPayload) || (int)($storedPayload['expires_at'] ?? 0) < $now) {
                    unset($tickets[$storedTicket]);
                    continue;
                }
                if ($storedTicket === $ticketHash) {
                    $payload = $storedPayload;
                    unset($tickets[$storedTicket]);
                }
            }

            $store[self::STREAM_TICKET_KEY] = $tickets;

            if (!\is_array($payload)) {
                throw new FrontendQueryException('auth_error', 'Invalid or expired worker stream ticket.', 401);
            }

            $channel = (string)($payload['channel'] ?? '');
            $params = $payload['params'] ?? [];
            if ($channel === '' || !\is_array($params)) {
                throw new FrontendQueryException('protocol_error', 'Invalid worker stream ticket payload.', 400);
            }

            return [
                'channel' => $channel,
                'params' => $params,
                'expires_at' => (int)($payload['expires_at'] ?? $now),
            ];
        });
    }

    private function cleanupSessions(array &$sessions, int $now): void
    {
        foreach ($sessions as $key => $session) {
            if (!\is_array($session) || (int)($session['expires_at'] ?? 0) < $now) {
                unset($sessions[$key]);
            }
        }
    }

    /**
     * @return array{secret:string, deploy_version:string, worker_build_id:string, created_at:int, expires_at:int}
     */
    private function assertSession(mixed $session, string $deployVersion, string $workerBuildId, int $now): array
    {
        if (!\is_array($session)) {
            throw new FrontendQueryException('auth_error', 'Invalid worker session token.', 401);
        }
        if ((int)($session['expires_at'] ?? 0) < $now) {
            throw new FrontendQueryException('auth_error', 'Expired worker session token.', 401);
        }
        if ((string)($session['deploy_version'] ?? '') !== $deployVersion) {
            throw new FrontendQueryException('auth_error', 'Worker session deployment mismatch.', 401);
        }
        if ((string)($session['worker_build_id'] ?? '') !== $workerBuildId) {
            throw new FrontendQueryException('auth_error', 'Worker build mismatch.', 401);
        }

        return [
            'secret' => (string)($session['secret'] ?? ''),
            'deploy_version' => (string)($session['deploy_version'] ?? ''),
            'worker_build_id' => (string)($session['worker_build_id'] ?? ''),
            'created_at' => (int)($session['created_at'] ?? 0),
            'expires_at' => (int)($session['expires_at'] ?? 0),
        ];
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
        $this->trimSessions($sessions);
        $store[self::SESSION_KEY] = $sessions;

        $allNonces = $this->getStoreArray($store, self::NONCE_KEY);
        foreach ($allNonces as $scope => $nonces) {
            if (!isset($sessions[$scope]) || !\is_array($nonces)) {
                unset($allNonces[$scope]);
                continue;
            }

            $this->cleanupNonceScope($nonces, $now);
            $this->trimNonceScope($nonces);
            if ($nonces === []) {
                unset($allNonces[$scope]);
                continue;
            }

            $allNonces[$scope] = $nonces;
        }
        $store[self::NONCE_KEY] = $allNonces;

        $meta['last_cleanup_at'] = $now;
        $store[self::META_KEY] = $meta;
    }

    private function cleanupNonceScope(array &$nonces, int $now): void
    {
        foreach ($nonces as $storedNonce => $expiresAt) {
            if ((int)$expiresAt < $now) {
                unset($nonces[$storedNonce]);
            }
        }
    }

    private function trimSessions(array &$sessions): void
    {
        if (\count($sessions) <= self::MAX_ACTIVE_SESSIONS) {
            return;
        }

        \uasort($sessions, static function (mixed $left, mixed $right): int {
            return (int)($left['expires_at'] ?? 0) <=> (int)($right['expires_at'] ?? 0);
        });

        while (\count($sessions) > self::MAX_ACTIVE_SESSIONS) {
            $oldest = \array_key_first($sessions);
            if ($oldest === null) {
                return;
            }
            unset($sessions[$oldest]);
        }
    }

    private function trimNonceScope(array &$nonces): void
    {
        if (\count($nonces) <= self::MAX_NONCES_PER_SCOPE) {
            return;
        }

        \asort($nonces, SORT_NUMERIC);
        while (\count($nonces) > self::MAX_NONCES_PER_SCOPE) {
            $oldest = \array_key_first($nonces);
            if ($oldest === null) {
                return;
            }
            unset($nonces[$oldest]);
        }
    }

    private function getStoreArray(array $store, string $key): array
    {
        $value = $store[$key] ?? [];
        return \is_array($value) ? $value : [];
    }

    /**
     * @template T
     * @param callable(array<string, mixed>&):T $callback
     * @return T
     */
    private function withStore(callable $callback): mixed
    {
        $this->ensureStoreDirectory();

        $lock = \fopen(self::LOCK_FILE, 'c');
        if ($lock === false) {
            throw new FrontendQueryException('auth_error', 'Worker session store lock is unavailable.', 503);
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
        $dir = \dirname(self::STORE_FILE);
        if (!\is_dir($dir) && !\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new FrontendQueryException('auth_error', 'Worker session store directory is unavailable.', 503);
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

        $content = \file_get_contents(self::STORE_FILE);
        if ($content === false || \trim($content) === '') {
            return [];
        }

        try {
            $decoded = \json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $store
     */
    private function writeStore(array $store): void
    {
        $json = \json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (\file_put_contents(self::STORE_FILE, $json) === false) {
            throw new FrontendQueryException('auth_error', 'Worker session store write failed.', 503);
        }
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
