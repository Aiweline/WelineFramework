<?php

declare(strict_types=1);

namespace Weline\Server\Console\Console\Server;

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
            // 后台运行模式
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $tempDir = sys_get_temp_dir();
                $vbsFile = $tempDir . DS . 'start_server_' . uniqid() . '.vbs';

                $phpBinary = str_replace('/', '\\', PHP_BINARY);
                $pubPath = rtrim(str_replace('/', '\\', PUB), '\\');
                $indexPath = $pubPath . '\\index.php';
                $cmdLine = sprintf('"%s" -S %s:%d -t "%s" "%s"', $phpBinary, $host, $port, $pubPath, $indexPath);

                $cmdLineEscaped = str_replace('"', '""', $cmdLine);
                $vbsContent = 'Set WshShell = CreateObject("WScript.Shell")' . "\n";
                $vbsContent .= 'WshShell.Run "' . $cmdLineEscaped . '", 0, False' . "\n";

                file_put_contents($vbsFile, $vbsContent);

                $output = [];
                $returnVar = 0;
                @exec('cscript //nologo "' . $vbsFile . '"', $output, $returnVar);

                sleep(3);

                $pid = self::getProcessIdByPort($port);

                if ($pid) {
                    @unlink($vbsFile);
                }

                return $pid;
            } else {
                $command .= ' > /dev/null 2>&1 & echo $!';
                $output = [];
                exec($command, $output);
                $pid = isset($output[0]) ? (int)$output[0] : null;

                sleep(1);

                if ($pid && function_exists('posix_kill') && !posix_kill($pid, 0)) {
                    $pid = self::getProcessIdByPort($port);
                }

                return $pid;
            }
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
                            posix_killpg($pid, SIGTERM);
                            exit(0);
                        });

                        pcntl_signal(SIGTERM, function () use ($pid) {
                            posix_killpg($pid, SIGTERM);
                            exit(0);
                        });

                        register_shutdown_function(function () use ($pid) {
                            if ($pid) {
                                posix_killpg($pid, SIGTERM);
                            }
                        });

                        sleep(1);

                        if (posix_kill($pid, 0)) {
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
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            @exec('netstat -ano | findstr ":' . $port . '"', $output);

            if (!empty($output)) {
                foreach ($output as $line) {
                    if (preg_match('/LISTENING\s+(\d+)/', $line, $matches)) {
                        $pid = (int)$matches[1];
                        if ($pid > 0) {
                            return $pid;
                        }
                    }
                }
            }
        } else {
            $output = [];
            @exec("lsof -ti:{$port} 2>/dev/null", $output);

            if (!empty($output)) {
                $pid = (int)trim($output[0]);
                if ($pid > 0) {
                    return $pid;
                }
            }
        }

        return null;
    }
}
