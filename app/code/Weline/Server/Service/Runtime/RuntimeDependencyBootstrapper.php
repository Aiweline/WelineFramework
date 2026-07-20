<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\App\Env;

/**
 * Validates runtime dependencies before WLS creates any managed process.
 *
 * Normal server:start never modifies PHP or the host. Installation is an
 * explicit operator action through --install-deps; Windows still refuses
 * unverified cross-version DLL downloads.
 */
final class RuntimeDependencyBootstrapper
{
    public const REENTRY_ENV = 'WLS_RUNTIME_DEPENDENCY_BOOTSTRAPPED';

    private const REENTRY_ARG = 'wls-runtime-dependency-reentry';

    private const INSTALL_TIMEOUT_SECONDS = 900;
    private const RELAUNCH_TIMEOUT_SECONDS = 1800;
    private const MAX_CAPTURE_BYTES = 1048576;

    /**
     * @param array<int|string, mixed> $args
     * @return array{status:string,message:string,restart_required:bool,output?:string}
     */
    public function ensureOptimalRuntime(
        array $args,
        RequestedTopology $requestedTopology,
        EffectiveTopology $effectiveTopology,
        bool $sslRequired = false,
    ): array
    {
        $posix = \in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true);
        $direct = $effectiveTopology->isDirect();
        $reentry = $this->isReentry($args);

        if ($direct && !$posix) {
            return $this->result(
                'failed',
                (string)__('当前平台无法履行 Direct 拓扑依赖契约；已拒绝启动。')
            );
        }
        if ($requestedTopology === RequestedTopology::Dispatcher && $direct) {
            return $this->result(
                'failed',
                (string)__('Dispatcher 请求与 Direct 有效拓扑冲突；已拒绝静默改写拓扑。')
            );
        }

        $installRequested = $this->hasFlag($args, ['install-deps', 'install-dependencies']);
        $installDisabled = $this->hasFlag($args, ['no-auto-deps', 'no-auto-dependencies']);
        if ($installRequested && $installDisabled) {
            return $this->result(
                'failed',
                (string)__('不能同时使用 --install-deps 与 --no-auto-deps。')
            );
        }

        $opensslReady = !$sslRequired || $this->canUseOpenSsl();
        if ($direct && $this->canUseSockets() && $this->canUseEvent() && $opensslReady) {
            return $this->result(
                'ready',
                $sslRequired
                    ? (string)__('sockets、OpenSSL 与 ext-event 已由当前 PHP 二进制加载且可用。')
                    : (string)__('sockets 与 ext-event 已由当前 PHP 二进制加载且可用。')
            );
        }
        if (!$direct && $this->canUseEvent() && $opensslReady) {
            return $this->result(
                'ready',
                $sslRequired
                    ? (string)__('OpenSSL 与 ext-event 已由当前 PHP 二进制加载且可用。')
                    : (string)__('ext-event 已由当前 PHP 二进制加载且可用。')
            );
        }

        if (!$installRequested || $installDisabled) {
            if (!$opensslReady) {
                return $this->result(
                    'failed',
                    (string)__('HTTPS 需要当前 PHP 二进制预先加载 OpenSSL；server:start 默认不会安装或编译依赖。')
                );
            }
            if ($direct) {
                $missing = [];
                if (!$this->canUseSockets()) {
                    $missing[] = 'sockets';
                }
                if (!$this->canUseEvent()) {
                    $missing[] = 'ext-event';
                }
                return $this->result(
                    'failed',
                    (string)__('Direct 运行时缺少预装依赖：%{1}。普通启动不会现场安装或编译；请先安装，或显式使用 --install-deps。', [
                        \implode(', ', $missing),
                    ])
                );
            }
            return $this->result(
                'platform_optimal',
                (string)__('server:start 仅完成依赖探测；Dispatcher 将使用当前 PHP 已有能力和有界 stream_select。')
            );
        }

        if (PHP_OS_FAMILY === 'Windows') {
            if (!$opensslReady) {
                if ($reentry) {
                    return $this->result(
                        'failed',
                        (string)__('本次显式 OpenSSL 安装后当前 Windows PHP 二进制仍无法加载该扩展；已拒绝重复安装循环。')
                    );
                }
                $lock = $this->acquireInstallLock();
                if ($lock === null) {
                    return $this->result('failed', (string)__('无法获取 WLS 运行时依赖安装锁；HTTPS 已拒绝启动。'));
                }
                try {
                    if (!$this->freshPhpCanUseOpenSsl()) {
                        $install = $this->installExtension('openssl');
                        if (!$install['success'] || !$this->freshPhpCanUseOpenSsl()) {
                            return [
                                'status' => 'failed',
                                'message' => (string)__('本次显式 OpenSSL 安装未能为当前 Windows PHP ABI（%{1}）生成可用扩展。', [
                                    $this->describeCurrentPhpAbi(),
                                ]),
                                'restart_required' => false,
                                'output' => $this->tail((string)$install['output']),
                            ];
                        }
                    }

                    return $this->afterSuccessfulInstall(
                        (string)__('Windows OpenSSL 已由当前 PHP ABI 实际加载验证。'),
                        false,
                        true,
                        false,
                    );
                } finally {
                    @\flock($lock, \LOCK_UN);
                    @\fclose($lock);
                }
            }

            // DLL “存在”不等于 ABI 可用。只信任当前 PHP_BINARY 的独立
            // 子进程能实际加载 EventBase/Event 的结果，避免残留、TS/NTS、
            // x86/x64 或 PHP minor 版本不匹配的 php_event.dll 拖垮整个 WLS。
            if ($this->freshPhpCanUseEvent()) {
                if ($reentry && !$this->canUseEvent()) {
                    return $this->result(
                        'platform_optimal',
                        (string)__('独立 PHP 探针可加载 event，但依赖重入进程仍未加载；为避免重复重启，WLS 将使用稳定的 Dispatcher + stream/select 运行时。')
                    );
                }
                return $this->afterSuccessfulInstall(
                    (string)__('Windows event 运行时已由当前 PHP 二进制实际加载验证。'),
                    false,
                    $sslRequired,
                    true,
                );
            }

            if ($reentry) {
                return $this->result(
                    'platform_optimal',
                    (string)__('官方 event 包安装后仍无法由当前 Windows PHP ABI（%{1}）加载；WLS 将使用稳定的 Dispatcher + stream/select 运行时，且不会重复安装。', [
                        $this->describeCurrentPhpAbi(),
                    ])
                );
            }

            $lock = $this->acquireInstallLock();
            if ($lock === null) {
                return $this->result(
                    'platform_optimal',
                    (string)__('无法获取 Windows event 安装锁；WLS 将使用稳定的 Dispatcher + stream/select 运行时。')
                );
            }

            try {
                if ($this->freshPhpCanUseEvent()) {
                    return $this->afterSuccessfulInstall(
                        (string)__('其他 WLS 启动进程已安装并验证 Windows event 运行时。'),
                        false,
                        $sslRequired,
                        true,
                    );
                }

                $install = $this->installExtension('event');
                if ($install['success'] && $this->freshPhpCanUseEvent()) {
                    return $this->afterSuccessfulInstall(
                        (string)__('Windows event 已从官方 PECL 精确 ABI 包安装，并由当前 PHP 二进制实际加载验证。'),
                        false,
                        $sslRequired,
                        true,
                    );
                }

                return [
                    'status' => 'platform_optimal',
                    'message' => (string)__('当前 Windows PHP ABI（%{1}）没有可实际加载的可信 event DLL；WLS 将使用稳定的 Dispatcher + stream/select 运行时，不会加载未验证 DLL。', [
                        $this->describeCurrentPhpAbi(),
                    ]),
                    'restart_required' => false,
                    'output' => $this->tail((string)$install['output']),
                ];
            } finally {
                @\flock($lock, \LOCK_UN);
                @\fclose($lock);
            }
        }

        if (!\in_array(PHP_OS_FAMILY, ['Darwin', 'Linux', 'Windows'], true)) {
            if (!$opensslReady) {
                return $this->result(
                    'failed',
                    (string)__('HTTPS 需要当前 PHP 二进制加载 OpenSSL；当前平台不支持通过 --install-deps 安全安装。')
                );
            }
            return $this->result(
                'platform_optimal',
                (string)__('当前平台不支持通过 --install-deps 安全安装 ext-event；WLS 将使用兼容运行时。')
            );
        }

        if ($reentry) {
            return ($direct || !$opensslReady)
                ? $this->result(
                    'failed',
                    (string)__('本次显式依赖安装后 sockets/OpenSSL/ext-event 仍不可用；已拒绝重复安装循环。')
                )
                : $this->result(
                    'platform_optimal',
                    (string)__('Dispatcher 的 ext-event 安装后仍不可用；保持显式 Dispatcher 并使用有界 stream_select，不改写拓扑。')
                );
        }

        $lock = $this->acquireInstallLock();
        if ($lock === null) {
            return ($direct || !$opensslReady)
                ? $this->result(
                    'failed',
                    $direct
                        ? (string)__('无法获取 WLS 运行时依赖安装锁；Direct 已拒绝启动。')
                        : (string)__('无法获取 WLS 运行时依赖安装锁；HTTPS/OpenSSL 已拒绝启动。')
                )
                : $this->result(
                    'platform_optimal',
                    (string)__('Dispatcher 无法获取可选 ext-event 安装锁；继续使用有界 stream_select。')
                );
        }

        try {
            $freshSocketsReady = !$direct || $this->freshPhpCanUseSockets();
            $freshOpenSslReady = !$sslRequired || $this->freshPhpCanUseOpenSsl();
            $freshEventReady = $this->freshPhpCanUseEvent();
            if ($freshSocketsReady && $freshOpenSslReady && $freshEventReady) {
                return $this->afterSuccessfulInstall(
                    $direct
                        ? (string)__('其他 WLS 启动进程已安装 sockets/OpenSSL/ext-event。')
                        : (string)__('其他 WLS 启动进程已安装 HTTPS/OpenSSL 与 Dispatcher 可选 ext-event。'),
                    $direct,
                    $sslRequired,
                    true,
                );
            }

            $dependencies = [];
            if ($direct && !$freshSocketsReady) {
                $dependencies[] = 'sockets';
            }
            if (!$freshOpenSslReady) {
                $dependencies[] = 'openssl';
            }
            if (!$freshEventReady) {
                $dependencies[] = 'event';
            }

            $requiredDependencyInstalled = false;
            foreach ($dependencies as $dependency) {
                $install = $this->installExtension($dependency);
                $verified = match ($dependency) {
                    'event' => $this->freshPhpCanUseEvent(),
                    'openssl' => $this->freshPhpCanUseOpenSsl(),
                    default => $this->freshPhpCanUseSockets(),
                };
                if (!$install['success'] || !$verified) {
                    $detail = $this->tail((string)$install['output']);
                    if (!$direct && $dependency === 'event') {
                        if ($requiredDependencyInstalled) {
                            $installed = $this->afterSuccessfulInstall(
                                (string)__('HTTPS 必需依赖已安装验证；Dispatcher 可选 ext-event 安装失败，重入后将使用有界 stream_select。'),
                                false,
                                $sslRequired,
                                false,
                            );
                            $installed['output'] = $detail;
                            return $installed;
                        }
                        return [
                            'status' => 'platform_optimal',
                            'message' => (string)__('%{1} 本次显式安装未能为 PHP %{2} 生成可用扩展；保持显式 Dispatcher 并使用有界 stream_select。', [$dependency, PHP_BINARY]),
                            'restart_required' => false,
                            'output' => $detail,
                        ];
                    }
                    return [
                        'status' => 'failed',
                        'message' => (string)__('%{1} 本次显式安装未能为 PHP %{2} 生成可用扩展。', [$dependency, PHP_BINARY]),
                        'restart_required' => false,
                        'output' => $detail,
                    ];
                }
                if ($dependency !== 'event') {
                    $requiredDependencyInstalled = true;
                }
            }

            return $this->afterSuccessfulInstall(
                $direct
                    ? (string)__('sockets/OpenSSL/ext-event 已使用当前 PHP 安装并验证。')
                    : (string)__('HTTPS/OpenSSL 与 Dispatcher 可选 ext-event 已使用当前 PHP 安装并验证。'),
                $direct,
                $sslRequired,
                true,
            );
        } finally {
            @\flock($lock, \LOCK_UN);
            @\fclose($lock);
        }
    }

    public function relaunchCurrentStartCommand(): int
    {
        $argv = \array_values(\array_map('strval', $_SERVER['argv'] ?? []));
        if ($argv === [] || !\in_array('server:start', $argv, true)) {
            return 125;
        }

        $command = [
            PHP_BINARY,
            ...$this->phpConfigurationArguments(),
            $this->resolveBinWPath(),
            'server:start',
            ...\array_slice($argv, 2),
        ];

        $previous = \getenv(self::REENTRY_ENV);
        \putenv(self::REENTRY_ENV . '=1');
        try {
            $result = $this->runProcess($command, self::RELAUNCH_TIMEOUT_SECONDS, true);
            return (int)$result['exit_code'];
        } finally {
            if ($previous === false) {
                \putenv(self::REENTRY_ENV);
            } else {
                \putenv(self::REENTRY_ENV . '=' . $previous);
            }
        }
    }

    /**
     * @return array{status:string,message:string,restart_required:bool}
     */
    private function afterSuccessfulInstall(
        string $message,
        bool $requireSockets,
        bool $requireOpenSsl,
        bool $requireEvent,
    ): array
    {
        if ((!$requireSockets || $this->canUseSockets())
            && (!$requireOpenSsl || $this->canUseOpenSsl())
            && (!$requireEvent || $this->loadEventIntoCurrentProcess())
        ) {
            return $this->result('installed', $message, false);
        }

        return $this->result('installed', $message, true);
    }

    private function canUseEvent(): bool
    {
        return \extension_loaded('event')
            && \class_exists(\EventBase::class)
            && \class_exists(\Event::class);
    }

    private function canUseSockets(): bool
    {
        return \extension_loaded('sockets')
            && \function_exists('socket_create')
            && \defined('SO_REUSEPORT');
    }

    private function canUseOpenSsl(): bool
    {
        return \extension_loaded('openssl')
            && \function_exists('openssl_x509_parse')
            && \defined('OPENSSL_VERSION_TEXT');
    }

    private function freshPhpCanUseSockets(): bool
    {
        $probe = $this->runProcess([
            PHP_BINARY,
            '-r',
            'exit(extension_loaded("sockets") && function_exists("socket_create") && defined("SO_REUSEPORT") ? 0 : 1);',
        ], 15, false);

        return $probe['success'];
    }

    private function freshPhpCanUseEvent(): bool
    {
        $probe = $this->runProcess([
            PHP_BINARY,
            '-r',
            'exit(extension_loaded("event") && class_exists("EventBase") && class_exists("Event") ? 0 : 1);',
        ], 15, false);

        return $probe['success'];
    }

    private function freshPhpCanUseOpenSsl(): bool
    {
        $probe = $this->runProcess([
            PHP_BINARY,
            '-r',
            'exit(extension_loaded("openssl") && function_exists("openssl_x509_parse") && defined("OPENSSL_VERSION_TEXT") ? 0 : 1);',
        ], 15, false);

        return $probe['success'];
    }

    private function loadEventIntoCurrentProcess(): bool
    {
        if ($this->canUseEvent() || !\function_exists('dl')) {
            return $this->canUseEvent();
        }

        $library = PHP_OS_FAMILY === 'Windows' ? 'php_event.dll' : 'event.' . PHP_SHLIB_SUFFIX;
        @\dl($library);
        return $this->canUseEvent();
    }

    /**
     * @return array{success:bool,exit_code:int,output:string,timed_out:bool}
     */
    private function installExtension(string $extension): array
    {
        return $this->runProcess([
            PHP_BINARY,
            $this->resolveBinWPath(),
            'env:install',
            $extension,
            '-y',
        ], self::INSTALL_TIMEOUT_SECONDS, true);
    }

    private function describeCurrentPhpAbi(): string
    {
        $threadSafety = \defined('PHP_ZTS') && PHP_ZTS ? 'TS' : 'NTS';
        $architecture = PHP_INT_SIZE >= 8 ? 'x64' : 'x86';
        $debug = \defined('PHP_DEBUG') && PHP_DEBUG ? 'debug' : 'release';

        return 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION
            . ' ' . $architecture . ' ' . $threadSafety . ' ' . $debug;
    }

    /**
     * @return resource|null
     */
    private function acquireInstallLock(): mixed
    {
        $directory = Env::VAR_DIR . 'server' . DS . 'locks';
        if (!\is_dir($directory) && !@\mkdir($directory, 0755, true) && !\is_dir($directory)) {
            return null;
        }

        $handle = @\fopen($directory . DS . 'runtime_dependency_install.lock', 'c+');
        if (!\is_resource($handle) || !@\flock($handle, \LOCK_EX)) {
            if (\is_resource($handle)) {
                @\fclose($handle);
            }
            return null;
        }

        return $handle;
    }

    private function resolveBinWPath(): string
    {
        $candidate = \defined('BP') ? BP . 'bin' . DS . 'w' : '';
        return $candidate !== '' && \is_file($candidate) ? $candidate : 'bin/w';
    }

    /** @return list<string> */
    private function phpConfigurationArguments(): array
    {
        $arguments = [];
        $loadedIni = \php_ini_loaded_file();
        if (\is_string($loadedIni) && $loadedIni !== '' && \is_file($loadedIni)) {
            $arguments[] = '-c';
            $arguments[] = $loadedIni;
        }
        if (\extension_loaded('FFI')) {
            $ffiEnable = \ini_get('ffi.enable');
            if (\is_string($ffiEnable) && $ffiEnable !== '') {
                $arguments[] = '-d';
                $arguments[] = 'ffi.enable=' . $ffiEnable;
            }
        }
        return $arguments;
    }

    /**
     * @param list<string> $command
     * @return array{success:bool,exit_code:int,output:string,timed_out:bool}
     */
    private function runProcess(array $command, int $timeoutSeconds, bool $streamOutput): array
    {
        if (!\function_exists('proc_open')) {
            return ['success' => false, 'exit_code' => 127, 'output' => (string)__('proc_open 不可用。'), 'timed_out' => false];
        }

        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['file', $nullDevice, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @\proc_open($command, $descriptors, $pipes, \defined('BP') ? BP : null, null, [
            'bypass_shell' => true,
        ]);
        if (!\is_resource($process)) {
            return ['success' => false, 'exit_code' => 126, 'output' => (string)__('无法启动依赖安装子进程。'), 'timed_out' => false];
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && \is_resource($pipes[$index])) {
                \stream_set_blocking($pipes[$index], false);
            }
        }

        $startedAt = \microtime(true);
        $output = '';
        $timedOut = false;
        $lastStatus = ['running' => true, 'exitcode' => -1];

        while (true) {
            $lastStatus = \proc_get_status($process);
            $read = [];
            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && \is_resource($pipes[$index]) && !\feof($pipes[$index])) {
                    $read[] = $pipes[$index];
                }
            }

            if ($read !== []) {
                $write = null;
                $except = null;
                @\stream_select($read, $write, $except, 0, 200000);
                foreach ($read as $pipe) {
                    $chunk = (string)(\fread($pipe, 8192) ?: '');
                    if ($chunk === '') {
                        continue;
                    }
                    if ($streamOutput) {
                        echo $chunk;
                    }
                    if (\strlen($output) < self::MAX_CAPTURE_BYTES) {
                        $output .= \substr($chunk, 0, self::MAX_CAPTURE_BYTES - \strlen($output));
                    }
                }
            }

            if (!($lastStatus['running'] ?? false)) {
                break;
            }
            if ((\microtime(true) - $startedAt) >= $timeoutSeconds) {
                $timedOut = true;
                @\proc_terminate($process);
                break;
            }
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && \is_resource($pipes[$index])) {
                $chunk = (string)(\stream_get_contents($pipes[$index]) ?: '');
                if ($streamOutput && $chunk !== '') {
                    echo $chunk;
                }
                if (\strlen($output) < self::MAX_CAPTURE_BYTES) {
                    $output .= \substr($chunk, 0, self::MAX_CAPTURE_BYTES - \strlen($output));
                }
                @\fclose($pipes[$index]);
            }
        }

        $closeCode = @\proc_close($process);
        $exitCode = $timedOut
            ? 124
            : ((int)($lastStatus['exitcode'] ?? -1) >= 0 ? (int)$lastStatus['exitcode'] : (int)$closeCode);

        return [
            'success' => !$timedOut && $exitCode === 0,
            'exit_code' => $exitCode,
            'output' => $output,
            'timed_out' => $timedOut,
        ];
    }

    /**
     * @param array<int|string, mixed> $args
     * @param list<string> $names
     */
    private function hasFlag(array $args, array $names): bool
    {
        foreach ($names as $name) {
            if (isset($args[$name])) {
                return true;
            }
        }
        foreach ($args as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $value = \ltrim(\strtolower((string)$value), '-');
            if (\in_array($value, $names, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The CLI marker is authoritative because some Windows launch chains do
     * not preserve a process-local putenv() value across every wrapper. The
     * environment marker remains for compatibility with older relaunches.
     *
     * @param array<int|string, mixed> $args
     */
    private function isReentry(array $args): bool
    {
        return (string)\getenv(self::REENTRY_ENV) === '1'
            || $this->hasFlag($args, [self::REENTRY_ARG]);
    }

    /**
     * @return array{status:string,message:string,restart_required:bool}
     */
    private function result(string $status, string $message, bool $restartRequired = false): array
    {
        return ['status' => $status, 'message' => $message, 'restart_required' => $restartRequired];
    }

    private function tail(string $output): string
    {
        $output = \trim($output);
        if ($output === '') {
            return '';
        }
        return \strlen($output) <= 2000 ? $output : \substr($output, -2000);
    }
}
