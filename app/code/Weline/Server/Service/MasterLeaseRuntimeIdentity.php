<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;
use Weline\Server\Service\Edge\Gateway\GatewayHostBootIdentity;

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

    public function __construct(
        ?\Closure $bootIdentityResolver = null,
        ?\Closure $monotonicClock = null,
        ?\Closure $processInfoResolver = null,
        ?\Closure $managedProcessVerifier = null,
        ?\Closure $pidNamespaceResolver = null,
    ) {
        $this->bootIdentityResolver = $bootIdentityResolver;
        $this->monotonicClock = $monotonicClock;
        $this->processInfoResolver = $processInfoResolver;
        $this->managedProcessVerifier = $managedProcessVerifier;
        $this->pidNamespaceResolver = $pidNamespaceResolver;
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
        if ($this->isProcessDefinitelyMissing($pid)) {
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
        // bounded/direct ps cannot. Self observation still has the authoritative
        // CLI title set by applyProcessTitle().
        if (($observedName === '' || $command === '')
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
        $info = $this->processInfo($pid);
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
        try {
            $result = GatewayBoundedCommandRunner::run(
                $command,
                self::PROCESS_PROBE_TIMEOUT_SECONDS,
            );
            $stdout = (string)($result['stdout'] ?? '');
            if (($result['truncated'] ?? true) === true
                || \strlen($stdout) > self::MAX_PROCESS_PROBE_OUTPUT_BYTES
            ) {
                $stdout = '';
            }
            // Under Master FD inheritance the bounded runner often returns 125
            // even when ps already wrote a valid row. Keep parseable stdout.
            if ($stdout === '' || !$this->looksLikePosixPsRow($stdout, $pid)) {
                $stdout = $this->readPosixProcessWithPsDirect($command);
            }
        } catch (\Throwable) {
            $stdout = $this->readPosixProcessWithPsDirect($command);
        }
        if ($stdout === '') {
            $exists = $this->probePosixProcessExistence($pid);
            return $exists === false ? ['exists' => false] : [];
        }
        if (\preg_match(
            '/\A\s*([1-9][0-9]*)\s+(\S+)\s+'
            . '(\S+\s+\S+\s+[0-9]{1,2}\s+[0-9]{2}:[0-9]{2}:[0-9]{2}\s+[0-9]{4})'
            . '(?:\s+(.*))?\s*\z/sD',
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
        return \preg_match(
            '/\A\s*' . $pid . '\s+\S+\s+'
            . '\S+\s+\S+\s+[0-9]{1,2}\s+[0-9]{2}:[0-9]{2}:[0-9]{2}\s+[0-9]{4}/sD',
            $stdout,
        ) === 1;
    }

    /**
     * Plain proc_open fallback when the bounded process-group runner cannot
     * prove exit cleanly (common under Master inherited FD 3 handoff).
     *
     * @param list<string> $command
     */
    private function readPosixProcessWithPsDirect(array $command): string
    {
        if (!\function_exists('proc_open') || $command === []) {
            return '';
        }
        $pipes = [];
        $process = @\proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            return '';
        }
        $stdout = '';
        try {
            if (isset($pipes[1]) && \is_resource($pipes[1])) {
                $chunk = @\stream_get_contents($pipes[1], self::MAX_PROCESS_PROBE_OUTPUT_BYTES + 1);
                if (\is_string($chunk) && \strlen($chunk) <= self::MAX_PROCESS_PROBE_OUTPUT_BYTES) {
                    $stdout = $chunk;
                }
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    @\fclose($pipe);
                }
            }
            @\proc_close($process);
        }

        return \trim($stdout);
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
    private function inspectWindowsProcess(int $pid): array
    {
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
                $handle = $ffi->OpenProcess(0x1000, 0, $pid);
                if (!\FFI::isNull($handle)) {
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
            $rawPath = \FFI::string(
                \FFI::cast('char *', $buffer),
                $characterCount * 2,
            );
            $imagePath = @\iconv('UTF-16LE', 'UTF-8', $rawPath);
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
