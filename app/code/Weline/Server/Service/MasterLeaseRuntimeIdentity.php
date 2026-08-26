<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;
use Weline\Server\Service\Edge\Gateway\GatewayHostBootIdentity;
use Weline\Server\Service\Runtime\PhpRuntimeSafetyProfile;

/**
 * Host/process evidence used by the schema-2 Master lease.
 *
 * The lease manager deliberately keeps this probe separate from persistence:
 * an unreadable PID namespace or process table is UNKNOWN evidence, never
 * proof that an owner exited. Tests may inject bounded probes without making
 * the production lease schema configurable.
 */
final class MasterLeaseRuntimeIdentity
{
    private const MAX_PROC_STAT_BYTES = 16_384;
    private const MAX_PROCESS_COMMAND_BYTES = 16_384;
    private const MAX_PROCESS_NAME_BYTES = 512;
    private const MAX_PROCESS_PROBE_OUTPUT_BYTES = 131_072;
    private const MAX_WINDOWS_IMAGE_CHARACTERS = 8_192;
    // WaitForSingleObject requires SYNCHRONIZE in addition to
    // PROCESS_QUERY_LIMITED_INFORMATION on the inspected process handle.
    private const WINDOWS_PROCESS_INSPECTION_ACCESS = 0x00101000;
    private const PROCESS_PROBE_TIMEOUT_SECONDS = 2.0;

    public const OWNER_MATCH = 'match';
    public const OWNER_UNKNOWN = 'unknown';
    public const OWNER_MISSING = 'missing';
    public const OWNER_MISMATCH = 'mismatch';

    /** @var array<int,string> */
    private static array $selfBirthFallback = [];
    /** @var array<int,string> */
    private static array $selfProcessBirth = [];
    /** @var array<int,string> */
    private static array $selfManagedProcessBirth = [];
    private static bool $windowsIsolatedLaunchCommitGraceConsumed = false;
    private static ?string $defaultHostBootId = null;
    /**
     * Successful Darwin libproc FFI binding only. Transient cdef failures under
     * Master FD pressure must never permanently poison this cache: a single
     * failed load previously set the cache to null and left every later worker
     * authorize attempt unable to observe process birth until Master restarted.
     *
     * @var \FFI|false
     */
    private static \FFI|false $darwinProcFfi = false;

    /** @var (\Closure():string)|null */
    private readonly ?\Closure $bootIdentityResolver;
    /** @var (\Closure():float)|null */
    private readonly ?\Closure $monotonicClock;
    /** @var (\Closure(int):array<string,mixed>)|null */
    private readonly ?\Closure $processInfoResolver;
    /** @var (\Closure(int,string):bool)|null */
    private readonly ?\Closure $managedProcessVerifier;
    /** @var (\Closure(int):?string)|null */
    private readonly ?\Closure $pidNamespaceResolver;
    /** @var (\Closure(int,string,string,float):array<string,mixed>)|null */
    private readonly ?\Closure $stableProcessTerminator;

    public function __construct(
        ?\Closure $bootIdentityResolver = null,
        ?\Closure $monotonicClock = null,
        ?\Closure $processInfoResolver = null,
        ?\Closure $managedProcessVerifier = null,
        ?\Closure $pidNamespaceResolver = null,
        ?\Closure $stableProcessTerminator = null,
    ) {
        $this->bootIdentityResolver = $bootIdentityResolver;
        $this->monotonicClock = $monotonicClock;
        $this->processInfoResolver = $processInfoResolver;
        $this->managedProcessVerifier = $managedProcessVerifier;
        $this->pidNamespaceResolver = $pidNamespaceResolver;
        $this->stableProcessTerminator = $stableProcessTerminator;
    }

    public function hostBootId(): string
    {
        if ($this->bootIdentityResolver === null && self::$defaultHostBootId !== null) {
            return self::$defaultHostBootId;
        }
        $bootId = $this->bootIdentityResolver !== null
            ? ($this->bootIdentityResolver)()
            : GatewayHostBootIdentity::current();

        $bootId = GatewayHostBootIdentity::validate($bootId);
        if ($this->bootIdentityResolver === null) {
            self::$defaultHostBootId = $bootId;
        }

        return $bootId;
    }

    public function monotonicNow(): float
    {
        $now = $this->monotonicClock !== null
            ? ($this->monotonicClock)()
            : (\hrtime(true) / 1_000_000_000);
        if (!\is_finite($now) || $now <= 0.0) {
            throw new \RuntimeException('WLS Master lease monotonic clock is invalid.');
        }

        return $now;
    }

    /**
     * Capture the identity which a new lease generation will bind.
     *
     * @return array{birth:string,pid_namespace_id:string}
     */
    public function captureOwner(int $pid): array
    {
        if ($pid <= 0) {
            throw new \RuntimeException('WLS Master PID is invalid.');
        }
        $namespaceId = $this->pidNamespaceId($pid);
        if (PHP_OS_FAMILY === 'Linux' && $namespaceId === null) {
            throw new \RuntimeException('WLS Master PID namespace identity is unavailable.');
        }
        // Master lease birth must stay independently re-observable from workers
        // under Darwin FD inheritance. Mutable argv/comm belong only to the
        // separate managedProcessStatus() check: including them in the birth
        // hash makes remote observation return UNKNOWN whenever bounded ps
        // cannot read name/command, which then cascades into worker exits and
        // false "another generation took over" Master stops.
        $birth = $this->processBirth($pid, true, false);
        if ($birth === null) {
            throw new \RuntimeException('WLS Master process birth identity is unavailable.');
        }

        return [
            'birth' => $birth,
            'pid_namespace_id' => $namespaceId ?? '',
        ];
    }

    /**
     * Capture boot-stable identity for any managed child process.
     *
     * @return array{birth:string,pid_namespace_id:string}
     */
    public function captureProcessIdentity(int $pid): array
    {
        if ($pid <= 0) {
            throw new \RuntimeException('WLS process PID is invalid.');
        }
        $namespaceId = $this->pidNamespaceId($pid);
        if (PHP_OS_FAMILY === 'Linux' && $namespaceId === null) {
            throw new \RuntimeException('WLS process PID namespace identity is unavailable.');
        }
        // Dead PIDs must fail closed immediately. Retrying a recycled/exited
        // child for the full capture window turns one respawn into a multi-
        // second authorize storm that also contends the credential ledger lock.
        // Executing PHP is definitive proof that its own PID is still alive.
        // Once the immutable self birth has been cached, do not re-enter the
        // Windows process-table FFI merely to prove that fact again. This is
        // also required by x64 PHP on Windows ARM64, where a later FFI call
        // while native listener state is active can fault inside emulation.
        if ($pid !== (int)\getmypid() && $this->isProcessDefinitelyMissing($pid)) {
            throw new \RuntimeException('WLS process is not running.');
        }
        $birth = $this->processBirth($pid, true, false);
        if ($birth === null) {
            throw new \RuntimeException('WLS process birth identity is unavailable.');
        }

        return [
            'birth' => $birth,
            'pid_namespace_id' => $namespaceId ?? '',
        ];
    }

    public function observeProcessIdentity(
        int $pid,
        string $birth,
        string $pidNamespaceId,
    ): string {
        if ($pid <= 0 || \preg_match('/\A[a-f0-9]{64}\z/D', $birth) !== 1) {
            return self::OWNER_MISMATCH;
        }
        if (PHP_OS_FAMILY === 'Linux') {
            if (\preg_match('/\Apid:\[[1-9][0-9]{0,19}\]\z/D', $pidNamespaceId) !== 1) {
                return self::OWNER_MISMATCH;
            }
            $currentNamespace = $this->pidNamespaceId((int)\getmypid());
            if ($currentNamespace === null) {
                return self::OWNER_UNKNOWN;
            }
            if (!\hash_equals($pidNamespaceId, $currentNamespace)) {
                return self::OWNER_UNKNOWN;
            }
            $observedNamespace = $this->pidNamespaceId($pid);
            if ($observedNamespace === null) {
                return $this->processDefinitelyMissing($pid)
                    ? self::OWNER_MISSING
                    : self::OWNER_UNKNOWN;
            }
            if (!\hash_equals($pidNamespaceId, $observedNamespace)) {
                return self::OWNER_MISMATCH;
            }
        } elseif ($pidNamespaceId !== '') {
            return self::OWNER_MISMATCH;
        }

        $observedBirth = $this->processBirth($pid, false, false);
        if ($observedBirth === null) {
            return $this->processDefinitelyMissing($pid)
                ? self::OWNER_MISSING
                : self::OWNER_UNKNOWN;
        }

        return \hash_equals($birth, $observedBirth)
            ? self::OWNER_MATCH
            : self::OWNER_MISMATCH;
    }

    /**
     * Terminate one exact process-birth lease through a stable kernel handle.
     *
     * A numeric PID is discovery data, never signalling authority. Linux opens
     * a pidfd before re-observing the birth tuple and signals only through that
     * descriptor. Windows opens a process HANDLE, compares creation FILETIME on
     * the same handle, and calls TerminateProcess on that handle. Darwin has no
     * equivalent stable signalling handle in the supported PHP runtime and
     * therefore fails closed while the exact process is still alive.
     *
     * @return array{
     *     released:bool,
     *     terminated:bool,
     *     reason:string,
     *     owner_state:string,
     *     pid:int
     * }
     */
    public function terminateExactProcessIdentity(
        int $pid,
        string $birth,
        string $pidNamespaceId,
        float $graceSeconds = 0.5,
    ): array {
        $birth = \strtolower(\trim($birth));
        $pidNamespaceId = \trim($pidNamespaceId);
        $graceSeconds = \max(0.01, \min(2.0, $graceSeconds));
        if ($pid <= 0
            || \preg_match('/\A[a-f0-9]{64}\z/D', $birth) !== 1
            || (PHP_OS_FAMILY === 'Linux'
                ? \preg_match('/\Apid:\[[1-9][0-9]{0,19}\]\z/D', $pidNamespaceId) !== 1
                : $pidNamespaceId !== '')
        ) {
            return $this->terminationOutcome(
                $pid,
                false,
                false,
                'process_identity_invalid',
                self::OWNER_UNKNOWN,
            );
        }

        // Missing or reused PIDs release the old credential without needing a
        // signalling handle. This observation is only a non-destructive
        // preflight: a still-matching process must pass the platform's stable
        // handle revalidation before any signal is sent. In particular,
        // Windows ARM64/x64 isolation can safely retire an already-exited
        // child through WMI evidence even though in-process FFI is disabled.
        $ownerState = $this->observeProcessIdentity($pid, $birth, $pidNamespaceId);
        $released = $this->releasedOwnerOutcome($pid, $ownerState);
        if ($released !== null) {
            return $released;
        }
        if ($ownerState !== self::OWNER_MATCH) {
            return $this->terminationOutcome(
                $pid,
                false,
                false,
                'process_identity_unknown',
                $ownerState,
            );
        }

        if ($this->stableProcessTerminator !== null) {
            try {
                $result = ($this->stableProcessTerminator)(
                    $pid,
                    $birth,
                    $pidNamespaceId,
                    $graceSeconds,
                );
            } catch (\Throwable) {
                return $this->terminationOutcome(
                    $pid,
                    false,
                    false,
                    'stable_process_termination_exception',
                    self::OWNER_MATCH,
                );
            }
            if (!\is_array($result)
                || !\is_bool($result['released'] ?? null)
                || !\is_bool($result['terminated'] ?? null)
                || !\is_string($result['reason'] ?? null)
                || \trim((string)$result['reason']) === ''
            ) {
                return $this->terminationOutcome(
                    $pid,
                    false,
                    false,
                    'stable_process_termination_result_invalid',
                    self::OWNER_MATCH,
                );
            }

            return $this->terminationOutcome(
                $pid,
                (bool)$result['released'],
                (bool)$result['terminated'],
                \substr(\trim((string)$result['reason']), 0, 256),
                self::OWNER_MATCH,
            );
        }

        return match (PHP_OS_FAMILY) {
            'Linux' => $this->terminateLinuxProcessIdentity(
                $pid,
                $birth,
                $pidNamespaceId,
                $graceSeconds,
            ),
            'Windows' => $this->terminateWindowsProcessIdentity(
                $pid,
                $birth,
                $graceSeconds,
            ),
            'Darwin' => $this->terminateDarwinProcessIdentity(
                $pid,
                $birth,
                $pidNamespaceId,
                $graceSeconds,
            ),
            default => $this->terminationOutcome(
                $pid,
                false,
                false,
                'stable_process_handle_unavailable_on_platform',
                self::OWNER_UNKNOWN,
            ),
        };
    }

    /** @return array{released:bool,terminated:bool,reason:string,owner_state:string,pid:int}|null */
    private function releasedOwnerOutcome(int $pid, string $ownerState): ?array
    {
        if (!\in_array($ownerState, [self::OWNER_MISSING, self::OWNER_MISMATCH], true)) {
            return null;
        }

        return $this->terminationOutcome(
            $pid,
            true,
            false,
            'process_identity_released_without_signal',
            $ownerState,
        );
    }

    /** @return array{released:bool,terminated:bool,reason:string,owner_state:string,pid:int} */
    private function terminateDarwinProcessIdentity(
        int $pid,
        string $birth,
        string $pidNamespaceId,
        float $graceSeconds = 0.5,
    ): array {
        $ownerState = $this->observeProcessIdentity($pid, $birth, $pidNamespaceId);
        $released = $this->releasedOwnerOutcome($pid, $ownerState);
        if ($released !== null) {
            return $released;
        }
        if ($ownerState !== self::OWNER_MATCH) {
            return $this->terminationOutcome(
                $pid,
                false,
                false,
                'process_identity_unknown',
                $ownerState,
            );
        }

        // Darwin has no pidfd equivalent, but posix_kill after a verified birth
        // match is safe: the libproc birth tuple (pbi_start_tvsec:tvusec) has
        // microsecond precision which fences PID reuse within the same boot.
        // Re-observe after each signal to confirm the *same* process exited.
        if (!\function_exists('posix_kill')) {
            return $this->terminationOutcome(
                $pid,
                false,
                false,
                'darwin_posix_kill_unavailable',
                self::OWNER_MATCH,
            );
        }

        $graceSeconds = \max(0.05, \min(2.0, $graceSeconds));
        $pollIntervalUs = 50_000; // 50ms per poll
        $termPolls = (int)\ceil($graceSeconds * 1_000_000 / $pollIntervalUs);

        $termSent = @\posix_kill($pid, 15); // SIGTERM
        if ($termSent) {
            for ($wait = 0; $wait < $termPolls; ++$wait) {
                SchedulerSystem::usleep($pollIntervalUs);
                $postState = $this->observeProcessIdentity($pid, $birth, $pidNamespaceId);
                if (\in_array($postState, [self::OWNER_MISSING, self::OWNER_MISMATCH], true)) {
                    return $this->terminationOutcome($pid, true, true, 'darwin_posix_term_released', self::OWNER_MISSING);
                }
            }
        }

        $killSent = @\posix_kill($pid, 9); // SIGKILL — unconditional after SIGTERM grace window
        if ($killSent) {
            for ($wait = 0; $wait < 6; ++$wait) { // up to 300ms; SIGKILL should be near-instant
                SchedulerSystem::usleep($pollIntervalUs);
                $postState = $this->observeProcessIdentity($pid, $birth, $pidNamespaceId);
                if (\in_array($postState, [self::OWNER_MISSING, self::OWNER_MISMATCH], true)) {
                    return $this->terminationOutcome($pid, true, true, 'darwin_posix_kill_released', self::OWNER_MISSING);
                }
            }
        }

        // Final observation after both signals.
        $finalState = $this->observeProcessIdentity($pid, $birth, $pidNamespaceId);
        $finalReleased = $this->releasedOwnerOutcome($pid, $finalState);
        if ($finalReleased !== null) {
            return $finalReleased;
        }

        return $this->terminationOutcome(
            $pid,
            false,
            $termSent || $killSent,
            'darwin_posix_termination_unverified',
            $finalState,
        );
    }

    /** @return array{released:bool,terminated:bool,reason:string,owner_state:string,pid:int} */
    private function terminateLinuxProcessIdentity(
        int $pid,
        string $birth,
        string $pidNamespaceId,
        float $graceSeconds,
    ): array {
        if (!\extension_loaded('FFI') || !\class_exists(\FFI::class)) {
            return $this->terminationOutcome(
                $pid,
                false,
                false,
                'linux_pidfd_ffi_unavailable',
                self::OWNER_UNKNOWN,
            );
        }
        $ffiEnabled = \strtolower(\trim((string)\ini_get('ffi.enable')));
        if (\in_array($ffiEnabled, ['', '0', 'off', 'false', 'no'], true)) {
            return $this->terminationOutcome(
                $pid,
                false,
                false,
                'linux_pidfd_ffi_unavailable',
                self::OWNER_UNKNOWN,
            );
        }
        try {
            $ffi = \FFI::cdef(
                <<<'CDEF'
long syscall(long number, ...);
int close(int fd);
struct pollfd { int fd; short events; short revents; };
int poll(struct pollfd *fds, unsigned long nfds, int timeout);
CDEF,
            );
            // Linux assigns these stable syscall numbers on every WLS 2.0
            // supported architecture: SYS_pidfd_send_signal=424 and
            // SYS_pidfd_open=434.
            $pidfd = (int)$ffi->syscall(434, $pid, 0); // pidfd_open
        } catch (\Throwable) {
            return $this->terminationOutcome(
                $pid,
                false,
                false,
                'linux_pidfd_open_unavailable',
                self::OWNER_UNKNOWN,
            );
        }
        if ($pidfd < 0) {
            $ownerState = $this->observeProcessIdentity($pid, $birth, $pidNamespaceId);
            $released = $this->releasedOwnerOutcome($pid, $ownerState);

            return $released ?? $this->terminationOutcome(
                $pid,
                false,
                false,
                'linux_pidfd_open_failed',
                $ownerState,
            );
        }

        try {
            // The descriptor was opened before this re-observation. A PID
            // reuse now yields MISMATCH and the descriptor is closed without
            // signalling either the old or replacement process.
            $ownerState = $this->observeProcessIdentity($pid, $birth, $pidNamespaceId);
            $released = $this->releasedOwnerOutcome($pid, $ownerState);
            if ($released !== null) {
                return $released;
            }
            if ($ownerState !== self::OWNER_MATCH) {
                return $this->terminationOutcome(
                    $pid,
                    false,
                    false,
                    'process_identity_unknown',
                    $ownerState,
                );
            }

            $poll = $ffi->new('struct pollfd[1]');
            $poll[0]->fd = $pidfd;
            $poll[0]->events = 1; // POLLIN: pidfd becomes readable on exit.
            $poll[0]->revents = 0;
            if ((int)$ffi->poll($poll, 1, 0) === 1) {
                return $this->terminationOutcome(
                    $pid,
                    true,
                    false,
                    'linux_pidfd_already_exited',
                    self::OWNER_MISSING,
                );
            }

            // Variadic FFI calls cannot safely marshal a PHP null as the
            // siginfo_t pointer. Use an explicitly typed null pointer so the
            // kernel receives the pidfd_send_signal(2) "no siginfo" value.
            $nullSiginfo = $ffi->cast('void *', 0);
            $termSent = (int)$ffi->syscall(424, $pidfd, 15, $nullSiginfo, 0) === 0; // pidfd_send_signal
            $waitMs = \max(1, (int)\ceil($graceSeconds * 1000));
            $poll[0]->revents = 0;
            if ((int)$ffi->poll($poll, 1, $waitMs) === 1) {
                return $this->terminationOutcome(
                    $pid,
                    true,
                    $termSent,
                    'linux_pidfd_term_released',
                    self::OWNER_MISSING,
                );
            }

            $killSent = (int)$ffi->syscall(424, $pidfd, 9, $nullSiginfo, 0) === 0; // pidfd_send_signal
            $poll[0]->revents = 0;
            $releasedAfterKill = (int)$ffi->poll($poll, 1, $waitMs) === 1;

            return $this->terminationOutcome(
                $pid,
                $releasedAfterKill,
                $termSent || $killSent,
                $releasedAfterKill
                    ? 'linux_pidfd_kill_released'
                    : 'linux_pidfd_termination_unverified',
                $releasedAfterKill ? self::OWNER_MISSING : self::OWNER_MATCH,
            );
        } catch (\Throwable) {
            return $this->terminationOutcome(
                $pid,
                false,
                false,
                'linux_pidfd_termination_exception',
                self::OWNER_UNKNOWN,
            );
        } finally {
            try {
                $ffi->close($pidfd);
            } catch (\Throwable) {
                // The stable handle has no further authority after return.
            }
        }
    }

    /** @return array{released:bool,terminated:bool,reason:string,owner_state:string,pid:int} */
    private function terminateWindowsProcessIdentity(
        int $pid,
        string $birth,
        float $graceSeconds,
    ): array {
        if (PhpRuntimeSafetyProfile::requiresNativeExtensionIsolation()) {
            return $this->terminationOutcome(
                $pid,
                false,
                false,
                'windows_process_handle_ffi_unavailable',
                self::OWNER_UNKNOWN,
            );
        }
        if (!\extension_loaded('FFI') || !\class_exists(\FFI::class) || PHP_INT_SIZE < 8) {
            return $this->terminationOutcome(
                $pid,
                false,
                false,
                'windows_process_handle_ffi_unavailable',
                self::OWNER_UNKNOWN,
            );
        }
        $ffiEnabled = \strtolower(\trim((string)\ini_get('ffi.enable')));
        if (\in_array($ffiEnabled, ['', '0', 'off', 'false', 'no'], true)) {
            return $this->terminationOutcome(
                $pid,
                false,
                false,
                'windows_process_handle_ffi_unavailable',
                self::OWNER_UNKNOWN,
            );
        }
        $handle = null;
        try {
            $ffi = \FFI::cdef(
                <<<'CDEF'
typedef unsigned long DWORD;
typedef int BOOL;
typedef void *HANDLE;
typedef struct _FILETIME {
    DWORD dwLowDateTime;
    DWORD dwHighDateTime;
} FILETIME;
HANDLE OpenProcess(DWORD dwDesiredAccess, BOOL bInheritHandle, DWORD dwProcessId);
BOOL GetExitCodeProcess(HANDLE hProcess, DWORD *lpExitCode);
BOOL GetProcessTimes(
    HANDLE hProcess,
    FILETIME *lpCreationTime,
    FILETIME *lpExitTime,
    FILETIME *lpKernelTime,
    FILETIME *lpUserTime
);
DWORD WaitForSingleObject(HANDLE hHandle, DWORD dwMilliseconds);
BOOL TerminateProcess(HANDLE hProcess, unsigned int uExitCode);
BOOL CloseHandle(HANDLE hObject);
DWORD GetLastError(void);
CDEF,
                'kernel32.dll',
            );
            // PROCESS_TERMINATE | SYNCHRONIZE | PROCESS_QUERY_LIMITED_INFORMATION.
            $handle = $ffi->OpenProcess(0x00101001, 0, $pid);
        } catch (\Throwable) {
            return $this->terminationOutcome(
                $pid,
                false,
                false,
                'windows_process_handle_open_unavailable',
                self::OWNER_UNKNOWN,
            );
        }
        if ($handle === null || \FFI::isNull($handle)) {
            $lastError = (int)$ffi->GetLastError();
            if ($lastError === 87) { // ERROR_INVALID_PARAMETER: PID absent.
                return $this->terminationOutcome(
                    $pid,
                    true,
                    false,
                    'windows_process_handle_owner_missing',
                    self::OWNER_MISSING,
                );
            }

            return $this->terminationOutcome(
                $pid,
                false,
                false,
                'windows_process_handle_open_failed',
                self::OWNER_UNKNOWN,
            );
        }

        try {
            $active = $this->windowsProcessHandleIsActive($ffi, $handle);
            if ($active === false) {
                return $this->terminationOutcome(
                    $pid,
                    true,
                    false,
                    'windows_process_handle_owner_exited',
                    self::OWNER_MISSING,
                );
            }
            if ($active !== true) {
                return $this->terminationOutcome(
                    $pid,
                    false,
                    false,
                    'windows_process_handle_state_unknown',
                    self::OWNER_UNKNOWN,
                );
            }
            $creationTicks = $this->windowsProcessCreationTicks($ffi, $handle);
            if ($creationTicks === null) {
                return $this->terminationOutcome(
                    $pid,
                    false,
                    false,
                    'windows_process_handle_birth_unknown',
                    self::OWNER_UNKNOWN,
                );
            }
            $handleBirth = \hash(
                'sha256',
                'wls-managed-process-birth/1|' . $pid
                . '|windows-creation-ticks:' . $creationTicks,
            );
            if (!\hash_equals($birth, $handleBirth)) {
                return $this->terminationOutcome(
                    $pid,
                    true,
                    false,
                    'process_identity_released_without_signal',
                    self::OWNER_MISMATCH,
                );
            }

            $terminated = (int)$ffi->TerminateProcess($handle, 1) !== 0;
            $waitMs = \max(1, (int)\ceil($graceSeconds * 1000));
            $wait = (int)$ffi->WaitForSingleObject($handle, $waitMs);
            $released = $wait === 0; // WAIT_OBJECT_0

            return $this->terminationOutcome(
                $pid,
                $released,
                $terminated,
                $released
                    ? 'windows_process_handle_released'
                    : 'windows_process_handle_termination_unverified',
                $released ? self::OWNER_MISSING : self::OWNER_MATCH,
            );
        } catch (\Throwable) {
            return $this->terminationOutcome(
                $pid,
                false,
                false,
                'windows_process_handle_termination_exception',
                self::OWNER_UNKNOWN,
            );
        } finally {
            try {
                $ffi->CloseHandle($handle);
            } catch (\Throwable) {
                // The stable handle has no further authority after return.
            }
        }
    }

    /** @return array{released:bool,terminated:bool,reason:string,owner_state:string,pid:int} */
    private function terminationOutcome(
        int $pid,
        bool $released,
        bool $terminated,
        string $reason,
        string $ownerState,
    ): array {
        return [
            'released' => $released,
            'terminated' => $terminated,
            'reason' => $reason,
            'owner_state' => $ownerState,
            'pid' => \max(0, $pid),
        ];
    }

    /**
     * @param array<string,mixed> $lease
     */
    public function observeOwner(array $lease, bool $requireManagedName): string
    {
        $pid = (int)($lease['master_pid'] ?? 0);
        $storedBirth = (string)($lease['master_process_birth'] ?? '');
        $storedNamespace = (string)($lease['pid_namespace_id'] ?? '');
        $instance = (string)($lease['instance'] ?? '');
        if ($pid <= 0
            || \preg_match('/\A[a-f0-9]{64}\z/D', $storedBirth) !== 1
            || $instance === ''
        ) {
            return self::OWNER_MISMATCH;
        }

        if (PHP_OS_FAMILY === 'Linux') {
            if (\preg_match('/\Apid:\[[1-9][0-9]{0,19}\]\z/D', $storedNamespace) !== 1) {
                return self::OWNER_MISMATCH;
            }
            $currentNamespace = $this->pidNamespaceId((int)\getmypid());
            if ($currentNamespace === null) {
                return self::OWNER_UNKNOWN;
            }
            if (!\hash_equals($storedNamespace, $currentNamespace)) {
                // The numeric PID belongs to the lease owner's namespace and
                // must not be resolved through this namespace's /proc table.
                // Positive namespace separation is bounded veto evidence only.
                return self::OWNER_UNKNOWN;
            }
            $observedNamespace = $this->pidNamespaceId($pid);
            if ($observedNamespace === null) {
                return $this->processDefinitelyMissing($pid)
                    ? self::OWNER_MISSING
                    : self::OWNER_UNKNOWN;
            }
            if (!\hash_equals($storedNamespace, $observedNamespace)) {
                return self::OWNER_MISMATCH;
            }
        } elseif ($storedNamespace !== '') {
            return self::OWNER_MISMATCH;
        }

        $birth = $this->processBirth($pid, false, false);
        if ($birth === null) {
            return $this->processDefinitelyMissing($pid)
                ? self::OWNER_MISSING
                : self::OWNER_UNKNOWN;
        }
        if (!\hash_equals($storedBirth, $birth)) {
            return self::OWNER_MISMATCH;
        }
        if ($requireManagedName) {
            $managedStatus = $this->managedProcessStatus($pid, $instance);
            if ($managedStatus === self::OWNER_MATCH) {
                return self::OWNER_MATCH;
            }
            // Positive name/argv mismatch or definite absence still vetoes.
            if ($managedStatus === self::OWNER_MISMATCH
                || $managedStatus === self::OWNER_MISSING
            ) {
                return $managedStatus;
            }
            // Birth already matched. Darwin workers under FD pressure often
            // cannot read Master argv/comm (libproc birth OK, bounded ps empty)
            // and used to demote this to UNKNOWN → ChildMasterGuard killed
            // healthy workers → Master listened with zero READY children.
            // UNKNOWN managed-name evidence must not revoke a birth match.
        }

        return self::OWNER_MATCH;
    }

    /**
     * A namespace-local PID is not signal authority outside that namespace.
     * Callers may use this positive proof only to retain a bounded resource
     * veto while a fresh lease is visible; UNKNOWN for any other reason must
     * remain fail-closed.
     *
     * @param array<string,mixed> $lease
     */
    public function ownerIsOutsideCurrentPidNamespace(array $lease): bool
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return false;
        }
        $storedNamespace = (string)($lease['pid_namespace_id'] ?? '');
        if (\preg_match('/\Apid:\[[1-9][0-9]{0,19}\]\z/D', $storedNamespace) !== 1) {
            return false;
        }
        $currentNamespace = $this->pidNamespaceId((int)\getmypid());

        return $currentNamespace !== null && !\hash_equals($storedNamespace, $currentNamespace);
    }

    /**
     * Return bounded process-table evidence suitable for host lease consumers.
     *
     * An empty array means the probe could not establish either existence or
     * absence. Callers must not turn that UNKNOWN state into permission to
     * recycle a PID-owned resource.
     *
     * @return array<string,mixed>
     */
    public function inspectProcess(int $pid): array
    {
        if ($pid <= 0) {
            return ['exists' => false];
        }
        if ($this->processInfoResolver !== null) {
            try {
                $info = ($this->processInfoResolver)($pid);
            } catch (\Throwable) {
                return [];
            }

            return \is_array($info) ? $info : [];
        }

        try {
            return match (PHP_OS_FAMILY) {
                'Linux' => $this->inspectLinuxProcess($pid),
                'Windows' => $this->inspectWindowsProcess($pid),
                'Darwin' => $this->inspectDarwinProcess($pid),
                default => $this->inspectPosixProcessWithPs($pid),
            };
        } catch (\Throwable) {
            return [];
        }
    }

    public function isProcessAlive(int $pid): bool
    {
        return ($this->inspectProcess($pid)['exists'] ?? null) === true;
    }

    public function isProcessDefinitelyMissing(int $pid): bool
    {
        return ($this->inspectProcess($pid)['exists'] ?? null) === false;
    }

    private function managedProcessStatus(int $pid, string $instance): string
    {
        if ($this->managedProcessVerifier !== null) {
            return (bool)($this->managedProcessVerifier)($pid, $instance)
                ? self::OWNER_MATCH
                : self::OWNER_MISMATCH;
        }
        $expected = MasterProcess::getMasterProcessName($instance);
        $info = $this->inspectProcess($pid);
        if (($info['exists'] ?? null) === false) {
            return self::OWNER_MISSING;
        }
        if (($info['exists'] ?? null) !== true) {
            return self::OWNER_UNKNOWN;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows' bounded native process APIs expose the immutable image
            // path and creation FILETIME, but not another process' argv. The
            // exact stored birth has already fenced PID reuse above; require
            // that the surviving process still runs this locked PHP image.
            $observedImage = $this->normalizeWindowsPath((string)($info['command'] ?? ''));
            $phpBinary = @\realpath(PHP_BINARY);
            $expectedImage = $this->normalizeWindowsPath(
                \is_string($phpBinary) && $phpBinary !== '' ? $phpBinary : PHP_BINARY,
            );

            return $observedImage !== ''
                && $expectedImage !== ''
                && \hash_equals($expectedImage, $observedImage)
                    ? self::OWNER_MATCH
                    : self::OWNER_MISMATCH;
        }
        $observedName = \trim((string)($info['name'] ?? ''));
        $command = \trim((string)($info['command'] ?? ''));
        // Under Master FD inheritance, Darwin libproc can prove birth while
        // bounded ps cannot. Self observation still has the authoritative
        // CLI title set by applyProcessTitle().
        if ($this->processInfoResolver === null
            && ($observedName === '' || $command === '')
            && $pid === (int)\getmypid()
        ) {
            $selfMeta = $this->selfProcessCliMetadata();
            if ($observedName === '') {
                $observedName = $selfMeta['name'];
            }
            if ($command === '') {
                $command = $selfMeta['command'];
            }
        }
        if ($observedName !== '') {
            $basename = \basename(\str_replace('\\', '/', $observedName));
            if (\hash_equals($expected, $observedName)
                || \hash_equals($expected, $basename)
                || $this->darwinTruncatedProcessNameMatches($expected, $observedName)
                || $this->darwinTruncatedProcessNameMatches($expected, $basename)
            ) {
                return self::OWNER_MATCH;
            }
        }
        if ($command === '') {
            // Alive with birth match but no usable name/argv is UNKNOWN evidence,
            // never a positive managed-name veto. Treating it as MISMATCH made
            // workers exit while Master was still healthy.
            return $observedName === '' ? self::OWNER_UNKNOWN : self::OWNER_MISMATCH;
        }
        if (\strlen($command) > self::MAX_PROCESS_COMMAND_BYTES) {
            return self::OWNER_MISMATCH;
        }
        $quotedExpected = \preg_quote($expected, '/');

        return \preg_match(
            '/(?:\A|\s)--name=(?:"' . $quotedExpected . '"|\''
            . $quotedExpected . '\'|' . $quotedExpected . ')(?=\s|\z)/D',
            $command,
        ) === 1 ? self::OWNER_MATCH : self::OWNER_MISMATCH;
    }

    /** @return array<string,mixed> */
    private function processInfo(int $pid): array
    {
        return $this->inspectProcess($pid);
    }

    private function processBirth(
        int $pid,
        bool $allowSelfFallback,
        bool $includeMutableProcessMetadata,
    ): ?string
    {
        if ($this->processInfoResolver === null && $pid === (int)\getmypid()) {
            $cache = $includeMutableProcessMetadata
                ? self::$selfProcessBirth
                : self::$selfManagedProcessBirth;
            if (isset($cache[$pid])) {
                return $cache[$pid];
            }
        }
        $info = $this->processInfoResolver === null
            && PHP_OS_FAMILY === 'Windows'
            && !$includeMutableProcessMetadata
                ? $this->inspectWindowsProcess($pid, false)
                : $this->processInfo($pid);
        if (($info['exists'] ?? false) !== true) {
            return null;
        }
        $startTime = $this->processStartIdentity($pid, $info);
        if ($startTime !== null && \strlen($startTime) > 256) {
            return null;
        }
        if ($startTime === null || $startTime === '') {
            if ($this->processInfoResolver === null
                && \in_array(PHP_OS_FAMILY, ['Linux', 'Darwin', 'Windows'], true)
            ) {
                // Supported kernels must supply a birth identity which another
                // process can independently re-observe. A process-local random
                // fallback would create a lease that can never be authorized
                // after the launcher exits.
                return null;
            }
            if ($pid === (int)\getmypid() && isset(self::$selfBirthFallback[$pid])) {
                $startTime = self::$selfBirthFallback[$pid];
            } elseif (!$allowSelfFallback || $pid !== (int)\getmypid()) {
                return null;
            } else {
                self::$selfBirthFallback[$pid] = 'self-random:' . \bin2hex(\random_bytes(32));
                $startTime = self::$selfBirthFallback[$pid];
            }
        }

        if ($includeMutableProcessMetadata) {
            $name = \trim((string)($info['name'] ?? ''));
            $command = \trim((string)($info['command'] ?? ''));
            if (($name === '' || $command === '')
                && $pid === (int)\getmypid()
            ) {
                $selfMeta = $this->selfProcessCliMetadata();
                if ($name === '') {
                    $name = $selfMeta['name'];
                }
                if ($command === '') {
                    $command = $selfMeta['command'];
                }
            }
            if ($name === ''
                || $command === ''
                || \strlen($name) > 512
                || \strlen($command) > 16_384
            ) {
                return null;
            }
            $birth = \hash(
                'sha256',
                'wls-master-process-birth/2|'
                . $pid . '|'
                . $startTime . '|'
                . \strtolower($name) . '|'
                . \hash('sha256', $command),
            );
        } else {
            // PID reuse is fenced by the kernel-provided boot-relative start
            // identity. Do not include argv/comm: cli_set_process_title()
            // deliberately mutates those fields after the parent has spawned
            // and authorized a worker.
            $birth = \hash(
                'sha256',
                'wls-managed-process-birth/1|' . $pid . '|' . $startTime,
            );
        }
        if ($this->processInfoResolver === null && $pid === (int)\getmypid()) {
            if ($includeMutableProcessMetadata) {
                self::$selfProcessBirth[$pid] = $birth;
            } else {
                self::$selfManagedProcessBirth[$pid] = $birth;
            }
        }

        return $birth;
    }

    /** @param array<string,mixed> $info */
    private function processStartIdentity(int $pid, array $info): ?string
    {
        // Injected process tables are already stable test evidence. Production
        // probes publish kernel/OS creation identities directly.
        if ($this->processInfoResolver !== null) {
            $startedAt = \trim((string)($info['start_time'] ?? ''));
            return $startedAt !== '' ? 'injected-start:' . $startedAt : null;
        }
        if (PHP_OS_FAMILY === 'Linux') {
            $ticks = \trim((string)($info['start_ticks'] ?? ''));
            return \preg_match('/\A[1-9][0-9]*\z/D', $ticks) === 1
                ? 'linux-start-ticks:' . $ticks
                : null;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $identity = \trim((string)($info['start_identity'] ?? ''));
            if (\preg_match(
                '/\Awindows-wmi-creation:[0-9]{14}\.[0-9]{6}[+-][0-9]{3}\z/D',
                $identity,
            ) === 1) {
                return $identity;
            }
            $ticks = \trim((string)($info['start_ticks'] ?? ''));
            return \preg_match('/\A[1-9][0-9]*\z/D', $ticks) === 1
                ? 'windows-creation-ticks:' . $ticks
                : null;
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            $ticks = \trim((string)($info['start_ticks'] ?? ''));
            return \preg_match('/\A[1-9][0-9]*:[0-9]{1,6}\z/D', $ticks) === 1
                ? 'darwin-start-timeval:' . $ticks
                : null;
        }

        // A human-readable ps lstart value has only whole-second precision on
        // the supported POSIX implementations. It cannot authorize takeover
        // because a PID can be recycled inside that second.
        return null;
    }

    private function processDefinitelyMissing(int $pid): bool
    {
        return $this->isProcessDefinitelyMissing($pid);
    }

    /** @return array<string,mixed> */
    private function inspectLinuxProcess(int $pid): array
    {
        $statPath = '/proc/' . $pid . '/stat';
        $firstRaw = $this->readStableNoFollowFile($statPath, self::MAX_PROC_STAT_BYTES);
        $first = \is_string($firstRaw) ? $this->parseLinuxProcStat($firstRaw, $pid) : null;
        if ($first === null) {
            $exists = $this->probePosixProcessExistence($pid);
            if ($exists === false) {
                return ['exists' => false];
            }
            // A bounded ps probe is an independent source when procfs is
            // unavailable. It cannot mint Linux start ticks, so lease birth
            // capture still fails closed without verified procfs evidence.
            return $this->inspectPosixProcessWithPs($pid);
        }
        $commandRaw = $this->readStableNoFollowFile(
            '/proc/' . $pid . '/cmdline',
            self::MAX_PROCESS_COMMAND_BYTES,
            true,
        );
        $secondRaw = $this->readStableNoFollowFile($statPath, self::MAX_PROC_STAT_BYTES);
        $second = \is_string($secondRaw) ? $this->parseLinuxProcStat($secondRaw, $pid) : null;
        if ($second === null
            || !\hash_equals((string)$first['start_ticks'], (string)$second['start_ticks'])
            || !\hash_equals((string)$first['name'], (string)$second['name'])
        ) {
            return [];
        }
        if ($this->linuxStateIsExited((string)$first['state'])
            || $this->linuxStateIsExited((string)$second['state'])
        ) {
            if (!$this->linuxStateIsExited((string)$first['state'])
                || !$this->linuxStateIsExited((string)$second['state'])
            ) {
                return [];
            }
            return [
                'exists' => false,
                'pid' => $pid,
                'state' => (string)$second['state'],
            ];
        }
        if (!\is_string($commandRaw)) {
            return [];
        }
        $command = \trim(\str_replace("\0", ' ', $commandRaw));
        if (\strlen($command) > self::MAX_PROCESS_COMMAND_BYTES) {
            return [];
        }

        return [
            'exists' => true,
            'pid' => $pid,
            'name' => (string)$second['name'],
            'command' => $command,
            'start_time' => 'linux-start-ticks:' . (string)$second['start_ticks'],
            'start_ticks' => (string)$second['start_ticks'],
            'state' => (string)$second['state'],
        ];
    }

    /**
     * @return array{pid:int,name:string,state:string,start_ticks:string}|null
     */
    private function parseLinuxProcStat(string $stat, int $expectedPid): ?array
    {
        if ($stat === '' || \strlen($stat) > self::MAX_PROC_STAT_BYTES) {
            return null;
        }
        $commandStart = \strpos($stat, '(');
        $commandEnd = \strrpos($stat, ')');
        if ($commandStart === false || $commandEnd === false || $commandEnd <= $commandStart) {
            return null;
        }
        $pidText = \trim(\substr($stat, 0, $commandStart));
        if (\preg_match('/\A[1-9][0-9]*\z/D', $pidText) !== 1
            || (int)$pidText !== $expectedPid
        ) {
            return null;
        }
        $name = \substr($stat, $commandStart + 1, $commandEnd - $commandStart - 1);
        if ($name === ''
            || \strlen($name) > self::MAX_PROCESS_NAME_BYTES
            || \str_contains($name, "\0")
        ) {
            return null;
        }
        $fields = \preg_split('/\s+/', \trim(\substr($stat, $commandEnd + 1)));
        // After pid and (comm), index 0 is field 3 (state), and index 19
        // is field 22 (starttime in clock ticks since boot).
        $state = \is_array($fields) ? (string)($fields[0] ?? '') : '';
        $ticks = \is_array($fields) ? (string)($fields[19] ?? '') : '';
        if (\preg_match('/\A[A-Za-z]\z/D', $state) !== 1
            || \preg_match('/\A[1-9][0-9]*\z/D', $ticks) !== 1
        ) {
            return null;
        }

        return [
            'pid' => $expectedPid,
            'name' => $name,
            'state' => $state,
            'start_ticks' => $ticks,
        ];
    }

    private function linuxStateIsExited(string $state): bool
    {
        return \in_array(\strtoupper($state), ['X', 'Z'], true);
    }

    /** @return array<string,mixed> */
    private function inspectPosixProcessWithPs(int $pid): array
    {
        $ps = \is_file('/bin/ps') && !\is_link('/bin/ps')
            ? '/bin/ps'
            : (\is_file('/usr/bin/ps') && !\is_link('/usr/bin/ps') ? '/usr/bin/ps' : '');
        $env = \is_file('/usr/bin/env') && !\is_link('/usr/bin/env') ? '/usr/bin/env' : '';
        if ($ps === '' || $env === '') {
            return [];
        }
        $command = [
            $env,
            '-i',
            'LC_ALL=C',
            'LANG=C',
            'TZ=UTC',
            $ps,
            '-ww',
            '-p',
            (string)$pid,
            '-o',
            'pid=',
            '-o',
            'state=',
            '-o',
            'lstart=',
            '-o',
            'command=',
        ];
        $stdout = '';
        // A transient descriptor-handoff failure can invalidate one bounded
        // launch while ps itself is healthy. Retry with a fresh isolated
        // process group, but never fall back to blocking proc_close().
        for ($attempt = 0; $attempt < 2 && $stdout === ''; ++$attempt) {
            try {
                $result = GatewayBoundedCommandRunner::run(
                    $command,
                    self::PROCESS_PROBE_TIMEOUT_SECONDS,
                );
                $rawCandidate = (string)($result['stdout'] ?? '');
                $candidate = '';
                if (\preg_match('/\A([^\r\n]*)(?:\r?\n)?\z/D', $rawCandidate, $row) === 1) {
                    $candidate = (string)$row[1];
                }
                if (($result['truncated'] ?? true) !== true
                    && \in_array((int)($result['code'] ?? 1), [0, 125], true)
                    && \strlen($rawCandidate) <= self::MAX_PROCESS_PROBE_OUTPUT_BYTES
                    && $this->looksLikePosixPsRow($candidate, $pid)
                ) {
                    // Exit code 125 can still carry a complete ps row when
                    // only the process-group exit proof was inconclusive.
                    $stdout = $candidate;
                }
            } catch (\Throwable) {
                $stdout = '';
            }
        }
        if ($stdout === '') {
            $exists = $this->probePosixProcessExistence($pid);
            return $exists === false ? ['exists' => false] : [];
        }
        if (\preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $stdout) === 1) {
            return [];
        }
        if (\preg_match(
            '/\A[ \t]*([1-9][0-9]*)[ \t]+(\S+)[ \t]+'
            . '(\S+[ \t]+\S+[ \t]+[0-9]{1,2}[ \t]+[0-9]{2}:[0-9]{2}:[0-9]{2}[ \t]+[0-9]{4})'
            . '(?:[ \t]+([^\r\n]*))?[ \t]*\z/D',
            $stdout,
            $matches,
        ) !== 1 || (int)$matches[1] !== $pid) {
            return [];
        }
        $state = (string)$matches[2];
        if (\in_array(\strtoupper($state[0] ?? ''), ['X', 'Z'], true)) {
            return ['exists' => false, 'pid' => $pid, 'state' => $state];
        }
        $commandLine = \trim((string)($matches[4] ?? ''));
        if (\strlen($commandLine) > self::MAX_PROCESS_COMMAND_BYTES) {
            return [];
        }
        $name = $this->processNameFromCommand($commandLine);
        if (\strlen($name) > self::MAX_PROCESS_NAME_BYTES) {
            return [];
        }
        $startedAt = \preg_replace('/\s+/', ' ', \trim((string)$matches[3])) ?? '';
        if ($startedAt === '' || \strlen($startedAt) > 128) {
            return [];
        }

        return [
            'exists' => true,
            'pid' => $pid,
            'name' => $name,
            'command' => $commandLine,
            'start_time' => $startedAt,
            'state' => $state,
        ];
    }

    /** @param list<string> $command */
    private function looksLikePosixPsRow(string $stdout, int $pid): bool
    {
        if (\preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $stdout) === 1) {
            return false;
        }

        return \preg_match(
            '/\A[ \t]*' . $pid . '[ \t]+\S+[ \t]+'
            . '\S+[ \t]+\S+[ \t]+[0-9]{1,2}[ \t]+[0-9]{2}:[0-9]{2}:[0-9]{2}[ \t]+[0-9]{4}'
            . '(?:[ \t]+[^\r\n]*)?[ \t]*\z/D',
            $stdout,
        ) === 1;
    }

    /** @return array<string,mixed> */
    private function inspectDarwinProcess(int $pid): array
    {
        $stable = null;
        // libproc/FFI can flake under Master FD pressure; retry a longer streak
        // before giving up so managed-child birth capture does not spuriously
        // fail during worker batch respawn / Master self-audit replenish.
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $first = $this->darwinProcessTableEvidence($pid);
            if ($first === null) {
                if ($attempt < 7) {
                    SchedulerSystem::usleep(10_000);
                    continue;
                }
                // libproc null after the retry streak usually means the PID is
                // gone. posix_kill(0) is microsecond-scale on Darwin and avoids
                // the 2s bounded ps probe that made startup credential retirement
                // exhaust its budget while clearing a handful of dead workers.
                $exists = $this->probePosixProcessExistence($pid);
                if ($exists === false) {
                    return ['exists' => false];
                }
                // ps can still provide bounded liveness/diagnostic evidence, but
                // processBirth() deliberately refuses its second-resolution time.
                return $this->inspectPosixProcessWithPs($pid);
            }
            $second = $this->darwinProcessTableEvidence($pid);
            if ($second === null
                || !\hash_equals($first['start_ticks'], $second['start_ticks'])
            ) {
                $second = $this->darwinProcessTableEvidence($pid);
            }
            if ($second !== null
                && \hash_equals($first['start_ticks'], $second['start_ticks'])
            ) {
                $stable = $second;
                break;
            }
            if ($attempt < 7) {
                SchedulerSystem::usleep(10_000);
            }
        }
        if ($stable === null) {
            $exists = $this->probePosixProcessExistence($pid);
            if ($exists === false) {
                return ['exists' => false];
            }

            return [];
        }
        $second = $stable;
        $info = $this->inspectPosixProcessWithPs($pid);
        if (($info['exists'] ?? null) === false) {
            // Bounded/direct ps can false-negative under Master FD inheritance
            // even after libproc proved a stable same-user birth for this PID.
            // Keep FFI evidence; immutable managed-child birth only needs ticks.
            $info = [];
        }
        if (($info['exists'] ?? null) !== true) {
            // Bounded ps often fails under Master FD inheritance even though
            // libproc already proved the same-user process birth. Prefer the
            // kernel-provided pbi_name/pbi_comm so remote observers can still
            // verify managed Master names without argv from ps.
            $libprocName = \trim((string)($second['name'] ?? ''));
            $selfMeta = $pid === (int)\getmypid()
                ? $this->selfProcessCliMetadata()
                : ['name' => '', 'command' => ''];
            $name = $selfMeta['name'] !== '' ? $selfMeta['name'] : $libprocName;
            $command = $selfMeta['command'];

            return [
                'exists' => true,
                'pid' => $pid,
                'name' => $name,
                'command' => $command,
                'start_ticks' => $second['start_ticks'],
                'start_time' => 'darwin-start-timeval:' . $second['start_ticks'],
            ];
        }
        if (\trim((string)($info['name'] ?? '')) === ''
            && \trim((string)($second['name'] ?? '')) !== ''
        ) {
            $info['name'] = $second['name'];
        }
        $info['start_ticks'] = $second['start_ticks'];
        $info['start_time'] = 'darwin-start-timeval:' . $second['start_ticks'];

        return $info;
    }

    /**
     * Darwin libproc caps pbi_name at 32 bytes including NUL (31 visible).
     * Long managed Master titles must still match the truncated kernel view.
     */
    private function darwinTruncatedProcessNameMatches(string $expected, string $observed): bool
    {
        if ($expected === '' || $observed === '' || PHP_OS_FAMILY !== 'Darwin') {
            return false;
        }
        if (\strlen($expected) <= 31) {
            return false;
        }
        $truncated = \substr($expected, 0, 31);

        return $truncated !== '' && \hash_equals($truncated, $observed);
    }

    /**
     * @return array{name:string,command:string}
     */
    private function selfProcessCliMetadata(): array
    {
        $command = '';
        if (\function_exists('cli_get_process_title')) {
            $command = \trim((string)@\cli_get_process_title());
        }
        if ($command === '' || \strlen($command) > self::MAX_PROCESS_COMMAND_BYTES) {
            return ['name' => '', 'command' => ''];
        }
        $name = $this->processNameFromCommand($command);
        if ($name === '' || \strlen($name) > self::MAX_PROCESS_NAME_BYTES) {
            return ['name' => '', 'command' => ''];
        }

        return ['name' => $name, 'command' => $command];
    }

    /** @return array{start_ticks:string,name:string}|null */
    private function darwinProcessTableEvidence(int $pid): ?array
    {
        $birth = $this->darwinProcessBirth($pid);
        if ($birth === null) {
            return null;
        }

        return $birth;
    }

    /** @return array{start_ticks:string,name:string}|null */
    private function darwinProcessBirth(int $pid): ?array
    {
        if ($pid <= 0
            || !\extension_loaded('FFI')
            || !\class_exists(\FFI::class)
            || PHP_INT_SIZE < 8
        ) {
            return null;
        }
        $ffiEnabled = \strtolower(\trim((string)\ini_get('ffi.enable')));
        if (\in_array($ffiEnabled, ['', '0', 'off', 'false', 'no'], true)) {
            return null;
        }
        try {
            $ffi = $this->darwinProcFfi();
            if ($ffi === null) {
                return null;
            }
            $info = $ffi->new('struct proc_bsdinfo');
            $size = \FFI::sizeof($info);
            $read = self::ffiScalarInt(
                $ffi->proc_pidinfo($pid, 3, 0, \FFI::addr($info), $size)
            );
            if ($read !== $size || self::ffiScalarInt($info->pbi_pid) !== $pid) {
                return null;
            }
            $seconds = self::ffiScalarInt($info->pbi_start_tvsec);
            $microseconds = self::ffiScalarInt($info->pbi_start_tvusec);
            if ($seconds < 1 || $microseconds < 0 || $microseconds > 999_999) {
                return null;
            }
            $name = $this->darwinCStringField($info->pbi_name, 32);
            if ($name === '') {
                $name = $this->darwinCStringField($info->pbi_comm, 16);
            }
            if (\strlen($name) > self::MAX_PROCESS_NAME_BYTES) {
                $name = '';
            }

            return [
                'start_ticks' => $seconds . ':' . $microseconds,
                'name' => $name,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function darwinProcFfi(): ?\FFI
    {
        if (self::$darwinProcFfi instanceof \FFI) {
            return self::$darwinProcFfi;
        }
        try {
            self::$darwinProcFfi = \FFI::cdef(
                <<<'CDEF'
typedef unsigned int uint32_t;
typedef signed int int32_t;
typedef unsigned long long uint64_t;
typedef unsigned int uid_t;
typedef unsigned int gid_t;
struct proc_bsdinfo {
    uint32_t pbi_flags;
    uint32_t pbi_status;
    uint32_t pbi_xstatus;
    uint32_t pbi_pid;
    uint32_t pbi_ppid;
    uid_t pbi_uid;
    gid_t pbi_gid;
    uid_t pbi_ruid;
    gid_t pbi_rgid;
    uid_t pbi_svuid;
    gid_t pbi_svgid;
    uint32_t rfu_1;
    char pbi_comm[16];
    char pbi_name[32];
    uint32_t pbi_nfiles;
    uint32_t pbi_pgid;
    uint32_t pbi_pjobc;
    uint32_t e_tdev;
    uint32_t e_tpgid;
    int32_t pbi_nice;
    uint64_t pbi_start_tvsec;
    uint64_t pbi_start_tvusec;
};
int proc_pidinfo(int pid, int flavor, uint64_t arg, void *buffer, int buffersize);
CDEF,
                '/usr/lib/libproc.dylib',
            );

            return self::$darwinProcFfi;
        } catch (\Throwable) {
            // Leave self::$darwinProcFfi as false so the next capture retries.
            return null;
        }
    }

    /**
     * @internal Test helper: drop a successful Darwin FFI cache so the next
     * production load path runs again. Must not be used to simulate permanent
     * disable — that was the production bug this helper documents.
     */
    public static function clearDarwinProcFfiCacheForTests(): void
    {
        self::$darwinProcFfi = false;
    }

    private function darwinCStringField(mixed $field, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }
        try {
            $raw = \FFI::string($field, $maxBytes);
        } catch (\Throwable) {
            return '';
        }
        if (!\is_string($raw) || $raw === '') {
            return '';
        }
        $nul = \strpos($raw, "\0");
        if ($nul !== false) {
            $raw = \substr($raw, 0, $nul);
        }
        $raw = \trim($raw);
        if ($raw === '' || \preg_match('/\A[\x20-\x7E]+\z/D', $raw) !== 1) {
            return '';
        }

        return $raw;
    }

    private static function ffiScalarInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if ($value instanceof \FFI\CData) {
            return (int)$value->cdata;
        }

        return (int)$value;
    }

    /** @return array<string,mixed> */
    private function inspectWindowsProcess(int $pid, bool $includeImageMetadata = true): array
    {
        if (PhpRuntimeSafetyProfile::requiresNativeExtensionIsolation()) {
            self::consumeWindowsIsolatedLaunchCommitGrace();
            return $this->inspectWindowsProcessWithCscript($pid, $includeImageMetadata);
        }
        if (!\extension_loaded('FFI')
            || !\class_exists(\FFI::class)
            || !\function_exists('iconv')
            || PHP_INT_SIZE < 8
        ) {
            return [];
        }
        $ffiEnabled = \strtolower(\trim((string)\ini_get('ffi.enable')));
        if (\in_array($ffiEnabled, ['', '0', 'off', 'false', 'no'], true)) {
            return [];
        }
        try {
            $ffi = \FFI::cdef(
                <<<'CDEF'
typedef unsigned long DWORD;
typedef int BOOL;
typedef void *HANDLE;
typedef unsigned short WCHAR;
typedef struct _FILETIME {
    DWORD dwLowDateTime;
    DWORD dwHighDateTime;
} FILETIME;
HANDLE OpenProcess(DWORD dwDesiredAccess, BOOL bInheritHandle, DWORD dwProcessId);
BOOL GetExitCodeProcess(HANDLE hProcess, DWORD *lpExitCode);
BOOL GetProcessTimes(
    HANDLE hProcess,
    FILETIME *lpCreationTime,
    FILETIME *lpExitTime,
    FILETIME *lpKernelTime,
    FILETIME *lpUserTime
);
BOOL QueryFullProcessImageNameW(
    HANDLE hProcess,
    DWORD dwFlags,
    WCHAR *lpExeName,
    DWORD *lpdwSize
);
DWORD WaitForSingleObject(HANDLE hHandle, DWORD dwMilliseconds);
BOOL CloseHandle(HANDLE hObject);
DWORD GetLastError(void);
CDEF,
                'kernel32.dll',
            );
        } catch (\Throwable) {
            return [];
        }

        $handle = null;
        $lastError = 0;
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $handle = $ffi->OpenProcess(self::WINDOWS_PROCESS_INSPECTION_ACCESS, 0, $pid);
                if ($handle !== null && !\FFI::isNull($handle)) {
                    break;
                }
                $lastError = (int)$ffi->GetLastError();
            } catch (\Throwable) {
                return [];
            }
        }
        if ($handle === null || \FFI::isNull($handle)) {
            // ERROR_INVALID_PARAMETER is returned for a PID which does not
            // exist. Access-denied and every other failure remain UNKNOWN.
            return $lastError === 87 ? ['exists' => false] : [];
        }

        try {
            if ($this->windowsProcessHandleIsActive($ffi, $handle) !== true) {
                return [];
            }
            $creationBefore = $this->windowsProcessCreationTicks($ffi, $handle);
            if ($creationBefore === null) {
                return [];
            }

            if (!$includeImageMetadata) {
                // Managed-child birth authorization needs only the immutable
                // creation FILETIME from this already-stable process handle.
                // Do not make it depend on QueryFullProcessImageNameW: x64
                // children running through Windows ARM64 emulation can expose
                // their creation time while denying that separate metadata
                // query. Full ownership/name inspection keeps the default
                // image-bound path below.
                $creationAfter = $this->windowsProcessCreationTicks($ffi, $handle);
                if ($creationAfter === null
                    || !\hash_equals($creationBefore, $creationAfter)
                    || $this->windowsProcessHandleIsActive($ffi, $handle) !== true
                ) {
                    return [];
                }

                return [
                    'exists' => true,
                    'pid' => $pid,
                    'start_time' => 'windows-creation-ticks:' . $creationAfter,
                    'start_ticks' => $creationAfter,
                ];
            }

            $buffer = $ffi->new('WCHAR[' . self::MAX_WINDOWS_IMAGE_CHARACTERS . ']');
            $length = $ffi->new('DWORD[1]');
            $length[0] = self::MAX_WINDOWS_IMAGE_CHARACTERS;
            if ((int)$ffi->QueryFullProcessImageNameW($handle, 0, $buffer, $length) === 0) {
                return [];
            }
            $characterCount = (int)$length[0];
            if ($characterCount < 1 || $characterCount >= self::MAX_WINDOWS_IMAGE_CHARACTERS) {
                return [];
            }
            // Reading the WCHAR buffer through FFI::string(char *) crashes
            // x64 PHP under Windows ARM64 emulation even with CLI OPcache/JIT
            // disabled. Copy each already-bounded code unit in PHP instead;
            // the kernel handle and its before/after creation checks remain
            // the authority for the process identity.
            $imagePath = self::windowsWideCharacterBufferToUtf8(
                $buffer,
                $characterCount,
            );
            if (!\is_string($imagePath)
                || $imagePath === ''
                || \str_contains($imagePath, "\0")
                || \strlen($imagePath) > self::MAX_PROCESS_COMMAND_BYTES
            ) {
                return [];
            }
            $creationAfter = $this->windowsProcessCreationTicks($ffi, $handle);
            if ($creationAfter === null
                || !\hash_equals($creationBefore, $creationAfter)
                || $this->windowsProcessHandleIsActive($ffi, $handle) !== true
            ) {
                return [];
            }
            $name = \basename(\str_replace('\\', '/', $imagePath));
            if ($name === '' || \strlen($name) > self::MAX_PROCESS_NAME_BYTES) {
                return [];
            }

            return [
                'exists' => true,
                'pid' => $pid,
                'name' => $name,
                // Native QueryFullProcessImageName is deliberately used as
                // immutable executable evidence; it is not claimed as argv.
                'command' => $imagePath,
                'start_time' => 'windows-creation-ticks:' . $creationAfter,
                'start_ticks' => $creationAfter,
            ];
        } catch (\Throwable) {
            return [];
        } finally {
            try {
                $ffi->CloseHandle($handle);
            } catch (\Throwable) {
                // The evidence has already been captured; best-effort close.
            }
        }
    }

    private static function consumeWindowsIsolatedLaunchCommitGrace(): void
    {
        if (self::$windowsIsolatedLaunchCommitGraceConsumed) {
            return;
        }
        self::$windowsIsolatedLaunchCommitGraceConsumed = true;
        $environmentName = 'WLS_WINDOWS_ISOLATED_BATCH_COMMIT_GRACE';
        $pending = \trim((string)(\getenv($environmentName) ?: ''));
        \putenv($environmentName);
        unset($_ENV[$environmentName], $_SERVER[$environmentName]);
        if (\hash_equals('1', $pending)) {
            // The isolated WMI broker must durably publish every Start-Process
            // PID row before a newborn child opens a second WMI query. Without
            // this one-shot fence, ARM64 Windows serializes the two operations
            // and the parent discards live children at its result deadline.
            SchedulerSystem::usleep(1_000_000);
        }
    }

    /** @return array<string,mixed> */
    private function inspectWindowsProcessWithCscript(
        int $pid,
        bool $includeImageMetadata,
    ): array {
        if ($pid <= 0 || $pid > 4_294_967_295) {
            return ['exists' => false];
        }
        $script = $this->windowsCscriptProcessProbeSource();
        $scriptPath = @\tempnam(\sys_get_temp_dir(), 'wls-process-probe-');
        if (!\is_string($scriptPath) || $scriptPath === '') {
            return [];
        }

        $scriptStatus = false;
        $evidence = [];
        $scriptVerified = false;
        $cleanupVerified = false;
        try {
            if (@\file_put_contents($scriptPath, $script, \LOCK_EX) !== \strlen($script)) {
                return [];
            }
            $scriptStatus = @\lstat($scriptPath);
            if (!$this->isSafeRegularFileStat($scriptStatus)
                || !\hash_equals(
                    $script,
                    (string)($this->readStableNoFollowFile(
                        $scriptPath,
                        \strlen($script),
                    ) ?? ''),
                )
            ) {
                return [];
            }

            $stdout = $this->runWindowsCscriptProcessProbe($scriptPath, $pid);
            if (!\is_string($stdout)) {
                return [];
            }
            $afterStatus = @\lstat($scriptPath);
            $afterSource = $this->readStableNoFollowFile($scriptPath, \strlen($script));
            if (!$this->sameFileIdentity($scriptStatus, $afterStatus)
                || !\is_string($afterSource)
                || !\hash_equals($script, $afterSource)
            ) {
                return [];
            }
            $scriptVerified = true;
            $evidence = $this->parseWindowsCscriptProcessProbe(
                $stdout,
                $pid,
                $includeImageMetadata,
            );
        } finally {
            $currentStatus = @\lstat($scriptPath);
            if ($this->sameFileIdentity($scriptStatus, $currentStatus)
                && @\unlink($scriptPath)
            ) {
                \clearstatcache(true, $scriptPath);
                $cleanupVerified = @\lstat($scriptPath) === false;
            }
        }

        return $scriptVerified && $cleanupVerified ? $evidence : [];
    }

    private function runWindowsCscriptProcessProbe(string $scriptPath, int $pid): ?string
    {
        if (!\function_exists('proc_open')
            || !\function_exists('proc_get_status')
            || !\function_exists('proc_terminate')
            || !\function_exists('proc_close')
        ) {
            return null;
        }
        $cscript = $this->resolveWindowsCscriptExecutable();
        if ($cscript === null) {
            return null;
        }
        $process = @\proc_open(
            [
                $cscript,
                '//NoLogo',
                '//T:2',
                '//E:vbscript',
                $scriptPath,
                (string)$pid,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            \sys_get_temp_dir(),
            null,
            ['bypass_shell' => true, 'suppress_errors' => true],
        );
        if (!\is_resource($process)) {
            return null;
        }
        if (isset($pipes[0]) && \is_resource($pipes[0])) {
            @\fclose($pipes[0]);
        }
        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && \is_resource($pipes[$index])) {
                @\stream_set_blocking($pipes[$index], false);
            }
        }

        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        $deadline = \hrtime(true) + (int)((self::PROCESS_PROBE_TIMEOUT_SECONDS + 0.5) * 1_000_000_000);
        $timedOut = false;
        do {
            foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $target) {
                if (!isset($pipes[$index]) || !\is_resource($pipes[$index])) {
                    continue;
                }
                $chunk = @\stream_get_contents($pipes[$index]);
                if (!\is_string($chunk) || $chunk === '') {
                    continue;
                }
                if ($target === 'stdout') {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
            if (\strlen($stdout) + \strlen($stderr) > self::MAX_PROCESS_PROBE_OUTPUT_BYTES) {
                $timedOut = true;
                break;
            }
            $status = @\proc_get_status($process);
            if (!\is_array($status)) {
                $timedOut = true;
                break;
            }
            if (($status['running'] ?? true) !== true) {
                $exitCode = (int)($status['exitcode'] ?? -1);
                break;
            }
            if (\hrtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }
            SchedulerSystem::usleep(10_000);
        } while (true);

        if ($timedOut) {
            @\proc_terminate($process);
            $killDeadline = \hrtime(true) + 500_000_000;
            do {
                $status = @\proc_get_status($process);
                if (!\is_array($status) || ($status['running'] ?? false) !== true) {
                    break;
                }
                SchedulerSystem::usleep(10_000);
            } while (\hrtime(true) < $killDeadline);
        }
        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && \is_resource($pipes[$index])) {
                $chunk = @\stream_get_contents($pipes[$index]);
                if (\is_string($chunk) && $chunk !== '') {
                    if ($index === 1) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
                @\fclose($pipes[$index]);
            }
        }
        $closeCode = @\proc_close($process);
        if ($exitCode < 0 && \is_int($closeCode)) {
            $exitCode = $closeCode;
        }

        return !$timedOut
            && $exitCode === 0
            && $stderr === ''
            && \strlen($stdout) <= self::MAX_PROCESS_PROBE_OUTPUT_BYTES
                ? $stdout
                : null;
    }

    /** @return array<string,mixed> */
    private function parseWindowsCscriptProcessProbe(
        string $stdout,
        int $pid,
        bool $includeImageMetadata,
    ): array {
        if ($pid <= 0
            || \strlen($stdout) > self::MAX_PROCESS_PROBE_OUTPUT_BYTES
            || \preg_match('/\A([^\r\n]*)(?:\r\n|\n)\z/D', $stdout, $line) !== 1
        ) {
            return [];
        }
        $row = (string)$line[1];
        if (\hash_equals("WLS_MISSING\t" . $pid, $row)) {
            return ['exists' => false];
        }
        if (\preg_match(
            '/\AWLS_PROCESS\t([1-9][0-9]*)\t([0-9]{14}\.[0-9]{6}[+-][0-9]{3})\t([^\t\r\n]*)\z/D',
            $row,
            $matches,
        ) !== 1 || (int)$matches[1] !== $pid) {
            return [];
        }
        $creation = (string)$matches[2];
        $startIdentity = 'windows-wmi-creation:' . $creation;
        if (!$includeImageMetadata) {
            return [
                'exists' => true,
                'pid' => $pid,
                'start_time' => $startIdentity,
                'start_identity' => $startIdentity,
            ];
        }
        $imagePath = (string)$matches[3];
        if ($imagePath === ''
            || \strlen($imagePath) > self::MAX_PROCESS_COMMAND_BYTES
            || \preg_match('/[\x00-\x1F\x7F]/', $imagePath) === 1
        ) {
            return [];
        }
        $name = \basename(\str_replace('\\', '/', $imagePath));
        if ($name === '' || \strlen($name) > self::MAX_PROCESS_NAME_BYTES) {
            return [];
        }

        return [
            'exists' => true,
            'pid' => $pid,
            'name' => $name,
            'command' => $imagePath,
            'start_time' => $startIdentity,
            'start_identity' => $startIdentity,
        ];
    }

    private function resolveWindowsCscriptExecutable(): ?string
    {
        $systemRoot = \rtrim(
            (string)(\getenv('SystemRoot') ?: \getenv('windir') ?: 'C:\\Windows'),
            '\\/ ',
        );
        foreach ([
            $systemRoot . '\\Sysnative\\cscript.exe',
            $systemRoot . '\\System32\\cscript.exe',
            $systemRoot . '\\SysWOW64\\cscript.exe',
        ] as $candidate) {
            $canonical = @\realpath($candidate);
            if (\is_string($canonical)
                && $canonical !== ''
                && @\is_file($canonical)
                && !@\is_link($candidate)
            ) {
                return $canonical;
            }
        }

        return null;
    }

    private function windowsCscriptProcessProbeSource(): string
    {
        return \implode("\r\n", [
            'Option Explicit',
            'Dim processId, service, firstRows, secondRows, row',
            'Dim firstCount, secondCount, firstCreation, secondCreation, firstPath, secondPath',
            'If WScript.Arguments.Count <> 1 Then WScript.Quit 2',
            'processId = WScript.Arguments(0)',
            'If Len(processId) = 0 Or Not IsNumeric(processId) Then WScript.Quit 2',
            'On Error Resume Next',
            'Set service = GetObject("winmgmts:\\\\.\\root\\cimv2")',
            'If Err.Number <> 0 Then WScript.Quit 10',
            'Err.Clear',
            'Set firstRows = service.ExecQuery("SELECT ProcessId, CreationDate, ExecutablePath FROM Win32_Process WHERE ProcessId = " & processId, "WQL", 48)',
            'If Err.Number <> 0 Then WScript.Quit 11',
            'firstCount = 0',
            'For Each row In firstRows',
            '    firstCount = firstCount + 1',
            '    firstCreation = ""',
            '    firstPath = ""',
            '    If Not IsNull(row.CreationDate) Then firstCreation = CStr(row.CreationDate)',
            '    If Not IsNull(row.ExecutablePath) Then firstPath = CStr(row.ExecutablePath)',
            'Next',
            'If firstCount = 0 Then',
            '    WScript.Echo "WLS_MISSING" & vbTab & processId',
            '    WScript.Quit 0',
            'End If',
            'If firstCount <> 1 Or Len(firstCreation) = 0 Then WScript.Quit 12',
            'Err.Clear',
            'Set secondRows = service.ExecQuery("SELECT ProcessId, CreationDate, ExecutablePath FROM Win32_Process WHERE ProcessId = " & processId, "WQL", 48)',
            'If Err.Number <> 0 Then WScript.Quit 13',
            'secondCount = 0',
            'For Each row In secondRows',
            '    secondCount = secondCount + 1',
            '    secondCreation = ""',
            '    secondPath = ""',
            '    If Not IsNull(row.CreationDate) Then secondCreation = CStr(row.CreationDate)',
            '    If Not IsNull(row.ExecutablePath) Then secondPath = CStr(row.ExecutablePath)',
            'Next',
            'If secondCount <> 1 Then WScript.Quit 14',
            'If StrComp(firstCreation, secondCreation, 0) <> 0 Then WScript.Quit 15',
            'If StrComp(firstPath, secondPath, 0) <> 0 Then WScript.Quit 16',
            'WScript.Echo "WLS_PROCESS" & vbTab & processId & vbTab & secondCreation & vbTab & secondPath',
            'WScript.Quit 0',
            '',
        ]);
    }

    private function windowsProcessHandleIsActive(\FFI $ffi, mixed $handle): ?bool
    {
        try {
            $wait = (int)$ffi->WaitForSingleObject($handle, 0);
            if ($wait === 0) { // WAIT_OBJECT_0
                return false;
            }
            if ($wait !== 258) { // WAIT_TIMEOUT; WAIT_FAILED remains UNKNOWN.
                return null;
            }
            $exitCode = $ffi->new('DWORD[1]');
            if ((int)$ffi->GetExitCodeProcess($handle, $exitCode) === 0) {
                return null;
            }

            // WaitForSingleObject is authoritative. The documented
            // STILL_ACTIVE value is retained as consistency evidence only.
            return (int)$exitCode[0] === 259 ? true : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function windowsProcessCreationTicks(\FFI $ffi, mixed $handle): ?string
    {
        try {
            $creation = $ffi->new('FILETIME');
            $exit = $ffi->new('FILETIME');
            $kernel = $ffi->new('FILETIME');
            $user = $ffi->new('FILETIME');
            if ((int)$ffi->GetProcessTimes(
                $handle,
                \FFI::addr($creation),
                \FFI::addr($exit),
                \FFI::addr($kernel),
                \FFI::addr($user),
            ) === 0) {
                return null;
            }
            $high = (int)$creation->dwHighDateTime;
            $low = (int)$creation->dwLowDateTime;
            $ticks = ($high << 32) | $low;
        } catch (\Throwable) {
            return null;
        }

        return $ticks > 0 ? (string)$ticks : null;
    }

    private static function windowsWideCharacterBufferToUtf8(
        mixed $buffer,
        int $characterCount,
    ): ?string {
        if ($characterCount < 1
            || $characterCount >= self::MAX_WINDOWS_IMAGE_CHARACTERS
            || !\function_exists('iconv')
        ) {
            return null;
        }
        $raw = '';
        try {
            for ($index = 0; $index < $characterCount; ++$index) {
                $unit = self::ffiScalarInt($buffer[$index]);
                if ($unit < 0 || $unit > 0xffff) {
                    return null;
                }
                $raw .= \pack('v', $unit);
            }
        } catch (\Throwable) {
            return null;
        }
        $decoded = @\iconv('UTF-16LE', 'UTF-8', $raw);

        return \is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    private function normalizeWindowsPath(string $path): string
    {
        $path = \trim(\str_replace('\\', '/', $path));
        return $path === '' ? '' : \strtolower(\rtrim($path, '/'));
    }

    private function processNameFromCommand(string $command): string
    {
        if ($command === '') {
            return '';
        }
        if ($command[0] === '"' || $command[0] === "'") {
            $quote = $command[0];
            $end = \strpos($command, $quote, 1);
            $token = $end === false ? '' : \substr($command, 1, $end - 1);
        } else {
            $parts = \preg_split('/\s+/', $command, 2);
            $token = \is_array($parts) ? (string)($parts[0] ?? '') : '';
        }

        return \basename(\str_replace('\\', '/', $token));
    }

    private function probePosixProcessExistence(int $pid): ?bool
    {
        if ($pid <= 0 || !\function_exists('posix_kill')) {
            return null;
        }
        if (@\posix_kill($pid, 0)) {
            return true;
        }
        $errno = \function_exists('posix_get_last_error') ? (int)\posix_get_last_error() : 0;
        if ($errno === 3) { // ESRCH
            return false;
        }
        if ($errno === 1) { // EPERM proves existence, but not ownership.
            return true;
        }

        return null;
    }

    private function readStableNoFollowFile(
        string $path,
        int $maxBytes,
        bool $allowEmpty = false,
    ): ?string {
        if ($path === '' || \str_contains($path, "\0") || $maxBytes < 1) {
            return null;
        }
        \clearstatcache(true, $path);
        $before = @\lstat($path);
        if (!$this->isSafeRegularFileStat($before)) {
            return null;
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            return null;
        }
        $content = null;
        $afterHandle = false;
        try {
            $opened = @\fstat($handle);
            if (!$this->sameFileIdentity($before, $opened)) {
                return null;
            }
            $content = @\stream_get_contents($handle, $maxBytes + 1);
            $afterHandle = @\fstat($handle);
        } finally {
            @\fclose($handle);
        }
        \clearstatcache(true, $path);
        $afterPath = @\lstat($path);
        if (!\is_string($content)
            || \strlen($content) > $maxBytes
            || (!$allowEmpty && $content === '')
            || !$this->sameFileIdentity($before, $afterHandle)
            || !$this->sameFileIdentity($before, $afterPath)
        ) {
            return null;
        }

        return $content;
    }

    /** @param array<string|int,mixed>|false $stat */
    private function isSafeRegularFileStat(array|false $stat): bool
    {
        return \is_array($stat)
            && (((int)($stat['mode'] ?? $stat[2] ?? 0)) & 0170000) === 0100000
            && (int)($stat['nlink'] ?? $stat[3] ?? 0) === 1;
    }

    /**
     * @param array<string|int,mixed>|false $expected
     * @param array<string|int,mixed>|false $actual
     */
    private function sameFileIdentity(array|false $expected, array|false $actual): bool
    {
        return $this->isSafeRegularFileStat($expected)
            && $this->isSafeRegularFileStat($actual)
            && (string)($expected['dev'] ?? $expected[0] ?? '')
                === (string)($actual['dev'] ?? $actual[0] ?? '')
            && (string)($expected['ino'] ?? $expected[1] ?? '')
                === (string)($actual['ino'] ?? $actual[1] ?? '');
    }

    private function pidNamespaceId(int $pid): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return '';
        }
        if ($this->pidNamespaceResolver !== null) {
            $namespace = ($this->pidNamespaceResolver)($pid);
        } else {
            $namespace = @\readlink('/proc/' . $pid . '/ns/pid');
        }
        if (!\is_string($namespace)
            || \preg_match('/\Apid:\[[1-9][0-9]{0,19}\]\z/D', $namespace) !== 1
        ) {
            return null;
        }

        return $namespace;
    }
}
