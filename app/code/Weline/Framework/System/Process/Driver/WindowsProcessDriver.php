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
    /** @var array<string, true> */
    private const DIRECT_BYPASS_SHELL_PROGRAMS = [
        'powershell' => true,
        'powershell.exe' => true,
        'wmic' => true,
        'wmic.exe' => true,
        'taskkill' => true,
        'taskkill.exe' => true,
        'tasklist' => true,
        'tasklist.exe' => true,
        'netstat' => true,
        'netstat.exe' => true,
        'findstr' => true,
        'findstr.exe' => true,
    ];

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
     * 进程内 `netstat -ano` LISTENING 全表缓存（port => pid）
     *
     * 背景：`isPortInUse` / `getProcessIdByPort` 在 server:status / server:stop / SharedStateServiceManager
     * 等路径上会被密集调用；过去每次都会重新跑一次 `netstat -ano`（本机实测 ~840ms/次），
     * 一次 status 命令累计十余次 netstat，把 CLI 总耗时拉到 70s+。
     *
     * 这里在驱动层做"进程内全表快照"：第一次调用时跑 1 次 netstat 解析全部 LISTENING 行，
     * 后续短 TTL 内（默认 1.5s）任意端口查询都直接读 map，不再触发系统命令。
     *
     * @var array<int, int>|null
     */
    private static ?array $listeningPortPidMap = null;
    private static float $listeningPortPidMapAt = 0.0;
    private static float $listeningPortPidMapTtl = 1.5;

    /**
     * 进程内 `tasklist /FO CSV /NH` 全表缓存（pid => info）
     *
     * 背景：`isRunningByPid` / `processExists` / `batchGetProcessInfo` 在 status / stop / 自愈检测路径上
     * 同样被反复调用；单次 `tasklist /FO CSV /NH` 本机实测 ~2630ms，单次 PowerShell `Get-Process` ~1040ms。
     * 不缓存就会把任何"扫一遍服务列表"都拖到几十秒。
     *
     * 这里在驱动层做"进程内全表快照"：第一次调用时跑 1 次 tasklist 解析全部行，后续短 TTL 内
     * （默认 1.5s）任意 PID 查询都直接读 map（O(1)），完全跳过系统命令。
     *
     * @var array<int, array{name:string, memory_kb:int}>|null
     */
    private static ?array $taskListPidMap = null;
    private static float $taskListPidMapAt = 0.0;
    private static float $taskListPidMapTtl = 1.5;

    /**
     * 进程内 `Get-CimInstance Win32_Process` 全表缓存（pid => commandLine）
     *
     * 背景：`getProcessCommandLine($pid)` 是 `isWelineServerProcess` / `isProcessManagerCreated`
     * 等"己方进程鉴权"路径的核心调用；单次 PowerShell CIM 按 PID 查询 ~1s，status / stop
     * 反复鉴权时会堆 5-10 次 ≈ 5-10s。
     *
     * 这里也走"全表 + 短 TTL + per-pid 兜底"策略：
     * - fetchAllProcessCommandLines() 一次拉全表（~2-3s）填满 map；
     * - 每个 PID 真正被查询时优先读 map，未命中再跑 per-pid PowerShell 并把结果回写（避免反复单查）。
     * - 这与"为命令行做 per-pid 单查的零碎 PowerShell"相比，最坏一次性，最好直接命中。
     *
     * @var array<int, string>
     */
    private static array $commandLineCache = [];
    private static float $commandLineCacheAt = 0.0;
    private static float $commandLineCacheTtl = 1.5;
    
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
     * Avoid routing core Windows process-management commands through
     * cmd.exe /c. Some desktop sessions fail to initialize cmd.exe and show
     * 0xc0000142 popups before the real process-management logic runs.
     */
    protected function executeCommand(string $command, array &$output = [], int &$exitCode = 0): bool
    {
        if ($this->tryExecuteCommandBypassShell($command, $output, $exitCode)) {
            return true;
        }

        return parent::executeCommand($command, $output, $exitCode);
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
        if (!$functions['proc_open']) {
            self::$powershellAvailable = false;
            return false;
        }
        
        $output = [];
        $exitCode = 0;
        $this->executeCommand('powershell -NoProfile -Command "echo ok" 2>NUL', $output, $exitCode);
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
        if (!$functions['proc_open']) {
            self::$wmicAvailable = false;
            return false;
        }
        
        $output = [];
        $exitCode = 0;
        $this->executeCommand('wmic os get caption 2>NUL', $output, $exitCode);
        self::$wmicAvailable = ($exitCode === 0 && !empty($output));
        
        return self::$wmicAvailable;
    }

    private function tryExecuteCommandBypassShell(string $command, array &$output, int &$exitCode): bool
    {
        $functions = $this->detectAvailableFunctions();
        if (!$functions['proc_open']) {
            return false;
        }

        $prepared = $this->prepareDirectBypassShellCommand($command);
        if ($prepared === null) {
            return false;
        }

        $descriptors = [
            0 => ['file', 'NUL', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $lastError = null;
        \set_error_handler(static function ($type, $msg) use (&$lastError): bool {
            $lastError = $msg;
            return true;
        });
        try {
            $process = @\proc_open(
                $prepared['argv'],
                $descriptors,
                $pipes,
                null,
                null,
                ['bypass_shell' => true]
            );
        } finally {
            \restore_error_handler();
        }

        if (!\is_resource($process)) {
            return false;
        }

        $stdout = '';
        $stderr = '';
        if (isset($pipes[1])) {
            $stdout = (string) (\stream_get_contents($pipes[1]) ?: '');
            @\fclose($pipes[1]);
        }
        if (isset($pipes[2])) {
            $stderr = (string) (\stream_get_contents($pipes[2]) ?: '');
            @\fclose($pipes[2]);
        }
        if (isset($pipes[0])) {
            @\fclose($pipes[0]);
        }

        $exitCode = @\proc_close($process);

        if ($prepared['merge_stderr'] && $stderr !== '') {
            if ($stdout !== '' && !\str_ends_with($stdout, "\n") && !\str_ends_with($stdout, "\r")) {
                $stdout .= PHP_EOL;
            }
            $stdout .= $stderr;
        }

        if ($stdout === '') {
            $output = [];
        } else {
            $output = \preg_split('/\r\n|\r|\n/', \rtrim($stdout, "\r\n")) ?: [];
        }

        if ($exitCode === -1 && $lastError !== null) {
            return false;
        }

        return true;
    }

    /**
     * @return array{argv: list<string>, merge_stderr: bool}|null
     */
    private function prepareDirectBypassShellCommand(string $command): ?array
    {
        $command = \trim($command);
        if ($command === '') {
            return null;
        }

        [$command, $mergeStderr] = $this->stripBypassShellRedirections($command);
        if ($command === '' || $this->containsUnsupportedShellSyntax($command)) {
            return null;
        }

        $argv = $this->tokenizeDirectCommand($command);
        if ($argv === []) {
            return null;
        }

        $program = \strtolower(\basename(\str_replace('\\', '/', (string) ($argv[0] ?? ''))));
        if (!isset(self::DIRECT_BYPASS_SHELL_PROGRAMS[$program])) {
            return null;
        }

        return [
            'argv' => $argv,
            'merge_stderr' => $mergeStderr,
        ];
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function stripBypassShellRedirections(string $command): array
    {
        $mergeStderr = false;
        $patterns = [
            '/\s+2>\s*NUL\b/i',
            '/\s+1>\s*NUL\b/i',
            '/\s+>\s*NUL\b/i',
        ];

        foreach ($patterns as $pattern) {
            $command = \preg_replace($pattern, '', $command) ?? $command;
        }

        $command = \preg_replace_callback(
            '/\s+2>\s*&1\b/i',
            static function () use (&$mergeStderr): string {
                $mergeStderr = true;
                return '';
            },
            $command
        ) ?? $command;

        return [\trim($command), $mergeStderr];
    }

    private function containsUnsupportedShellSyntax(string $command): bool
    {
        $inQuotes = false;
        $length = \strlen($command);
        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];
            if ($char === '"') {
                $inQuotes = !$inQuotes;
                continue;
            }

            if (!$inQuotes && (\in_array($char, ['|', '&', '<', '>'], true))) {
                return true;
            }
        }

        return $inQuotes;
    }

    /**
     * @return list<string>
     */
    private function tokenizeDirectCommand(string $command): array
    {
        $tokens = [];
        $buffer = '';
        $inQuotes = false;
        $length = \strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];
            if ($char === '"') {
                $inQuotes = !$inQuotes;
                continue;
            }

            if (!$inQuotes && \ctype_space($char)) {
                if ($buffer !== '') {
                    $tokens[] = $buffer;
                    $buffer = '';
                }
                continue;
            }

            $buffer .= $char;
        }

        if ($inQuotes) {
            return [];
        }

        if ($buffer !== '') {
            $tokens[] = $buffer;
        }

        return $tokens;
    }

    private function toPowerShellSingleQuoted(string $value): string
    {
        return "'" . \str_replace("'", "''", $value) . "'";
    }

    /**
     * @param list<string> $values
     */
    private function buildPowerShellArrayLiteral(array $values): string
    {
        if ($values === []) {
            return '@()';
        }

        return '@(' . \implode(',', \array_map(
            fn (string $value): string => $this->toPowerShellSingleQuoted($value),
            $values
        )) . ')';
    }

    /**
     * @param list<string> $arguments
     */
    private function buildPowerShellStartProcessPidCommand(
        string $filePath,
        array $arguments,
        string $workingDirectory = '',
        string $windowStyle = 'Hidden',
        string $stdoutLog = '',
        string $stderrLog = ''
    ): string {
        $script = [
            '$ErrorActionPreference = ' . $this->toPowerShellSingleQuoted('Stop'),
            '$startArgs = @{}',
            '$startArgs.FilePath = ' . $this->toPowerShellSingleQuoted($filePath),
            '$startArgs.WindowStyle = ' . $this->toPowerShellSingleQuoted($windowStyle),
            '$startArgs.PassThru = $true',
            '$startArgs.ErrorAction = ' . $this->toPowerShellSingleQuoted('Stop'),
            '$argList = ' . $this->buildPowerShellArrayLiteral($arguments),
            'if ($argList.Count -gt 0) { $startArgs.ArgumentList = $argList }',
        ];

        if ($workingDirectory !== '') {
            $script[] = '$startArgs.WorkingDirectory = ' . $this->toPowerShellSingleQuoted($workingDirectory);
        }
        if ($stdoutLog !== '') {
            $script[] = '$startArgs.RedirectStandardOutput = ' . $this->toPowerShellSingleQuoted($stdoutLog);
        }
        if ($stderrLog !== '') {
            $script[] = '$startArgs.RedirectStandardError = ' . $this->toPowerShellSingleQuoted($stderrLog);
        }

        $script[] = '$p = Start-Process @startArgs';
        $script[] = '[Console]::Out.WriteLine([string]$p.Id)';

        return 'powershell -NoProfile -Command "' . \implode('; ', $script) . '" 2>NUL';
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
            $psCmd = 'powershell -NoProfile -Command "Get-CimInstance Win32_Process | Where-Object { $_.Name -match \'^php(?:-cgi)?\.exe$\' -and $_.CommandLine -like \'*' . $escapedName . '*\' } | Select-Object -First 1 -ExpandProperty ProcessId" 2>NUL';
            $output = [];
            $exitCode = 0;
            $this->executeCommand($psCmd, $output, $exitCode);
            
            if ($exitCode === 0) {
                foreach ($output as $line) {
                    $pid = $this->sanitizePid($line);
                    if ($pid > 0 && $this->isPhpProcessPid($pid)) {
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
                if ($pid > 0 && $this->isPhpProcessPid($pid)) {
                    return $pid;
                }
            }
        }
        
        // 策略4：tasklist 全表扫描（最后手段，最慢但最通用）
        // 先搜索 weline- 前缀进程
        $output = [];
        $this->executeCommand('tasklist /V /FO CSV 2>NUL', $output);
        foreach ($output as $line) {
            if (\stripos($line, $pname) !== false) {
                $parts = \str_getcsv($line, ',', '"', '');
                if (\count($parts) > 1 && $this->isPhpProcessName((string) ($parts[0] ?? ''))) {
                    $pid = $this->sanitizePid($parts[1]);
                    if ($pid > 0) {
                        return $pid;
                    }
                }
            }
        }
        
        // 再搜索 php 进程
        foreach ($output as $line) {
            if (\stripos($line, 'php') !== false && \stripos($line, $pname) !== false) {
                $parts = \str_getcsv($line, ',', '"', '');
                if (\count($parts) > 1 && $this->isPhpProcessName((string) ($parts[0] ?? ''))) {
                    $pid = $this->sanitizePid($parts[1]);
                    if ($pid > 0) {
                        return $pid;
                    }
                }
            }
        }
        
        return 0;
    }

    private function isPhpProcessPid(int $pid): bool
    {
        if (!$this->isValidPid($pid)) {
            return false;
        }

        $info = $this->getProcessInfo($pid);

        return $this->isPhpProcessName((string) ($info['name'] ?? ''));
    }

    private function isPhpProcessName(string $name): bool
    {
        $name = \strtolower(\trim($name));

        return \in_array($name, ['php', 'php.exe', 'php-cgi.exe'], true);
    }
    
    /**
     * @inheritDoc
     */
    public function startBuiltInServer(string $docRoot, int $port, string $logFile): int
    {
        $uniqueLog = \str_replace('.log', '_' . $port . '.log', $logFile);
        $logDir = \dirname($uniqueLog);
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }

        // 优先使用 PowerShell Start-Process -PassThru 获取 PID
        if ($this->isPowerShellAvailable()) {
            $psCmd = $this->buildPowerShellStartProcessPidCommand(
                PHP_BINARY,
                ['-S', 'localhost:' . $port, '-t', $docRoot],
                '',
                'Hidden',
                $uniqueLog,
                $uniqueLog . '.err'
            );
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
        
        return 0;
    }
    
    /**
     * @inheritDoc
     */
    public function startBackgroundProcess(string $command, string $logFile, bool $block = false): int
    {
        // 使用 PowerShell Start-Process -PassThru 获取 PID
        if ($this->isPowerShellAvailable()) {
            $argv = $this->tokenizeDirectCommand($command);
            if ($argv !== []) {
                $filePath = (string) \array_shift($argv);
                $psCmd = $this->buildPowerShellStartProcessPidCommand(
                    $filePath,
                    \array_values($argv),
                    BP,
                    $block ? 'Hidden' : 'Minimized',
                    $logFile,
                    $logFile !== '' ? $logFile . '.err' : ''
                );
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
     *
     * 重试机制：每个方案最多重试3次，每次间隔100ms
     */
    public function killProcess(int $pid): bool
    {
        if (!$this->isValidPid($pid)) {
            return false;
        }

        $maxRetries = 3;
        $retryDelayMs = 100;

        // 方案1：taskkill（最快最通用）
        for ($retry = 0; $retry < $maxRetries; $retry++) {
            $output = [];
            $exitCode = 0;
            $this->executeCommand("taskkill /F /PID {$pid} 2>NUL", $output, $exitCode);

            if ($exitCode === 0) {
                return true;
            }

            if ($retry < $maxRetries - 1) {
                $this->waitMs($retryDelayMs);
            }
        }

        // 方案2：PowerShell Stop-Process（taskkill 失败时回退）
        if ($this->isPowerShellAvailable()) {
            for ($retry = 0; $retry < $maxRetries; $retry++) {
                $output = [];
                $exitCode = 0;
                $this->executeCommand(
                    "powershell -NoProfile -Command \"Stop-Process -Id {$pid} -Force -ErrorAction SilentlyContinue\" 2>NUL",
                    $output,
                    $exitCode
                );
                if ($exitCode === 0) {
                    return true;
                }

                if ($retry < $maxRetries - 1) {
                    $this->waitMs($retryDelayMs);
                }
            }
        }

        // 方案3：wmic terminate（兼容旧系统）
        if ($this->isWmicAvailable()) {
            for ($retry = 0; $retry < $maxRetries; $retry++) {
                $output = [];
                $exitCode = 0;
                $this->executeCommand("wmic process where ProcessId={$pid} call terminate 2>NUL", $output, $exitCode);
                if ($exitCode === 0) {
                    return true;
                }
                $this->waitMs($retryDelayMs);
            }
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
     *
     * 重试机制：每个方案最多重试3次，每次间隔100ms
     */
    public function killProcessTree(int $pid): bool
    {
        if (!$this->isValidPid($pid)) {
            return false;
        }

        $maxRetries = 3;
        $retryDelayMs = 100;

        // 方案1：taskkill /T（杀进程树，最快最通用）
        for ($retry = 0; $retry < $maxRetries; $retry++) {
            $output = [];
            $exitCode = 0;
            $this->executeCommand("taskkill /F /T /PID {$pid} 2>NUL", $output, $exitCode);

            if ($exitCode === 0) {
                return true;
            }

            if ($retry < $maxRetries - 1) {
                $this->waitMs($retryDelayMs);
            }
        }

        // 方案2：PowerShell 递归查找并杀死子进程
        // 使用字符串拼接替代 heredoc，避免不同 PHP 版本/行尾符下的解析问题
        if ($this->isPowerShellAvailable()) {
            for ($retry = 0; $retry < $maxRetries; $retry++) {
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

                $this->waitMs($retryDelayMs);
                if ($exitCode === 0) {
                    return true;
                }
            }
        }

        // 方案3：wmic terminate（兼容旧系统）
        if ($this->isWmicAvailable()) {
            for ($retry = 0; $retry < $maxRetries; $retry++) {
                $output = [];
                $exitCode = 0;
                $this->executeCommand("wmic process where ProcessId={$pid} call terminate 2>NUL", $output, $exitCode);
                if ($exitCode === 0) {
                    return true;
                }
                $this->waitMs($retryDelayMs);
            }
        }

        return false;
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

        // Windows 上优先基于 LISTENING 状态判定。
        // 某些“幽灵监听”端口不会正确响应 TCP connect，若先用 socket 探测会被误判成空闲，
        // 导致 Master 复用同一个坏控制端口并永久起不来。
        //
        // 优化：通过 fetchListeningPortPidMap() 复用进程内 netstat 全表快照（短 TTL）；
        // 这样同一 CLI 内多次 isPortInUse / getProcessIdByPort 调用只会触发 1 次 netstat，
        // 而不是过去的 N 次（status/stop 路径上 N 可达 10+ → CLI 耗时从 70s 降到秒级）。
        $portPidMap = $this->fetchListeningPortPidMap();
        if ($portPidMap !== null) {
            $inUse = isset($portPidMap[$port]);
            if ($inUse) {
                self::$portCache[$port] = ['inUse' => true, 'time' => \time()];
                return true;
            }
            // netstat 全表已知但本端口不在表里——若 netstat 本身返回行非空，
            // 则可信任"未占用"判定，跳过下面 PowerShell + socket 两个慢回退；
            // 仅在 netstat 整张表为空（异常或权限丢失）时才落到回退路径。
            if ($portPidMap !== []) {
                self::$portCache[$port] = ['inUse' => false, 'time' => \time()];
                return false;
            }
        }

        // 回退1：PowerShell Get-NetTCPConnection（Windows 8+/Server 2012+）
        // 仅在 netstat 全表为空（exec 失败/权限缺失）时才使用
        if ($this->isPowerShellAvailable()) {
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

        // 回退2：socket 探测（最慢但最通用）
        $socketResult = $this->socketPortCheck($port);
        if ($socketResult !== null) {
            self::$portCache[$port] = ['inUse' => $socketResult, 'time' => \time()];
            return $socketResult;
        }

        self::$portCache[$port] = ['inUse' => false, 'time' => \time()];
        return false;
    }

    /**
     * 跑一次 `netstat -ano` 解析全部 LISTENING 行，得到 port=>pid 全表，并按 TTL 缓存到进程内静态变量。
     *
     * 设计要点：
     * - 命中缓存直接返回 map，不触发任何系统命令，调用方平摊到 O(1)。
     * - 缓存为 null 表示从未尝试或被显式清理；空数组 [] 表示"已尝试但 netstat 没有输出可解析的行"。
     *   后续 isPortInUse / getProcessIdByPort 把"空数组"视为权限/环境异常，自动落回 PowerShell/socket 路径。
     * - TTL 默认 1.5s，足够覆盖 status / stop / start 等 CLI 命令在同一秒内的多次重复探测；
     *   守护进程主循环中的相邻探测需要"较新"数据时可调 clearPortCache() 主动失效。
     *
     * @return array<int, int>|null
     */
    protected function fetchListeningPortPidMap(): ?array
    {
        if (self::$listeningPortPidMap !== null
            && (\microtime(true) - self::$listeningPortPidMapAt) < self::$listeningPortPidMapTtl
        ) {
            return self::$listeningPortPidMap;
        }

        $output = [];
        $exitCode = 0;
        $this->executeCommand('netstat -ano 2>NUL', $output, $exitCode);

        $map = [];
        if ($exitCode === 0 && !empty($output)) {
            foreach ($output as $line) {
                if (\strpos($line, 'LISTENING') === false) {
                    continue;
                }
                // 典型行：  TCP    127.0.0.1:9501       0.0.0.0:0        LISTENING       12345
                if (\preg_match('/[:\.](\d+)\s+\S+\s+LISTENING\s+(\d+)/', $line, $m)) {
                    $port = (int) $m[1];
                    $pid = $this->sanitizePid($m[2]);
                    if ($port > 0 && $pid > 0 && !isset($map[$port])) {
                        $map[$port] = $pid;
                    }
                }
            }
        }

        self::$listeningPortPidMap = $map;
        self::$listeningPortPidMapAt = \microtime(true);
        return $map;
    }

    /**
     * 跑一次 `tasklist /FO CSV /NH` 解析全部进程行，得到 pid=>info 全表，并按 TTL 缓存到进程内静态变量。
     *
     * 同样的"全表一次扫描 + 短 TTL"思路：让 isRunningByPid / processExists / batchGetProcessInfo
     * 在同一 CLI 命令周期内只触发 1 次 tasklist（~2.6s）而不是 N 次。
     *
     * @return array<int, array{name:string, memory_kb:int}>|null
     */
    protected function fetchTaskListPidMap(): ?array
    {
        if (self::$taskListPidMap !== null
            && (\microtime(true) - self::$taskListPidMapAt) < self::$taskListPidMapTtl
        ) {
            return self::$taskListPidMap;
        }

        $output = [];
        $exitCode = 0;
        $this->executeCommand('tasklist /FO CSV /NH 2>NUL', $output, $exitCode);

        $map = [];
        if ($exitCode === 0 && !empty($output)) {
            foreach ($output as $line) {
                $line = \trim($line);
                if ($line === '') {
                    continue;
                }
                $parts = \str_getcsv($line, ',', '"', '');
                if (\count($parts) < 5) {
                    continue;
                }
                $pid = $this->sanitizePid($parts[1] ?? '');
                if ($pid <= 0) {
                    continue;
                }
                $memKb = (int) \preg_replace('/[^\d]/', '', (string) ($parts[4] ?? ''));
                $map[$pid] = [
                    'name' => (string) ($parts[0] ?? ''),
                    'memory_kb' => $memKb,
                ];
            }
        }

        self::$taskListPidMap = $map;
        self::$taskListPidMapAt = \microtime(true);
        return $map;
    }
    
    /**
     * 清除端口缓存
     *
     * 同时失效 netstat / tasklist 全表快照——这是必需的：
     * Stop / Start / SharedStateServiceManager 调用方在杀进程或拉起进程后
     * 会显式调用 clearPortCache() 期望"下一次 isPortInUse 反映最新状态"，
     * 如果只清 portCache 而保留 listeningPortPidMap，刚关闭的端口仍会被判为"占用"。
     */
    public function clearPortCache(?int $port = null): void
    {
        if ($port !== null) {
            unset(self::$portCache[$port]);
        } else {
            self::$portCache = [];
        }

        // 全表快照按"全部失效"处理：单端口失效与全表失效语义相同（下一次访问会重新扫描全表）。
        self::$listeningPortPidMap = null;
        self::$listeningPortPidMapAt = 0.0;
        self::$taskListPidMap = null;
        self::$taskListPidMapAt = 0.0;
        self::$commandLineCache = [];
        self::$commandLineCacheAt = 0.0;
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
        // 方案1：复用进程内 netstat 全表快照（短 TTL）。
        // 过去这里每次都会重跑 `netstat -ano`（~840ms），同一 CLI 命令调几十次就把 status 拖到 70s；
        // 现在与 isPortInUse 共用一份 map，多次调用全部 O(1)。
        $portPidMap = $this->fetchListeningPortPidMap();
        if ($portPidMap !== null && $portPidMap !== []) {
            return (int) ($portPidMap[$port] ?? 0);
        }
        // map 为空（netstat 没产出可解析的行）时，落到 PowerShell 兜底；不再重跑 netstat。

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
     * 1. PowerShell Get-Process（优先，通常最快）
     * 2. tasklist /FI "PID eq"（兼容兜底，所有 Windows 版本支持）
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

        // 方案1：复用进程内 tasklist 全表快照（短 TTL）。
        // status / stop / 自愈检测等路径上需要批量判定一组 PID 是否存活；
        // 全表查询 O(1)，且与 batchGetProcessInfo / processExists 共用同一份缓存，
        // 把过去每次单独跑 PowerShell（~1s）或单条 tasklist /FI（~2.6s）压成 1 次。
        $taskListMap = $this->fetchTaskListPidMap();
        if ($taskListMap !== null && $taskListMap !== []) {
            return isset($taskListMap[$pid]);
        }
        // 全表为空（exec 失败/权限缺失等异常）时落回逐 PID 检测路径，保持原语义不变。

        // 方案2：PowerShell Get-Process（兜底）
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
        
        // 方案3：tasklist /FI（最末端兜底）
        $output = [];
        $exitCode = 0;
        $this->executeCommand("tasklist /FI \"PID eq {$pid}\" /FO CSV /NH 2>NUL", $output, $exitCode);
        
        if (!empty($output)) {
            foreach ($output as $line) {
                $line = \trim($line);
                if ($line === '') {
                    continue;
                }
                $parts = \str_getcsv($line, ',', '"', '');
                if (\count($parts) >= 2) {
                    $parsedPid = $this->sanitizePid($parts[1]);
                    if ($parsedPid === $pid) {
                        return true;
                    }
                }
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
            $ps = "powershell -NoProfile -Command \"Get-CimInstance Win32_Process -Filter 'ProcessId={$pid}' -ErrorAction SilentlyContinue | Select-Object Name,CommandLine,WorkingSetSize | Format-List\"";
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

        // 命中进程内 commandLine per-pid 缓存。
        //
        // 之前曾经在这里主动 bootstrap "Get-CimInstance Win32_Process 全表"，但实测
        // 发现 status 这种命令通常只需要鉴权 1~3 个 PID（共享服务端口占用方），
        // 全表 PowerShell CIM 自身就 ~3s，反而比 per-pid 单查还贵。
        //
        // 因此改为纯 per-pid 缓存：第一次单查（~1s）后所有重复鉴权 O(1)。
        // 仅当 commandLine 缓存项已经过 TTL 时才会让单条查询重新落到 PowerShell。
        $now = \microtime(true);
        if (self::$commandLineCacheAt > 0
            && ($now - self::$commandLineCacheAt) >= self::$commandLineCacheTtl
        ) {
            // TTL 过期：清空已缓存条目，让本次以及后续单查都拿到最新结果
            self::$commandLineCache = [];
            self::$commandLineCacheAt = 0.0;
        }
        if (\array_key_exists($pid, self::$commandLineCache)) {
            return self::$commandLineCache[$pid];
        }

        // 方案1：PowerShell CIM（per-pid 兜底；查询结果回写缓存避免重复单查同一 PID）
        if ($this->isPowerShellAvailable()) {
            $out = [];
            $exitCode = 0;
            $this->executeCommand(
                "powershell -NoProfile -Command \"(Get-CimInstance Win32_Process -Filter 'ProcessId={$pid}' -ErrorAction SilentlyContinue).CommandLine\" 2>NUL",
                $out,
                $exitCode
            );

            if ($exitCode === 0 && !empty($out)) {
                $cmdLine = \trim(\implode(' ', $out));
                if ($cmdLine !== '' && \stripos($cmdLine, 'No Instance') === false) {
                    $this->rememberCommandLine($pid, $cmdLine);
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
                    $this->rememberCommandLine($pid, $line);
                    return $line;
                }
            }
        }

        // 显式记录为空字符串，避免下次再走 per-pid 慢路径
        $this->rememberCommandLine($pid, '');
        return '';
    }

    /**
     * 把 per-pid 单查得到的 commandLine 回写进程内 TTL 缓存，并在第一次回写时锚定 TTL 起点。
     */
    protected function rememberCommandLine(int $pid, string $cmdLine): void
    {
        self::$commandLineCache[$pid] = $cmdLine;
        if (self::$commandLineCacheAt <= 0) {
            self::$commandLineCacheAt = \microtime(true);
        }
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
    
    /**
     * @inheritDoc
     * 
     * 批量获取进程信息，单次系统调用获取所有进程
     * 
     * 性能策略（快→慢）：
     * 1. tasklist（最快，约 200-500ms，足够获取 PID/内存/进程名）
     * 2. PowerShell CIM（较慢，约 2-3s 启动开销，但能获取完整命令行）
     */
    public function batchGetProcessInfo(array $pids): array
    {
        $result = [];
        
        $validPids = \array_filter($pids, fn($pid) => $this->isValidPid($pid));
        if (empty($validPids)) {
            foreach ($pids as $pid) {
                $result[$pid] = $this->getDefaultProcessInfo($pid);
            }
            return $result;
        }
        
        foreach ($pids as $pid) {
            $result[$pid] = $this->getDefaultProcessInfo($pid);
        }

        // 方案1：复用进程内 tasklist 全表快照（短 TTL）。
        // 同一 CLI 命令周期内 status 会反复需要"批量进程存在/内存信息"——
        // 让 batchGetProcessInfo 与 isRunningByPid / processExists 共用一份 map，
        // 第一次调用真跑 tasklist（~2.6s），后续 1.5s 内全部 O(1)。
        $taskListMap = $this->fetchTaskListPidMap();
        if ($taskListMap !== null && $taskListMap !== []) {
            foreach ($validPids as $pid) {
                if (isset($taskListMap[$pid])) {
                    $info = $taskListMap[$pid];
                    $result[$pid]['name'] = (string) $info['name'];
                    $result[$pid]['exists'] = true;
                    $memKb = (int) $info['memory_kb'];
                    if ($memKb > 0) {
                        $result[$pid]['memory'] = \round($memKb / 1024, 2) . ' MB';
                    }
                }
            }
            // tasklist 全表是 Windows 上"进程存活"的权威来源——
            // 一个有效 PID 不在全表里就是真的不存在，没必要再跑一次 ~3s 的 PowerShell CIM 全表去重复确认。
            // 之前会触发 CIM 兜底，是 server:status 在 master 已退出时仍要 6-10s 的关键来源之一。
            return $result;
        }
        
        // 方案2：PowerShell CIM 补充（仅当 tasklist 未找到或需要命令行时）
        // 注：通常不会执行到这里，因为 tasklist 已经能找到所有运行中的进程
        if ($this->isPowerShellAvailable()) {
            $missingPids = [];
            foreach ($validPids as $pid) {
                if (!($result[$pid]['exists'] ?? false)) {
                    $missingPids[] = $pid;
                }
            }
            
            if (!empty($missingPids)) {
                $pidFilter = \implode(',', $missingPids);
                $ps = "powershell -NoProfile -Command \"Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object { \$_.ProcessId -in @({$pidFilter}) } | Select-Object ProcessId,Name,CommandLine,WorkingSetSize | ConvertTo-Json -Compress\"";
                $out = [];
                $code = 0;
                $this->executeCommand($ps, $out, $code);
                
                if ($code === 0 && !empty($out)) {
                    $json = \implode('', $out);
                    $data = \json_decode($json, true);
                    
                    if ($data !== null) {
                        if (isset($data['ProcessId'])) {
                            $data = [$data];
                        }
                        
                        foreach ($data as $proc) {
                            $pid = (int) ($proc['ProcessId'] ?? 0);
                            if ($pid > 0 && isset($result[$pid])) {
                                $result[$pid]['exists'] = true;
                                $result[$pid]['name'] = (string) ($proc['Name'] ?? '');
                                $result[$pid]['command'] = (string) ($proc['CommandLine'] ?? '');
                                $ws = (int) ($proc['WorkingSetSize'] ?? 0);
                                if ($ws > 0) {
                                    $result[$pid]['memory'] = \round($ws / 1024 / 1024, 2) . ' MB';
                                }
                            }
                        }
                    }
                }
            }
        }
        
        return $result;
    }
}
