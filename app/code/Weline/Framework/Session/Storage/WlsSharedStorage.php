<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Storage;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;

final class WlsSharedStorage implements SessionStorageInterface
{
    private array $config;
    private int $defaultTtl;
    private ?SharedSessionStateInterface $sessionFacade = null;
    private ?SessionStorageInterface $fallbackStorage = null;
    private int $sharedUnavailableUntilTs = 0;
    private int $retryIntervalSec;
    private string $fallbackReason = '';
    /** @var null|callable */
    private $sessionFacadeFactory;

    public function __construct(
        array $config = [],
        ?callable $sessionFacadeFactory = null,
        ?SessionStorageInterface $fallbackStorage = null
    )
    {
        $this->config = $config;
        $this->defaultTtl = (int) ($config['lifetime'] ?? $config['session_ttl'] ?? 3600);
        $this->retryIntervalSec = \max(1, (int) ($config['fallback_retry_interval_sec'] ?? 5));
        $this->sessionFacadeFactory = $sessionFacadeFactory;
        $this->fallbackStorage = $fallbackStorage;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    private static function shouldLogSessionOperations(): bool
    {
        return \function_exists('w_log_info')
            && (bool)\Weline\Framework\App\Env::get('wls.debug.hot_path_logs', false);
    }

    public function read(string $sessionId): array
    {
        $facade = $this->sessionFacade();
        if ($facade === null) {
            $data = $this->fallbackStorage()->read($sessionId);
        } else {
            $data = $facade->read($sessionId);
            // Session Server 曾短暂不可用时写入会落在文件；恢复后若只读共享存储会得到空数组，
            // 表现为「已登录 Cookie 仍在但 WF_BACKEND_USER_ID 丢失」→ ACL not_logged_in 循环。
            if ($data === []) {
                $fileData = $this->fallbackStorage()->read($sessionId);
                if ($fileData !== []) {
                    $ttl = $this->defaultTtl > 0 ? $this->defaultTtl : 3600;
                    $repairOk = $facade->write($sessionId, $fileData, $ttl);
                    if ($repairOk) {
                        $data = $fileData;
                        if (\function_exists('w_log_warning')) {
                            w_log_warning(
                                '[WlsSharedStorage] Session 已从文件回灌到共享存储（此前可能仅写入了 fallback 文件）。sid=' . \substr($sessionId, 0, 8) . '...',
                                ['keys' => \count($data)],
                                'session'
                            );
                        }
                    } else {
                        $this->markSharedUnavailable('Shared session facade lost health during read repair');
                        return $fileData;
                    }
                }
            }
        }
        if (self::shouldLogSessionOperations()) {
            w_log_info(
                '[WlsSharedStorage] read sid=' . \substr($sessionId, 0, 8) . '... keys=' . \count($data),
                [],
                'session'
            );
        }

        return $data;
    }

    public function write(string $sessionId, array $data, int $ttl): bool
    {
        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        $facade = $this->sessionFacade();
        if ($facade !== null) {
            $ok = $facade->write($sessionId, $data, $ttl);
            if ($ok) {
                $this->persistFallbackMirror($sessionId, $data, $ttl);
            } else {
                $this->markSharedUnavailable('Shared session facade is not healthy after write');
                $ok = $this->fallbackStorage()->write($sessionId, $data, $ttl);
            }
        } else {
            $ok = $this->fallbackStorage()->write($sessionId, $data, $ttl);
        }
        if (self::shouldLogSessionOperations()) {
            w_log_info(
                '[WlsSharedStorage] write sid=' . \substr($sessionId, 0, 8) . '... keys=' . \count($data) . ' ttl=' . $ttl . ' ok=' . ($ok ? '1' : '0'),
                [],
                'session'
            );
        }
        if (!$ok && \function_exists('w_log_warning') && $facade !== null) {
            w_log_warning(
                '[WlsSharedStorage] Session write failed, shared session facade is not healthy. sessionId=' . \substr($sessionId, 0, 8) . '...',
                ['connected' => false, 'fallback_reason' => $this->fallbackReason],
                'session'
            );
        }

        return $ok;
    }

    public function destroy(string $sessionId): bool
    {
        $facade = $this->sessionFacade();
        if ($facade !== null) {
            $okShared = $facade->destroy($sessionId);
            if (!$okShared) {
                $this->markSharedUnavailable('Shared session facade lost health during destroy');
            }
            $okFile = $this->fallbackStorage()->destroy($sessionId);
            $ok = $okShared && $okFile;
        } else {
            $ok = $this->fallbackStorage()->destroy($sessionId);
        }
        if (self::shouldLogSessionOperations()) {
            w_log_info(
                '[WlsSharedStorage] destroy sid=' . \substr($sessionId, 0, 8) . '... ok=' . ($ok ? '1' : '0'),
                [],
                'session'
            );
        }

        return $ok;
    }

    public function exists(string $sessionId): bool
    {
        $facade = $this->sessionFacade();

        return $facade !== null
            ? $facade->exists($sessionId)
            : $this->fallbackStorage()->exists($sessionId);
    }

    public function touch(string $sessionId, int $ttl): bool
    {
        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        $facade = $this->sessionFacade();

        if ($facade !== null) {
            $ok = $facade->touch($sessionId, $ttl);
            $this->fallbackStorage()->touch($sessionId, $ttl);
            return $ok;
        }

        return $this->fallbackStorage()->touch($sessionId, $ttl);
    }

    public function gc(int $maxLifetime): int
    {
        // 共享存储 GC 与 var/session 镜像文件 GC 必须同时做：主存储淘汰后镜像可能仍留在磁盘。
        $fileCleaned = $this->fallbackStorage()->gc($maxLifetime);
        $facade = $this->sessionFacade();
        if ($facade === null) {
            return $fileCleaned;
        }

        return $facade->gc($maxLifetime) + $fileCleaned;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function isConnected(): bool
    {
        return $this->sessionFacade !== null && $this->sessionFacade->ping();
    }

    public function disconnect(): void
    {
        if ($this->sessionFacade !== null) {
            $this->sessionFacade->disconnect();
        }
        if ($this->fallbackStorage instanceof FileStorage) {
            // No persistent connection to close for file fallback.
        }
    }

    public function getStats(): array
    {
        $facade = $this->sessionFacade();
        if ($facade === null) {
            return [
                'mode' => 'file_fallback',
                'connected' => false,
                'fallback_reason' => $this->fallbackReason,
                'retry_after' => $this->sharedUnavailableUntilTs > 0 ? $this->sharedUnavailableUntilTs : null,
            ];
        }

        $stats = $facade->getStats();
        $stats['mode'] = 'strong_consistency';

        return $stats;
    }

    public function ping(): bool
    {
        $facade = $this->sessionFacade();

        return $facade !== null && $facade->ping();
    }

    public function list(array $options = []): array
    {
        $facade = $this->sessionFacade();
        $payload = [
            'filter' => \is_array($options['filter'] ?? null) ? $options['filter'] : [],
            'limit' => (int) ($options['limit'] ?? 50),
        ];

        return $facade !== null
            ? $facade->list($payload)
            : $this->fallbackStorage()->list($payload);
    }

    private function sessionFacade(): ?SharedSessionStateInterface
    {
        if ($this->sessionFacade === null) {
            if ($this->sharedUnavailableUntilTs > \time()) {
                return null;
            }

            try {
                $config = $this->config;
                $config['prefer_direct_connect'] = $config['prefer_direct_connect'] ?? true;
                $config['fail_fast_on_unhealthy'] = $config['fail_fast_on_unhealthy'] ?? true;

                if ($this->sessionFacadeFactory !== null) {
                    $factory = $this->sessionFacadeFactory;
                    $facade = $factory($config);
                    if (!$facade instanceof SharedSessionStateInterface) {
                        throw new \RuntimeException('Session facade factory must return SharedSessionStateInterface.');
                    }
                    $this->sessionFacade = $facade;
                } else {
                    $implementation = (new ServiceProviderRegistry())->implementationFor(SharedSessionStateInterface::class);
                    if ($implementation === null) {
                        throw new \RuntimeException('No shared session state provider is registered. Run: php bin/w framework:compile');
                    }
                    $facade = ObjectManager::getInstance($implementation, [$config], false);
                    if (!$facade instanceof SharedSessionStateInterface) {
                        throw new \RuntimeException("Shared session provider {$implementation} violates its contract.");
                    }
                    $this->sessionFacade = $facade;
                }
                $this->sharedUnavailableUntilTs = 0;
                $this->fallbackReason = '';
            } catch (\Throwable $throwable) {
                $this->sharedUnavailableUntilTs = \time() + $this->retryIntervalSec;
                $this->fallbackReason = \trim($throwable->getMessage()) ?: 'Shared session facade is not healthy';

                if (\function_exists('w_log_warning')) {
                    w_log_warning(
                        '[WlsSharedStorage] Shared session unavailable, falling back to file storage.',
                        [
                            'reason' => $this->fallbackReason,
                            'retry_after' => $this->sharedUnavailableUntilTs,
                        ],
                        'session'
                    );
                }

                return null;
            }
        }

        return $this->sessionFacade;
    }

    private function fallbackStorage(): SessionStorageInterface
    {
        if ($this->fallbackStorage === null) {
            $this->fallbackStorage = new FileStorage($this->config);
        }

        return $this->fallbackStorage;
    }

    private function persistFallbackMirror(string $sessionId, array $data, int $ttl): void
    {
        $ok = $this->fallbackStorage()->write($sessionId, $data, $ttl);
        if (!$ok && \function_exists('w_log_warning')) {
            w_log_warning(
                '[WlsSharedStorage] Fallback mirror write failed. sessionId=' . \substr($sessionId, 0, 8) . '...',
                ['keys' => \count($data), 'ttl' => $ttl],
                'session'
            );
        }
    }

    private function markSharedUnavailable(string $reason): void
    {
        $this->sharedUnavailableUntilTs = \time() + $this->retryIntervalSec;
        $this->fallbackReason = $reason;
        if ($this->sessionFacade !== null) {
            $this->sessionFacade->disconnect();
            $this->sessionFacade = null;
        }
    }
}
