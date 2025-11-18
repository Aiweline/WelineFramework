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
            // 后台运行模式
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // Windows 系统：使用 VBS 脚本后台启动（最可靠的方式）
                // VBS 的 WScript.Shell.Run 可以真正后台启动，不阻塞
                
                // 创建临时 VBS 脚本文件
                $tempDir = sys_get_temp_dir();
                $vbsFile = $tempDir . DS . 'start_server_' . uniqid() . '.vbs';
                
                // 构造命令参数
                $phpBinary = str_replace('/', '\\', PHP_BINARY);
                $pubPath = rtrim(str_replace('/', '\\', PUB), '\\'); // 移除末尾反斜杠
                $indexPath = $pubPath . '\\index.php';
                // 使用 rtrim 确保路径不以反斜杠结尾，避免转义引号
                $cmdLine = sprintf('"%s" -S %s:%d -t "%s" "%s"', $phpBinary, $host, $port, $pubPath, $indexPath);
                
                // VBS 脚本内容：使用 Run 方法，第二个参数 0 表示隐藏窗口，第三个参数 false 表示不等待
                // 注意：VBS 中字符串用双引号包裹，命令行中的引号需要转义
                $cmdLineEscaped = str_replace('"', '""', $cmdLine); // VBS 中双引号转义为两个双引号
                $vbsContent = 'Set WshShell = CreateObject("WScript.Shell")' . "\n";
                $vbsContent .= 'WshShell.Run "' . $cmdLineEscaped . '", 0, False' . "\n";
                
                // 写入 VBS 文件
                file_put_contents($vbsFile, $vbsContent);
                
                // 执行 VBS 脚本（cscript //nologo 不显示logo，立即返回）
                // 使用 @exec 抑制错误，避免阻塞
                $output = [];
                $returnVar = 0;
                @exec('cscript //nologo "' . $vbsFile . '"', $output, $returnVar);
                
                // 等待进程启动
                sleep(3); // 增加等待时间到3秒
                
                // 调试：不立即删除 VBS 文件，方便排查问题
                // @unlink($vbsFile);
                
                // 通过端口获取进程ID
                $pid = self::getProcessIdByPort($port);
                
                // 如果获取到PID，删除 VBS 文件
                if ($pid) {
                    @unlink($vbsFile);
                }
                
                return $pid;
            } else {
                // Unix/Linux 系统：使用后台执行
                $command .= ' > /dev/null 2>&1 & echo $!';
                $output = [];
                exec($command, $output);
                $pid = isset($output[0]) ? (int)$output[0] : null;
                
                // 等待一下确保进程启动
                sleep(1);
                
                // 验证进程是否真的启动
                if ($pid && function_exists('posix_kill') && !posix_kill($pid, 0)) {
                    // 进程没有启动，尝试通过端口获取PID
                    $pid = self::getProcessIdByPort($port);
                }
                
                return $pid;
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
            // Unix/Linux系统
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