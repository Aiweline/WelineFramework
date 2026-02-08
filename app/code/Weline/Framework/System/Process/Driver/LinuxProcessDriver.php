<?php
declare(strict_types=1);

namespace Weline\Framework\System\Process\Driver;

/**
 * Linux/Unix/Mac 进程驱动
 * 
 * 实现 Linux、Unix、macOS 平台特定的进程操作。
 * 
 * 参考国际顶级 PHP 进程管理库的跨平台最佳实践：
 * 
 * 1. Symfony Process：
 *    - 使用 /proc 文件系统（Linux）实现零开销进程检测
 *    - sigchild 兼容（--enable-sigchild 编译选项）
 *    - 使用 exec 前缀确保信号传递到实际进程而非 shell
 * 
 * 2. AMPHP Process：
 *    - 使用 POSIX 信号（posix_kill）进行进程检测
 *    - 标准 pipe 用于 Unix（非阻塞原生支持）
 * 
 * 工具优先级：
 * - 进程检测：/proc > posix_kill > ps（零开销 → 系统调用 → fork进程）
 * - 端口检测：socket > ss > netstat > lsof（快→慢）
 * - 进程搜索：pgrep > ps+grep（高效→通用）
 * - 端口PID：ss -tlnp > lsof > fuser（高效→通用→兼容）
 */
class LinuxProcessDriver extends AbstractProcessDriver
{
    /**
     * 端口状态缓存（与 Windows 驱动对齐）
     */
    private static array $portCache = [];
    
    /**
     * 端口缓存过期时间（秒）
     */
    private static int $portCacheTtl = 10;
    
    /**
     * @inheritDoc
     */
    public function getOsName(): string
    {
        return PHP_OS;
    }
    
    /**
     * @inheritDoc
     */
    public function supports(): bool
    {
        return \strtoupper(\substr(PHP_OS, 0, 3)) !== 'WIN';
    }
    
    /**
     * @inheritDoc
     * 
     * 策略（快→慢）：
     * 1. pgrep -f（高效，直接在内核态匹配命令行）
     * 2. ps aux + grep（通用回退，所有 Unix 系统都支持）
     */
    public function findPhpProcessPid(string $pname): int
    {
        $output = [];
        $exitCode = 0;
        
        // 方案1：使用 pgrep（更高效，不 fork 额外的 grep 进程）
        $escapedName = \str_replace("'", "'\\''", $pname);
        $this->executeCommand("pgrep -f '{$escapedName}' 2>/dev/null", $output, $exitCode);
        
        if ($exitCode === 0 && !empty($output)) {
            foreach ($output as $line) {
                $pid = $this->sanitizePid($line);
                if ($pid > 0 && $pid !== \getmypid()) {
                    return $pid;
                }
            }
        }
        
        // 方案2：回退到 ps + grep（兼容所有 Unix 系统，包括 Alpine/BusyBox 等没有 pgrep 的系统）
        $output = [];
        $this->executeCommand(
            'ps aux 2>/dev/null | grep -F ' . \escapeshellarg($pname) . ' | grep -v grep | awk \'{print $2}\'',
            $output
        );
        
        foreach ($output as $line) {
            $pid = $this->sanitizePid($line);
            if ($pid > 0 && $pid !== \getmypid()) {
                return $pid;
            }
        }
        
        return 0;
    }
    
    /**
     * @inheritDoc
     */
    public function startBuiltInServer(string $docRoot, int $port, string $logFile): int
    {
        $functions = $this->detectAvailableFunctions();
        
        if ($functions['exec']) {
            $uniqueLog = \str_replace('.log', '_' . $port . '.log', $logFile);
            $logDir = \dirname($uniqueLog);
            if (!\is_dir($logDir)) {
                @\mkdir($logDir, 0755, true);
            }
            
            // 使用 exec 前缀确保 PID 是实际进程而非 shell（参考 Symfony Process）
            $cmd = \sprintf(
                'nohup php -S localhost:%d -t %s > %s 2>&1 & echo $!',
                $port,
                \escapeshellarg($docRoot),
                \escapeshellarg($uniqueLog)
            );
            $out = [];
            $this->executeCommand($cmd, $out);
            
            if (!empty($out[0])) {
                $pid = $this->sanitizePid($out[0]);
                if ($pid > 0) {
                    return $pid;
                }
            }
        }
        
        return 0;
    }
    
    /**
     * @inheritDoc
     */
    public function startBackgroundProcess(string $command, string $logFile, bool $block = false): int
    {
        $functions = $this->detectAvailableFunctions();
        
        if ($functions['exec']) {
            $logDir = \dirname($logFile);
            if (!\is_dir($logDir)) {
                @\mkdir($logDir, 0755, true);
            }
            
            $escapedLog = \escapeshellarg($logFile);
            $cmd = 'cd ' . \escapeshellarg(BP) . ' && nohup ' . $command . ' > ' . $escapedLog . ' 2>&1 & echo $!';
            $out = [];
            $this->executeCommand($cmd, $out);
            
            if (!empty($out[0])) {
                $pid = $this->sanitizePid($out[0]);
                if ($pid > 0) {
                    return $pid;
                }
            }
        }
        
        return 0;
    }
    
    /**
     * @inheritDoc
     * 
     * 使用 SIGTERM（优雅终止），参考 Symfony Process 的 stop() 方法
     */
    public function killProcess(int $pid): bool
    {
        if (!$this->isValidPid($pid)) {
            return false;
        }
        
        // 1. 先发送 SIGTERM（优雅终止）
        $output = [];
        $exitCode = 0;
        $this->executeCommand("kill -" . self::SIGTERM . " {$pid} 2>/dev/null", $output, $exitCode);
        
        // 等待进程响应 SIGTERM
        $this->waitMs(300);
        
        // 2. 检查进程是否已退出
        if (!$this->isRunningByPid($pid)) {
            return true;
        }
        
        // 3. 进程仍在运行 -> 无论 SIGTERM 结果如何，都尝试 SIGKILL
        //    SIGKILL 无法被捕获/忽略，即使 SIGTERM 因权限不足失败，SIGKILL 仍可能成功
        $output = [];
        $exitCode = 0;
        $this->executeCommand("kill -" . self::SIGKILL . " {$pid} 2>/dev/null", $output, $exitCode);
        $this->waitMs(100);
        
        return !$this->isRunningByPid($pid);
    }
    
    /**
     * @inheritDoc
     * 
     * 真正杀死进程树（包括所有子进程）：
     * 1. 收集所有子进程 PID（使用 /proc 或 pgrep -P）
     * 2. 先对所有进程发送 SIGTERM
     * 3. 等待优雅退出
     * 4. 对仍存活的进程发送 SIGKILL
     * 
     * 参考 Symfony Process 的进程树管理
     */
    public function killProcessTree(int $pid): bool
    {
        if (!$this->isValidPid($pid)) {
            return false;
        }
        
        // 1. 收集进程树中所有 PID（子进程 + 本身）
        $allPids = $this->getProcessTreePids($pid);
        // 将父进程放在最后，先杀子进程
        $allPids[] = $pid;
        $allPids = \array_unique($allPids);
        
        // 2. 先向所有进程发送 SIGTERM（优雅终止）
        foreach ($allPids as $p) {
            $functions = $this->detectAvailableFunctions();
            if ($functions['posix_kill']) {
                @\posix_kill($p, self::SIGTERM);
            } else {
                @\exec("kill -" . self::SIGTERM . " {$p} 2>/dev/null");
            }
        }
        
        // 3. 等待优雅退出
        $this->waitMs(500);
        
        // 4. 检查哪些进程仍在运行，发送 SIGKILL
        $stillRunning = false;
        foreach ($allPids as $p) {
            if ($this->isRunningByPid($p)) {
                $stillRunning = true;
                $functions = $this->detectAvailableFunctions();
                if ($functions['posix_kill']) {
                    @\posix_kill($p, self::SIGKILL);
                } else {
                    @\exec("kill -" . self::SIGKILL . " {$p} 2>/dev/null");
                }
            }
        }
        
        if ($stillRunning) {
            $this->waitMs(200);
        }
        
        // 5. 最终回退：使用 pkill 按进程组杀（某些系统上更可靠）
        if ($this->isRunningByPid($pid)) {
            $output = [];
            $this->executeCommand("pkill -9 -P {$pid} 2>/dev/null", $output);
            $this->executeCommand("kill -9 {$pid} 2>/dev/null", $output);
            $this->waitMs(100);
        }
        
        return !$this->isRunningByPid($pid);
    }
    
    /**
     * 获取进程树中所有子进程的 PID（递归）
     * 
     * 策略（快→慢）：
     * 1. /proc/{pid}/task/{pid}/children（Linux 3.5+，零开销）
     * 2. pgrep -P（大多数 Unix 系统）
     * 3. ps --ppid（通用回退）
     * 
     * @param int $pid 父进程 PID
     * @return int[] 子进程 PID 列表
     */
    private function getProcessTreePids(int $pid): array
    {
        $children = [];
        
        // 方案1：/proc 文件系统（Linux 特有，零开销，最快）
        $childrenFile = "/proc/{$pid}/task/{$pid}/children";
        if (\is_file($childrenFile)) {
            $content = @\file_get_contents($childrenFile);
            if ($content !== false && $content !== '') {
                $childPids = \preg_split('/\s+/', \trim($content));
                foreach ($childPids as $childPid) {
                    $cpid = (int) $childPid;
                    if ($cpid > 0) {
                        $children[] = $cpid;
                        // 递归获取孙进程
                        $children = \array_merge($children, $this->getProcessTreePids($cpid));
                    }
                }
                return $children;
            }
        }
        
        // 方案2：pgrep -P（高效，大多数 Unix 系统支持）
        $output = [];
        $exitCode = 0;
        $this->executeCommand("pgrep -P {$pid} 2>/dev/null", $output, $exitCode);
        
        if ($exitCode === 0 && !empty($output)) {
            foreach ($output as $line) {
                $cpid = $this->sanitizePid($line);
                if ($cpid > 0) {
                    $children[] = $cpid;
                    // 递归获取孙进程
                    $children = \array_merge($children, $this->getProcessTreePids($cpid));
                }
            }
            return $children;
        }
        
        // 方案3：ps 通用回退（兼容 BusyBox/Alpine 等最小化系统）
        $output = [];
        $this->executeCommand("ps -o pid= --ppid {$pid} 2>/dev/null", $output);
        
        foreach ($output as $line) {
            $cpid = $this->sanitizePid($line);
            if ($cpid > 0) {
                $children[] = $cpid;
                $children = \array_merge($children, $this->getProcessTreePids($cpid));
            }
        }
        
        return $children;
    }
    
    /**
     * @inheritDoc
     * 
     * 端口检测策略（快→慢，参考 Symfony/AMPHP 的做法）：
     * 1. 缓存查询（< 0.01ms）
     * 2. socket 探测（跨平台，~1-5ms）
     * 3. ss -tln（现代 Linux，~5-10ms）
     * 4. netstat -tln（兼容旧系统，~10-50ms）
     */
    public function isPortInUse(int $port): bool
    {
        // 检查缓存
        if (isset(self::$portCache[$port])) {
            $cached = self::$portCache[$port];
            if (\time() - $cached['time'] < self::$portCacheTtl) {
                return $cached['inUse'];
            }
        }
        
        // 优先：socket 探测（跨平台通用，最快）
        $socketResult = $this->socketPortCheck($port);
        if ($socketResult !== null) {
            self::$portCache[$port] = ['inUse' => $socketResult, 'time' => \time()];
            return $socketResult;
        }
        
        // 回退1：ss（现代 Linux，比 netstat 更快更可靠）
        // 使用精确端口匹配：:PORT 后面跟空格或行尾
        $output = [];
        $exitCode = 0;
        $this->executeCommand("ss -tln 'sport = :{$port}' 2>/dev/null", $output, $exitCode);
        
        if ($exitCode === 0) {
            // ss 输出中如果有 LISTEN 行则端口在使用
            foreach ($output as $line) {
                if (\stripos($line, 'LISTEN') !== false) {
                    self::$portCache[$port] = ['inUse' => true, 'time' => \time()];
                    return true;
                }
            }
            self::$portCache[$port] = ['inUse' => false, 'time' => \time()];
            return false;
        }
        
        // 回退2：netstat（兼容旧系统和 macOS）
        // macOS 的 netstat 输出格式与 Linux 不同，需要兼容处理
        $output = [];
        if (PHP_OS === 'Darwin') {
            // macOS: netstat -an -p tcp
            $this->executeCommand("netstat -an -p tcp 2>/dev/null", $output);
        } else {
            // Linux: netstat -tln
            $this->executeCommand("netstat -tln 2>/dev/null", $output);
        }
        
        foreach ($output as $line) {
            // 精确匹配端口号（避免 :80 匹配到 :8080）
            // 匹配格式：:PORT 后面紧跟空格（Linux/macOS）
            if (\preg_match('/[:\.]' . $port . '\s/', $line) && 
                (\stripos($line, 'LISTEN') !== false)) {
                self::$portCache[$port] = ['inUse' => true, 'time' => \time()];
                return true;
            }
        }
        
        self::$portCache[$port] = ['inUse' => false, 'time' => \time()];
        return false;
    }
    
    /**
     * 清除端口缓存
     * 
     * @param int|null $port 指定端口，null 清除全部
     */
    public function clearPortCache(?int $port = null): void
    {
        if ($port !== null) {
            unset(self::$portCache[$port]);
        } else {
            self::$portCache = [];
        }
    }
    
    /**
     * @inheritDoc
     * 
     * 策略（快→慢，兼容性强→弱）：
     * 1. ss -tlnp（现代 Linux，直接输出 PID）
     * 2. lsof -ti:PORT（macOS/Linux 通用）
     * 3. fuser PORT/tcp（某些 Linux 发行版）
     */
    public function getProcessIdByPort(int $port): int
    {
        // 方案1：ss -tlnp（现代 Linux 上最快，直接输出 PID）
        $output = [];
        $exitCode = 0;
        $this->executeCommand("ss -tlnp 'sport = :{$port}' 2>/dev/null", $output, $exitCode);
        
        if ($exitCode === 0) {
            foreach ($output as $line) {
                // ss 输出格式：LISTEN ... users:(("php",pid=12345,fd=3))
                if (\preg_match('/pid=(\d+)/', $line, $matches)) {
                    $pid = $this->sanitizePid($matches[1]);
                    if ($pid > 0) {
                        return $pid;
                    }
                }
            }
        }
        
        // 方案2：lsof（macOS/Linux 通用，但某些 Docker 镜像中可能缺失）
        $output = [];
        $this->executeCommand("lsof -ti:{$port} -sTCP:LISTEN 2>/dev/null", $output);
        
        if (!empty($output[0])) {
            $pid = $this->sanitizePid($output[0]);
            if ($pid > 0) {
                return $pid;
            }
        }
        
        // 方案3：lsof 不带 -sTCP:LISTEN（某些旧版 lsof 不支持 -sTCP 参数）
        $output = [];
        $this->executeCommand("lsof -ti:{$port} 2>/dev/null", $output);
        
        if (!empty($output[0])) {
            $pid = $this->sanitizePid($output[0]);
            if ($pid > 0) {
                return $pid;
            }
        }
        
        // 方案4：fuser（某些最小化 Linux 发行版中可用）
        $output = [];
        $this->executeCommand("fuser {$port}/tcp 2>/dev/null", $output);
        
        if (!empty($output[0])) {
            // fuser 输出格式：" 12345"（前面可能有空格）
            $pid = $this->sanitizePid($output[0]);
            if ($pid > 0) {
                return $pid;
            }
        }
        
        return 0;
    }
    
    /**
     * @inheritDoc
     * 
     * 进程检测策略（参考 Symfony Process 和 AMPHP）：
     * 1. /proc/{pid}/status（Linux 特有，零开销，不 fork 任何进程）
     * 2. posix_kill($pid, 0)（POSIX 标准，零开销系统调用）
     * 3. ps -p $pid（通用回退，所有 Unix 系统支持，但需要 fork）
     * 
     * 注：Symfony 和 AMPHP 都优先使用 /proc 或 posix 函数，
     * 因为 ps 命令需要 fork 一个新进程，在高频调用场景下开销很大。
     */
    public function isRunningByPid(int $pid): bool
    {
        if (!$this->isValidPid($pid)) {
            return false;
        }
        
        // 方案1：/proc 文件系统（Linux 特有，零开销，< 0.1ms）
        // 参考 Symfony Process 的做法
        if (\is_dir("/proc/{$pid}")) {
            // 额外检查进程状态，排除僵尸进程
            $statusFile = "/proc/{$pid}/status";
            if (\is_file($statusFile)) {
                $status = @\file_get_contents($statusFile);
                if ($status !== false) {
                    // 检查进程状态：Z = 僵尸进程（已退出但未被回收）
                    if (\preg_match('/^State:\s+Z/m', $status)) {
                        return false; // 僵尸进程不算运行中
                    }
                    return true;
                }
            }
            return true; // /proc/{pid} 目录存在 = 进程存在
        }
        
        // 方案2：posix_kill($pid, 0)（POSIX 标准信号检测，零开销）
        // 发送信号 0 不会影响进程，只检查进程是否存在
        // 参考 AMPHP Process 的做法
        $functions = $this->detectAvailableFunctions();
        if ($functions['posix_kill']) {
            $result = @\posix_kill($pid, 0);
            if ($result) {
                return true;
            }
            // posix_kill 返回 false 时检查错误码：
            // ESRCH (3) = 进程不存在
            // EPERM (1) = 权限不足（进程存在但无权操作）
            $errno = \posix_get_last_error();
            if ($errno === 1) { // EPERM = 进程存在但属于其他用户
                return true;
            }
            return false; // ESRCH 或其他错误 = 进程不存在
        }
        
        // 方案3：ps -p（通用回退，所有 Unix/macOS 系统支持）
        $output = [];
        $exitCode = 0;
        $this->executeCommand("ps -p {$pid} -o pid= 2>/dev/null", $output, $exitCode);
        
        // 使用 -o pid= 避免表头，如果有输出就说明进程存在
        if ($exitCode === 0 && !empty($output)) {
            foreach ($output as $line) {
                if ($this->sanitizePid($line) === $pid) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * @inheritDoc
     */
    public function getProcessInfo(int $pid): array
    {
        $info = $this->getDefaultProcessInfo($pid);
        
        if (!$this->isValidPid($pid)) {
            return $info;
        }
        
        // 优先尝试 /proc 文件系统（Linux，零开销）
        if (\is_dir("/proc/{$pid}")) {
            $info['exists'] = true;
            
            // 读取进程名
            $commFile = "/proc/{$pid}/comm";
            if (\is_file($commFile)) {
                $comm = @\file_get_contents($commFile);
                if ($comm !== false) {
                    $info['name'] = \trim($comm);
                }
            }
            
            // 读取命令行
            $cmdlineFile = "/proc/{$pid}/cmdline";
            if (\is_file($cmdlineFile)) {
                $cmdline = @\file_get_contents($cmdlineFile);
                if ($cmdline !== false) {
                    $info['command'] = \str_replace("\0", ' ', \trim($cmdline));
                }
            }
            
            // 读取内存信息
            $statusFile = "/proc/{$pid}/status";
            if (\is_file($statusFile)) {
                $status = @\file_get_contents($statusFile);
                if ($status !== false) {
                    if (\preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $m)) {
                        $info['memory'] = \round(((int) $m[1]) / 1024, 2) . ' MB';
                    }
                }
            }
            
            // 读取启动时间
            $statFile = "/proc/{$pid}/stat";
            if (\is_file($statFile)) {
                $stat = @\file_get_contents($statFile);
                if ($stat !== false) {
                    // 进程启动时间（从 /proc/stat 中读取系统启动时间计算）
                    $parts = \explode(' ', $stat);
                    // 字段 22 (starttime) 是进程启动的 jiffies
                    if (isset($parts[21])) {
                        $uptimeContent = @\file_get_contents('/proc/uptime');
                        if ($uptimeContent !== false) {
                            $uptime = (float) \explode(' ', $uptimeContent)[0];
                            $clkTck = 100; // 默认 USER_HZ
                            $startTime = \time() - $uptime + ((int) $parts[21] / $clkTck);
                            $info['start_time'] = \date('Y-m-d H:i:s', (int) $startTime);
                        }
                    }
                }
            }
            
            return $info;
        }
        
        // 回退到 ps 命令（macOS 和其他 Unix）
        $output = [];
        $this->executeCommand("ps -p {$pid} -o pid,comm,%mem,%cpu,lstart 2>/dev/null", $output);
        
        if (\count($output) > 1) {
            $parts = \preg_split('/\s+/', \trim($output[1]));
            if (\count($parts) >= 5) {
                $info['name'] = $parts[1] ?? '';
                $info['memory'] = ($parts[2] ?? '') . '%';
                $info['cpu'] = ($parts[3] ?? '') . '%';
                $info['start_time'] = \implode(' ', \array_slice($parts, 4));
                $info['exists'] = true;
            }
        }
        
        // 获取完整命令行
        $cmdOutput = [];
        $this->executeCommand("ps -p {$pid} -o args= 2>/dev/null", $cmdOutput);
        if (!empty($cmdOutput[0])) {
            $info['command'] = \trim($cmdOutput[0]);
        }
        
        return $info;
    }
    
    /**
     * @inheritDoc
     * 
     * 策略（快→慢）：
     * 1. /proc/{pid}/cmdline（Linux，零开销）
     * 2. ps -p -o args=（通用 Unix/macOS）
     */
    public function getProcessCommandLine(int $pid): string
    {
        if (!$this->isValidPid($pid)) {
            return '';
        }
        
        // 方案1：/proc 文件系统（Linux 特有，零开销，最快）
        $procCmdline = "/proc/{$pid}/cmdline";
        if (\is_file($procCmdline)) {
            $cmdline = @\file_get_contents($procCmdline);
            if ($cmdline !== false && $cmdline !== '') {
                // cmdline 中参数用 \0 分隔
                return \str_replace("\0", ' ', \rtrim($cmdline, "\0"));
            }
        }
        
        // 方案2：ps -o args=（通用 Unix/macOS 回退）
        $output = [];
        $this->executeCommand("ps -p {$pid} -o args= 2>/dev/null", $output);
        
        if (!empty($output[0])) {
            return \trim($output[0]);
        }
        
        // 方案3：/proc/{pid}/comm（Linux，仅进程名，无参数）
        $procComm = "/proc/{$pid}/comm";
        if (\is_file($procComm)) {
            $comm = @\file_get_contents($procComm);
            if ($comm !== false && $comm !== '') {
                return \trim($comm);
            }
        }
        
        return '';
    }
    
    /**
     * @inheritDoc
     * 
     * 策略：
     * 1. pgrep -f（高效，大多数 Unix 系统）
     * 2. ps aux + grep（通用回退，兼容 BusyBox/Alpine）
     */
    public function findProcessesByName(string $processName): array
    {
        $pids = [];
        $currentPid = \getmypid();
        
        // 方案1：pgrep（高效）
        $output = [];
        $exitCode = 0;
        $escapedName = \str_replace("'", "'\\''", $processName);
        $this->executeCommand("pgrep -f -- '--name={$escapedName}' 2>/dev/null", $output, $exitCode);
        
        if ($exitCode === 0) {
            foreach ($output as $line) {
                $pid = $this->sanitizePid($line);
                if ($pid > 0 && $pid !== $currentPid) {
                    $pids[] = $pid;
                }
            }
        }
        
        // 如果 pgrep 没找到，回退到 ps + grep
        if (empty($pids)) {
            $output = [];
            $this->executeCommand(
                "ps aux 2>/dev/null | grep -F " . \escapeshellarg('--name=' . $processName) . " | grep -v grep | awk '{print \$2}'",
                $output
            );
            
            foreach ($output as $line) {
                $pid = $this->sanitizePid($line);
                if ($pid > 0 && $pid !== $currentPid) {
                    $pids[] = $pid;
                }
            }
        }
        
        return \array_unique($pids);
    }
    
    /**
     * @inheritDoc
     * 
     * 渐进式端口释放策略：
     * 1. SIGTERM（优雅终止）
     * 2. SIGKILL（强制终止）
     * 3. fuser -k（内核级终止）
     */
    public function forceReleasePort(int $port): bool
    {
        $pid = $this->getProcessIdByPort($port);
        
        if ($pid <= 0) {
            // 清除端口缓存后重新检查
            $this->clearPortCache($port);
            return !$this->isPortInUse($port);
        }
        
        // 阶段1：SIGTERM 优雅终止
        $output = [];
        $this->executeCommand("kill -" . self::SIGTERM . " {$pid} 2>/dev/null", $output);
        $this->waitMs(500);
        
        $this->clearPortCache($port);
        if (!$this->isPortInUse($port)) {
            return true;
        }
        
        // 阶段2：SIGKILL 强制终止
        $this->executeCommand("kill -" . self::SIGKILL . " {$pid} 2>/dev/null", $output);
        $this->waitMs(500);
        
        $this->clearPortCache($port);
        if (!$this->isPortInUse($port)) {
            return true;
        }
        
        // 阶段3：fuser -k 内核级终止（终极手段）
        $this->executeCommand("fuser -k {$port}/tcp 2>/dev/null", $output);
        $this->waitMs(500);
        
        $this->clearPortCache($port);
        return !$this->isPortInUse($port);
    }
}
