<?php
declare(strict_types=1);

namespace Weline\Framework\System\Process\Driver;

/**
 * Windows 进程驱动
 * 
 * 实现 Windows 平台特定的进程操作。
 * 
 * 参考国际顶级 PHP 进程管理库的 Windows 兼容性最佳实践：
 * 
 * 1. Symfony Process：
 *    - Windows 上使用临时文件替代管道（PHP bug #51800 - 管道阻塞）
 *    - 使用 bypass_shell 选项避免 cmd.exe 中间层
 *    - 处理 Windows 上 proc_get_status 返回的是 cmd 的 PID 而非实际进程 PID
 * 
 * 2. AMPHP Process：
 *    - 使用编译好的 Windows process wrapper 将管道转为 socket
 *    - 通过 wrapper 传递实际进程 PID
 * 
 * 工具优先级（现代 Windows 10/11/Server 2016+）：
 * - 进程信息：PowerShell CIM（Get-CimInstance） > wmic（已废弃）> tasklist
 * - 进程检测：tasklist /FI "PID eq"（最快且可靠）> PowerShell
 * - 进程搜索：PowerShell CIM + CommandLine > wmic（已废弃）
 * - 端口检测：socket 探测 > netstat -ano > PowerShell Get-NetTCPConnection
 * - 进程终止：taskkill /F > PowerShell Stop-Process > wmic terminate
 * 
 * 注：wmic 自 Windows 10 版本 21H1 起已被废弃，
 * 应优先使用 PowerShell CIM（Get-CimInstance）替代。
 */
class WindowsProcessDriver extends AbstractProcessDriver
{
    /**
     * 端口状态缓存 [port => ['inUse' => bool, 'time' => int]]
     */
    private static array $portCache = [];
    
    /**
     * 端口缓存过期时间（秒）
     */
    private static int $portCacheTtl = 10;
    
    /**
     * wmic 是否可用（缓存检测结果，避免每次都检查）
     */
    private static ?bool $wmicAvailable = null;
    
    /**
     * PowerShell 是否可用（缓存检测结果）
     */
    private static ?bool $powershellAvailable = null;
    
    /**
     * @inheritDoc
     */
    public function getOsName(): string
    {
        return 'Windows';
    }
    
    /**
     * @inheritDoc
     */
    public function supports(): bool
    {
        return \strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN';
    }
    
    /**
     * 检测 PowerShell 是否可用（结果缓存）
     */
    private function isPowerShellAvailable(): bool
    {
        if (self::$powershellAvailable !== null) {
            return self::$powershellAvailable;
        }
        
        $functions = $this->detectAvailableFunctions();
        if (!$functions['exec']) {
            self::$powershellAvailable = false;
            return false;
        }
        
        $output = [];
        $exitCode = 0;
        @\exec('powershell -NoProfile -Command "echo ok" 2>NUL', $output, $exitCode);
        self::$powershellAvailable = ($exitCode === 0 && !empty($output) && \trim($output[0]) === 'ok');
        
        return self::$powershellAvailable;
    }
    
    /**
     * 检测 wmic 是否可用（结果缓存）
     * 
     * wmic 自 Windows 10 21H1 起已废弃，Windows 11 某些版本可能缺失
     */
    private function isWmicAvailable(): bool
    {
        if (self::$wmicAvailable !== null) {
            return self::$wmicAvailable;
        }
        
        $functions = $this->detectAvailableFunctions();
        if (!$functions['exec']) {
            self::$wmicAvailable = false;
            return false;
        }
        
        $output = [];
        $exitCode = 0;
        @\exec('wmic os get caption 2>NUL', $output, $exitCode);
        self::$wmicAvailable = ($exitCode === 0 && !empty($output));
        
        return self::$wmicAvailable;
    }
    
    /**
     * @inheritDoc
     * 
     * 策略（快→慢）：
     * 1. 如果有端口信息，直接用 netstat 查找（精确）
     * 2. PowerShell CIM 按命令行搜索（现代 Windows，快速）
     * 3. wmic 按命令行搜索（兼容旧 Windows）
     * 4. tasklist /V 全表扫描（最后手段，最慢）
     */
    public function findPhpProcessPid(string $pname): int
    {
        // 策略1：如果传入的是包含端口的标识（如 localhost:9980），使用 netstat
        if (\preg_match('/localhost:(\d+)/i', $pname, $m)) {
            $port = (int) $m[1];
            $pid = $this->getProcessIdByPort($port);
            if ($pid > 0) {
                // 验证是 PHP 进程
                $info = $this->getProcessInfo($pid);
                if (\stripos($info['name'] ?? '', 'php') !== false) {
                    return $pid;
                }
            }
        }
        
        // 策略2：PowerShell CIM 按命令行搜索（优先，wmic 已废弃）
        // 使用 Where-Object -like 替代 WQL LIKE，避免复杂的引号嵌套问题
        if ($this->isPowerShellAvailable()) {
            $escapedName = \str_replace("'", "''", $pname);
            $psCmd = 'powershell -NoProfile -Command "Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -like \'*' . $escapedName . '*\' } | Select-Object -First 1 -ExpandProperty ProcessId" 2>NUL';
            $output = [];
            $exitCode = 0;
            $this->executeCommand($psCmd, $output, $exitCode);
            
            if ($exitCode === 0) {
                foreach ($output as $line) {
                    $pid = $this->sanitizePid($line);
                    if ($pid > 0) {
                        return $pid;
                    }
                }
            }
        }
        
        // 策略3：wmic（兼容旧系统，wmic 可能不可用）
        if ($this->isWmicAvailable()) {
            $escapedName = \str_replace('"', '""', $pname);
            $wmicPattern = '%' . $escapedName . '%';
            $output = [];
            $this->executeCommand(
                'wmic process where "CommandLine like \'' . $wmicPattern . '\'" get ProcessId 2>NUL',
                $output
            );
            
            foreach ($output as $line) {
                $pid = $this->sanitizePid($line);
                if ($pid > 0) {
                    return $pid;
                }
            }
        }
        
        // 策略4：tasklist 全表扫描（最后手段，最慢但最通用）
        // 先搜索 weline- 前缀进程
        $output = [];
        $this->executeCommand('tasklist /V /FO CSV 2>NUL | findstr /I "weline-"', $output);
        foreach ($output as $line) {
            if (\stripos($line, $pname) !== false) {
                $parts = \str_getcsv($line, ',', '"', '');
                if (\count($parts) > 1) {
                    $pid = $this->sanitizePid($parts[1]);
                    if ($pid > 0) {
                        return $pid;
                    }
                }
            }
        }
        
        // 再搜索 php 进程
        $output = [];
        $this->executeCommand('tasklist /V /FO CSV 2>NUL | findstr /I "php"', $output);
        foreach ($output as $line) {
            if (\stripos($line, $pname) !== false) {
                $parts = \str_getcsv($line, ',', '"', '');
                if (\count($parts) > 1) {
                    $pid = $this->sanitizePid($parts[1]);
                    if ($pid > 0) {
                        return $pid;
                    }
                }
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
        
        if (!$functions['exec']) {
            return 0;
        }
        
        // 优先使用 PowerShell Start-Process -PassThru 获取 PID
        if ($this->isPowerShellAvailable()) {
            $escapedDocRoot = \str_replace("'", "''", $docRoot);
            $psCmd = "powershell -NoProfile -Command \"(\$p = Start-Process -FilePath 'php' -ArgumentList '-S','localhost:{$port}','-t','{$escapedDocRoot}' -WindowStyle Hidden -PassThru).Id\"";
            $out = [];
            $code = 0;
            $this->executeCommand($psCmd, $out, $code);
            if ($code === 0 && !empty($out[0])) {
                $pid = $this->sanitizePid($out[0]);
                if ($pid > 0) {
                    return $pid;
                }
            }
        }
        
        // 回退：使用 start /B 并轮询 netstat 查找 pid
        $uniqueLog = \str_replace('.log', '_' . $port . '.log', $logFile);
        $logDir = \dirname($uniqueLog);
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }
        $cmd = \sprintf('start /B "" php -S localhost:%d -t "%s" > "%s" 2>&1', $port, $docRoot, $uniqueLog);
        
        if ($functions['popen']) {
            @\pclose(@\popen($cmd, 'r'));
        } else {
            @\exec($cmd);
        }
        
        // 轮询查找（最多等待 5 秒）
        for ($i = 0; $i < 50; $i++) {
            $this->waitMs(100);
            $pid = $this->getProcessIdByPort($port);
            if ($pid > 0) {
                return $pid;
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
        
        if (!$functions['exec']) {
            return 0;
        }
        
        // 使用 PowerShell Start-Process -PassThru 获取 PID
        if ($this->isPowerShellAvailable()) {
            // PowerShell 单引号中用 '' 转义单引号
            $escapedCommand = \str_replace("'", "''", $command);
            $escapedLog = \str_replace("'", "''", $logFile);
            $psCmd = "powershell -NoProfile -Command \"(\$p = Start-Process -FilePath 'cmd' -ArgumentList '/c','{$escapedCommand}' -WindowStyle Hidden -PassThru -RedirectStandardOutput '{$escapedLog}').Id\"";
            $out = [];
            $code = 0;
            $this->executeCommand($psCmd, $out, $code);
            if ($code === 0 && !empty($out[0])) {
                $pid = $this->sanitizePid($out[0]);
                if ($pid > 0) {
                    return $pid;
                }
            }
        }
        
        // 回退：使用 start（通用）
        $escapedBP = \str_replace('"', '', BP);
        if ($block) {
            $cmd = 'start /B /D "' . $escapedBP . '" ' . $command . ' > "' . $logFile . '" 2>&1';
        } else {
            $cmd = 'start /min /D "' . $escapedBP . '" ' . $command . ' > "' . $logFile . '" 2>&1';
        }
        
        if ($functions['popen']) {
            @\pclose(@\popen($cmd, 'r'));
        } else {
            @\exec($cmd);
        }
        
        return 0;
    }
    
    /**
     * @inheritDoc
     * 
     * 策略：
     * 1. taskkill /F /PID（最快最可靠）
     * 2. PowerShell Stop-Process -Force（回退）
     * 3. wmic terminate（兼容旧系统）
     */
    public function killProcess(int $pid): bool
    {
        if (!$this->isValidPid($pid)) {
            return false;
        }
        
        // 方案1：taskkill（最快最通用）
        $output = [];
        $exitCode = 0;
        $this->executeCommand("taskkill /F /PID {$pid} 2>NUL", $output, $exitCode);
        
        if ($exitCode === 0) {
            return true;
        }
        
        // 方案2：PowerShell Stop-Process（taskkill 失败时回退）
        if ($this->isPowerShellAvailable()) {
            $output = [];
            $exitCode = 0;
            $this->executeCommand(
                "powershell -NoProfile -Command \"Stop-Process -Id {$pid} -Force -ErrorAction SilentlyContinue\" 2>NUL",
                $output,
                $exitCode
            );
            if ($exitCode === 0 || !$this->isRunningByPid($pid)) {
                return true;
            }
        }
        
        // 方案3：wmic terminate（兼容旧系统）
        if ($this->isWmicAvailable()) {
            $output = [];
            $this->executeCommand("wmic process where ProcessId={$pid} call terminate 2>NUL", $output);
            $this->waitMs(200);
            return !$this->isRunningByPid($pid);
        }
        
        return false;
    }
    
    /**
     * @inheritDoc
     * 
     * 策略：
     * 1. taskkill /F /T /PID（杀进程树，最快）
     * 2. PowerShell 递归杀子进程
     * 3. wmic terminate（兼容旧系统）
     */
    public function killProcessTree(int $pid): bool
    {
        if (!$this->isValidPid($pid)) {
            return false;
        }
        
        // 方案1：taskkill /T（杀进程树，最快最通用）
        $output = [];
        $exitCode = 0;
        $this->executeCommand("taskkill /F /T /PID {$pid} 2>NUL", $output, $exitCode);
        
        if ($exitCode === 0) {
            return true;
        }
        
        // 方案2：PowerShell 递归查找并杀死子进程
        // 使用字符串拼接替代 heredoc，避免不同 PHP 版本/行尾符下的解析问题
        if ($this->isPowerShellAvailable()) {
            // 先杀子进程，再杀父进程
            // 注意引号规则（参见 windows-command-quoting 技能）：
            // - 外层 " 由 cmd.exe 剥离后传给 PowerShell
            // - 内部 $ppid / $_ 是 PowerShell 变量，PHP 单引号串中不会被 PHP 解析
            // - Filter 用 ('ParentProcessId=' + $ppid) 避免嵌套双引号
            $psCmd = 'powershell -NoProfile -Command "'
                . 'function Kill-Tree($ppid) { '
                . 'Get-CimInstance Win32_Process -Filter (\'ParentProcessId=\' + $ppid) -ErrorAction SilentlyContinue | ForEach-Object { '
                . 'Kill-Tree $_.ProcessId; '
                . 'Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue '
                . '} }; '
                . 'Kill-Tree ' . $pid . '; '
                . 'Stop-Process -Id ' . $pid . ' -Force -ErrorAction SilentlyContinue'
                . '" 2>NUL';
            $output = [];
            $exitCode = 0;
            $this->executeCommand($psCmd, $output, $exitCode);
            
            $this->waitMs(200);
            if (!$this->isRunningByPid($pid)) {
                return true;
            }
        }
        
        // 方案3：wmic terminate（兼容旧系统）
        if ($this->isWmicAvailable()) {
            $output = [];
            $this->executeCommand("wmic process where ProcessId={$pid} call terminate 2>NUL", $output);
            $this->waitMs(200);
            return !$this->isRunningByPid($pid);
        }
        
        return !$this->isRunningByPid($pid);
    }
    
    /**
     * @inheritDoc
     */
    public function sendSignal(int $pid, int $signal): bool
    {
        if (!$this->isValidPid($pid)) {
            return false;
        }
        // Windows 不支持 POSIX 信号语义：统一退化为强制终止
        return $this->killProcess($pid);
    }
    
    /**
     * @inheritDoc
     * 
     * 端口检测策略（快→慢）：
     * 1. 缓存（< 0.01ms）
     * 2. socket 探测（跨平台，~1-5ms）
     * 3. netstat -ano（通用，~50-200ms）
     * 4. PowerShell Get-NetTCPConnection（现代 Windows 回退）
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
        
        // 回退1：netstat（通用，所有 Windows 版本支持）
        $output = [];
        $this->executeCommand("netstat -ano 2>NUL", $output);
        
        foreach ($output as $line) {
            if (\strpos($line, 'LISTENING') !== false) {
                // 精确匹配端口：:PORT 后面紧跟空格
                if (\preg_match('/[:\.]' . $port . '\s/', $line)) {
                    self::$portCache[$port] = ['inUse' => true, 'time' => \time()];
                    return true;
                }
            }
        }
        
        // 回退2：PowerShell Get-NetTCPConnection（Windows 8+/Server 2012+）
        // 当 netstat 输出为空或异常时使用
        if (empty($output) && $this->isPowerShellAvailable()) {
            $psOutput = [];
            $exitCode = 0;
            $this->executeCommand(
                "powershell -NoProfile -Command \"Get-NetTCPConnection -LocalPort {$port} -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1 OwningProcess\" 2>NUL",
                $psOutput,
                $exitCode
            );
            
            if ($exitCode === 0) {
                foreach ($psOutput as $line) {
                    $pid = $this->sanitizePid($line);
                    if ($pid > 0) {
                        self::$portCache[$port] = ['inUse' => true, 'time' => \time()];
                        return true;
                    }
                }
            }
        }
        
        self::$portCache[$port] = ['inUse' => false, 'time' => \time()];
        return false;
    }
    
    /**
     * 清除端口缓存
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
     * 策略（快→慢）：
     * 1. netstat -ano + LISTENING 精确匹配
     * 2. PowerShell Get-NetTCPConnection（现代 Windows）
     */
    public function getProcessIdByPort(int $port): int
    {
        // 方案1：netstat -ano（最快，所有 Windows 版本支持）
        $output = [];
        $this->executeCommand("netstat -ano 2>NUL", $output);
        
        foreach ($output as $line) {
            if (\strpos($line, 'LISTENING') !== false && \preg_match('/[:\.]' . $port . '\s/', $line)) {
                if (\preg_match('/LISTENING\s+(\d+)/', $line, $matches)) {
                    $pid = $this->sanitizePid($matches[1]);
                    if ($pid > 0) {
                        return $pid;
                    }
                }
            }
        }
        
        // 方案2：PowerShell Get-NetTCPConnection（Windows 8+/Server 2012+）
        if ($this->isPowerShellAvailable()) {
            $output = [];
            $exitCode = 0;
            $this->executeCommand(
                "powershell -NoProfile -Command \"(Get-NetTCPConnection -LocalPort {$port} -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1).OwningProcess\" 2>NUL",
                $output,
                $exitCode
            );
            
            if ($exitCode === 0 && !empty($output[0])) {
                $pid = $this->sanitizePid($output[0]);
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
     * 进程检测策略（参考 Symfony Process）：
     * 1. tasklist /FI "PID eq"（最快且最通用，所有 Windows 版本支持）
     * 2. PowerShell Get-Process（回退，处理 tasklist 本地化问题）
     * 
     * 注意 Windows 的 tasklist 输出可能受系统语言影响：
     * - 英文：INFO: No tasks are running which match the specified criteria.
     * - 中文：信息: 没有运行的任务匹配指定标准。
     * - 其他语言：各有不同
     * 因此不能硬编码错误消息，应通过 PID 存在性来判断。
     */
    public function isRunningByPid(int $pid): bool
    {
        if (!$this->isValidPid($pid)) {
            return false;
        }
        
        // 方案1：tasklist /FI（最快，所有 Windows 版本支持）
        $output = [];
        $exitCode = 0;
        // 使用 /FO CSV 格式输出，便于解析且不受本地化影响
        $this->executeCommand("tasklist /FI \"PID eq {$pid}\" /FO CSV /NH 2>NUL", $output, $exitCode);
        
        if (!empty($output)) {
            foreach ($output as $line) {
                $line = \trim($line);
                if ($line === '') {
                    continue;
                }
                // CSV 格式的第一个字段如果以 "INFO:" 开头（不分语言），说明进程不存在
                // 但有些本地化版本用不同前缀，所以我们解析 CSV 检查 PID 字段
                $parts = \str_getcsv($line, ',', '"', '');
                if (\count($parts) >= 2) {
                    $parsedPid = $this->sanitizePid($parts[1]);
                    if ($parsedPid === $pid) {
                        return true;
                    }
                }
            }
        }
        
        // 方案2：PowerShell Get-Process（处理 tasklist 异常或本地化问题）
        if ($this->isPowerShellAvailable()) {
            $output = [];
            $exitCode = 0;
            $this->executeCommand(
                "powershell -NoProfile -Command \"if(Get-Process -Id {$pid} -ErrorAction SilentlyContinue){echo 1}else{echo 0}\" 2>NUL",
                $output,
                $exitCode
            );
            
            if ($exitCode === 0 && !empty($output[0])) {
                return \trim($output[0]) === '1';
            }
        }
        
        return false;
    }
    
    /**
     * @inheritDoc
     * 
     * 使用 PowerShell CIM 获取进程信息（优先），wmic 作为兼容回退
     */
    public function getProcessInfo(int $pid): array
    {
        $info = $this->getDefaultProcessInfo($pid);
        
        if (!$this->isValidPid($pid)) {
            return $info;
        }
        
        // 方案1：PowerShell CIM（推荐，现代 Windows）
        if ($this->isPowerShellAvailable()) {
            $ps = "powershell -NoProfile -Command \"Get-CimInstance Win32_Process -Filter \\\"ProcessId={$pid}\\\" -ErrorAction SilentlyContinue | Select-Object Name,CommandLine,WorkingSetSize | Format-List\"";
            $out = [];
            $code = 0;
            $this->executeCommand($ps, $out, $code);
            
            if ($code === 0 && !empty($out)) {
                $content = \implode("\n", $out);
                
                if (\preg_match('/Name\s*:\s*(.+)/i', $content, $m)) {
                    $info['name'] = \trim($m[1]);
                    $info['exists'] = true;
                }
                if (\preg_match('/CommandLine\s*:\s*(.+)/i', $content, $m)) {
                    $info['command'] = \trim($m[1]);
                }
                if (\preg_match('/WorkingSetSize\s*:\s*(\d+)/i', $content, $m)) {
                    $info['memory'] = \round(((int) $m[1]) / 1024 / 1024, 2) . ' MB';
                }
                
                if ($info['exists']) {
                    return $info;
                }
            }
        }
        
        // 方案2：tasklist /FI（快速检测进程存在性）
        $output = [];
        $this->executeCommand("tasklist /FI \"PID eq {$pid}\" /FO CSV /NH 2>NUL", $output);
        
        foreach ($output as $line) {
            $parts = \str_getcsv(\trim($line), ',', '"', '');
            if (\count($parts) >= 5) {
                $parsedPid = $this->sanitizePid($parts[1]);
                if ($parsedPid === $pid) {
                    $info['name'] = $parts[0] ?? '';
                    $info['exists'] = true;
                    // tasklist 格式：ImageName, PID, SessionName, Session#, MemUsage
                    $memStr = $parts[4] ?? '';
                    // 移除非数字字符（如逗号、空格、K、KB 等）
                    $memKb = (int) \preg_replace('/[^\d]/', '', $memStr);
                    if ($memKb > 0) {
                        $info['memory'] = \round($memKb / 1024, 2) . ' MB';
                    }
                    break;
                }
            }
        }
        
        return $info;
    }
    
    /**
     * @inheritDoc
     * 
     * 策略（快→慢，现代→兼容）：
     * 1. PowerShell CIM（推荐，wmic 的替代品）
     * 2. wmic（兼容旧系统，已废弃）
     * 3. tasklist /V（最后手段，最慢但最通用）
     */
    public function getProcessCommandLine(int $pid): string
    {
        if (!$this->isValidPid($pid)) {
            return '';
        }
        
        // 方案1：PowerShell CIM（推荐，wmic 的官方替代品）
        if ($this->isPowerShellAvailable()) {
            $out = [];
            $exitCode = 0;
            $this->executeCommand(
                "powershell -NoProfile -Command \"(Get-CimInstance Win32_Process -Filter \\\"ProcessId={$pid}\\\" -ErrorAction SilentlyContinue).CommandLine\" 2>NUL",
                $out,
                $exitCode
            );
            
            if ($exitCode === 0 && !empty($out)) {
                $cmdLine = \trim(\implode(' ', $out));
                if ($cmdLine !== '' && \stripos($cmdLine, 'No Instance') === false) {
                    return $cmdLine;
                }
            }
        }
        
        // 方案2：wmic（兼容旧系统，已废弃但某些环境仍可用）
        if ($this->isWmicAvailable()) {
            $out = [];
            $this->executeCommand("wmic process where ProcessId={$pid} get CommandLine 2>NUL", $out);
            foreach ($out as $line) {
                $line = \trim($line);
                if ($line !== '' && \strtoupper($line) !== 'COMMANDLINE') {
                    return $line;
                }
            }
        }
        
        return '';
    }
    
    /**
     * @inheritDoc
     * 
     * 策略（现代→兼容）：
     * 1. PowerShell CIM + CommandLine 匹配（推荐）
     * 2. wmic + CommandLine（兼容旧系统）
     */
    public function findProcessesByName(string $processName): array
    {
        $pids = [];
        
        // 方案1：PowerShell CIM（推荐，wmic 的替代品）
        if ($this->isPowerShellAvailable()) {
            $pattern = '*--name=' . \str_replace("'", "''", $processName) . '*';
            $psCmd = "powershell -NoProfile -Command \"Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object { \$_.CommandLine -like '{$pattern}' } | Select-Object -ExpandProperty ProcessId\" 2>NUL";
            $output = [];
            $this->executeCommand($psCmd, $output);
            
            foreach ($output as $line) {
                $pid = $this->sanitizePid($line);
                if ($pid > 0) {
                    $pids[] = $pid;
                }
            }
            
            if (!empty($pids)) {
                return $pids;
            }
        }
        
        // 方案2：wmic（兼容旧系统）
        if ($this->isWmicAvailable()) {
            $escapedName = \str_replace('"', '""', $processName);
            $wmicPattern = '%--name=' . $escapedName . '%';
            $cmd = 'wmic process where "CommandLine like \'' . $wmicPattern . '\'" get ProcessId 2>NUL';
            $output = [];
            $this->executeCommand($cmd, $output);
            
            foreach ($output as $line) {
                $pid = $this->sanitizePid($line);
                // 过滤掉 "ProcessId" 表头
                if ($pid > 0 && \strtoupper(\trim($line)) !== 'PROCESSID') {
                    $pids[] = $pid;
                }
            }
        }
        
        return $pids;
    }
    
    /**
     * @inheritDoc
     * 
     * 渐进式端口释放策略：
     * 1. taskkill /F /T（进程树）
     * 2. PowerShell Stop-Process
     * 3. wmic terminate（兼容旧系统）
     */
    public function forceReleasePort(int $port): bool
    {
        $pid = $this->getProcessIdByPort($port);
        
        if ($pid <= 0) {
            $this->clearPortCache($port);
            return !$this->isPortInUse($port);
        }
        
        // 阶段1：taskkill /T（杀死进程树）
        $this->killProcessTree($pid);
        $this->waitMs(500);
        
        $this->clearPortCache($port);
        if (!$this->isPortInUse($port)) {
            return true;
        }
        
        // 阶段2：PowerShell Stop-Process -Force
        if ($this->isPowerShellAvailable()) {
            $output = [];
            $this->executeCommand(
                "powershell -NoProfile -Command \"Stop-Process -Id {$pid} -Force -ErrorAction SilentlyContinue\" 2>NUL",
                $output
            );
            $this->waitMs(500);
            
            $this->clearPortCache($port);
            if (!$this->isPortInUse($port)) {
                return true;
            }
        }
        
        // 阶段3：wmic terminate（兼容旧系统）
        if ($this->isWmicAvailable()) {
            $output = [];
            $this->executeCommand("wmic process where ProcessId={$pid} call terminate 2>NUL", $output);
            $this->waitMs(500);
        }
        
        $this->clearPortCache($port);
        return !$this->isPortInUse($port);
    }
}
