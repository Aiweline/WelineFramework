<?php

declare(strict_types=1);

namespace Weline\Server\Extends\Module\Weline_Framework\Session;

use Weline\Framework\Session\Driver\SessionDriverHandlerInterface;
use Weline\Framework\Session\Session;
use Weline\Server\Service\SessionStateFacade;

class WlsSharedSession implements SessionDriverHandlerInterface
{
    private SessionStateFacade $sessionFacade;
    private string $currentSessionId = '';
    private int $defaultLifetime = 3600;
    private bool $cookieSet = false;
    private array $localCache = [];
    private bool $localCacheValid = false;
    private array $config;
    private bool $dirty = false;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->defaultLifetime = (int) ($config['lifetime'] ?? 3600);
        $this->sessionFacade = new SessionStateFacade($config);
        $this->initSessionId();

        \register_shutdown_function([$this, 'close']);
    }

    public function __destruct()
    {
        $this->sessionFacade->disconnect();
    }

    private function initSessionId(): void
    {
        $sessionName = Session::session_name;

        if (isset($_COOKIE[$sessionName]) && !empty($_COOKIE[$sessionName])) {
            $this->currentSessionId = (string) $_COOKIE[$sessionName];
            $this->loadSessionData();

            return;
        }

        $this->currentSessionId = \bin2hex(\random_bytes(16));
        $_SESSION = [];
        $this->localCache = [];
        $this->localCacheValid = true;
        $this->setSessionCookie();
    }

    private function loadSessionData(): void
    {
        $data = $this->sessionFacade->read($this->currentSessionId);
        $_SESSION = $data;
        $this->localCache = $data;
        $this->localCacheValid = true;
    }

    private function setSessionCookie(): void
    {
        if ($this->cookieSet) {
            return;
        }

        $headerCollector = \Weline\Framework\Http\HeaderCollector::getInstance();
        $headerCollector->setCookie(
            Session::session_name,
            $this->currentSessionId,
            \time() + 86400 * 30,
            '/',
            '',
            isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            true,
            'Lax'
        );
        $this->cookieSet = true;
    }

    public function set($name, $value): bool
    {
        $_SESSION[$name] = $value;
        $this->localCache[$name] = $value;
        $this->dirty = true;

        return true;
    }

    public function get($name = null): mixed
    {
        if ($name === null) {
            return $this->localCache;
        }

        return $this->localCache[$name] ?? null;
    }

    public function delete($name): bool
    {
        unset($_SESSION[$name], $this->localCache[$name]);
        $this->dirty = true;

        return true;
    }

    public function open(string $path, string $name): bool
    {
        return $this->sessionFacade->ping();
    }

    public function close(): bool
    {
        if (!$this->dirty || $this->currentSessionId === '') {
            return true;
        }

        $result = $this->sessionFacade->write($this->currentSessionId, $this->localCache, $this->defaultLifetime);
        if ($result) {
            $this->dirty = false;
        }

        return $result;
    }

    public function read(string $id): string|false
    {
        if ($id === $this->currentSessionId && $this->localCacheValid) {
            return $this->localCache === [] ? '' : \serialize($this->localCache);
        }

        $data = $this->sessionFacade->read($id);

        return $data === [] ? '' : \serialize($data);
    }

    public function write(string $id, string $data): bool
    {
        if ($data === '') {
            return true;
        }

        $sessionData = @\unserialize($data);
        if (!\is_array($sessionData)) {
            return false;
        }

        return $this->sessionFacade->write($id, $sessionData, $this->defaultLifetime);
    }

    public function destroy(string $id): bool
    {
        $this->localCache = [];
        $this->localCacheValid = false;
        $this->dirty = false;
        $_SESSION = [];

        return $this->sessionFacade->destroy($id);
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->sessionFacade->gc($max_lifetime);
    }

    public function getSessionId(): string
    {
        return $this->currentSessionId;
    }

    public function refresh(): void
    {
        $this->localCacheValid = false;
        $this->loadSessionData();
    }

    public function getStats(): array
    {
        return $this->sessionFacade->getStats();
    }

    public function getBackend(): SessionStateFacade
    {
        return $this->sessionFacade;
    }

    public function reset(): void
    {
        $this->currentSessionId = '';
        $this->cookieSet = false;
        $this->localCache = [];
        $this->localCacheValid = false;
        $this->dirty = false;
        $_SESSION = [];
    }

    public function isDegradedMode(): bool
    {
        return false;
    }

    public function getPendingWrites(): array
    {
        return [];
    }
}
