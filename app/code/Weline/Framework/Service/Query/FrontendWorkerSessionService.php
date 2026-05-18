<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query;

final class FrontendWorkerSessionService
{
    private const SESSION_KEY = 'weline_frontend_worker_sessions';
    private const NONCE_KEY = 'weline_frontend_worker_nonces';
    private const STREAM_TICKET_KEY = 'weline_frontend_worker_stream_tickets';
    private const SESSION_TTL = 600;
    private const NONCE_TTL = 180;
    private const STREAM_TICKET_TTL = 60;
    private const STORE_FILE = BP . 'var' . DS . 'cache' . DS . 'frontend_worker_store.json';
    private const LOCK_FILE = BP . 'var' . DS . 'cache' . DS . 'frontend_worker_store.lock';

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

            $session = $sessions[$this->hash($token)] ?? null;
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

            return $session;
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

            foreach ($nonces as $storedNonce => $expiresAt) {
                if ((int)$expiresAt < $now) {
                    unset($nonces[$storedNonce]);
                }
            }

            if (isset($nonces[$nonce])) {
                $allNonces[$scope] = $nonces;
                $store[self::NONCE_KEY] = $allNonces;
                throw new FrontendQueryException('auth_error', 'Worker nonce has already been used.', 401);
            }

            $nonces[$nonce] = $now + self::NONCE_TTL;
            $allNonces[$scope] = $nonces;
            $store[self::NONCE_KEY] = $allNonces;
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
                'url' => '/api/framework/stream?ticket=' . \rawurlencode($ticket),
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

        try {
            if (!\flock($lock, LOCK_EX)) {
                throw new FrontendQueryException('auth_error', 'Worker session store is locked.', 503);
            }

            $store = $this->readStore();
            $result = $callback($store);
            $this->writeStore($store);
            \flock($lock, LOCK_UN);

            return $result;
        } finally {
            \fclose($lock);
        }
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
        if (\file_put_contents(self::STORE_FILE, $json, LOCK_EX) === false) {
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
}
