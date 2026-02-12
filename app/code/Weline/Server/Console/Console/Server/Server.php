<?php

declare(strict_types=1);

namespace Weline\Server\Console\Console\Server;

use Weline\Framework\System\Process\Processer;

/**
 * PHP 内置 Web 服务器启动类
 * 从 Framework 迁入 Server 模块
 */
class Server
{
    public static function instance(
        string $host = '127.0.0.1',
        int $port = 9981,
        bool $backend = false
    ): ?int {
        # 启动PHP内置web服务器
        $command = PHP_BINARY . ' -S ' . $host . ':' . $port . ' -t ' . PUB . ' ' . PUB . 'index.php';

        if ($backend) {
            $processName = 'weline-cli-server-' . $port;
            $command .= ' --name=' . $processName;
            $pid = Processer::create($command, false, false);
            if ($pid > 0) {
                return $pid;
            }
            sleep(1);
            return self::getProcessIdByPort($port);
        } else {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                exec($command);
                return null;
            } else {
                if (function_exists('pcntl_fork')) {
                    $pid = pcntl_fork();

                    if ($pid == 0) {
                        posix_setpgid(0, 0);
                        exec($command);
                        exit(0);
                    } elseif ($pid > 0) {
                        posix_setpgid($pid, $pid);

                        pcntl_signal(SIGINT, function () use ($pid) {
                            self::terminateProcessGroup($pid, SIGTERM);
                            exit(0);
                        });

                        pcntl_signal(SIGTERM, function () use ($pid) {
                            self::terminateProcessGroup($pid, SIGTERM);
                            exit(0);
                        });

                        register_shutdown_function(function () use ($pid) {
                            if ($pid) {
                                self::terminateProcessGroup($pid, SIGTERM);
                            }
                        });

                        sleep(1);

                        if (Processer::isRunningByPid($pid)) {
                            return $pid;
                        } else {
                            throw new \Exception('子进程启动失败');
                        }
                    } else {
                        throw new \Exception('fork失败');
                    }
                } else {
                    exec($command);
                    return null;
                }
            }
        }
    }

    private static function getProcessIdByPort(int $port): ?int
    {
        $pid = Processer::getProcessIdByPort($port);
        return $pid > 0 ? $pid : null;
    }

    /**
     * 通过 Processer 发送终止信号
     */
    private static function terminateProcessGroup(int $pid, int $signal): void
    {
        if ($pid <= 0) {
            return;
        }
        if ($signal === SIGKILL) {
            Processer::killProcessTreeByPid($pid, true);
            return;
        }
        Processer::sendSignal($pid, $signal, true);
    }
}
