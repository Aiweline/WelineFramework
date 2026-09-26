<?php

declare(strict_types=1);

namespace Weline\Framework\System\Process\Driver;

use Weline\Framework\System\Process\Native\DarwinProcessProbe;

/**
 * macOS（Darwin）进程驱动。
 *
 * ## 存在理由
 *
 * macOS 没有 `/proc`，`LinuxProcessDriver` 在 Darwin 上的**每一条**进程观察通路
 * 都落到外部 `ps`：
 *
 * | 能力 | `LinuxProcessDriver` 在 Darwin 上的实际通路 |
 * |------|--------------------------------------------|
 * | 命令行 | `/proc/{pid}/cmdline`（不存在）→ `ps -p -o args=` |
 * | 进程信息 | `/proc/{pid}/…`（不存在）→ `ps -p -o pid=,%mem=,%cpu=,lstart=` + `ps -o comm=` |
 * | 存活/僵尸 | `posix_kill`，僵尸细化再依赖 `ps -p -o pid=,stat=` |
 *
 * 于是「WLS 是否允许操作这个进程」这一**安全门禁**被押在一个可被运行环境禁用的
 * 外部二进制上。实测（agent 沙箱）：`ps` 被策略拒绝
 * （`operation not permitted: ps`，`dangerouslyDisableSandbox=true` 亦无效）⇒
 * `Processer::getProcessCommandLine()` 恒返回空串 ⇒
 * `NginxProcessIdentity` 报 `PID or command line is unavailable.` ⇒
 * 托管 Nginx 的 start / reload / stop 整体不可用。
 *
 * 本驱动用内核接口（libproc / sysctl，见 {@see DarwinProcessProbe}）补齐这些通路：
 * **零子进程、零外部二进制**。
 *
 * ## 策略：原生**优先**，但只在拿到明确结论时短路
 *
 * 正常 macOS 主机上 `ps` 可用，既有行为（含 `%mem` / `%cpu` / `lstart` 字段）
 * 被测试钉住，不能动。因此：
 *
 * - {@see self::getProcessCommandLine()}：原生优先（更准、不 fork、不受 `ps` 输出截断影响），
 *   原生取不到才回落 `parent`；
 * - {@see self::getProcessInfo()}：**`parent` 优先**，原生只补空缺字段
 *   ⇒ `ps` 可用时逐字段与今天一致；
 * - {@see self::probeProcessState()}：原生三态明确时短路，否则回落 `parent`。
 *
 * `batchGetProcessInfo()` 无需覆盖：`LinuxProcessDriver` 对非 `/proc` 平台会委托
 * {@see self::getProcessInfo()}。
 *
 * 故意**不**声明 `final`：与 {@see LinuxProcessDriver} 一致，测试需要用匿名子类
 * 覆盖 {@see AbstractProcessDriver::executeCommand()} 来模拟「外部命令不可用」，
 * 这是本缺陷唯一有判别力的回归手法。
 */
class DarwinProcessDriver extends LinuxProcessDriver
{
    /**
     * @inheritDoc
     *
     * 只认 Darwin。注册表里必须排在 {@see LinuxProcessDriver} **之前**
     * （后者对所有非 Windows 返回 true），否则本驱动永远不会被选中。
     */
    public function supports(): bool
    {
        return \PHP_OS_FAMILY === 'Darwin';
    }

    /**
     * @inheritDoc
     */
    public function getOsName(): string
    {
        return 'Darwin';
    }

    /**
     * @inheritDoc
     *
     * 策略（快→慢）：
     * 1. `sysctl(CTL_KERN, KERN_PROCARGS2, pid)`（libproc/sysctl，零子进程，字节级原始 argv）
     * 2. `ps -p -o args=`（通用回退，行为与修复前一致）
     *
     * 原生通路故意**不**做引号转义、**不**拒绝非 ASCII：
     * 与 Linux `/proc/cmdline` 的 `\0`→空格 转换保持同一呈现，
     * 使 {@see \Weline\Server\Service\Edge\Nginx\Runtime\NginxProcessIdentity::commandMatches()}
     * 的 tokenize 语义三条通路完全一致；含空格的路径在三者上同样失败闭合。
     */
    public function getProcessCommandLine(int $pid): string
    {
        if ($this->isValidPid($pid)) {
            $native = DarwinProcessProbe::commandLine($pid);
            if ($native !== null && $native !== '') {
                return $native;
            }
        }

        return parent::getProcessCommandLine($pid);
    }

    /**
     * @inheritDoc
     *
     * 原生优先三态：
     * - `running` → RUNNING；
     * - `zombie` → EXITED（进程已退出，PID 由尸体持有）；
     * - `exited` → EXITED；
     * - `unknown` → 回落 `parent`（`ps` + `posix_kill`），保持 fail-closed。
     */
    public function probeProcessState(int $pid, bool $fresh = false): string
    {
        $native = DarwinProcessProbe::liveness($pid);
        if ($native === DarwinProcessProbe::LIVENESS_RUNNING) {
            return self::PROCESS_STATE_RUNNING;
        }
        if ($native === DarwinProcessProbe::LIVENESS_ZOMBIE
            || $native === DarwinProcessProbe::LIVENESS_EXITED
        ) {
            return self::PROCESS_STATE_EXITED;
        }

        return parent::probeProcessState($pid, $fresh);
    }

    /**
     * @inheritDoc
     *
     * `parent` 先跑（`ps` 可用时字段与今天逐字节一致），原生只补 `parent` 没拿到的：
     * `exists` / `name` / `command` / `start_time`。
     *
     * `memory` / `cpu` 无原生等价物，保持 `''`
     * —— 与 {@see AbstractProcessDriver::getDefaultProcessInfo()} 的「未取到」语义一致，不伪造。
     */
    public function getProcessInfo(int $pid): array
    {
        $info = parent::getProcessInfo($pid);
        if (!$this->isValidPid($pid)) {
            return $info;
        }
        $native = DarwinProcessProbe::bsdInfo($pid);
        if ($native === null) {
            // libproc 读不到 = 进程已消失 / 是僵尸 / 无权限。
            // 三种情况都不足以改写 parent 的结论。
            return $info;
        }

        $info['exists'] = true;
        if (\trim((string)($info['name'] ?? '')) === '') {
            $info['name'] = (string)$native['name'];
        }
        if (\trim((string)($info['command'] ?? '')) === '') {
            $command = DarwinProcessProbe::commandLine($pid);
            if ($command !== null) {
                $info['command'] = $command;
            }
        }
        if (\trim((string)($info['start_time'] ?? '')) === '') {
            // 与 `ps -o lstart=` 同格式，使
            // NginxProcessIdentity::normalizeProcessStartTime() 的两条正则都能命中。
            $info['start_time'] = \date('D M j H:i:s Y', (int)$native['start_tvsec']);
        }

        return $info;
    }
}
