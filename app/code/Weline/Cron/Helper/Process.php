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
use Weline\Framework\System\OS\Win;

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
        if (IS_WIN) {
            # 使用cmd命令行创建进程
            $command = ' cmd /c start /b ' . $process_name . ' > "' . $process_log_path . '" 2>&1 "&" echo $!';
        } else {
            $command = 'nohup ' . $process_name . ' > "' . $process_log_path . '" 2>&1 & echo $!';
        }

        Process::setProcessOutput($process_name, $command . PHP_EOL);
        $procPipes = [];
        $process = proc_open($command, $descriptorspec, $procPipes);
        Process::setProcessOutput($process_name, json_encode($process) . PHP_EOL);
        # 设置进程非阻塞
//        stream_set_blocking($procPipes[1], false);
        stream_set_blocking($procPipes[1], true);
        if (is_resource($process)) {
            $pid = proc_get_status($process)['pid'];
            // 关闭文件指针
            fclose($procPipes[0]);
            fclose($procPipes[1]);
            fclose($procPipes[2]);
            return $pid;
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
        $path = Env::VAR_DIR . 'cron' . DS . $file_name . '.log';
        if (!is_file($path)) {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            touch($path);
        }
        return $path;
    }

    static public function unsetLogProcessFilePath(string $pname)
    {
        $path = self::getLogProcessFilePath($pname);
        if (is_file($path)) {
            unlink($path);
        }
        return true;
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
        return file_put_contents($path, $content, FILE_APPEND);
    }

    static public function getPidByName(string $pname): int
    {
        # 分系统环境
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $pname = str_replace(PHP_BINARY, '', $pname);
            # 查询所有包含php.exe的进程信息，然后从详情中过滤对应$pname的进程ID
            $command = "wmic process where name='php.exe' get CommandLine,ProcessId";
            $res = [];
            exec($command, $res);
            # 查询进程详情
            array_shift($res);
            foreach ($res as $value) {
                if (empty($value)) {
                    continue;
                }
                $value = str_replace('"' . PHP_BINARY . '"', '', $value);
                if (str_starts_with($value, $pname)) {
                    $value = explode(' ', $value);
                    return (int)end($value);
                }
            }
            return 0;
        } else {
            return (int)exec('ps aux | egrep "' . $pname . '" | grep -v grep | tail -n 1 | awk \'{print $2}\'');
        }
    }
}
