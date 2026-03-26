<?php

declare(strict_types=1);

namespace Weline\Server\Session\Backend;

use Weline\Server\Service\SessionStateFacade;

final class WlsSessionBackend implements SessionBackendInterface
{
    private ?SessionStateFacade $sessionFacade = null;
    private array $config;
    private bool $connected = false;
    private bool $preconnect;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->preconnect = (bool) ($config['preconnect'] ?? false);

        if ($this->preconnect) {
            $this->connect();
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    private function getFacade(): SessionStateFacade
    {
        if ($this->sessionFacade === null) {
            $this->sessionFacade = new SessionStateFacade($this->config);
        }

        return $this->sessionFacade;
    }

    public function connect(): bool
    {
        $this->connected = $this->getFacade()->ping();

        return $this->connected;
    }

    public function disconnect(): void
    {
        if ($this->sessionFacade !== null) {
            $this->sessionFacade->disconnect();
        }
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->sessionFacade !== null && $this->sessionFacade->ping();
    }

    public function get(string $sessionId, ?string $key = null): mixed
    {
        $data = $this->getFacade()->read($sessionId);
        if ($key === null) {
            return $data;
        }

        return $data[$key] ?? null;
    }

    public function set(string $sessionId, string $key, mixed $value, int $ttl = 3600): bool
    {
        $data = $this->getFacade()->read($sessionId);
        $data[$key] = $value;

        return $this->getFacade()->write($sessionId, $data, $ttl);
    }

    public function delete(string $sessionId, string $key): bool
    {
        $data = $this->getFacade()->read($sessionId);
        if (!\array_key_exists($key, $data)) {
            return false;
        }

        unset($data[$key]);

        return $this->getFacade()->write(
            $sessionId,
            $data,
            (int) ($this->config['lifetime'] ?? $this->config['session_ttl'] ?? 3600)
        );
    }

    public function destroy(string $sessionId): bool
    {
        return $this->getFacade()->destroy($sessionId);
    }

    public function getAll(string $sessionId): array
    {
        return $this->getFacade()->read($sessionId);
    }

    public function setAll(string $sessionId, array $data, int $ttl = 3600): bool
    {
        return $this->getFacade()->write($sessionId, $data, $ttl);
    }

    public function gc(int $maxLifetime): int
    {
        return $this->getFacade()->gc($maxLifetime);
    }

    public function touch(string $sessionId, int $ttl = 3600): bool
    {
        return $this->getFacade()->touch($sessionId, $ttl);
    }

    public function exists(string $sessionId): bool
    {
        return $this->getFacade()->exists($sessionId);
    }

    public function getStats(): array
    {
        $stats = $this->getFacade()->getStats();
        $stats['backend'] = 'wls';

        return $stats;
    }

    public function ping(): bool
    {
        return $this->getFacade()->ping();
    }

    public function persist(): bool
    {
        return $this->getFacade()->persist();
    }

    public function healthCheck(): bool
    {
        return $this->getFacade()->ping();
    }
}
