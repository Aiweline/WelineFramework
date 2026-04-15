<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：22/12/2023 09:26:37
 */

namespace Weline\Cron\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;

class Process
{
    static public function initTaskName(string $pname)
    {
        # 字符串安全
        $speicials = [
            ' ', '\'', '"'
        ];
        foreach ($speicials as $special) {
            $pname = str_replace($special, '-', $pname);
        }
        return $pname;
    }

    static public function create(string $process_name): int
    {
        $descriptorspec = array(
            0 => array('pipe', 'r'),   // 子进程将从此管道读取stdin
            1 => array('pipe', 'w'),   // 子进程将向此管道写入stdout
            2 => array('pipe', 'w')    // 子进程将向此管道写入stderr
        );
        # 创建异步程序
        $process_log_path = Process::getLogProcessFilePath($process_name);
        // 上一轮异常未归档时仍有内容；shell 重定向会截断，先移入 history 保留
        if (\is_file($process_log_path) && (int) \filesize($process_log_path) > 0) {
            self::moveCurrentLogToHistory($process_name);
        }
        if (IS_WIN) {
            # 使用cmd命令行创建进程
            $command = ' cmd /c start /b ' . $process_name . ' > "' . $process_log_path . '"';
        } else {
            $command = 'nohup ' . $process_name . ' > "' . $process_log_path . '"';
        }

        Process::setProcessOutput($process_name, $command . PHP_EOL);

        // 部分主机/环境禁用了 proc_open，这里做降级处理，避免致命错误
        if (!\function_exists('proc_open')) {
            // 同步执行一遍命令，将输出写入日志文件，但无法获取真实 PID
            // Windows / *nix 都复用同一条命令（上面已根据系统拼接好了）
            @\exec($command . ' 2>&1', $output, $exitCode);
            Process::setProcessOutput($process_name, implode(PHP_EOL, $output) . PHP_EOL);
            return 0;
        }

        $procPipes = [];
        $process = \proc_open($command, $descriptorspec, $procPipes);
        Process::setProcessOutput($process_name, json_encode($process) . PHP_EOL);
        // 设置进程阻塞读取（这里仅用于获取 PID，随后就关闭）
        \stream_set_blocking($procPipes[1], true);
        if (\is_resource($process)) {
            $status = \proc_get_status($process);
            $pid = $status['pid'] ?? 0;
            // 关闭文件指针
            \fclose($procPipes[0]);
            \fclose($procPipes[1]);
            \fclose($procPipes[2]);
            return (int)$pid;
        }
        return 0;
    }

    static public function getPPid(int $pid)
    {
        if (IS_WIN) {
            $command = "wmic process where processid=$pid get parentprocessid";
            $ppid = exec($command);
        } else {
            $command = "ps -p $pid -o ppid=";
            $ppid = exec($command);
        }
        return $ppid;
    }

    static public function getLogProcessFilePath(string $pname)
    {
        # 取出进程名称
        $names = [
            '-name', '-process'
        ];
        foreach ($names as $name) {
            if (str_contains($pname, $name)) {
                $pname = trim($pname);
                $pname = explode($name, $pname);
                $pname = $pname[1];
                $pname = trim($pname);
                $pname = explode(' ', $pname);
                $pname = $pname[0];
            }
        }
        $file_name = str_replace(':', '-', $pname);
        $path = Env::VAR_DIR . 'log' . DS . 'cron' . DS . $file_name . '.log';
        if (!is_file($path)) {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            touch($path);
        }
        return $path;
    }

    /**
     * 任务结束或切换进程时：将 var/cron/{任务}.log 移入 history，便于后台随时查看历史。
     */
    static public function unsetLogProcessFilePath(string $pname): bool
    {
        self::moveCurrentLogToHistory($pname);

        return true;
    }

    private const HISTORY_MAX_FILES = 200;

    public static function moveCurrentLogToHistory(string $pname): void
    {
        $path = self::getLogProcessFilePath($pname);
        if (!\is_file($path)) {
            return;
        }
        $size = (int) \filesize($path);
        if ($size === 0) {
            @\unlink($path);

            return;
        }
        $base = \basename($path, '.log');
        $histDir = Env::VAR_DIR . 'log' . DS . 'cron' . DS . 'history' . DS . $base;
        if (!\is_dir($histDir) && !@\mkdir($histDir, 0777, true)) {
            // 无法建目录时仍尝试删除，避免阻塞调度
            for ($i = 0; $i < 3; $i++) {
                if (@\unlink($path)) {
                    break;
                }
                \Weline\Framework\Runtime\SchedulerSystem::usleep(100000);
            }

            return;
        }
        $dest = $histDir . DS . \date('Y-m-d_His') . '_' . \bin2hex(\random_bytes(3)) . '.log';
        if (!@\rename($path, $dest)) {
            for ($i = 0; $i < 3; $i++) {
                if (@\unlink($path)) {
                    break;
                }
                \Weline\Framework\Runtime\SchedulerSystem::usleep(100000);
            }

            return;
        }
        self::pruneHistoryDir($histDir, self::HISTORY_MAX_FILES);
    }

    private static function pruneHistoryDir(string $dir, int $maxFiles): void
    {
        $files = \glob($dir . DS . '*.log') ?: [];
        if (\count($files) <= $maxFiles) {
            return;
        }
        \usort($files, static function (string $a, string $b): int {
            return \filemtime($b) <=> \filemtime($a);
        });
        foreach (\array_slice($files, $maxFiles) as $f) {
            @\unlink($f);
        }
    }

    /** 由 execute_name 得到与 getLogProcessFilePath 一致的 .log 基名（不含路径） */
    public static function logBasenameForExecuteName(string $executeName): string
    {
        return \str_replace(':', '-', self::initTaskName($executeName));
    }

    static public function killPid(int $pid, string $pname)
    {
        $logfile = self::getLogProcessFilePath($pname);
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            exec("kill $pid 2>/dev/null", $output, $exitCode);
            file_put_contents($logfile, json_encode($output), FILE_APPEND);
            return $exitCode === 0;
        } else {
            exec("taskkill /F /PID $pid 2>NUL", $output, $exitCode);
            file_put_contents($logfile, json_encode($output), FILE_APPEND);
            return $exitCode === 0;
        }
    }

    static public function isProcessRunning(int $pid)
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $output = [];
            exec("tasklist /FI \"PID eq $pid\" 2>NUL", $output, $exitCode);
            foreach ($output as $line) {
                if (strpos($line, " $pid ") !== false) {
                    return true;
                }
            }
        } else {
            $output = [];
            exec("ps -p $pid", $output);
            return count($output) > 1;
        }
        return false;
    }

    static public function getProcessOutput(string $pname): string|false
    {
        $path = self::getLogProcessFilePath($pname);
        return file_get_contents($path);
    }

    static public function setProcessOutput(string $pname, string $content): false|int
    {
        $path = self::getLogProcessFilePath($pname);
        // Windows 文件锁竞争：最多重试 3 次，每次间隔 100ms
        for ($i = 0; $i < 3; $i++) {
            $result = @file_put_contents($path, $content, FILE_APPEND | LOCK_EX);
            if ($result !== false) {
                return $result;
            }
            if ($i < 2) {
                SchedulerSystem::usleep(100000);
            }
        }
        return false;
    }

    static public function getPidByName(string $pname): int
    {
        # 分系统环境
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows 10/11 环境中 wmic 可能不存在，这里统一使用 tasklist 兼容实现
            // 通过 tasklist /V 获取带命令行信息的 php.exe 进程，然后按命令行前缀匹配 $pname
            $pname = str_replace(PHP_BINARY, '', $pname);
            $pname = trim($pname);
            $command = 'tasklist /FI "IMAGENAME eq php.exe" /V /FO LIST 2>NUL';
            $output = [];
            exec($command, $output);

            $currentCmdLine = '';
            $currentPid = 0;

            foreach ($output as $line) {
                $line = trim($line);
                if ($line === '') {
                    // 一条进程记录结束，判断是否匹配
                    if ($currentPid > 0 && $currentCmdLine !== '') {
                        // 去掉 php.exe 路径，只看其后面的命令行是否以 $pname 开头
                        $normalized = str_replace('"'.PHP_BINARY.'"', '', $currentCmdLine);
                        $normalized = trim($normalized);
                        if ($normalized !== '' && str_starts_with($normalized, $pname)) {
                            return $currentPid;
                        }
                    }
                    $currentCmdLine = '';
                    $currentPid = 0;
                    continue;
                }

                if (stripos($line, 'PID:') === 0) {
                    $currentPid = (int)trim(substr($line, 4));
                } elseif (stripos($line, 'Command Line:') === 0) {
                    $currentCmdLine = trim(substr($line, strlen('Command Line:')));
                }
            }

            return 0;
        } else {
            // 使用 escapeshellarg + grep -F 避免路径/字符串中的 \ 被 grep 误解析（stray \ before G/C/A）
            $cmd = 'ps aux 2>/dev/null | grep -F -- ' . \escapeshellarg($pname) . ' | grep -v grep | tail -n 1 | awk \'{print $2}\'';
            $lastLine = \exec($cmd) ?: '';
            return $lastLine !== '' ? (int)\trim($lastLine) : 0;
        }
    }
}
