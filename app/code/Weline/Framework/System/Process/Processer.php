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
        
        # 检查可用的进程控制函数
        $availableFunctions = [
            'proc_open' => function_exists('proc_open'),
            'exec' => function_exists('exec'),
            'shell_exec' => function_exists('shell_exec'),
            'popen' => function_exists('popen'),
            'pclose' => function_exists('pclose')
        ];
        
        # 创建异步程序 - 优先使用最可靠的方法
        $command_fix = !IS_WIN ? ' 2>&1 & echo $!' : '';
        $command = 'cd ' . BP . ' && ' . (IS_WIN ? 'start /min /d ' : 'nohup') . ' ' . $pname . ' > "' . self::getLogFile($pname) . '" ' . $command_fix;
        self::setOutput($pname, $command . PHP_EOL, false);
        
        $pid = 0;
        
        # 方案1: proc_open (最可靠，但可能被禁用)
        if ($availableFunctions['proc_open']) {
            $process = proc_open($command, $descriptorspec, $procPipes);
            self::setOutput($pname, json_encode($process) . PHP_EOL);
            # 设置进程非阻塞
            if (isset($procPipes[1])) {
                stream_set_blocking($procPipes[1], $block);
            }
            if (is_resource($process)) {
                $pid = (int)proc_get_status($process)['pid'];
                $pid = self::setPid($pname, $pid);
                // 关闭文件指针
                if (isset($procPipes[0])) fclose($procPipes[0]);
                if (isset($procPipes[1])) fclose($procPipes[1]);
                if (isset($procPipes[2])) fclose($procPipes[2]);
                return $pid;
            }
        }
        
        # 方案2: Windows下的PowerShell启动
        if (IS_WIN && $availableFunctions['exec']) {
            # 使用PowerShell启动隐藏进程
            $psCommand = 'powershell -WindowStyle Hidden -Command "Start-Process php -ArgumentList \'' . addslashes($pname) . '\' -WindowStyle Hidden"';
            exec($psCommand, $output, $returnCode);
            if ($returnCode === 0) {
                # 等待一秒让进程启动，然后查找PID
                sleep(1);
                $pid = self::findPhpProcessPid($pname);
                if ($pid > 0) {
                    $pid = self::setPid($pname, $pid);
                    return $pid;
                }
            }
        }
        
        # 方案3: 使用cmd /c start (Windows)
        if (IS_WIN && $availableFunctions['exec']) {
            $cmdCommand = 'cmd /c start /min /d "' . BP . '" ' . $pname;
            exec($cmdCommand . ' > "' . self::getLogFile($pname) . '" 2>&1');
            # 等待一秒让进程启动，然后查找PID
            sleep(1);
            $pid = self::findPhpProcessPid($pname);
            if ($pid > 0) {
                $pid = self::setPid($pname, $pid);
                return $pid;
            }
        }
        
        # 方案4: Linux/Mac下的后台启动
        if (!IS_WIN && $availableFunctions['exec']) {
            $nohupCommand = 'nohup ' . $pname . ' > "' . self::getLogFile($pname) . '" 2>&1 & echo $!';
            $output = [];
            exec($nohupCommand, $output);
            if (!empty($output) && is_numeric($output[0])) {
                $pid = (int)$output[0];
                $pid = self::setPid($pname, $pid);
                return $pid;
            }
        }
        
        return 0;
    }

    /**
     * @DESC          # 通过进程名查找PHP进程PID
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/11/8
     * 参数区：
     * @param string $pname
     * @return int
     */
    public static function findPhpProcessPid(string $pname): int
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // 如果传入的是包含端口的标识（如 localhost:9980 ），优先用 netstat+tasklist 查询 PID（更快且不依赖 WMI）
            if (preg_match('/localhost:(\d+)/i', $pname, $m)) {
                $port = (int)$m[1];
                $net = [];
                exec("netstat -ano | findstr :$port 2>NUL", $net);
                foreach ($net as $line) {
                    if (preg_match('/\s+(\d+)$/', trim($line), $mm)) {
                        $candidate = (int)$mm[1];
                        if ($candidate > 0) {
                            $tl = [];
                            exec("tasklist /FI \"PID eq $candidate\" /FO CSV 2>NUL", $tl);
                            foreach ($tl as $tlLine) {
                                if (stripos($tlLine, 'php.exe') !== false) {
                                    return $candidate;
                                }
                            }
                        }
                    }
                }
            }

            // 通用回退：使用 tasklist 全表扫描，匹配命令行包含关键字
            $output = [];
            exec("tasklist /V /FO CSV | findstr /I \"php\"", $output);
            foreach ($output as $line) {
                if (stripos($line, $pname) !== false) {
                    $parts = str_getcsv($line, ',', '"', '\\');
                    if (count($parts) > 1 && is_numeric(trim($parts[1]))) {
                        return (int)trim($parts[1]);
                    }
                }
            }
            return 0;
        } else {
            // Linux/Mac环境通过 ps+grep 查找
            $command = 'ps aux | grep -v grep | grep "' . $pname . '" | awk \'{print $2}\'';
            $output = [];
            exec($command, $output);
            if (!empty($output) && is_numeric($output[0])) {
                return (int)$output[0];
            }
            return 0;
        }
    }

    /**
     * 启动 PHP 内置服务器并尽量返回 PID（跨平台）
     *
     * @param string $docRoot
     * @param int $port
     * @param string $logFile
     * @return int PID or 0
     */
    public static function startBuiltInServer(string $docRoot, int $port, string $logFile): int
    {
        $pid = 0;
        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        if ($isWin) {
            # 优先使用 PowerShell Start-Process -PassThru 获取 PID（在 Windows 下隐藏控制台窗口）
            $psCmd = "powershell -NoProfile -WindowStyle Hidden -Command \"(Start-Process -FilePath 'php' -ArgumentList '-S','localhost:$port','-t','" . addslashes($docRoot) . "' -WindowStyle Hidden -PassThru).Id\"";
            $out = [];
            $code = null;
            exec($psCmd, $out, $code);
            if ($code === 0 && !empty($out[0]) && is_numeric(trim($out[0]))) {
                return (int)trim($out[0]);
            }

            # 回退：使用 start /B 并轮询 netstat 查找 pid
            $uniqueLog = str_replace('.log', '_' . $port . '.log', $logFile);
            $logDir = dirname($uniqueLog);
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $cmd = sprintf('start /B "" php -S localhost:%d -t "%s" > "%s" 2>&1', $port, addslashes($docRoot), addslashes($uniqueLog));
            exec($cmd);

            $attempts = 10;
            for ($i = 0; $i < $attempts; $i++) {
                $check = [];
                exec("netstat -ano | findstr :$port 2>NUL", $check);
                foreach ($check as $line) {
                    if (preg_match('/\s+(\d+)$/', trim($line), $m)) {
                        $candidate = (int)$m[1];
                        if ($candidate > 0) {
                            $tl = [];
                            exec("tasklist /FI \"PID eq $candidate\" /FO CSV 2>NUL", $tl);
                            foreach ($tl as $tlLine) {
                                if (stripos($tlLine, 'php.exe') !== false) {
                                    return $candidate;
                                }
                            }
                        }
                    }
                }
                usleep(200000);
            }

            # 最后回退到 findPhpProcessPid
            return self::findPhpProcessPid("localhost:$port");
        } else {
            # Linux/Mac: 使用 nohup 返回 $!
            $cmd = 'nohup php -S localhost:' . $port . ' -t ' . $docRoot . ' > "' . $logFile . '" 2>&1 & echo $!';
            $out = [];
            exec($cmd, $out);
            if (!empty($out[0]) && is_numeric(trim($out[0]))) {
                return (int)trim($out[0]);
            }

            # 回退：轮询 ps
            $attempts = 8;
            for ($i = 0; $i < $attempts; $i++) {
                $out = [];
                exec("ps aux | grep -v grep | grep \"php.*localhost:$port\" | awk '{print $2}' 2>/dev/null", $out);
                if (!empty($out[0]) && is_numeric($out[0])) {
                    return (int)$out[0];
                }
                usleep(200000);
            }
            return 0;
        }
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
            $ps = "powershell -NoProfile -Command \"(Get-CimInstance Win32_Process -Filter \\\"ProcessId=$pid\\\").ParentProcessId\"";
            $out = [];
            exec($ps, $out, $code);
            $ppid = (!empty($out[0]) && is_numeric(trim($out[0]))) ? (int)trim($out[0]) : 0;
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
     * 检查端口是否被占用
     * 
     * @param int $port 端口号
     * @return bool true=被占用，false=可用
     */
    public static function isPortInUse(int $port): bool
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            # Windows下使用netstat检查端口
            $output = [];
            exec("netstat -an | findstr :$port", $output);
            if (!empty($output)) {
                foreach ($output as $line) {
                    if (strpos($line, "LISTENING") !== false || strpos($line, "ESTABLISHED") !== false) {
                        return true;
                    }
                }
            }
        } else {
            # Linux/Mac下使用netstat或ss检查端口
            $output = [];
            exec("netstat -tln 2>/dev/null | grep :$port || ss -tln 2>/dev/null | grep :$port", $output);
            if (!empty($output)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 查找可用端口
     * 
     * @param int $startPort 起始端口
     * @param int $maxAttempts 最大尝试次数
     * @return int 可用端口
     */
    public static function findAvailablePort(int $startPort = 9980, int $maxAttempts = 50): int
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $port = $startPort + $i;
            if (!self::isPortInUse($port)) {
                return $port;
            }
        }
        # 如果所有端口都被占用，返回原始端口
        return $startPort;
    }
    
    /**
     * 查找并终止占用指定端口的进程
     * 
     * @param int $port 端口号
     * @return bool 是否成功终止
     */
    public static function killProcessByPort(int $port): bool
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            # Windows下查找占用端口的进程
            $output = [];
            exec("netstat -ano | findstr :$port", $output);
            foreach ($output as $line) {
                if (preg_match('/:\d+\s+.*?(\d+)$/', $line, $matches)) {
                    $pid = (int)$matches[1];
                    if ($pid > 0) {
                        exec("taskkill /F /PID $pid 2>NUL");
                        return true;
                    }
                }
            }
        } else {
            # Linux/Mac下查找占用端口的进程
            $output = [];
            exec("lsof -ti:$port 2>/dev/null", $output);
            foreach ($output as $line) {
                $pid = (int)trim($line);
                if ($pid > 0) {
                    exec("kill -9 $pid 2>/dev/null");
                    return true;
                }
            }
        }
        return false;
    }
    
    /**
     * 改进的进程检测方法
     * 
     * @param int $pid 进程ID
     * @return bool 进程是否运行
     */
    public static function isRunningByPid(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            # Windows下使用多种方法检测进程
            $output = [];
            
            # 方法1: tasklist精确匹配
            exec("tasklist /FI \"PID eq $pid\" 2>NUL", $output, $exitCode);
            if ($exitCode === 0 && count($output) > 2) {
                foreach ($output as $line) {
                    if (strpos($line, (string)$pid) !== false && strpos($line, 'INFO:') === false) {
                        return true;
                    }
                }
            }
            
            # 方法2: PowerShell检测
            $psOutput = [];
            exec("powershell -Command \"Get-Process -Id $pid -ErrorAction SilentlyContinue\" 2>NUL", $psOutput);
            if (!empty($psOutput) && strpos(implode(' ', $psOutput), 'Cannot') === false) {
                return true;
            }
        } else {
            # Linux/Mac下使用ps检测
            $output = [];
            exec("ps -p $pid", $output, $exitCode);
            return $exitCode === 0 && count($output) > 1;
        }
        return false;
    }
    
    /**
     * 获取进程详细信息
     * 
     * @param int $pid 进程ID
     * @return array 进程信息
     */
    public static function getProcessInfo(int $pid): array
    {
        $info = [
            'pid' => $pid,
            'exists' => false,
            'name' => '',
            'command' => '',
            'memory' => '',
            'cpu' => '',
            'start_time' => ''
        ];
        
        if ($pid <= 0) {
            return $info;
        }
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            # Windows下使用 PowerShell CIM 获取进程信息（替代 wmic，避免阻塞）
            $out = [];
            $ps = "powershell -NoProfile -Command \"Get-CimInstance Win32_Process -Filter \\\"ProcessId=$pid\\\" | Select-Object Name,CommandLine,WorkingSetSize\"";
            exec($ps, $out, $code);
            if ($code === 0 && !empty($out)) {
                $content = implode("\n", $out);
                // 解析 Name
                if (preg_match('/Name\\s+:\\s*(.+)/i', $content, $m)) {
                    $info['name'] = trim($m[1]);
                    $info['exists'] = true;
                }
                if (preg_match('/CommandLine\\s+:\\s*(.+)/i', $content, $m)) {
                    $info['command'] = trim($m[1]);
                }
                if (preg_match('/WorkingSetSize\\s+:\\s*(\\d+)/i', $content, $m)) {
                    $info['memory'] = round(((int)$m[1]) / 1024 / 1024, 2) . ' MB';
                }
            }
        } else {
            # Linux/Mac下使用ps获取进程信息
            $output = [];
            exec("ps -p $pid -o pid,comm,%mem,%cpu,lstart 2>NUL", $output);
            if (count($output) > 1) {
                $parts = preg_split('/\s+/', $output[1]);
                if (count($parts) >= 5) {
                    $info['name'] = $parts[1] ?? '';
                    $info['memory'] = $parts[2] ?? '';
                    $info['cpu'] = $parts[3] ?? '';
                    $info['start_time'] = implode(' ', array_slice($parts, 4));
                    $info['exists'] = true;
                }
            }
        }
        
        return $info;
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
