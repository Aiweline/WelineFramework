<?php

declare(strict_types=1);

namespace Weline\Framework\Router;

use Weline\Framework\Cache\Adapter\WlsMemoryAdapter;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;

final class FullPageCacheCoordinator
{
    private const LOCK_TTL_SECONDS = 15;
    private const LOCK_WAIT_TIMEOUT_MS = 1500;
    private const LOCK_WAIT_STEP_MS = 5;

    /**
     * @var array<string, bool>
     */
    private const EXCLUDED_HEADERS = [
        'content-length' => true,
        'connection' => true,
        'server' => true,
        'x-powered-by' => true,
        'set-cookie' => true,
        'x-weline-fpc' => true,
    ];

    private ?CacheManager $cacheManager;
    private ?CachePoolInterface $cachePool;

    public function __construct(?CacheManager $cacheManager = null, ?CachePoolInterface $cachePool = null)
    {
        $this->cacheManager = $cacheManager;
        $this->cachePool = $cachePool;
    }

    public function getCachedResponse(string $method = 'GET'): ?Response
    {
        $response = $this->getUnifiedCachedResponse($method);
        if ($response !== null) {
            return $this->markCacheHit($response);
        }

        $legacyKey = $this->getLegacyCacheKey();
        if ($legacyKey === null) {
            return null;
        }

        $html = $this->cache()->get($legacyKey);
        if (!\is_string($html) || $html === '') {
            return null;
        }

        return $this->markCacheHit(Response::html($html)->setHeader('X-Weline-FPC', 'HIT'));
    }

    /**
     * @return array{driver:string,key:string,token?:string,handle?:resource}|null
     */
    public function acquireBuildLock(string $method = 'GET'): ?array
    {
        $fullUri = $this->getFullUri();
        if (!KeyBuilder::isValidFullPageCacheKey($fullUri)) {
            return null;
        }

        $lockKey = $this->getBuildLockKey($method);
        $adapter = $this->resolveWlsMemoryAdapter();
        if ($adapter !== null) {
            $token = $this->generateLockToken();
            if ($adapter->compareAndSet($lockKey, null, $token, self::LOCK_TTL_SECONDS)) {
                return [
                    'driver' => 'shared',
                    'key' => $lockKey,
                    'token' => $token,
                ];
            }

            return null;
        }

        $handle = $this->acquireFileLock($lockKey);
        if ($handle === null) {
            return null;
        }

        return [
            'driver' => 'file',
            'key' => $lockKey,
            'handle' => $handle,
        ];
    }

    public function waitForPublishedResponse(string $method = 'GET', int $timeoutMs = self::LOCK_WAIT_TIMEOUT_MS): ?Response
    {
        if ($timeoutMs <= 0) {
            return $this->getCachedResponse($method);
        }

        $deadline = \microtime(true) + ($timeoutMs / 1000);
        do {
            $response = $this->getCachedResponse($method);
            if ($response !== null) {
                return $response;
            }

            $remainingMs = (int)\max(0, \ceil(($deadline - \microtime(true)) * 1000));
            if ($remainingMs <= 0) {
                break;
            }

            SchedulerSystem::yieldDelay(\min(self::LOCK_WAIT_STEP_MS, $remainingMs));
        } while (\microtime(true) < $deadline);

        return $this->getCachedResponse($method);
    }

    /**
     * @param array{driver:string,key:string,token?:string,handle?:resource}|null $lock
     */
    public function releaseBuildLock(?array $lock): void
    {
        if ($lock === null) {
            return;
        }

        if (($lock['driver'] ?? '') === 'shared') {
            $adapter = $this->resolveWlsMemoryAdapter();
            $token = (string)($lock['token'] ?? '');
            $key = (string)($lock['key'] ?? '');
            if ($adapter !== null && $token !== '' && $key !== '') {
                $adapter->compareAndSet($key, $token, null, 1);
            }

            return;
        }

        $handle = $lock['handle'] ?? null;
        if (\is_resource($handle)) {
            @\flock($handle, \LOCK_UN);
            @\fclose($handle);
        }
    }

    public function publishResponse(
        Response $response,
        string $url,
        array $rule,
        array $router,
        array $generatedParams,
        string $method = 'GET'
    ): void {
        $fullUri = $this->getFullUri();
        $body = $response->getBody();
        if ($body === '' || !KeyBuilder::isValidFullPageCacheKey($fullUri)) {
            return;
        }

        $payload = [
            KeyBuilder::UNIFIED_CACHE_URL_KEY => $url,
            KeyBuilder::UNIFIED_CACHE_RULE_KEY => $rule,
            KeyBuilder::UNIFIED_CACHE_ROUTER_KEY => $router,
            KeyBuilder::UNIFIED_CACHE_PARAMS_KEY => $generatedParams,
            KeyBuilder::UNIFIED_CACHE_FPC_KEY => $body,
            KeyBuilder::UNIFIED_CACHE_HEADERS_KEY => $this->sanitizeHeaders($response->getHeaders()),
            KeyBuilder::UNIFIED_CACHE_STATUS_KEY => $response->getStatusCode(),
        ];

        $this->cache()->set($this->getUnifiedCacheKey($method), $payload, 3600);

        $legacyKey = $this->getLegacyCacheKey();
        if ($legacyKey !== null) {
            $this->cache()->set($legacyKey, $body, 5);
        }
    }

    private function getUnifiedCachedResponse(string $method): ?Response
    {
        $cached = $this->cache()->get($this->getUnifiedCacheKey($method));
        if (!\is_array($cached)) {
            return null;
        }

        $body = (string)($cached[KeyBuilder::UNIFIED_CACHE_FPC_KEY] ?? '');
        if ($body === '') {
            return null;
        }

        $statusCode = (int)($cached[KeyBuilder::UNIFIED_CACHE_STATUS_KEY] ?? 200);
        $response = Response::fromContent($body, $statusCode);
        $this->applyCachedHeaders($response, $cached[KeyBuilder::UNIFIED_CACHE_HEADERS_KEY] ?? []);
        $response->setHeader('X-Weline-FPC', 'HIT');

        return $response;
    }

    private function applyCachedHeaders(Response $response, mixed $headers): void
    {
        if (!\is_array($headers)) {
            return;
        }

        $normalized = [];
        $isList = \array_is_list($headers);
        if ($isList) {
            foreach ($headers as $headerLine) {
                if (!\is_string($headerLine) || !\str_contains($headerLine, ':')) {
                    continue;
                }

                [$name, $value] = \explode(':', $headerLine, 2);
                $name = \trim($name);
                if ($this->shouldSkipHeader($name)) {
                    continue;
                }

                $headerValue = \trim($value);
                if (!isset($normalized[$name])) {
                    $normalized[$name] = $headerValue;
                    continue;
                }

                if (\is_array($normalized[$name])) {
                    $normalized[$name][] = $headerValue;
                    continue;
                }

                $normalized[$name] = [$normalized[$name], $headerValue];
            }
        } else {
            foreach ($headers as $name => $value) {
                if (!\is_string($name) || $this->shouldSkipHeader($name)) {
                    continue;
                }

                if (\is_array($value)) {
                    $normalized[$name] = \array_values(\array_map('strval', $value));
                } else {
                    $normalized[$name] = (string)$value;
                }
            }
        }

        foreach ($normalized as $name => $value) {
            $response->setHeaders([$name => $value]);
        }
    }

    /**
     * @param array<string, string|array> $headers
     * @return array<string, string|array>
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];
        foreach ($headers as $name => $value) {
            if (!\is_string($name) || $this->shouldSkipHeader($name)) {
                continue;
            }

            if (\is_array($value)) {
                $sanitized[$name] = \array_values(\array_map('strval', $value));
            } else {
                $sanitized[$name] = (string)$value;
            }
        }

        return $sanitized;
    }

    private function shouldSkipHeader(string $name): bool
    {
        return self::EXCLUDED_HEADERS[\strtolower($name)] ?? false;
    }

    private function markCacheHit(Response $response): Response
    {
        WelineEnv::set('response.from_cache', true, 'FullPageCacheCoordinator cache hit');

        return $response;
    }

    private function getUnifiedCacheKey(string $method): string
    {
        return KeyBuilder::buildUnifiedRequestCacheKey('', $method);
    }

    private function getLegacyCacheKey(): ?string
    {
        $fullUri = $this->getFullUri();
        if (!KeyBuilder::isValidFullPageCacheKey($fullUri)) {
            return null;
        }

        return KeyBuilder::build('router', 'fpc:' . $fullUri . ':' . Cookie::getLangLocal());
    }

    private function getBuildLockKey(string $method): string
    {
        return KeyBuilder::build('router', 'fpc-lock:' . $this->getFullUri() . ':' . $method);
    }

    private function getFullUri(): string
    {
        return (string)(\w_env('full_request_uri', '') ?? '');
    }

    private function cache(): CachePoolInterface
    {
        if ($this->cachePool === null) {
            $this->cachePool = $this->cacheManager()->pool('router');
        }

        return $this->cachePool;
    }

    private function cacheManager(): CacheManager
    {
        if ($this->cacheManager === null) {
            $this->cacheManager = ObjectManager::getInstance(CacheManager::class);
        }

        return $this->cacheManager;
    }

    private function resolveWlsMemoryAdapter(): ?WlsMemoryAdapter
    {
        $cache = $this->cache();
        if (!\method_exists($cache, 'getAdapter')) {
            return null;
        }

        $adapter = $cache->getAdapter();
        return $adapter instanceof WlsMemoryAdapter ? $adapter : null;
    }

    /**
     * @return resource|null
     */
    private function acquireFileLock(string $lockKey)
    {
        $lockDir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-locks';
        if (!\is_dir($lockDir) && !@\mkdir($lockDir, 0775, true) && !\is_dir($lockDir)) {
            return null;
        }

        $lockFile = $lockDir . \DIRECTORY_SEPARATOR . \hash('sha256', $lockKey) . '.lock';
        $handle = @\fopen($lockFile, 'c+');
        if ($handle === false) {
            return null;
        }

        if (!@\flock($handle, \LOCK_EX | \LOCK_NB)) {
            @\fclose($handle);
            return null;
        }

        return $handle;
    }

    private function generateLockToken(): string
    {
        try {
            return \bin2hex(\random_bytes(16));
        } catch (\Throwable) {
            return \md5(\uniqid('fpc-lock', true));
        }
    }
}
