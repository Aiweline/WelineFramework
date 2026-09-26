<?php

declare(strict_types=1);

namespace Weline\Framework\System\Process\Native;

/**
 * Darwin 进程观察原语：**不启动任何子进程**、不依赖外部二进制。
 *
 * ## 为什么需要它
 *
 * macOS 没有 `/proc`。此前 Darwin 上唯一的进程命令行来源是外部 `ps`
 * （{@see \Weline\Framework\System\Process\Driver\LinuxProcessDriver::getProcessCommandLine()}），
 * 于是「安全门禁」被押在一个可被运行环境禁用的外部二进制上：
 * 沙箱 / 加固运行时 / 受限 PATH 下 `ps` 不可用 ⇒ 命令行取不到 ⇒
 * `NginxProcessIdentity` 身份校验必然失败 ⇒ 托管 Nginx 生命周期整体不可用。
 *
 * 本类用内核接口补齐这条通路：
 * - `proc_pidinfo(pid, PROC_PIDTBSDINFO, …)`（libproc）→ 出生时间、状态、进程名；
 * - `sysctl(CTL_KERN, KERN_PROCARGS2, pid)`（libproc/sysctl）→ 原始 argv；
 * - `posix_kill(pid, 0)` → 仅用于区分「僵尸」与「已回收」。
 *
 * ## ★ 实测不变量（2026-09-26，macOS arm64 / PHP 8.4，`pcntl_fork` 造真实僵尸）
 *
 * | 进程状态 | `proc_pidinfo(pid,3)` | `KERN_PROCARGS2` | `posix_kill(pid,0)` |
 * |----------|----------------------|------------------|---------------------|
 * | 存活     | `read=136`，`status=2` | `rc=0`，`len>0` | `1` |
 * | **僵尸** | **`read=0`**          | **`rc=-1`**      | **`1`** |
 * | 已回收   | `read=0`              | `rc=-1`          | **`0`** |
 *
 * 两条结论（本类全部逻辑都建立在它们之上）：
 * 1. **libproc 读不到「尸体」**：僵尸与已回收在 libproc 下**完全不可区分**；
 *    唯一判据是 `posix_kill(pid, 0)` 的 `1` / `0`。
 * 2. **`read=0` 本身不等于进程已消失**：`proc_pidinfo(1, 3, …)` 同样返回 `read=0`。
 *    因此绝不允许把「读不到」直接当成 `exited`。
 *
 * ## 安全边界（务必遵守）
 *
 * - `unknown` 是唯一「不表态」的出口。调用方在 `unknown` 时**必须**保持 fail-closed，
 *   回落既有通路，不得自行推断。
 * - 「libproc 读不到 + 可发信号 ⇒ 僵尸」这条推断**只对同 uid 进程成立**。
 *   若进程在启动后改变了 uid（setuid 程序），libproc 会读不到而 `posix_kill` 仍成功，
 *   本类会把它误判为僵尸。WLS 管理的子进程（PHP / Nginx）全部同 uid 启动，
 *   不存在该形态；**不得**把本原语用于外部进程归属判定。
 * - FFI 不可用时一律 `unknown`（{@see self::available()} 是硬前置条件），
 *   绝不允许退化成「读不到 ⇒ 僵尸」而把存活进程判死。
 */
final class DarwinProcessProbe
{
    public const LIVENESS_RUNNING = 'running';
    public const LIVENESS_ZOMBIE = 'zombie';
    public const LIVENESS_EXITED = 'exited';
    public const LIVENESS_UNKNOWN = 'unknown';

    /** Darwin `<sys/proc_info.h>`：PROC_PIDTBSDINFO。 */
    private const PROC_PIDTBSDINFO = 3;
    /** Darwin `<sys/sysctl.h>`：CTL_KERN。 */
    private const CTL_KERN = 1;
    /** Darwin `<sys/sysctl.h>`：KERN_ARGMAX。 */
    private const KERN_ARGMAX = 8;
    /** Darwin `<sys/sysctl.h>`：KERN_PROCARGS2。 */
    private const KERN_PROCARGS2 = 49;
    /** Darwin `<sys/proc.h>`：SZOMB。 */
    private const SZOMB = 5;
    /** Darwin `<sys/errno.h>`：ESRCH —— 进程不存在。 */
    private const ESRCH = 3;
    /** Darwin `<sys/errno.h>`：EPERM —— 进程存在但不属于当前用户。 */
    private const EPERM = 1;

    /** 命令行上限：与 {@see \Weline\Server\Service\MasterLeaseRuntimeIdentity} 的上限对齐。 */
    private const MAX_COMMAND_BYTES = 65536;
    /** `KERN_ARGMAX` 上限护栏：内核值异常时不分配巨块。 */
    private const MAX_ARGMAX_BYTES = 4 * 1024 * 1024;

    /** Darwin `pid_t` = `int32_t`，恒为 4 字节（**不是** `PHP_INT_SIZE`）。 */
    private const PID_T_BYTES = 4;
    /** 子进程枚举上限：与 `NginxChildProcessProbe::MAX_CHILD_PROCESSES` 对齐。 */
    private const MAX_CHILD_PIDS = 4096;

    private static ?\FFI $ffi = null;

    /**
     * 本机是否具备 ps-free 观察能力。
     *
     * 这是 {@see self::liveness()} 的**硬前置条件**：不满足时必须返回 `unknown`，
     * 否则「libproc 读不到」会被误读成「僵尸」。
     */
    public static function available(): bool
    {
        if (\PHP_OS_FAMILY !== 'Darwin' || \PHP_INT_SIZE < 8) {
            return false;
        }
        if (!\extension_loaded('FFI') || !\class_exists(\FFI::class)) {
            return false;
        }
        $ffiEnabled = \strtolower(\trim((string)\ini_get('ffi.enable')));

        return !\in_array($ffiEnabled, ['', '0', 'off', 'false', 'no'], true);
    }

    /**
     * 内核视角的 BSD 进程信息。
     *
     * `null` 表示**读不到**（进程已消失、是僵尸，或无权限），
     * 调用方不得据此断言进程不存在 —— 见类注释结论 2。
     *
     * @return array{
     *   pid:int, ppid:int, status:int,
     *   start_tvsec:int, start_tvusec:int,
     *   name:string, comm:string
     * }|null
     */
    public static function bsdInfo(int $pid): ?array
    {
        if ($pid < 1) {
            return null;
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $info = $ffi->new('struct proc_bsdinfo');
            $size = \FFI::sizeof($info);
            $read = self::scalarInt(
                $ffi->proc_pidinfo($pid, self::PROC_PIDTBSDINFO, 0, \FFI::addr($info), $size)
            );
            if ($read !== $size || self::scalarInt($info->pbi_pid) !== $pid) {
                return null;
            }
            $seconds = self::scalarInt($info->pbi_start_tvsec);
            $microseconds = self::scalarInt($info->pbi_start_tvusec);
            if ($seconds < 1 || $microseconds < 0 || $microseconds > 999_999) {
                return null;
            }
            $comm = self::cString($info->pbi_comm, 16);
            $name = self::cString($info->pbi_name, 32);
            if ($name === '') {
                $name = $comm;
            }

            return [
                'pid' => $pid,
                'ppid' => self::scalarInt($info->pbi_ppid),
                'status' => self::scalarInt($info->pbi_status),
                'start_tvsec' => $seconds,
                'start_tvusec' => $microseconds,
                'name' => $name,
                'comm' => $comm,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 原始 argv，按单个空格拼接（**与 `ps -o args=` / Linux `/proc/cmdline` 的呈现一致**）。
     *
     * ### 与 `ps` 呈现的两处刻意取舍
     *
     * 1. **做 `trim()`**：本仓 `ps` 通路自己就会 trim
     *    （{@see \Weline\Framework\System\Process\Driver\LinuxProcessDriver::getProcessCommandLine()}
     *    的 `\trim($output[0])`、`getProcessInfo()` 的 `\trim($cmdOutput[0])`），
     *    且 `ps` 显示 `args` 时也不保留尾部空白。
     *    不 trim 会让同一进程在两条通路上产生不同字符串
     *    （实测：`cli_set_process_title()` 用空格补齐原标题时留下尾随空格）。
     * 2. **不做 `strnvis()` 转义**（与 {@see \Weline\Server\Service\MasterLeaseRuntimeIdentity::parseDarwinProcessArguments()}
     *    不同，那里刻意对齐 `ps` 的 `VIS_TAB|VIS_NL|VIS_NOSLASH` 呈现）：
     *    `strnvis` 在 C locale 下会把 **非 ASCII 字节**转成八进制转义，
     *    而本仓自身的项目路径就含非 ASCII（`…/Official/框架/…`），
     *    转义后与 `NginxProcessIdentity::$binary` 的字节比对必然失败。
     *    控制字符保持原样，只影响含 tab/换行的进程标题（WLS 标题不含）。
     *
     * 含空格的路径在三条约通路上**同样**失败闭合，不会出现「原生通路更宽松」的假接受。
     */
    public static function commandLine(int $pid): ?string
    {
        if ($pid < 1) {
            return null;
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $mib = $ffi->new('int[3]');
            $argmax = $ffi->new('int');
            $length = $ffi->new('size_t');
            $mib[0] = self::CTL_KERN;
            $mib[1] = self::KERN_ARGMAX;
            $length->cdata = \FFI::sizeof($argmax);
            if (self::scalarInt($ffi->sysctl($mib, 2, \FFI::addr($argmax), \FFI::addr($length), null, 0)) !== 0) {
                return null;
            }
            $capacity = self::scalarInt($argmax);
            if ($capacity < 1 || $capacity > self::MAX_ARGMAX_BYTES) {
                return null;
            }

            $buffer = $ffi->new('char[' . $capacity . ']');
            $length->cdata = $capacity;
            $mib[1] = self::KERN_PROCARGS2;
            $mib[2] = $pid;
            if (self::scalarInt($ffi->sysctl($mib, 3, $buffer, \FFI::addr($length), null, 0)) !== 0) {
                return null;
            }
            $bytes = self::scalarInt($length);
            if ($bytes < 4 || $bytes > $capacity) {
                return null;
            }

            return self::parseArguments(\FFI::string($buffer, $bytes));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 直接子进程 PID 列表（`proc_listchildpids`），等价于 `pgrep -P`，但**零子进程**。
     *
     * ### 返回值语义（★ 实测确认，与头文件注释不一致）
     *
     * `proc_listchildpids(ppid, buf, bytes)` 的返回值实测是**写入的 `pid_t` 条目数**，
     * 不是字节数：
     *
     * | 场景 | 返回值 | 缓冲区内容 |
     * |------|--------|-----------|
     * | `ppid=1` | `475` | 475 个 PID（`pgrep -P 1` 同时刻给出 474，差 1 为竞态） |
     * | shell 带 3 个 `sleep` + 探针自身 | `4` | `[90073, 90074, 90075, 90079]` |
     * | shell 带 2 个 `sleep` + 探针自身 | `3` | `[90494, 90495, 90499]` |
     *
     * 为避免押在这个未文档化的语义上，本方法**不**用返回值当循环上界，
     * 而是扫描「开头连续的正数」——缓冲区是零初始化的，未写入的槽位恒为 0，
     * 因此两种语义下都能得到正确的 PID 列表；随后再用返回值做一次一致性交叉校验。
     *
     * ### 失败闭合
     *
     * - `$pid < 1` → `null`（实测 `ppid=0` 返回的是垃圾数据，必须拦住）；
     * - FFI 不可用 → `null`；
     * - 返回值 `< 0`，或「说写了内容但扫不出 PID」，或「扫描填满整个缓冲区（可能被截断）」，
     *   或「数量既不等于返回值、也不等于返回值/4」→ `null`（**不表态**，调用方保持 fail-closed）。
     *
     * `null` 与 `[]` 语义严格区分：`[]` 是「确认没有子进程」，`null` 是「问不出来」。
     *
     * @return list<int>|null
     */
    public static function childPids(int $pid): ?array
    {
        if ($pid < 1) {
            return null;
        }
        if (!self::available()) {
            return null;
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $capacity = self::MAX_CHILD_PIDS;
            $buffer = $ffi->new('int[' . $capacity . ']', false, false);
            $written = self::scalarInt(
                $ffi->proc_listchildpids(
                    $pid,
                    \FFI::addr($buffer[0]),
                    $capacity * self::PID_T_BYTES,
                )
            );
            if ($written < 0) {
                return null;
            }
            if ($written === 0) {
                return [];
            }

            $pids = [];
            for ($index = 0; $index < $capacity; $index++) {
                $candidate = self::scalarInt($buffer[$index]);
                if ($candidate < 1) {
                    break;
                }
                $pids[] = $candidate;
            }
            $count = \count($pids);
            if ($count === 0 || $count === $capacity) {
                return null;
            }
            if ($count !== $written && $count !== \intdiv($written, self::PID_T_BYTES)) {
                return null;
            }

            return $pids;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 四态存活判定。
     *
     * - `running`：内核给出可读的、非 SZOMB 的进程记录；
     * - `zombie`：内核读不到该 PID 的进程记录，但它仍可被本进程发信号
     *   ⇒ 进程已退出、PID 由尸体持有（唯一能区分「僵尸」与「已回收」的原语）；
     * - `exited`：`posix_kill` 报 `ESRCH`，PID 已回收；
     * - `unknown`：FFI 不可用 / 无 `posix_kill` / 进程属他人（`EPERM`）。
     *   **调用方必须保持 fail-closed。**
     */
    public static function liveness(int $pid): string
    {
        if ($pid < 1) {
            // 与 LinuxProcessDriver::probeProcessState() 对非法 PID 的既有约定一致。
            return self::LIVENESS_EXITED;
        }
        if (!self::available()) {
            // ★ 硬护栏：没有 ps-free 能力时必须不表态。
            // 否则下面「读不到 + 可发信号 ⇒ 僵尸」会把存活进程判死。
            return self::LIVENESS_UNKNOWN;
        }

        $info = self::bsdInfo($pid);
        if ($info !== null) {
            return $info['status'] === self::SZOMB
                ? self::LIVENESS_ZOMBIE
                : self::LIVENESS_RUNNING;
        }

        $signalable = self::signalable($pid);
        if ($signalable === true) {
            return self::LIVENESS_ZOMBIE;
        }
        if ($signalable === false) {
            return self::LIVENESS_EXITED;
        }

        return self::LIVENESS_UNKNOWN;
    }

    /**
     * 是否可作为僵尸判据的「本进程可发信号」证据。
     *
     * - `true`：`posix_kill(pid, 0)` **真的成功** ⇒ 存在且属于当前用户；
     * - `false`：`ESRCH` ⇒ 不存在；
     * - `null`：`EPERM`（存在但属他人）或无 `posix_kill` ⇒ **不可用于僵尸推断**。
     *
     * `EPERM` 必须归入 `null`：它对「进程是否存在」是肯定证据，
     * 但对「是不是尸体」毫无信息量。
     */
    private static function signalable(int $pid): ?bool
    {
        if (!\function_exists('posix_kill')) {
            return null;
        }
        if (\function_exists('posix_clear_last_error')) {
            @\posix_clear_last_error();
        }
        if (@\posix_kill($pid, 0)) {
            return true;
        }
        $errno = \function_exists('posix_get_last_error') ? (int)\posix_get_last_error() : 0;
        if ($errno === self::EPERM) {
            return null;
        }
        if ($errno === self::ESRCH) {
            return false;
        }

        return null;
    }

    /**
     * 解析 `KERN_PROCARGS2` 载荷：`int argc` + 可执行路径 + 对齐 NUL + `argc` 个 argv。
     *
     * 只取 argv，**不**触碰其后的环境块（内核会把 environ 追加在同一缓冲里）。
     */
    private static function parseArguments(string $raw): ?string
    {
        $length = \strlen($raw);
        if ($length < 5) {
            return null;
        }
        $argc = \unpack('i', \substr($raw, 0, 4))[1];
        if ($argc < 1 || $argc > $length - 4) {
            return null;
        }
        // 跳过内核保存的可执行路径。
        $offset = \strpos($raw, "\0", 4);
        if ($offset === false) {
            return null;
        }
        // 跳过路径与其后的对齐 NUL。
        while ($offset < $length && $raw[$offset] === "\0") {
            ++$offset;
        }

        $command = '';
        for ($index = 0; $index < $argc; ++$index) {
            if ($offset >= $length) {
                return null;
            }
            $end = \strpos($raw, "\0", $offset);
            if ($end === false) {
                return null;
            }
            $command .= ($index > 0 ? ' ' : '') . \substr($raw, $offset, $end - $offset);
            if (\strlen($command) > self::MAX_COMMAND_BYTES) {
                return null;
            }
            $offset = $end + 1;
        }
        // `ps` 显示 args 与 `getProcessCommandLine()` 的 ps 通路都会 trim 尾部空白
        // （`cli_set_process_title()` 用空格补齐原标题时会留下尾随空格）。
        $command = \trim($command);

        return $command === '' ? null : $command;
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffi instanceof \FFI) {
            return self::$ffi;
        }
        if (!self::available()) {
            return null;
        }
        // 成功结果缓存；失败**不**置永久不可用标志 —— 一次瞬时失败就把整个
        // Darwin 原生通路永久关掉，是本仓已经踩过的生产缺陷形态。
        try {
            self::$ffi = \FFI::cdef(
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
int proc_listchildpids(int ppid, void *buffer, int buffersize);
int sysctl(int *name, unsigned int namelen, void *oldp, size_t *oldlenp, void *newp, size_t newlen);
CDEF,
                '/usr/lib/libproc.dylib',
            );
        } catch (\Throwable) {
            self::$ffi = null;

            return null;
        }

        return self::$ffi;
    }

    /**
     * FFI 标量读成 PHP int。
     *
     * ★ 不要用 `isset($value->cdata)` 探测：`FFI\CData` 实现了数组访问，
     * `isset()` 会退化到 `offsetExists()` 并抛
     * `Cannot use object of type FFI\CData as array`。
     * 必须显式 `instanceof \FFI\CData` 后再读 `->cdata`
     * （与 {@see \Weline\Server\Service\MasterLeaseRuntimeIdentity::ffiScalarInt()} 一致）。
     */
    private static function scalarInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if ($value instanceof \FFI\CData) {
            return (int)$value->cdata;
        }

        return (int)$value;
    }

    /** 读取定长 `char[N]` 字段到首个 NUL。 */
    private static function cString(mixed $field, int $maxLength): string
    {
        try {
            $value = \FFI::string($field, $maxLength);
        } catch (\Throwable) {
            return '';
        }
        $nul = \strpos($value, "\0");

        return \trim($nul === false ? $value : \substr($value, 0, $nul));
    }

    /**
     * @internal 测试辅助：丢弃 FFI 句柄缓存，使下一次调用重新加载。
     */
    public static function clearCacheForTests(): void
    {
        self::$ffi = null;
    }
}
