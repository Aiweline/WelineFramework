<?php

declare(strict_types=1);

namespace Weline\Server\Console\Console\Server;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\Process\Processer;

/**
 * Compatibility cleanup for a retired PHP built-in server generation.
 *
 * The persisted PID and port are discovery hints only. Destructive work is
 * allowed solely through an exact, generation-bound managed-process lease and
 * a fresh live-argv identity check performed by Processer.
 */
final class Stop implements CommandInterface
{
    public function __construct(private Printing $printer)
    {
    }

    public function execute(array $args = [], array $data = []): int
    {
        $serverConfig = Env::getInstance()->get('cli_server');
        if (!\is_array($serverConfig) || !\array_key_exists('pid', $serverConfig)) {
            $this->printer->info(__('没有已登记的退役 PHP 内置服务器。'));
            return 0;
        }

        $pid = (int)($serverConfig['pid'] ?? 0);
        $port = (int)($serverConfig['port'] ?? 9981);
        if ($pid <= 0) {
            if (!$this->clearRuntimeConfig()) {
                $this->printer->error(__('无法持久化清理后的退役 PHP 服务器记录。'));
                return 1;
            }
            $this->printer->note(__('已清理无效的退役 PHP 内置服务器运行时记录。'));
            return 0;
        }
        if (Processer::probeProcessState($pid, true) === Processer::PROCESS_STATE_EXITED) {
            if (!$this->clearRuntimeConfig()) {
                $this->printer->error(__('进程已退出，但无法持久化清理后的运行时记录。'));
                return 1;
            }
            $this->printer->note(__('退役 PHP 内置服务器已退出；仅清理运行时记录，未发送信号。'));
            return 0;
        }
        if ($port < 1 || $port > 65535) {
            return $this->refuseUnsafeTermination('持久化端口无效');
        }

        $expectedProcessName = 'weline-cli-server-' . $port;
        $nameOnlyPname = '--name=' . $expectedProcessName;
        $lease = Processer::getManagedProcessLeaseRecord($pid, $nameOnlyPname);
        $leasePname = \trim((string)($lease['pname'] ?? ''));
        $recordedProcessName = \trim((string)($lease['process_name'] ?? ''));
        $launchId = \trim((string)($lease['launch_id'] ?? ''));

        if ($lease === []
            || (int)($lease['pid'] ?? 0) !== $pid
            || $recordedProcessName === ''
            || !\hash_equals($expectedProcessName, $recordedProcessName)
            || $launchId === ''
            || $leasePname === ''
            || !\str_contains($leasePname, '--launch-id=' . \rawurlencode($launchId))
        ) {
            return $this->refuseUnsafeTermination('缺少可验证的精确出生 lease');
        }

        // Re-open by the generation-bearing pname so a name-only discovery
        // record can never authorize termination of another launch generation.
        $exactLease = Processer::getManagedProcessLeaseRecord($pid, $leasePname);
        if ($exactLease === []
            || !\hash_equals($launchId, \trim((string)($exactLease['launch_id'] ?? '')))
            || !\hash_equals($leasePname, \trim((string)($exactLease['pname'] ?? '')))
        ) {
            return $this->refuseUnsafeTermination('精确出生 lease 已变更');
        }

        $result = Processer::terminateManagedProcessLease(
            $pid,
            $expectedProcessName,
            $launchId,
            $leasePname,
            true,
            ['name' => $expectedProcessName, 'launch-id' => $launchId]
        );
        if (!(bool)($result['released'] ?? false)) {
            return $this->refuseUnsafeTermination(
                (string)($result['reason'] ?? '实时身份无法确认')
            );
        }

        if (!Processer::removeManagedProcessLeaseRecord($pid, $expectedProcessName, $launchId)) {
            $this->printer->error(__('进程已退出，但精确 lease 清理失败；已保留运行时记录以便重试。'));
            return 1;
        }

        if (!$this->clearRuntimeConfig()) {
            $this->printer->error(__('进程已释放，但运行时记录持久化失败；已保留记录以便重试。'));
            return 1;
        }
        if ((bool)($result['terminated'] ?? false)) {
            $this->printer->success(__('已按精确出生身份停止退役 PHP 内置服务器。'));
        } else {
            $this->printer->note(__('旧 lease 已失效，未向当前 PID 发送信号；运行时记录已清理。'));
        }

        return 0;
    }

    private function refuseUnsafeTermination(string $reason): int
    {
        $this->printer->error(__('已拒绝自动停止退役 PHP 内置服务器：%{1}。', [$reason]));
        $this->printer->note(__('持久化 PID、端口或进程名不构成终止授权；请由宿主管理员核对实际进程后处理。'));

        return 1;
    }

    private function clearRuntimeConfig(): bool
    {
        $env = Env::getInstance();
        $server = $env->get('cli_server');
        if (!\is_array($server)) {
            return true;
        }

        foreach (['pid', 'start_time', 'status'] as $runtimeKey) {
            unset($server[$runtimeKey]);
        }
        return $env->setConfig('cli_server', $server);
    }

    public function tip(): string
    {
        return (string)__('停止已退役的 PHP 内置服务器（仅精确出生 lease）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'console:server:stop',
            $this->tip(),
            [
                '-f, --force' => __('兼容参数；不会绕过精确进程出生身份校验'),
            ],
            [
                __('安全边界') => __('无法证明受管代次时拒绝发送任何终止信号'),
            ],
            []
        );
    }
}
