<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Server\Service\MasterLeaseRuntimeIdentity;

/**
 * Native/runtime boundary for the Windows listener handoff state machine.
 *
 * Production uses ext-sockets directly. Optional closures keep ownership and
 * cleanup behavior executable on non-Windows CI without pretending that a
 * source-text assertion validates resource lifetime.
 */
final class WindowsListenerHandoffRuntime
{
    private const EXPORT_MUTEX_NAME = 'Local\\Weline.WLS2.WSAPROTOCOL.Export.v1';
    private const WAIT_OBJECT_0 = 0x00000000;
    private const WAIT_ABANDONED = 0x00000080;
    private const WAIT_TIMEOUT = 0x00000102;
    private const WAIT_FAILED = 0xFFFFFFFF;

    /** @var (\Closure(\Socket,int):mixed)|null */
    private readonly ?\Closure $exporter;
    /** @var (\Closure(string):mixed)|null */
    private readonly ?\Closure $importer;
    /** @var (\Closure(string):bool)|null */
    private readonly ?\Closure $releaser;
    /** @var (\Closure(\Socket):void)|null */
    private readonly ?\Closure $closer;
    /** @var (\Closure():float)|null */
    private readonly ?\Closure $monotonicClock;
    /** @var (\Closure():int)|null */
    private readonly ?\Closure $wallClock;
    /** @var (\Closure():int)|null */
    private readonly ?\Closure $pidResolver;
    /** @var (\Closure(int):WindowsListenerHandoffMutexGuard)|null */
    private readonly ?\Closure $mutexAcquirer;
    /** @var (\Closure(string):bool)|null */
    private readonly ?\Closure $publisherCoordinator;

    public function __construct(
        private readonly MasterLeaseRuntimeIdentity $processIdentity
            = new MasterLeaseRuntimeIdentity(),
        ?\Closure $exporter = null,
        ?\Closure $importer = null,
        ?\Closure $releaser = null,
        ?\Closure $closer = null,
        ?\Closure $monotonicClock = null,
        ?\Closure $wallClock = null,
        ?\Closure $pidResolver = null,
        ?\Closure $mutexAcquirer = null,
        ?\Closure $publisherCoordinator = null,
    ) {
        $this->exporter = $exporter;
        $this->importer = $importer;
        $this->releaser = $releaser;
        $this->closer = $closer;
        $this->monotonicClock = $monotonicClock;
        $this->wallClock = $wallClock;
        $this->pidResolver = $pidResolver;
        $this->mutexAcquirer = $mutexAcquirer;
        $this->publisherCoordinator = $publisherCoordinator;
    }

    public function export(\Socket $socket, int $targetPid): mixed
    {
        return $this->exporter !== null
            ? ($this->exporter)($socket, $targetPid)
            : @\socket_wsaprotocol_info_export($socket, $targetPid);
    }

    public function import(string $protocolId): mixed
    {
        return $this->importer !== null
            ? ($this->importer)($protocolId)
            : @\socket_wsaprotocol_info_import($protocolId);
    }

    public function release(string $protocolId): bool
    {
        return $this->releaser !== null
            ? (bool)($this->releaser)($protocolId)
            : @\socket_wsaprotocol_info_release($protocolId) === true;
    }

    public function close(\Socket $socket): void
    {
        if ($this->closer !== null) {
            ($this->closer)($socket);
            return;
        }
        @\socket_close($socket);
    }

    /** @return array{birth:string,pid_namespace_id:string} */
    public function captureProcessIdentity(int $pid): array
    {
        return $this->processIdentity->captureProcessIdentity($pid);
    }

    public function observeProcessIdentity(
        int $pid,
        string $birth,
        string $pidNamespaceId,
    ): string {
        return $this->processIdentity->observeProcessIdentity(
            $pid,
            $birth,
            $pidNamespaceId,
        );
    }

    public function processBirthMatches(int $pid, string $birth): bool
    {
        try {
            $observed = $this->captureProcessIdentity($pid);
        } catch (\Throwable) {
            return false;
        }
        return \preg_match('/\A[a-f0-9]{64}\z/D', $birth) === 1
            && \hash_equals($birth, $observed['birth']);
    }

    public function hostBootId(): string
    {
        return $this->processIdentity->hostBootId();
    }

    public function monotonicNow(): float
    {
        $now = $this->monotonicClock !== null
            ? (float)($this->monotonicClock)()
            : \hrtime(true) / 1_000_000_000;
        if (!\is_finite($now) || $now <= 0.0) {
            throw new \RuntimeException('Windows listener handoff monotonic clock is invalid.');
        }
        return $now;
    }

    public function wallNow(): int
    {
        $now = $this->wallClock !== null
            ? (int)($this->wallClock)()
            : \time();
        if ($now < 1) {
            throw new \RuntimeException('Windows listener handoff wall clock is invalid.');
        }
        return $now;
    }

    public function currentPid(): int
    {
        $pid = $this->pidResolver !== null
            ? (int)($this->pidResolver)()
            : (int)\getmypid();
        if ($pid < 1) {
            throw new \RuntimeException('Windows listener handoff process PID is invalid.');
        }
        return $pid;
    }

    public function acquireExportMutex(int $timeoutMilliseconds): WindowsListenerHandoffMutexGuard
    {
        if ($timeoutMilliseconds < 1 || $timeoutMilliseconds > 60_000) {
            throw new \InvalidArgumentException(
                'Windows listener export mutex wait must be within [1, 60000] milliseconds.'
            );
        }
        if ($this->mutexAcquirer !== null) {
            $guard = ($this->mutexAcquirer)($timeoutMilliseconds);
            if (!$guard instanceof WindowsListenerHandoffMutexGuard) {
                throw new \RuntimeException(
                    'Windows listener export mutex provider returned an invalid guard.'
                );
            }
            return $guard;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException('Windows listener export mutex is Windows-only.');
        }
        if ((int)\PHP_ZTS !== 0) {
            throw new \RuntimeException(
                'Windows listener export mutex requires the locked non-ZTS PHP runtime.'
            );
        }
        if (!\extension_loaded('FFI') || !\class_exists(\FFI::class)) {
            throw new \RuntimeException(
                'Windows listener export mutex requires FFI in the locked PHP runtime.'
            );
        }
        $ffiEnabled = \strtolower(\trim((string)\ini_get('ffi.enable')));
        if (\in_array($ffiEnabled, ['', '0', 'off', 'false', 'no'], true)) {
            throw new \RuntimeException(
                'Windows listener export mutex requires ffi.enable.'
            );
        }
        $ffi = null;
        $handle = null;
        $ownsMutex = false;
        try {
            $ffi = \FFI::cdef(
                <<<'CDEF'
typedef void *HANDLE;
typedef unsigned long DWORD;
typedef int BOOL;
HANDLE CreateMutexA(void *attributes, BOOL initial_owner, const char *name);
DWORD WaitForSingleObject(HANDLE handle, DWORD milliseconds);
BOOL ReleaseMutex(HANDLE handle);
BOOL CloseHandle(HANDLE handle);
DWORD GetCurrentThreadId(void);
DWORD GetLastError(void);
CDEF,
                'kernel32.dll',
            );
            $handle = $ffi->CreateMutexA(null, 0, self::EXPORT_MUTEX_NAME);
            if ($handle === null || \FFI::isNull($handle)) {
                throw new \RuntimeException(
                    'CreateMutexA failed with Windows error ' . (int)$ffi->GetLastError() . '.'
                );
            }
            $wait = (int)$ffi->WaitForSingleObject($handle, $timeoutMilliseconds);
            if (!\in_array($wait, [self::WAIT_OBJECT_0, self::WAIT_ABANDONED], true)) {
                $error = $wait === self::WAIT_FAILED ? (int)$ffi->GetLastError() : 0;
                if ($wait === self::WAIT_TIMEOUT) {
                    throw new \RuntimeException(
                        'Timed out acquiring the host/session Windows listener export mutex.'
                    );
                }
                throw new \RuntimeException(
                    'Windows listener export mutex wait failed with error ' . $error . '.'
                );
            }
            $ownsMutex = true;
            $ownerThreadId = (int)$ffi->GetCurrentThreadId();
            return new WindowsListenerHandoffMutexGuard(
                $wait === self::WAIT_ABANDONED,
                static function () use ($ffi, $handle, $ownerThreadId): void {
                    if ((int)$ffi->GetCurrentThreadId() !== $ownerThreadId) {
                        throw new \RuntimeException(
                            'Windows listener export mutex release moved to another OS thread.'
                        );
                    }
                    if ((int)$ffi->ReleaseMutex($handle) === 0) {
                        throw new \RuntimeException(
                            'Windows listener export mutex could not be released: '
                            . (int)$ffi->GetLastError() . '.'
                        );
                    }
                    // Mutex ownership is already released. A CloseHandle failure
                    // can only leak this process-local handle; it cannot keep a
                    // contender blocked, so do not turn it into an unsafe retry.
                    try {
                        $ffi->CloseHandle($handle);
                    } catch (\Throwable) {
                    }
                },
            );
        } catch (\Throwable $throwable) {
            if ($ffi instanceof \FFI
                && $handle !== null
                && !\FFI::isNull($handle)
            ) {
                try {
                    if ($ownsMutex) {
                        $ffi->ReleaseMutex($handle);
                    }
                    $ffi->CloseHandle($handle);
                } catch (\Throwable) {
                }
            }
            if ($throwable instanceof \RuntimeException) {
                throw $throwable;
            }
            throw new \RuntimeException(
                'Windows listener export mutex FFI initialization failed.',
                0,
                $throwable,
            );
        }
    }

    /**
     * Deterministic test/runtime hook invoked after the protected envelope is
     * visible. Returning true means another event loop owns release polling;
     * production has no hook and always performs the native bounded wait.
     */
    public function publisherReleaseWaitIsExternallyDriven(string $path): bool
    {
        return $this->publisherCoordinator !== null
            && (bool)($this->publisherCoordinator)($path);
    }
}
