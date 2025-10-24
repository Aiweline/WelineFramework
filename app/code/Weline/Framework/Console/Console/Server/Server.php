<?php

namespace Weline\Framework\Console\Console\Server;

class Server {
    static function instance(
        string $host = '127.0.0.1',
        int $port = 9981,
        bool $backend = false
    ): ?int
    {
        # 启动PHP内置web服务器
        $command = PHP_BINARY . ' -S ' . $host . ':' . $port . ' -t ' . PUB.' ' .PUB. 'index.php';
        
        if ($backend) {
            // 后台运行模式 - 使用proc_open处理
            if (function_exists('proc_open')) {
                // 使用proc_open启动后台进程
                $descriptorspec = [
                    0 => ['pipe', 'r'],  // stdin
                    1 => ['pipe', 'w'],  // stdout
                    2 => ['pipe', 'w'],  // stderr
                ];
                
                $process = proc_open($command, $descriptorspec, $pipes);
                
                if (is_resource($process)) {
                    // 获取进程状态
                    $status = proc_get_status($process);
                    $pid = $status['pid'];
                    
                    // 关闭管道，让进程在后台运行
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    
                    // 等待一下确保进程启动
                    sleep(1);
                    
                    // 验证进程是否真的启动
                    if ($pid) {
                        if (function_exists('posix_kill')) {
                            $isRunning = posix_kill($pid, 0);
                            if (!$isRunning) {
                                // 进程没有启动，尝试通过端口获取PID
                                $pid = self::getProcessIdByPort($port);
                            }
                        } else {
                            // Windows系统检查
                            $output = [];
                            exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output);
                            if (empty($output) || count($output) <= 1) {
                                // 进程没有启动，尝试通过端口获取PID
                                $pid = self::getProcessIdByPort($port);
                            }
                        }
                    }
                    
                    return $pid;
                } else {
                    throw new \Exception('proc_open函数执行失败，无法启动后台进程');
                }
            } else {
                // 如果proc_open不可用，使用传统方式
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    // Windows系统后台运行 - 使用更可靠的方式
                    $command = 'start /B ' . $command . ' > NUL 2>&1';
                    exec($command);
                    
                    // 等待一下确保进程启动
                    sleep(2);
                    
                    // 通过端口获取进程ID
                    $pid = self::getProcessIdByPort($port);
                    
                    return $pid;
                } else {
                    // Unix/Linux系统后台运行
                    $command .= ' > /dev/null 2>&1 & echo $!';
                    $output = [];
                    exec($command, $output);
                    $pid = isset($output[0]) ? (int)$output[0] : null;
                    
                    // 等待一下确保进程启动
                    sleep(1);
                    
                    // 验证进程是否真的启动
                    if ($pid && !posix_kill($pid, 0)) {
                        // 进程没有启动，尝试通过端口获取PID
                        $pid = self::getProcessIdByPort($port);
                    }
                    
                    return $pid;
                }
            }
        } else {
            // 实时运行模式 - 真正的实时模式，关闭终端时子进程也会终止
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // Windows系统实时运行 - 直接执行命令，阻塞当前进程
                // 这样当用户关闭终端时，进程会自动终止
                // 使用已经构建好的命令，不要重新定义
                
                // 直接执行命令，让进程保持在前台
                // 这种方式会阻塞当前进程，直到服务器停止或用户按Ctrl+C
                exec($command);
                
                // 如果执行到这里，说明进程已经结束
                return null;
            } else {
                // Unix/Linux系统实时运行 - 使用原生方式
                if (function_exists('pcntl_fork')) {
                    $pid = pcntl_fork();
                    
                    if ($pid == 0) {
                        // 子进程执行服务器
                        // 设置新的进程组，确保关闭终端时整个进程组被终止
                        posix_setpgid(0, 0);
                        
                        // 执行服务器命令
                        exec($command);
                        exit(0);
                    } else if ($pid > 0) {
                        // 父进程
                        // 设置子进程的进程组
                        posix_setpgid($pid, $pid);
                        
                        // 注册信号处理
                        pcntl_signal(SIGINT, function() use ($pid) {
                            // 用户按Ctrl+C时终止整个进程组
                            posix_killpg($pid, SIGTERM);
                            exit(0);
                        });
                        
                        pcntl_signal(SIGTERM, function() use ($pid) {
                            // 收到终止信号时终止整个进程组
                            posix_killpg($pid, SIGTERM);
                            exit(0);
                        });
                        
                        // 注册关闭时的清理函数
                        register_shutdown_function(function() use ($pid) {
                            if ($pid) {
                                posix_killpg($pid, SIGTERM);
                            }
                        });
                        
                        // 等待子进程启动
                        sleep(1);
                        
                        // 验证子进程是否还在运行
                        if (posix_kill($pid, 0)) {
                            return $pid;
                        } else {
                            throw new \Exception('子进程启动失败');
                        }
                    } else {
                        throw new \Exception('fork失败');
                    }
                } else {
                    // 如果没有pcntl扩展，直接执行命令
                    // 这种方式在关闭终端时进程会被终止
                    exec($command);
                    return null;
                }
            }
        }
    }
    
    /**
     * 通过端口获取进程ID
     */
    private static function getProcessIdByPort(int $port): ?int
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows系统
            $output = [];
            exec("netstat -ano | findstr :{$port}", $output);
            
            foreach ($output as $line) {
                if (strpos($line, 'LISTENING') !== false) {
                    $parts = preg_split('/\s+/', trim($line));
                    if (count($parts) >= 5) {
                        $pid = (int)$parts[count($parts) - 1];
                        if ($pid > 0) {
                            return $pid;
                        }
                    }
                }
            }
        } else {
            // Unix/Linux系统
            $output = [];
            exec("lsof -ti:{$port}", $output);
            
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