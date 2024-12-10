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

class Process
{
    static public function getPPid(int $pid)
    {
        if (IS_WIN) {
            $command = "wmic process where processid=$pid get parentprocessid";
            $ppid    = exec($command);
        } else {
            $command = "ps -p $pid -o ppid=";
            $ppid    = exec($command);
        }
        return $ppid;
    }

    static public function getLogProcessFilePath(string $pname)
    {
        $path = Env::VAR_DIR . 'cron' . DS . str_replace('\'"', '', $pname) . '.log';
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

    static public function getPidByName(string $pname):int
    {
        # 分系统环境
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $command = "wmic process where name=\"$pname\" get processid";
            return (int)exec($command);
        }else{
            return (int)exec('ps aux | egrep "' . $pname . '" | grep -v grep | tail -n 1 | awk \'{print $2}\'');
        }
    }
}