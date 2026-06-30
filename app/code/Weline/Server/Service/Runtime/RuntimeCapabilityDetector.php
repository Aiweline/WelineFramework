<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

final class RuntimeCapabilityDetector
{
    public function detect(): WlsRuntimeProfile
    {
        $disabled = $this->disabledFunctions();
        $functions = [];
        foreach (['proc_open', 'proc_close', 'pcntl_fork', 'exec', 'popen', 'shell_exec', 'passthru'] as $function) {
            $functions[$function] = \function_exists($function) && !\in_array($function, $disabled, true);
        }

        $extensions = [
            'event' => \extension_loaded('event'),
            'ev' => \extension_loaded('ev'),
            'sockets' => \extension_loaded('sockets'),
            'pcntl' => \extension_loaded('pcntl'),
            'posix' => \extension_loaded('posix'),
            'opcache' => \extension_loaded('Zend OPcache') || \function_exists('opcache_get_status'),
        ];

        $windowsTools = [];
        if (PHP_OS_FAMILY === 'Windows') {
            foreach (['powershell', 'pwsh', 'netstat', 'tasklist', 'wmic'] as $tool) {
                $windowsTools[$tool] = $this->commandExists($tool, $functions);
            }
        }

        $data = [
            'php_version' => PHP_VERSION,
            'php_binary' => PHP_BINARY,
            'php_ini' => \php_ini_loaded_file() ?: '',
            'os_family' => PHP_OS_FAMILY,
            'os' => PHP_OS,
            'kernel_release' => \function_exists('php_uname') ? (string) @\php_uname('r') : '',
            'cpu_cores' => $this->detectCpuCores($functions),
            'memory_mb' => $this->detectTotalMemoryMb($functions),
            'disabled_functions' => $disabled,
            'functions' => $functions,
            'extensions' => $extensions,
            'event_classes_available' => \class_exists(\EventBase::class) && \class_exists(\Event::class),
            'opcache_enable_cli' => (string) \ini_get('opcache.enable_cli'),
            'opcache_jit' => (string) \ini_get('opcache.jit'),
            'opcache_jit_buffer_size' => (string) \ini_get('opcache.jit_buffer_size'),
            'memory_limit' => (string) \ini_get('memory_limit'),
            'supports_reuse_port' => $this->detectReusePortSupport(),
            'reuse_port_constant' => \defined('SO_REUSEPORT'),
            'windows_tools' => $windowsTools,
        ];

        return new WlsRuntimeProfile($data, $this->buildFindings($data));
    }

    /**
     * @return string[]
     */
    private function disabledFunctions(): array
    {
        $raw = (string) \ini_get('disable_functions');
        if ($raw === '') {
            return [];
        }

        return \array_values(\array_filter(\array_map(
            static fn(string $item): string => \strtolower(\trim($item)),
            \explode(',', $raw)
        )));
    }

    /**
     * @param array<string, bool> $functions
     */
    private function detectCpuCores(array $functions): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return \max(1, (int) (\getenv('NUMBER_OF_PROCESSORS') ?: 4));
        }

        if (!empty($functions['shell_exec'])) {
            $nproc = @\shell_exec('nproc 2>/dev/null');
            if (\is_string($nproc) && \trim($nproc) !== '' && \ctype_digit(\trim($nproc))) {
                return \max(1, (int) \trim($nproc));
            }

            $sysctl = @\shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if (\is_string($sysctl) && \trim($sysctl) !== '' && \ctype_digit(\trim($sysctl))) {
                return \max(1, (int) \trim($sysctl));
            }
        }

        return 4;
    }

    /**
     * @param array<string, bool> $functions
     */
    private function detectTotalMemoryMb(array $functions): ?int
    {
        if (PHP_OS_FAMILY === 'Linux' && \is_file('/proc/meminfo')) {
            $raw = @\file_get_contents('/proc/meminfo');
            if (\is_string($raw) && \preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $raw, $m)) {
                return (int) \floor(((int) $m[1]) / 1024);
            }
        }

        if (PHP_OS_FAMILY === 'Darwin' && !empty($functions['shell_exec'])) {
            $bytes = @\shell_exec('sysctl -n hw.memsize 2>/dev/null');
            if (\is_string($bytes) && \trim($bytes) !== '' && \ctype_digit(\trim($bytes))) {
                return (int) \floor(((int) \trim($bytes)) / 1048576);
            }
        }

        if (PHP_OS_FAMILY === 'Windows' && !empty($functions['shell_exec'])) {
            $powershell = $this->resolveWindowsCommandPath('powershell');
            if ($powershell !== null) {
                $cmd = $this->quoteWindowsCommand($powershell)
                    . ' -NoProfile -Command "(Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory" 2>NUL';
                $bytes = @\shell_exec($cmd);
                if (\is_string($bytes) && \trim($bytes) !== '' && \ctype_digit(\trim($bytes))) {
                    return (int) \floor(((int) \trim($bytes)) / 1048576);
                }
            }
        }

        return null;
    }

    private function detectReusePortSupport(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            return true;
        }
        if (PHP_OS_FAMILY === 'Linux') {
            $release = \function_exists('php_uname') ? (string) @\php_uname('r') : '';
            return $release !== '' && \version_compare($release, '3.9', '>=');
        }

        return false;
    }

    /**
     * @param array<string, bool> $functions
     */
    private function commandExists(string $command, array $functions): bool
    {
        if (empty($functions['exec'])) {
            return false;
        }

        $output = [];
        $exitCode = 1;
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->resolveWindowsCommandPath($command) !== null;
        } else {
            @\exec('command -v ' . \escapeshellarg($command) . ' 2>/dev/null', $output, $exitCode);
        }

        return $exitCode === 0 && $output !== [];
    }

    private function quoteWindowsCommand(string $path): string
    {
        return '"' . \str_replace('"', '\"', $path) . '"';
    }

    private function resolveWindowsCommandPath(string $command): ?string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return null;
        }

        $command = \trim($command);
        if ($command === '') {
            return null;
        }

        if (\str_contains($command, '\\') || \str_contains($command, '/')) {
            return \is_file($command) ? $command : null;
        }

        $names = [$command];
        if (!\str_ends_with(\strtolower($command), '.exe')) {
            $names[] = $command . '.exe';
        }
        $names = \array_values(\array_unique($names));

        $systemRoot = \rtrim((string) (\getenv('SystemRoot') ?: \getenv('windir') ?: 'C:\\Windows'), '\\/');
        $directories = [
            $systemRoot . '\\System32',
            $systemRoot . '\\Sysnative',
            $systemRoot . '\\SysWOW64',
            $systemRoot . '\\System32\\WindowsPowerShell\\v1.0',
            $systemRoot . '\\System32\\wbem',
        ];

        $path = (string) \getenv('PATH');
        if ($path !== '') {
            foreach (\explode(PATH_SEPARATOR, $path) as $directory) {
                $directory = \trim($directory, " \t\n\r\0\x0B\"'");
                if ($directory !== '') {
                    $directories[] = $directory;
                }
            }
        }

        foreach (\array_unique($directories) as $directory) {
            foreach ($names as $name) {
                $candidate = \rtrim($directory, '\\/') . '\\' . $name;
                if (\is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{level:string,code:string,message:string,action?:string}>
     */
    private function buildFindings(array $data): array
    {
        $findings = [];
        $extensions = \is_array($data['extensions'] ?? null) ? $data['extensions'] : [];
        $functions = \is_array($data['functions'] ?? null) ? $data['functions'] : [];

        if (empty($extensions['event'])) {
            $findings[] = [
                'level' => PHP_OS_FAMILY === 'Windows' ? 'warning' : 'info',
                'code' => 'event_missing',
                'message' => 'PHP event extension is not loaded; WLS will use stream_select unless event is installed.',
                'action' => PHP_OS_FAMILY === 'Windows'
                    ? 'Install php_event.dll and add extension=event in php.ini.'
                    : 'Install with pecl install event and enable extension=event.',
            ];
        }
        if (empty($extensions['opcache']) || (string)($data['opcache_enable_cli'] ?? '') !== '1') {
            $findings[] = [
                'level' => 'info',
                'code' => 'opcache_cli_disabled',
                'message' => 'OPcache CLI is not fully enabled for long-running WLS processes.',
                'action' => 'Set opcache.enable_cli=1 in php.ini.',
            ];
        }
        $jit = \strtolower(\trim((string)($data['opcache_jit'] ?? '')));
        $jitBuffer = \strtolower(\trim((string)($data['opcache_jit_buffer_size'] ?? '')));
        if (!empty($extensions['opcache'])
            && ($jit === '' || \in_array($jit, ['0', 'off', 'disable', 'disabled'], true) || \preg_match('/^0+[kmg]?$/', $jitBuffer))) {
            $findings[] = [
                'level' => 'info',
                'code' => 'opcache_jit_disabled',
                'message' => 'OPcache JIT is not enabled; CPU-heavy WLS workloads may miss peak PHP execution performance.',
                'action' => 'For CPU-heavy workloads set opcache.jit=tracing and opcache.jit_buffer_size=64M; keep it disabled for mostly IO-bound workloads if latency is better.',
            ];
        }
        if (empty($functions['proc_open'])) {
            $findings[] = [
                'level' => 'warning',
                'code' => 'proc_open_unavailable',
                'message' => 'proc_open is unavailable; precise process lifecycle management is degraded.',
                'action' => 'Remove proc_open from disable_functions.',
            ];
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $tools = \is_array($data['windows_tools'] ?? null) ? $data['windows_tools'] : [];
            foreach (['powershell', 'netstat', 'tasklist'] as $tool) {
                if (empty($tools[$tool])) {
                    $findings[] = [
                        'level' => 'warning',
                        'code' => 'windows_tool_missing_' . $tool,
                        'message' => "Windows tool {$tool} was not found; WLS diagnostics and process discovery may be degraded.",
                        'action' => "Ensure {$tool} is available in PATH.",
                    ];
                }
            }
        }
        if (($data['memory_mb'] ?? null) === null) {
            $findings[] = [
                'level' => 'info',
                'code' => 'memory_unknown',
                'message' => 'System memory could not be detected; worker auto-sizing will use CPU-only limits.',
            ];
        }

        return $findings;
    }
}
