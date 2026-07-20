<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

final class RuntimeCapabilityDetector
{
    /** @var array<string, array<string, mixed>> */
    private static array $directListenerProbeCache = [];

    /** @var array<string, array<string, mixed>> */
    private static array $reusePortProbeCache = [];

    public function detect(?string $listenHost = null): WlsRuntimeProfile
    {
        $disabled = $this->disabledFunctions();
        $functions = [];
        foreach (['proc_open', 'proc_close', 'proc_get_status', 'pcntl_fork', 'pcntl_exec', 'posix_setsid', 'posix_kill', 'exec', 'popen', 'shell_exec', 'passthru'] as $function) {
            $functions[$function] = \function_exists($function) && !\in_array($function, $disabled, true);
        }

        $extensions = [
            'event' => \extension_loaded('event'),
            'ev' => \extension_loaded('ev'),
            'sockets' => \extension_loaded('sockets'),
            'openssl' => \extension_loaded('openssl'),
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

        $cpuTopology = $this->detectCpuTopology($functions);
        $probeCacheKey = PHP_OS_FAMILY . '|' . PHP_BINARY . '|' . PHP_VERSION_ID . '|'
            . \strtolower(\trim((string)$listenHost));
        $reusePortProbe = self::$reusePortProbeCache[$probeCacheKey]
            ??= $this->detectReusePortSupport($listenHost);
        $directListenerProbe = $this->detectDirectListenerSupport($listenHost, $functions, $reusePortProbe);
        $data = [
            'php_version' => PHP_VERSION,
            'php_binary' => PHP_BINARY,
            'php_ini' => \php_ini_loaded_file() ?: '',
            'os_family' => PHP_OS_FAMILY,
            'os' => PHP_OS,
            'kernel_release' => \function_exists('php_uname') ? (string) @\php_uname('r') : '',
            'cpu_cores' => $cpuTopology['logical'],
            'cpu_physical_cores' => $cpuTopology['physical'],
            'cpu_performance_cores' => $cpuTopology['performance'],
            'cpu_topology_source' => $cpuTopology['source'],
            'memory_mb' => $this->detectTotalMemoryMb($functions),
            'disabled_functions' => $disabled,
            'functions' => $functions,
            'extensions' => $extensions,
            'event_classes_available' => \class_exists(\EventBase::class) && \class_exists(\Event::class),
            'opcache_enable_cli' => (string) \ini_get('opcache.enable_cli'),
            'opcache_jit' => (string) \ini_get('opcache.jit'),
            'opcache_jit_buffer_size' => (string) \ini_get('opcache.jit_buffer_size'),
            'memory_limit' => (string) \ini_get('memory_limit'),
            'supports_reuse_port' => (bool)$reusePortProbe['supported'],
            'reuse_port_probe' => $reusePortProbe,
            'reuse_port_constant' => \defined('SO_REUSEPORT'),
            'supports_direct_listener' => (bool)($directListenerProbe['supported'] ?? false),
            'direct_listener_mode' => (string)($directListenerProbe['mode'] ?? ''),
            'direct_listener_probe' => $directListenerProbe,
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
     * @return array{logical:int,physical:int,performance:int,source:string}
     */
    private function detectCpuTopology(array $functions): array
    {
        $logical = $this->detectCpuCores($functions);
        $physical = $logical;
        $performance = $logical;
        $source = PHP_OS_FAMILY === 'Linux' ? 'linux_nproc' : 'logical_cpu';

        if (PHP_OS_FAMILY !== 'Darwin' || empty($functions['shell_exec'])) {
            return [
                'logical' => $logical,
                'physical' => $physical,
                'performance' => $performance,
                'source' => $source,
            ];
        }

        $detectedPhysical = $this->readPositiveIntegerCommand('sysctl -n hw.physicalcpu 2>/dev/null');
        if ($detectedPhysical !== null) {
            $physical = \min($logical, $detectedPhysical);
            $performance = $physical;
            $source = 'darwin_physicalcpu';
        }

        // Apple Silicon exposes the high-performance cluster as perflevel0.
        // Intel macOS has no perflevel key and intentionally keeps the physical-core fallback.
        $detectedPerformance = $this->readPositiveIntegerCommand(
            'sysctl -n hw.perflevel0.physicalcpu 2>/dev/null'
        );
        if ($detectedPerformance !== null && $detectedPerformance <= $physical) {
            $performance = $detectedPerformance;
            $source = 'darwin_perflevel0';
        }

        return [
            'logical' => $logical,
            'physical' => \max(1, $physical),
            'performance' => \max(1, $performance),
            'source' => $source,
        ];
    }

    private function readPositiveIntegerCommand(string $command): ?int
    {
        $output = @\shell_exec($command);
        if (!\is_string($output)) {
            return null;
        }

        $output = \trim($output);
        if ($output === '' || !\ctype_digit($output)) {
            return null;
        }

        $value = (int)$output;
        return $value > 0 ? $value : null;
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
            $registryMemoryMb = $this->detectWindowsRegistryMemoryMb();
            if ($registryMemoryMb !== null) {
                return $registryMemoryMb;
            }

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

    private function detectWindowsRegistryMemoryMb(): ?int
    {
        if (PHP_INT_SIZE < 8) {
            return null;
        }

        $reg = $this->resolveWindowsCommandPath('reg');
        if ($reg === null) {
            return null;
        }

        $command = $this->quoteWindowsCommand($reg)
            . ' query "HKLM\HARDWARE\RESOURCEMAP\System Resources\Physical Memory"'
            . ' /v .Translated 2>NUL';
        $output = @\shell_exec($command);
        if (!\is_string($output)
            || !\preg_match('/REG_RESOURCE_LIST\s+([0-9a-f]+)/i', $output, $match)
        ) {
            return null;
        }

        $hex = (string)($match[1] ?? '');
        if ($hex === '' || (\strlen($hex) % 2) !== 0) {
            return null;
        }

        $resourceList = @\hex2bin($hex);
        return \is_string($resourceList)
            ? $this->parseWindowsPhysicalMemoryResourceListMb($resourceList)
            : null;
    }

    /**
     * Decode the packed CM_RESOURCE_LIST stored in the Physical Memory
     * registry value. Windows encodes ordinary ranges in bytes and large
     * ranges with the low 8, 16, or 32 bits omitted according to Flags.
     */
    private function parseWindowsPhysicalMemoryResourceListMb(string $resourceList): ?int
    {
        $size = \strlen($resourceList);
        if ($size < 20) {
            return null;
        }

        $header = \unpack('Vcount', \substr($resourceList, 0, 4));
        $fullDescriptorCount = (int)($header['count'] ?? 0);
        if ($fullDescriptorCount < 1 || $fullDescriptorCount > 64) {
            return null;
        }

        $offset = 4;
        $totalBytes = 0;
        for ($fullIndex = 0; $fullIndex < $fullDescriptorCount; $fullIndex++) {
            if (($offset + 16) > $size) {
                return null;
            }

            $partialHeader = \unpack('Vcount', \substr($resourceList, $offset + 12, 4));
            $partialDescriptorCount = (int)($partialHeader['count'] ?? 0);
            if ($partialDescriptorCount < 1 || $partialDescriptorCount > 4096) {
                return null;
            }
            $offset += 16;

            $deviceSpecificBytes = 0;
            for ($partialIndex = 0; $partialIndex < $partialDescriptorCount; $partialIndex++) {
                if (($offset + 20) > $size) {
                    return null;
                }

                $type = \ord($resourceList[$offset]);
                $flagsData = \unpack('vflags', \substr($resourceList, $offset + 2, 2));
                $lengthData = \unpack('Vlength', \substr($resourceList, $offset + 12, 4));
                $flags = (int)($flagsData['flags'] ?? 0);
                $length = (int)($lengthData['length'] ?? 0);

                $multiplier = match ($type) {
                    3 => 1,
                    7 => match (true) {
                        ($flags & 0x0200) !== 0 => 256,
                        ($flags & 0x0400) !== 0 => 65536,
                        ($flags & 0x0800) !== 0 => 4294967296,
                        default => 0,
                    },
                    default => 0,
                };
                if ($multiplier > 0 && $length > 0) {
                    if ($length > \intdiv(PHP_INT_MAX - $totalBytes, $multiplier)) {
                        return null;
                    }
                    $totalBytes += $length * $multiplier;
                }

                // CmResourceTypeDeviceSpecific must be the last descriptor;
                // its private payload follows the descriptor array.
                if ($type === 5) {
                    if ($partialIndex !== ($partialDescriptorCount - 1)) {
                        return null;
                    }
                    $dataSize = \unpack('Vlength', \substr($resourceList, $offset + 4, 4));
                    $deviceSpecificBytes = (int)($dataSize['length'] ?? 0);
                }

                $offset += 20;
            }

            if ($deviceSpecificBytes < 0 || ($offset + $deviceSpecificBytes) > $size) {
                return null;
            }
            $offset += $deviceSpecificBytes;
        }

        if ($offset !== $size || $totalBytes <= 0) {
            return null;
        }

        return (int)\floor($totalBytes / 1048576);
    }

    /**
     * Probe the same address family used by the public listener with two real
     * listening sockets and real client connections. Duplicate bind alone is
     * insufficient: Darwin accepts it while routing one client identity to a
     * single socket, which leaves WLS effectively single-Worker.
     *
     * @return array{supported:bool,host:string,family:string,reason:string,error_code:int}
     */
    private function detectReusePortSupport(?string $listenHost = null): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->reusePortProbeResult(false, '', 'unsupported', 'Windows requires Dispatcher topology.');
        }
        if (!\in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true)) {
            return $this->reusePortProbeResult(false, '', 'unsupported', 'SO_REUSEPORT direct topology is supported only on Linux and macOS.');
        }
        if (!\extension_loaded('sockets')
            || !\function_exists('socket_create')
            || !\function_exists('socket_set_option')
            || !\function_exists('socket_bind')
            || !\function_exists('socket_listen')
            || !\defined('SO_REUSEPORT')
        ) {
            return $this->reusePortProbeResult(false, '', 'unavailable', 'PHP sockets extension and SO_REUSEPORT are required.');
        }

        [$family, $host, $familyName] = $this->normalizeReusePortProbeHost($listenHost);
        $first = null;
        $second = null;

        try {
            $first = @\socket_create($family, \SOCK_STREAM, \SOL_TCP);
            if ($first === false) {
                return $this->reusePortSocketFailure($host, $familyName, null, 'Unable to create the first probe socket.');
            }
            if (!@\socket_set_option($first, \SOL_SOCKET, \SO_REUSEADDR, 1)
                || !@\socket_set_option($first, \SOL_SOCKET, \SO_REUSEPORT, 1)
            ) {
                return $this->reusePortSocketFailure($host, $familyName, $first, 'Unable to enable SO_REUSEPORT on the first probe socket.');
            }
            if (!@\socket_bind($first, $host, 0) || !@\socket_listen($first, 128)) {
                return $this->reusePortSocketFailure($host, $familyName, $first, 'Unable to bind/listen with the first probe socket.');
            }

            $boundHost = $host;
            $boundPort = 0;
            if (!@\socket_getsockname($first, $boundHost, $boundPort) || $boundPort <= 0) {
                return $this->reusePortSocketFailure($host, $familyName, $first, 'Unable to resolve the first probe socket port.');
            }

            $second = @\socket_create($family, \SOCK_STREAM, \SOL_TCP);
            if ($second === false) {
                return $this->reusePortSocketFailure($boundHost, $familyName, null, 'Unable to create the second probe socket.');
            }
            if (!@\socket_set_option($second, \SOL_SOCKET, \SO_REUSEADDR, 1)
                || !@\socket_set_option($second, \SOL_SOCKET, \SO_REUSEPORT, 1)
            ) {
                return $this->reusePortSocketFailure($boundHost, $familyName, $second, 'Unable to enable SO_REUSEPORT on the second probe socket.');
            }
            if (!@\socket_bind($second, $boundHost, $boundPort) || !@\socket_listen($second, 128)) {
                return $this->reusePortSocketFailure($boundHost, $familyName, $second, 'The second listener could not share the first listener port.');
            }

            return $this->verifyReusePortAcceptDistribution(
                $first,
                $second,
                $boundHost,
                $boundPort,
                $familyName,
            );
        } finally {
            if ($second instanceof \Socket) {
                \socket_close($second);
            }
            if ($first instanceof \Socket) {
                \socket_close($first);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyReusePortAcceptDistribution(
        \Socket $first,
        \Socket $second,
        string $boundHost,
        int $boundPort,
        string $familyName,
    ): array {
        @\socket_set_nonblock($first);
        @\socket_set_nonblock($second);
        $connectHost = $this->probeConnectHost($boundHost, $familyName);
        $counts = [0, 0];
        $connected = 0;

        // A 128-connection sample can exceed the 1.5 ratio by ordinary hash
        // variance and intermittently reject a healthy Linux kernel. Keep the
        // strict balance gate, but use a large local-only sample so the result
        // represents listener capability instead of one short random burst.
        for ($index = 0; $index < 512; $index++) {
            $client = @\socket_create($familyName === 'ipv6' ? \AF_INET6 : \AF_INET, \SOCK_STREAM, \SOL_TCP);
            if (!$client instanceof \Socket) {
                continue;
            }
            if (@\socket_connect($client, $connectHost, $boundPort)) {
                $connected++;
            }
            @\socket_close($client);
            $this->drainProbeAccepts($first, $counts[0]);
            $this->drainProbeAccepts($second, $counts[1]);
        }
        $deadlineNanoseconds = \hrtime(true) + 150_000_000;
        do {
            $before = $counts[0] + $counts[1];
            $this->drainProbeAccepts($first, $counts[0]);
            $this->drainProbeAccepts($second, $counts[1]);
            if ($counts[0] + $counts[1] >= $connected) {
                break;
            }
            if (($counts[0] + $counts[1]) === $before) {
                SchedulerSystem::usleep(1000);
            }
        } while (\hrtime(true) < $deadlineNanoseconds);

        $min = \min($counts);
        $max = \max($counts);
        $ratio = $min > 0 ? $max / $min : INF;
        $supported = $connected >= 32
            && ($counts[0] + $counts[1]) >= \min(32, $connected)
            && $min > 0
            && $ratio <= 1.5;
        $reason = $supported
            ? 'SO_REUSEPORT accepted real connections across both listeners.'
            : 'SO_REUSEPORT duplicate bind succeeded but real accepts were not balanced across both listeners.';

        return $this->reusePortProbeResult($supported, $boundHost, $familyName, $reason) + [
            'connected' => $connected,
            'accepted' => $counts,
            'max_min_ratio' => \is_finite($ratio) ? \round($ratio, 3) : null,
        ];
    }

    private function drainProbeAccepts(\Socket $listener, int &$count): void
    {
        while (($accepted = @\socket_accept($listener)) instanceof \Socket) {
            $count++;
            @\socket_close($accepted);
        }
        @\socket_clear_error($listener);
    }

    private function probeConnectHost(string $boundHost, string $familyName): string
    {
        if ($familyName === 'ipv6') {
            return $boundHost === '::' ? '::1' : $boundHost;
        }

        return $boundHost === '0.0.0.0' ? '127.0.0.1' : $boundHost;
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function normalizeReusePortProbeHost(?string $listenHost): array
    {
        $host = \trim((string)$listenHost);
        $host = \trim($host, '[]');
        if ($host !== '' && \filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) !== false) {
            return [\AF_INET6, $host, 'ipv6'];
        }
        if ($host === '' || \filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) === false) {
            $host = '127.0.0.1';
        }

        return [\AF_INET, $host, 'ipv4'];
    }

    /**
     * @return array{supported:bool,host:string,family:string,reason:string,error_code:int}
     */
    private function reusePortSocketFailure(string $host, string $family, ?\Socket $socket, string $reason): array
    {
        $errorCode = $socket instanceof \Socket ? \socket_last_error($socket) : \socket_last_error();
        if ($errorCode > 0) {
            $reason .= ' ' . \socket_strerror($errorCode);
        }

        return $this->reusePortProbeResult(false, $host, $family, $reason, $errorCode);
    }

    /**
     * @return array{supported:bool,host:string,family:string,reason:string,error_code:int}
     */
    private function reusePortProbeResult(
        bool $supported,
        string $host,
        string $family,
        string $reason,
        int $errorCode = 0
    ): array {
        return [
            'supported' => $supported,
            'host' => $host,
            'family' => $family,
            'reason' => $reason,
            'error_code' => $errorCode,
        ];
    }

    /**
     * @param array<string, bool> $functions
     * @param array<string, mixed> $reusePortProbe
     * @return array<string, mixed>
     */
    private function detectDirectListenerSupport(
        ?string $listenHost,
        array $functions,
        array $reusePortProbe,
    ): array {
        if (PHP_OS_FAMILY === 'Windows') {
            return [
                'supported' => false,
                'mode' => 'dispatcher',
                'reason' => 'Windows requires Dispatcher topology.',
            ];
        }
        if (PHP_OS_FAMILY === 'Linux') {
            return [
                'supported' => (bool)($reusePortProbe['supported'] ?? false),
                'mode' => 'reuseport',
                'reason' => (string)($reusePortProbe['reason'] ?? ''),
                'reuse_port_probe' => $reusePortProbe,
            ];
        }
        if (PHP_OS_FAMILY !== 'Darwin') {
            return [
                'supported' => false,
                'mode' => 'dispatcher',
                'reason' => 'Direct listener strategies are supported only on Linux and macOS.',
            ];
        }

        [, $host, $family] = $this->normalizeReusePortProbeHost($listenHost);
        $cacheKey = PHP_BINARY . '|' . PHP_VERSION_ID . '|' . $family . '|' . $host;
        if (isset(self::$directListenerProbeCache[$cacheKey])) {
            return self::$directListenerProbeCache[$cacheKey];
        }

        return self::$directListenerProbeCache[$cacheKey] = $this->probeDarwinSharedListener(
            $family,
            $functions,
        );
    }

    /**
     * Verify the macOS Master-owned listener prerequisites without launching
     * synthetic PHP Workers.
     *
     * The real Master listener, FD 3 delivery, Worker bootstrap, policy digest,
     * and warmup are mandatory runtime READY gates. Repeating those checks with
     * two extra PHP interpreters made a healthy host look unsupported whenever
     * macOS executable verification was busy.
     *
     * @param array<string, bool> $functions
     * @return array<string, mixed>
     */
    private function probeDarwinSharedListener(string $family, array $functions): array
    {
        foreach (['proc_open', 'proc_close', 'proc_get_status', 'posix_setsid', 'posix_kill'] as $required) {
            if (empty($functions[$required])) {
                return [
                    'supported' => false,
                    'mode' => 'shared_fd',
                    'reason' => 'macOS direct shared listener requires proc_open and POSIX process lifecycle functions.',
                    'missing_function' => $required,
                ];
            }
        }
        if (!\is_dir('/dev/fd')) {
            return [
                'supported' => false,
                'mode' => 'shared_fd',
                'reason' => 'macOS direct shared listener requires /dev/fd descriptor access.',
            ];
        }

        $bindHost = $family === 'ipv6' ? '::1' : '127.0.0.1';
        $addressHost = $family === 'ipv6' ? '[' . $bindHost . ']' : $bindHost;
        $errno = 0;
        $errstr = '';
        $listener = @\stream_socket_server(
            'tcp://' . $addressHost . ':0',
            $errno,
            $errstr,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        if (!\is_resource($listener)) {
            return [
                'supported' => false,
                'mode' => 'shared_fd',
                'reason' => "Unable to create the macOS shared-listener probe: {$errstr}",
                'error_code' => $errno,
            ];
        }

        try {
            @\stream_set_blocking($listener, false);
            $endpoint = (string)@\stream_socket_get_name($listener, false);
            $separator = \strrpos($endpoint, ':');
            $port = $separator === false ? 0 : (int)\substr($endpoint, $separator + 1);
            if ($port <= 0) {
                return [
                    'supported' => false,
                    'mode' => 'shared_fd',
                    'reason' => 'Unable to resolve the macOS shared-listener probe port.',
                ];
            }

            return [
                'supported' => true,
                'mode' => 'shared_fd',
                'reason' => 'Preflight created a real Master listener; actual FD 3 delivery, Worker READY, and warmup remain mandatory runtime gates.',
                'host' => $bindHost,
                'family' => $family,
                'inherited_fd' => DirectSharedListener::INHERITED_FD,
                'runtime_ack_required' => true,
            ];
        } finally {
            @\fclose($listener);
        }
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
        $runtimeSafetyRequired = PhpRuntimeSafetyProfile::requiresJitIsolation();
        if ($runtimeSafetyRequired) {
            $findings[] = [
                'level' => 'info',
                'code' => 'windows_arm64_x64_php_opcache_isolated',
                'message' => 'CLI OPcache and JIT are intentionally disabled for x64 PHP emulation on Windows ARM64 after reproduced native access violations.',
                'action' => 'Use a native ARM64 PHP runtime to restore bytecode OPcache, then benchmark JIT separately.',
            ];
        } elseif (empty($extensions['opcache']) || (string)($data['opcache_enable_cli'] ?? '') !== '1') {
            $findings[] = [
                'level' => 'info',
                'code' => 'opcache_cli_disabled',
                'message' => 'OPcache CLI is not fully enabled for long-running WLS processes.',
                'action' => 'Set opcache.enable_cli=1 in php.ini.',
            ];
        }
        $jit = \strtolower(\trim((string)($data['opcache_jit'] ?? '')));
        $jitBuffer = \strtolower(\trim((string)($data['opcache_jit_buffer_size'] ?? '')));
        if (!$runtimeSafetyRequired
            && !empty($extensions['opcache'])
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
