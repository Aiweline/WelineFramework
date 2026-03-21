<?php
declare(strict_types=1);

/**
 * Weline Server - 结束占用端口的进程
 *
 * 用于手动结束占用指定端口的进程（如 443 被非框架进程占用时）。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\System\Process\Processer;

/**
 * server:kill-port - 结束占用指定端口的进程
 */
class KillPort extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $port = (int) ($args['p'] ?? $args['port'] ?? 0);
        $force = isset($args['f']) || isset($args['force']);
        $positionalArgs = [];
        foreach ($args as $key => $arg) {
            if (\is_int($key) && !\str_starts_with((string)$arg, '-')) {
                $positionalArgs[] = $arg;
            }
        }
        array_shift($positionalArgs);
        if ($port <= 0 && isset($positionalArgs[0]) && \is_numeric($positionalArgs[0])) {
            $port = (int) $positionalArgs[0];
        }
        if ($port <= 0) {
            $port = 443;
        }

        // 检查端口是否被占用
        if (!Processer::isPortInUse($port)) {
            $this->printer->note(__('端口 %{1} 当前没有进程在监听', [$port]));
            return;
        }

        $inspect = Processer::inspectPortOccupantWithHistory($port);
        $pid = (int) ($inspect['pid'] ?? 0);
        $isWeline = (bool) ($inspect['is_weline'] ?? false);
        $state = (string) ($inspect['state'] ?? '');
        $processTag = $isWeline ? __('框架进程') : (($state === 'orphan') ? __('异常占用') : __('非框架进程'));
        
        $this->printer->warning(__('端口 %{1} 被进程占用%{2}', [
            $port,
            $pid > 0 ? " (PID: {$pid})" . ' [' . $processTag . ']' : ' [' . $processTag . ']'
        ]));

        // 非框架进程需要 -f 才能杀死；异常占用（PID 失效）无法直接 kill
        if (!$isWeline && !$force) {
            if ($state === 'orphan') {
                $this->printer->error(__('该端口处于异常占用状态（系统返回的 PID 已失效），当前无法直接杀进程'));
            } else {
                $this->printer->error(__('该端口被非框架进程占用，出于安全考虑不会自动杀死'));
            }
            $this->printer->note(__('如果确定要杀死该进程，请使用 -f 参数:'));
            $this->printer->note(__('  php bin/w server:kill-port %{1} -f', [$port]));
            $this->printer->note(__('或手动执行:'));
            $this->printer->note(__('  taskkill /F /PID %{1}', [$pid]));
            return;
        }
        
        if (!$isWeline && $force) {
            if ($state === 'orphan') {
                $this->printer->warning(__('警告：端口占用者处于异常状态（PID 失效），强制模式可能仍无法释放端口。'));
            } else {
                $this->printer->warning(__('警告：即将杀死非框架进程！'));
            }
        }

        $this->printer->note(__('正在释放端口...'));
        
        // 业务逻辑在 Server 模块决定，Processer 只负责执行
        $ok = Processer::killProcessByPort($port);
        if (!$ok) {
            // 尝试更激进的方式
            $ok = Processer::forceReleasePort($port);
        }
        
        if ($ok) {
            $this->printer->success(__('端口 %{1} 已成功释放', [$port]));
        } else {
            $this->printer->error(__('端口释放失败，请尝试：'));
            $this->printer->note(__('  1. 以管理员身份运行'));
            $this->printer->note(__('  2. 手动执行: taskkill /F /PID %{1}', [$pid > 0 ? $pid : '(未知)']));
            if ($isWeline) {
                $this->printer->note(__('  3. 使用 -f 强制模式: php bin/w server:kill-port %{1} -f', [$port]));
            }
        }
    }

    public function tip(): string
    {
        return __('结束占用指定端口的进程（如 443 被非框架进程占用时）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:kill-port [port] [-f]',
            __('结束占用指定端口的进程'),
            [
                '[port]' => __('端口号（默认 443）'),
                '-p, --port' => __('端口号'),
                '-f, --force' => __('强制模式：杀死非框架进程（危险！默认只杀框架进程）'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('结束占用 443 的框架进程') => 'php bin/w server:kill-port 443',
                __('强制杀死任何进程（危险）') => 'php bin/w server:kill-port 19443 -f',
            ]
        );
    }
}
