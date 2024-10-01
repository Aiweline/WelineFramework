<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/9/27 10:17:25
 */

namespace Weline\Framework\System\Process;

use Weline\Framework\App\Env;

class Processer
{
    public static function parseArgs(string $pname): array
    {
        $args = explode(' ', $pname);
        foreach ($args as $k => $arg) {
            if ($k == 0) {
                $args['command'] = $arg;
                continue;
            }
            if (is_string($k)) {
                continue;
            }
            if (str_contains($arg, '=')) {
                $arg                      = explode('=', $arg);
                $args[trim($arg[0], '-')] = $arg[1] ?? true;
                continue;
            }
            # 参数名
            if (str_starts_with($arg, '-')) {
                $argName = trim($arg, '-');
                $next    = $args[$k + 1] ?? null;
                if (empty($next)) {
                    $args[$argName] = true;
                    $args[$arg]     = true;
                    continue;
                }
                if (str_starts_with($next, '-')) {
                    $args[$arg]     = true;
                    $args[$argName] = true;
                    $argName        = null;
                }
            } elseif (!empty($argName)) {
                if (!isset($args[$argName])) {
                    $args[$argName] = $arg;
                } else {
                    if (is_array($args[$argName])) {
                        $args[$argName][] = $arg;
                    } else {
                        $args[$argName] = [$args[$argName], $arg];
                    }
                }
            }
        }
        return $args;
    }

    /**
     * @DESC          # 创建进程
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:11
     * 参数区：
     * @param string $pname
     * @param $block
     * @return int
     */
    public static function create(string $pname, $block = true): int
    {
        if (self::running($pname)) {
            return self::getPid($pname);
        }
        $descriptorspec = array(
            0 => array('pipe', 'r'),   // 子进程将从此管道读取stdin
            1 => array('pipe', 'w'),   // 子进程将向此管道写入stdout
            2 => array('pipe', 'w')    // 子进程将向此管道写入stderr
        );
        # 创建异步程序
        $command_fix = !IS_WIN ? ' 2>&1 & echo $!' : '';
        $command     = 'cd ' . BP . ' && ' . (IS_WIN ? 'start /min /d ' : 'nohup') . ' ' . $pname . ' > "' . self::getLogFile($pname) . '" ' . $command_fix;
        self::setOutput($pname, $command . PHP_EOL, false);
        $process = proc_open($command, $descriptorspec, $procPipes);
        self::setOutput($pname, json_encode($process) . PHP_EOL);
        # 设置进程非阻塞
        stream_set_blocking($procPipes[1], $block);
        if (is_resource($process)) {
            $pid = (int)proc_get_status($process)['pid'];
            $pid = self::setPid($pname, $pid);
            // 关闭文件指针
            fclose($procPipes[0]);
            fclose($procPipes[1]);
            fclose($procPipes[2]);
            return $pid;
        }
        return 0;
    }

    public static function setPid(string $pname, int $pid): int
    {
        $pid_file  = self::getPidFile($pname);
        $name_file = self::getPidNameFile($pid);
        $task_name = self::getTaskName($pname);
        file_put_contents($pid_file, json_encode([
            'pid' => $pid,
            'time' => time(),
            'date' => date('Y-m-d H:i:s'),
            'pname' => $pname,
            'task_name' => $task_name,
        ]));
        file_put_contents($name_file, $pname);
        return $pid;
    }

    /**
     * @DESC          # 获取进程数据
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 下午5:13
     * 参数区：
     * @param string $pname
     * @param string $key
     * @return array|string
     */
    public static function getData(string $pname, string $key = ''): mixed
    {
        $pid_file = self::getPidFile($pname);
        $data     = json_decode(file_get_contents($pid_file) ?: '', true) ?: [];
        if ($key && isset($data[$key])) {
            return $data[$key];
        }
        return $data;
    }

    /**
     * @DESC          # 设置进程数据
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 下午5:12
     * 参数区：
     * @param string $pname
     * @param string $key
     * @param string $value
     * @return array
     */
    public static function setData(string $pname, string $key, string $value): array
    {
        $pid_file   = self::getPidFile($pname);
        $data       = json_decode(file_get_contents($pid_file) ?: '', true) ?: [];
        $data[$key] = $value;
        file_put_contents($pid_file, json_encode($data));
        return $data;
    }

    /**
     * @DESC          # 获取进程pid
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 下午5:12
     * 参数区：
     * @param string $pname
     * @return int
     */
    public static function getPid(string $pname): int
    {
        $pid = self::getData($pname, 'pid') ?: 0;
        if ($pid) {
            return $pid;
        }
        # 分系统环境
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            # windows环境通过命令行获取cmd进程后处理
            $command = 'wmic process where "name=\'cmd.exe\'" get ProcessId,CommandLine /format:list';
            exec($command, $output);
            foreach ($output as $out_key => $line) {
                if (empty($line)) {
                    continue;
                }
                $line = html_entity_decode($line);
                if (str_contains($line, $pname)) {
                    $pid = (int)explode('=', $output[$out_key + 1])[1] ?? 0;
                    self::setPid($pname, $pid);
                    return $pid;
                }
            }
            return 0;
        } else {
            return (int)exec('ps aux | egrep "' . $pname . '" | grep -v grep | tail -n 1 | awk \'{print $2}\'');
        }
    }

    /**
     * @DESC          # 获取父进程pid
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:11
     * 参数区：
     * @param string $pname
     * @return int
     */
    public static function getParentPid(string $pname): int
    {
        $pid  = self::getPidByName($pname);
        $ppid = self::getParentPidByPid($pid);
        return $ppid;
    }

    /**
     * @DESC          # 获取进程日志
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:11
     * 参数区：
     * @param string $pname
     * @return string
     */
    public static function getLogFile(string $pname): string
    {
        $task_name = self::getTaskName($pname);
        $path      = Env::VAR_DIR . 'process' . DS . $task_name . '.log';
        if (!is_file($path)) {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            touch($path);
        }
        return $path;
    }

    /**
     * @DESC          # 获取进程名
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 下午4:41
     * 参数区：
     * @param string $pname
     * @return string
     * @throws \Exception
     */
    public static function getTaskName(string $pname): string
    {
        if (empty($pname)) {
            throw new \Exception('进程名不能为空');
        }
        $args      = self::parseArgs($pname);
        $task_name = $args['name'] ?? $args['process'] ?? '';
        if (empty($task_name)) {
            $p_name_array = explode(PHP_BINARY, $pname);
            $task_name    = array_pop($p_name_array);
        }
        // 替换空格和单双引号
        if (empty($task_name)) {
            throw new \Exception('进程名不能为空');
        }
        return str_replace([' ', '"', "'"], '', $task_name);
    }

    /**
     * @DESC          # 获取进程日志
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:11
     * 参数区：
     * @param string $pname
     * @return string
     */
    public static function getPidFile(string $pname): string
    {
        $task_name = self::getTaskName($pname);
        $path      = Env::VAR_DIR . 'process' . DS . 'pid' . DS . $task_name . '-pid.json';
        if (!is_file($path)) {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            touch($path);
        }
        return $path;
    }

    /**
     * @DESC          # 获取进程日志
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:11
     * 参数区：
     * @param string $pname
     * @return string
     */
    public static function getPidNameFile(int $pid): string
    {
        if (0 === $pid) {
            return '';
        }
        $path = Env::VAR_DIR . 'process' . DS . 'pid' . DS . $pid . '.pid';
        if (!is_file($path)) {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            touch($path);
        }
        return $path;
    }

    /**
     * @DESC          # 移除进程日志文件
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午10:44
     * 参数区：
     * @param string $pname
     * @return true
     */
    public static function removeLogFile(string $pname)
    {
        $path = self::getLogFile($pname);
        if (is_file($path)) {
            unlink($path);
        }
        return true;
    }

    /**
     * @DESC          # 移除进程日志文件
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午10:44
     * 参数区：
     * @param string $pname
     * @return true
     */
    public static function removePidFile(string $pname)
    {
        $pid  = self::getPid($pname);
        $path = self::getPidNameFile($pid);
        if (is_file($path)) {
            unlink($path);
        }
        $path = self::getPidFile($pname);
        if (is_file($path)) {
            unlink($path);
        }
        return true;
    }

    /**
     * @DESC          # 杀死进程
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午10:45
     * 参数区：
     * @param string $pname
     * @return bool
     */
    public static function kill(string $pname)
    {
        $pid = self::getPidByName($pname);
        return self::killByPid($pid);
    }


    /**
     * @DESC          # 判断进程是否在运行
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午10:45
     * 参数区：
     * @param string $pname
     * @return bool
     */
    public static function running(string $pname): bool
    {
        $pid = self::getPid($pname);
        if (empty($pid)) {
            return false;
        }
        return self::isRunningByPid($pid);
    }

    /**
     * @DESC          # 判断进程是否在运行
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午10:45
     * 参数区：
     * @param string $pname
     * @return bool
     */
    public static function destroy(string $pname): bool
    {
        $pid = self::getPid($pname);
        if (empty($pid)) {
            self::removePidFile($pname);
            self::removeLogFile($pname);
            return false;
        }
        return self::killByPid($pid);
    }


    /**
     * @DESC          # 获取进程输出
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午10:45
     * 参数区：
     * @param string $pname
     * @return string|false
     */
    public static function output(string $pname): string|false
    {
        $path = self::getLogFile($pname);
        return file_get_contents($path);
    }


    /**
     * @DESC          # 写入进程日志
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:11
     * 参数区：
     * @param string $pname
     * @param string $content
     * @return false|int
     */
    public static function setOutput(string $pname, string $content, bool $append = true): false|int
    {
        $path = self::getLogFile($pname);
        return file_put_contents($path, $content, $append ? FILE_APPEND : 0);
    }

    /*----------------------------------------通过Pid操作函数区域------------------------------------------*/
    /**
     * @DESC          # 通过pid获取父进程pid
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:12
     * 参数区：
     * @param int $pid
     * @return int
     */
    public static function getParentPidByPid(int $pid): int
    {
        if (IS_WIN) {
            $command = "wmic process where processid=$pid get parentprocessid";
            $ppid    = exec($command);
        } else {
            $command = "ps -p $pid -o ppid=";
            $ppid    = exec($command);
        }
        return (int)$ppid;
    }

    /**
     * @DESC          # 通过pid移除进程日志文件
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午10:44
     * 参数区：
     * @param string $pname
     * @return true
     */
    public static function removeLogFileByPid(int $pid)
    {
        $pname = self::getNameByPid($pid);
        return self::removeLogFile($pname);
    }

    /**
     * @DESC          # 通过pid获取进程日志文件
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:12
     * 参数区：
     * @param int $pid
     * @return string
     */
    public static function getLogFileByPid(int $pid): string
    {
        $pname = self::getNameByPid($pid);
        return self::getLogFile($pname);
    }

    /**
     * @DESC          # 通过pid杀死进程
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午10:45
     * 参数区：
     * @param int $pid
     * @return bool
     */

    public static function killByPid(int $pid)
    {
        $pname   = self::getNameByPid($pid);
        $logfile = '';
        if ($pname) {
            $logfile = self::getLogFile($pname);
        }
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            exec("kill $pid 2>/dev/null", $output, $exitCode);
            if ($logfile) {
                file_put_contents($logfile, json_encode($output), FILE_APPEND);
            }
            $result = $exitCode === 0;
        } else {
            exec("taskkill /F /PID $pid 2>NUL", $output, $exitCode);
            if ($logfile) {
                file_put_contents($logfile, json_encode($output), FILE_APPEND);
            }
            $result = $exitCode === 0;
        }

        if ($pname) {
            # 卸载pid文件
            self::removePidFile($pname);
            # 卸载日志文件
            self::removeLogFile($pname);
        }
        return $result;
    }

    /**
     * @DESC          # 通过pid判断进程是否在运行
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午10:45
     * 参数区：
     * @param int $pid
     * @return bool
     */
    public static function isRunningByPid(int $pid): bool
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

    /**
     * @DESC          # 通过pid获取进程输出
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:12
     * 参数区：
     * @param int $pid
     * @return string|false
     */
    public static function outputByPid(int $pid): string|false
    {
        $pname = self::getNameByPid($pid);
        $path  = self::getLogFile($pname);
        return file_get_contents($path);
    }

    /**
     * @DESC          # 通过pid设置进程输出到日志
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:12
     * 参数区：
     * @param int $pid
     * @param string $content
     * @return false|int
     */
    public static function setOutputByPid(int $pid, string $content): false|int
    {
        $pname = self::getNameByPid($pid);
        $path  = self::getLogFile($pname);
        return file_put_contents($path, $content, FILE_APPEND);
    }

    /**
     * @DESC          # 通过进程名获取pid
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:13
     * 参数区：
     * @param string $pname
     * @return int
     */
    public static function getPidByName(string $pname): int
    {
        return self::getPid($pname);
    }

    /**
     * @DESC          # 通过pid获取进程名
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:13
     * 参数区：
     * @param int $pid
     * @return string
     */
    public static function getNameByPid(int $pid): string
    {
        $name_file = self::getPidNameFile($pid);
        if (!file_exists($name_file)) {
            return 'unknown';
        }
        return (string)file_get_contents($name_file);
    }
}
