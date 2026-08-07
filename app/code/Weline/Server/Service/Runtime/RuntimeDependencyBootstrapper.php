<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\App\Env;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;

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
    private const MAX_EVENT_INI_BYTES = 65536;
    private const DEFAULT_LOCK_WAIT_SECONDS = 30.0;
    private const MAX_LOCK_WAIT_SECONDS = 300.0;
    private const LOCK_RETRY_MICROSECONDS = 10_000;

    private readonly float $lockWaitSeconds;

    public function __construct(
        float $lockWaitSeconds = self::DEFAULT_LOCK_WAIT_SECONDS,
    ) {
        if (!\is_finite($lockWaitSeconds)
            || $lockWaitSeconds <= 0.0
            || $lockWaitSeconds > self::MAX_LOCK_WAIT_SECONDS
        ) {
            throw new \InvalidArgumentException(
                'Runtime dependency lock wait must be within (0, 300] seconds.'
            );
        }
        $this->lockWaitSeconds = $lockWaitSeconds;
    }

    /**
     * @param array<int|string, mixed> $args
     * @return array{status:string,message:string,restart_required:bool,output?:string}
     */
    public function ensureOptimalRuntime(
        array $args,
        RequestedTopology $requestedTopology,
        EffectiveTopology $effectiveTopology,
        bool $sslRequired = false,
        bool $reusePortRequired = false,
    ): array
    {
        $posix = \in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true);
        $direct = $effectiveTopology->isDirect();
        $reentry = $this->isReentry($args);
        $installRequested = $this->hasFlag($args, ['install-deps', 'install-dependencies']);
        $installDisabled = $this->hasFlag($args, ['no-auto-deps', 'no-auto-dependencies']);

        if ($installRequested && $installDisabled) {
            return $this->result(
                'failed',
                (string)__('不能同时使用 --install-deps 与 --no-auto-deps。')
            );
        }

        if ($requestedTopology === RequestedTopology::Dispatcher && $direct) {
            return $this->result(
                'failed',
                (string)__('Dispatcher 请求与 Direct 有效拓扑冲突；已拒绝静默改写拓扑。')
            );
        }
        if ($direct && PHP_OS_FAMILY === 'Windows') {
            return $this->result(
                'platform_optimal',
                (string)__(
                    'Windows Direct 使用 Nginx 直连的独立 Worker 回环端口与内置 stream_select；不需要安装或编译 ext-event。'
                )
            );
        }
        if ($direct && !$posix) {
            return $this->result(
                'failed',
                (string)__('当前平台无法履行 Direct 拓扑依赖契约；已拒绝启动。')
            );
        }
        if ($direct && !$reusePortRequired && !$this->canUseSharedFdPrimitives()) {
            return $this->result(
                'failed',
                (string)__('Direct shared_fd 需要 proc_open/proc_get_status、POSIX 进程控制和可枚举的继承描述符；当前 PHP/系统不满足。')
            );
        }

        if ($installRequested && $posix && $this->canUseEvent()) {
            $eventRecovery = $this->configureEventExtensionForRuntime();
            if (($eventRecovery['status'] ?? 'failed') !== 'ready') {
                return [
                    'status' => 'failed',
                    'message' => (string)($eventRecovery['message']
                        ?? 'ext-event ini 崩溃恢复失败。'),
                    'restart_required' => false,
                    'output' => (string)($eventRecovery['diagnostics']
                        ?? $eventRecovery['message']
                        ?? ''),
                ];
            }
        }

        $opensslReady = !$sslRequired || $this->canUseOpenSsl();
        if ($direct
            && (!$reusePortRequired || $this->canUseSockets())
            && $this->canUseEvent()
            && $opensslReady
        ) {
            return $this->result(
                'ready',
                $reusePortRequired
                    ? ($sslRequired
                        ? (string)__('sockets、OpenSSL 与 ext-event 已由当前 PHP 二进制加载且可用。')
                        : (string)__('sockets 与 ext-event 已由当前 PHP 二进制加载且可用。'))
                    : ($sslRequired
                        ? (string)__('OpenSSL、ext-event 与 POSIX shared_fd 启动原语已可用。')
                        : (string)__('ext-event 与 POSIX shared_fd 启动原语已可用。'))
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
                if ($reusePortRequired && !$this->canUseSockets()) {
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
                $installLock = $this->acquireInstallLock();
                $lock = $installLock['handle'];
                if ($lock === null) {
                    return $this->result(
                        'failed',
                        (string)__('无法获取 WLS 运行时依赖安装锁；HTTPS 已拒绝启动。')
                            . ' ' . $installLock['error'],
                    );
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

            $installLock = $this->acquireInstallLock();
            $lock = $installLock['handle'];
            if ($lock === null) {
                return $this->result(
                    'platform_optimal',
                    (string)__('无法获取 Windows event 安装锁；WLS 将使用稳定的 Dispatcher + stream/select 运行时。')
                        . ' ' . $installLock['error'],
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

        if (!$reentry && $posix && !$this->canUseEvent()) {
            $eventConfiguration = $this->configureEventExtensionForRuntime();
            $eventConfigurationStatus = (string)($eventConfiguration['status'] ?? 'failed');
            if ($eventConfigurationStatus === 'ready') {
                return $this->afterSuccessfulInstall(
                    (string)__('ext-event 已在当前 PHP CLI 扫描目录中原子启用并由新 PHP 子进程验证。'),
                    $reusePortRequired,
                    $sslRequired,
                    true,
                );
            }
            if ($eventConfigurationStatus === 'failed') {
                return [
                    'status' => 'failed',
                    'message' => (string)__('ext-event 配置自愈失败；未修改主 php.ini，已拒绝继续。'),
                    'restart_required' => false,
                    'output' => (string)($eventConfiguration['diagnostics'] ?? $eventConfiguration['message'] ?? ''),
                ];
            }
        }

        if ($reentry) {
            return ($direct || !$opensslReady)
                ? $this->result(
                    'failed',
                    $reusePortRequired
                        ? (string)__('本次显式依赖安装后 sockets/OpenSSL/ext-event 仍不可用；已拒绝重复安装循环。')
                        : (string)__('本次显式依赖安装后 OpenSSL/ext-event 仍不可用；已拒绝重复安装循环。')
                )
                : $this->result(
                    'platform_optimal',
                    (string)__('Dispatcher 的 ext-event 安装后仍不可用；保持显式 Dispatcher 并使用有界 stream_select，不改写拓扑。')
                );
        }

        $installLock = $this->acquireInstallLock();
        $lock = $installLock['handle'];
        if ($lock === null) {
            return ($direct || !$opensslReady)
                ? $this->result(
                    'failed',
                    ($direct
                        ? (string)__('无法获取 WLS 运行时依赖安装锁；Direct 已拒绝启动。')
                        : (string)__('无法获取 WLS 运行时依赖安装锁；HTTPS/OpenSSL 已拒绝启动。'))
                        . ' ' . $installLock['error'],
                )
                : $this->result(
                    'platform_optimal',
                    (string)__('Dispatcher 无法获取可选 ext-event 安装锁；继续使用有界 stream_select。')
                        . ' ' . $installLock['error'],
                );
        }

        try {
            $freshSocketsReady = !$reusePortRequired || $this->freshPhpCanUseSockets();
            $freshOpenSslReady = !$sslRequired || $this->freshPhpCanUseOpenSsl();
            $freshEventReady = $this->freshPhpCanUseEvent();
            if ($freshSocketsReady && $freshOpenSslReady && $freshEventReady) {
                return $this->afterSuccessfulInstall(
                    $direct
                        ? ($reusePortRequired
                            ? (string)__('其他 WLS 启动进程已安装 sockets/OpenSSL/ext-event。')
                            : (string)__('其他 WLS 启动进程已安装 OpenSSL/ext-event。'))
                        : (string)__('其他 WLS 启动进程已安装 HTTPS/OpenSSL 与 Dispatcher 可选 ext-event。'),
                    $reusePortRequired,
                    $sslRequired,
                    true,
                );
            }

            $dependencies = [];
            if ($reusePortRequired && !$freshSocketsReady) {
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
                if ($dependency === 'event' && ($install['success'] ?? false) === true) {
                    $eventConfiguration = $this->configureEventExtensionForRuntime();
                    if (($eventConfiguration['status'] ?? 'failed') !== 'ready') {
                        $install['success'] = false;
                        $install['output'] = \trim(
                            (string)($install['output'] ?? '') . "\n"
                            . (string)($eventConfiguration['message'] ?? 'ext-event configuration verification failed') . "\n"
                            . (string)($eventConfiguration['diagnostics'] ?? '')
                        );
                    }
                }
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
                    ? ($reusePortRequired
                        ? (string)__('sockets/OpenSSL/ext-event 已使用当前 PHP 安装并验证。')
                        : (string)__('OpenSSL/ext-event 已使用当前 PHP 安装并验证。'))
                    : (string)__('HTTPS/OpenSSL 与 Dispatcher 可选 ext-event 已使用当前 PHP 安装并验证。'),
                $reusePortRequired,
                $sslRequired,
                true,
            );
        } finally {
            @\flock($lock, \LOCK_UN);
            @\fclose($lock);
        }
    }

    private const EVENT_INI_NAME = '99-weline-event.ini';
    private const EVENT_LOCK_NAME = '.weline-event.ini.lock';
    private const EVENT_MANAGED_MARKER = '; Managed by WLS 2.0 explicit --install-deps';
    private const EVENT_CHILD_TIMEOUT_SECONDS = 15;
    private const EVENT_MAX_CAPTURE_BYTES = 262144;
    private const EVENT_STAGING_PREFIX = '.wls-event-staging-';
    private const EVENT_BACKUP_PREFIX = '.wls-event-backup-';
    private const EVENT_LEGACY_TEMP_PREFIX = '.wls-event-';
    private const EVENT_MAX_RECOVERY_FILES_PER_KIND = 8;
    private const EVENT_MAX_RECOVERY_DIRECTORY_ENTRIES = 256;

    /**
     * Atomically enables an installed ext-event for the current POSIX CLI.
     * Runtime overrides are an internal test seam; production callers omit them.
     *
     * @param array<string, mixed>|null $runtimeOverride
     * @return array{status:'ready'|'absent'|'failed',changed:bool,message:string,diagnostics:string,target:?string}
     * @internal
     */
    public function configureEventExtensionForRuntime(
        ?array $runtimeOverride = null,
        ?\Closure $childProbeOverride = null,
    ): array {
        $runtime = $this->eventRuntimeSnapshot($runtimeOverride);
        $diagnostics = $this->eventDiagnostics($runtime);

        if (($runtime['os_family'] ?? '') !== 'Darwin' && ($runtime['os_family'] ?? '') !== 'Linux') {
            return $this->eventConfigurationResult('failed', false, 'ext-event 自动配置只允许 POSIX CLI。', $diagnostics);
        }

        if (($runtime['event_loaded'] ?? false) === true
            && ($runtime['event_base_available'] ?? false) === true
            && ($runtime['event_buffer_available'] ?? false) === true
        ) {
            $recovery = $this->recoverLoadedEventPublications($runtime);
            if (!$recovery['ok']) {
                return $this->eventConfigurationResult(
                    'failed',
                    false,
                    '已加载 ext-event，但 ini 崩溃恢复制品不安全或无法回收。',
                    $diagnostics,
                    $recovery['target'],
                );
            }
            return $this->eventConfigurationResult('ready', false, 'ext-event 已加载，无需修改配置。', $diagnostics);
        }

        $extensionBinary = (string)($runtime['extension_binary'] ?? '');
        if ($extensionBinary === '' || !\is_file($extensionBinary)) {
            return $this->eventConfigurationResult('absent', false, '当前 extension_dir 中尚无 event.so。', $diagnostics);
        }
        if (!$this->isSafeEventRegularFile($extensionBinary)) {
            return $this->eventConfigurationResult('failed', false, 'event.so 路径包含符号链接、路径穿越或不是普通文件。', $diagnostics);
        }

        $scanDirectory = $this->selectEventScanDirectory((array)($runtime['scan_dirs'] ?? []));
        if ($scanDirectory === null) {
            return $this->eventConfigurationResult('failed', false, '当前 PHP CLI 没有可安全写入的实际 additional ini scan dir。', $diagnostics);
        }

        $target = $scanDirectory . DIRECTORY_SEPARATOR . self::EVENT_INI_NAME;
        $lockPath = $scanDirectory . DIRECTORY_SEPARATOR . self::EVENT_LOCK_NAME;
        if (\is_link($target) || (\file_exists($target) && !\is_file($target))) {
            return $this->eventConfigurationResult('failed', false, 'WLS ext-event ini 目标不是安全普通文件。', $diagnostics, $target);
        }
        $eventLock = $this->acquireExclusiveRuntimeLock(
            $lockPath,
            'WLS ext-event 配置锁',
        );
        $lock = $eventLock['handle'];
        if ($lock === null) {
            return $this->eventConfigurationResult(
                'failed',
                false,
                $eventLock['error'],
                $diagnostics,
                $target,
            );
        }

        try {
            if (!$this->openedEventFileMatchesPath($lock, $lockPath)) {
                return $this->eventConfigurationResult('failed', false, 'WLS ext-event 配置锁在打开期间发生身份变化。', $diagnostics, $target);
            }
            if (!$this->recoverEventIniPublication($target)) {
                return $this->eventConfigurationResult('failed', false, 'WLS ext-event ini 崩溃恢复制品不安全或已超出固定配额。', $diagnostics, $target);
            }
            if (\is_link($target) || (\file_exists($target) && !\is_file($target))) {
                return $this->eventConfigurationResult('failed', false, 'WLS ext-event ini 目标在加锁后不再安全。', $diagnostics, $target);
            }

            $previousExists = \is_file($target);
            $previousContent = $previousExists
                ? $this->readBoundedEventIni($target)
                : false;
            if ($previousExists && !\is_string($previousContent)) {
                return $this->eventConfigurationResult('failed', false, '无法读取既有 WLS ext-event ini。', $diagnostics, $target);
            }
            if ($previousExists && !\str_contains((string)$previousContent, self::EVENT_MANAGED_MARKER)) {
                return $this->eventConfigurationResult('failed', false, '同名 ini 不属于 WLS，拒绝覆盖。', $diagnostics, $target);
            }

            $content = self::EVENT_MANAGED_MARKER . "\nextension=event\n";
            $changed = !$previousExists || !\hash_equals((string)$previousContent, $content);
            if ($changed && !$this->atomicPublishEventIni($target, $content)) {
                return $this->eventConfigurationResult('failed', false, '无法原子发布 WLS ext-event ini。', $diagnostics, $target);
            }

            $probe = $this->probeFreshEventChild(
                (string)($runtime['php_binary'] ?? PHP_BINARY),
                $target,
                $childProbeOverride,
            );
            if (!$this->eventProbePassed($probe, $target)) {
                $rollbackOk = true;
                if ($changed) {
                    $rollbackOk = $previousExists
                        ? $this->atomicPublishEventIni($target, (string)$previousContent)
                        : $this->removePublishedEventIni($target);
                }
                $probeDetail = \trim((string)($probe['stderr'] ?? '') . ' ' . (string)($probe['output'] ?? ''));
                $failureDiagnostics = $diagnostics
                    . '; child_exit=' . (string)($probe['exit_code'] ?? -1)
                    . '; child=' . ($probeDetail !== '' ? $probeDetail : 'event/classes/scanned-file verification failed')
                    . '; rollback=' . ($rollbackOk ? 'ok' : 'failed');
                return $this->eventConfigurationResult('failed', false, '新 PHP 子进程未通过 ext-event 配置验证，已拒绝发布。', $failureDiagnostics, $target);
            }

            return $this->eventConfigurationResult('ready', $changed, 'ext-event 已由独立 ini 启用并通过新 PHP 子进程验证。', $diagnostics, $target);
        } finally {
            @\flock($lock, \LOCK_UN);
            @\fclose($lock);
        }
    }

    /** @param array<string, mixed>|null $runtimeOverride @return array<string, mixed> */
    private function eventRuntimeSnapshot(?array $runtimeOverride): array
    {
        if ($runtimeOverride !== null) {
            return $runtimeOverride;
        }

        $phpBinary = PHP_BINARY;
        $extensionDirectory = \rtrim((string)\ini_get('extension_dir'), '/\\');
        return [
            'os_family' => PHP_OS_FAMILY,
            'php_binary' => $phpBinary,
            'event_loaded' => \extension_loaded('event'),
            'event_base_available' => \class_exists('EventBase', false),
            'event_buffer_available' => \class_exists('EventBufferEvent', false),
            'loaded_ini' => (string)(\php_ini_loaded_file() ?: '(none)'),
            'scan_dirs' => $this->detectEventScanDirectories($phpBinary),
            'extension_dir' => $extensionDirectory,
            'extension_binary' => $extensionDirectory !== ''
                ? $extensionDirectory . DIRECTORY_SEPARATOR . 'event.so'
                : '',
        ];
    }

    /** @return list<string> */
    private function detectEventScanDirectories(string $phpBinary): array
    {
        $result = $this->runEventProcess([$phpBinary, '--ini']);
        $scanValue = '';
        if (\preg_match('/^Scan for additional \.ini files in:\s*(.+)$/mi', (string)($result['output'] ?? ''), $match)) {
            $scanValue = \trim((string)$match[1]);
        }
        if ($scanValue === '' || \strcasecmp($scanValue, '(none)') === 0) {
            $scanValue = \trim((string)(\get_cfg_var('cfg_file_scan_dir') ?: ''));
        }
        if ($scanValue === '' || \strcasecmp($scanValue, '(none)') === 0) {
            return [];
        }

        $directories = [];
        foreach (\explode(PATH_SEPARATOR, $scanValue) as $directory) {
            $directory = \rtrim(\trim($directory), '/\\');
            if ($directory !== '' && !\in_array($directory, $directories, true)) {
                $directories[] = $directory;
            }
        }
        return $directories;
    }

    /** @param list<mixed> $directories */
    private function selectEventScanDirectory(array $directories): ?string
    {
        foreach ($directories as $directory) {
            if (!\is_string($directory) || $directory === '') {
                continue;
            }
            $directory = \rtrim($directory, '/\\');
            if ($this->isSafeEventDirectory($directory) && \is_writable($directory)) {
                return $directory;
            }
        }
        return null;
    }

    private function isSafeEventDirectory(string $directory): bool
    {
        if ($directory === '' || !\str_starts_with($directory, DIRECTORY_SEPARATOR) || !\is_dir($directory)) {
            return false;
        }
        $real = \realpath($directory);
        if ($real === false || !\hash_equals($real, $directory)) {
            return false;
        }

        $current = DIRECTORY_SEPARATOR;
        foreach (\array_filter(\explode(DIRECTORY_SEPARATOR, \trim($directory, DIRECTORY_SEPARATOR)), 'strlen') as $component) {
            $current = $current === DIRECTORY_SEPARATOR
                ? $current . $component
                : $current . DIRECTORY_SEPARATOR . $component;
            if (\is_link($current)) {
                return false;
            }
        }
        return true;
    }

    private function isSafeEventRegularFile(string $file): bool
    {
        if ($file === '' || \is_link($file) || !\is_file($file)) {
            return false;
        }
        $real = \realpath($file);
        return $real !== false
            && \hash_equals($real, $file)
            && $this->isSafeEventDirectory(\dirname($file));
    }

    /** @param resource $handle */
    private function openedEventFileMatchesPath($handle, string $path): bool
    {
        if (\is_link($path) || !\is_file($path)) {
            return false;
        }
        $opened = @\fstat($handle);
        $named = @\lstat($path);
        return \is_array($opened)
            && \is_array($named)
            && (int)($opened['dev'] ?? -1) === (int)($named['dev'] ?? -2)
            && (int)($opened['ino'] ?? -1) === (int)($named['ino'] ?? -2);
    }

    private function atomicPublishEventIni(string $target, string $content): bool
    {
        $directory = \dirname($target);
        if (!$this->isSafeEventDirectory($directory)
            || \is_link($target)
            || \strlen($content) > self::MAX_EVENT_INI_BYTES
            || !\str_contains($content, self::EVENT_MANAGED_MARKER)
            || !$this->recoverEventIniPublication($target)
        ) {
            return false;
        }
        $directoryIdentity = @\lstat($directory);
        $targetBefore = $this->eventManagedTargetSnapshot($target, $directoryIdentity);
        if (!\is_array($directoryIdentity)
            || $targetBefore === false
            || ($targetBefore['exists'] && !$targetBefore['valid'])
        ) {
            return false;
        }

        $temporary = $this->allocateEventRecoveryPath(
            $directory,
            self::EVENT_STAGING_PREFIX,
            12,
        );
        if ($temporary === null) {
            return false;
        }
        $handle = @\fopen($temporary, 'x+b');
        if (!\is_resource($handle)) {
            return false;
        }
        $temporaryIdentity = null;
        $backup = null;
        $backupIdentity = null;
        try {
            $temporaryIdentity = $this->sealedEventStagingIdentity(
                $handle,
                $temporary,
                $directoryIdentity,
                $content,
            );
            if ($temporaryIdentity === null) {
                return false;
            }
            @\fclose($handle);
            $handle = null;

            $publicationArtifacts = $this->eventPublicationArtifacts($directory);
            if (!\is_array($publicationArtifacts)
                || \count($publicationArtifacts) !== 1
                || !isset($publicationArtifacts[$temporary])
                || !$this->sameEventPublicationState(
                    $temporaryIdentity,
                    $publicationArtifacts[$temporary]['identity'],
                )
                || !$this->sameEventDirectoryIdentity($directory, $directoryIdentity)
            ) {
                return false;
            }

            $currentTarget = $this->eventManagedTargetSnapshot($target, $directoryIdentity);
            if ($currentTarget === false
                || $currentTarget['exists'] !== $targetBefore['exists']
                || ($currentTarget['exists']
                    && !$this->sameEventPublicationState(
                        $targetBefore['identity'],
                        $currentTarget['identity'],
                    ))
            ) {
                return false;
            }

            if ($targetBefore['exists']) {
                $backup = $this->allocateEventRecoveryPath(
                    $directory,
                    self::EVENT_BACKUP_PREFIX,
                    8,
                );
                if ($backup === null
                    || !@\rename($target, $backup)
                ) {
                    return false;
                }
                $backupIdentity = @\lstat($backup);
                if (!\is_array($backupIdentity)
                    || !$this->sameEventPublicationState(
                        $targetBefore['identity'],
                        $backupIdentity,
                    )
                    || !$this->sameEventDirectoryIdentity($directory, $directoryIdentity)
                ) {
                    return false;
                }
            }

            if (!@\rename($temporary, $target)) {
                if (\is_string($backup)
                    && \is_array($backupIdentity)
                    && !\file_exists($target)
                    && !\is_link($target)
                    && $this->eventPathHasIdentity($backup, $backupIdentity)
                ) {
                    @\rename($backup, $target);
                }
                return false;
            }
            $temporary = '';
            $published = $this->eventManagedTargetSnapshot($target, $directoryIdentity);
            if ($published === false
                || !$published['exists']
                || !$published['valid']
                || !\hash_equals($content, $published['content'])
                || !$this->sameEventPublicationState(
                    $temporaryIdentity,
                    $published['identity'],
                )
            ) {
                return false;
            }
            if (\is_string($backup)
                && \is_array($backupIdentity)
                && !$this->removeEventRecoveryArtifact($backup, $backupIdentity)
            ) {
                return false;
            }
            $backup = null;
            return $this->sameEventDirectoryIdentity($directory, $directoryIdentity);
        } finally {
            if (\is_resource($handle)) {
                @\fclose($handle);
            }
            if ($temporary !== ''
                && \is_array($temporaryIdentity)
                && $this->eventPathHasIdentity($temporary, $temporaryIdentity)
            ) {
                @\unlink($temporary);
            }
        }
    }

    /**
     * Reconciles publication artifacts while the caller owns EVENT_LOCK_NAME.
     * A committed managed target wins. If the target is missing, one stable
     * previous-generation backup is restored before uncommitted staging and
     * legacy tempnam files are collected. Unsafe or ambiguous evidence is
     * preserved in full and the explicit install fails closed.
     */
    private function recoverEventIniPublication(string $target): bool
    {
        $directory = \dirname($target);
        if (!$this->isSafeEventDirectory($directory)) {
            return false;
        }
        $directoryIdentity = @\lstat($directory);
        if (!\is_array($directoryIdentity)) {
            return false;
        }
        $artifacts = $this->eventPublicationArtifacts($directory);
        if (!\is_array($artifacts)) {
            return false;
        }
        if ($artifacts === []) {
            return true;
        }

        $targetSnapshot = $this->eventManagedTargetSnapshot(
            $target,
            $directoryIdentity,
        );
        if ($targetSnapshot === false
            || ($targetSnapshot['exists'] && !$targetSnapshot['valid'])
        ) {
            return false;
        }
        $backups = \array_filter(
            $artifacts,
            static fn (array $artifact): bool => $artifact['kind'] === 'backup',
        );
        if (!$targetSnapshot['exists'] && \count($backups) > 1) {
            $backupDigests = [];
            foreach ($backups as $artifact) {
                $backupDigests[] = \hash('sha256', $artifact['content']);
            }
            if (\count(\array_unique($backupDigests, SORT_STRING)) !== 1) {
                return false;
            }
        }

        $rechecked = $this->eventPublicationArtifacts($directory);
        $targetRechecked = $this->eventManagedTargetSnapshot(
            $target,
            $directoryIdentity,
        );
        if (!\is_array($rechecked)
            || $targetRechecked === false
            || !$this->sameEventArtifactInventory($artifacts, $rechecked)
            || !$this->sameEventManagedTargetSnapshot(
                $targetSnapshot,
                $targetRechecked,
            )
            || !$this->sameEventDirectoryIdentity($directory, $directoryIdentity)
        ) {
            return false;
        }

        $restoredPath = null;
        if (!$targetSnapshot['exists'] && $backups !== []) {
            $restoredPath = (string)\array_key_first($backups);
            $restored = $backups[$restoredPath];
            if (\file_exists($target)
                || \is_link($target)
                || !$this->eventPathHasIdentity(
                    $restoredPath,
                    $restored['identity'],
                )
                || !@\rename($restoredPath, $target)
            ) {
                return false;
            }
            $restoredTarget = $this->eventManagedTargetSnapshot(
                $target,
                $directoryIdentity,
            );
            if ($restoredTarget === false
                || !$restoredTarget['exists']
                || !$restoredTarget['valid']
                || !$this->sameEventPublicationState(
                    $restored['identity'],
                    $restoredTarget['identity'],
                )
            ) {
                return false;
            }
        }

        foreach ($artifacts as $path => $artifact) {
            if ($path === $restoredPath) {
                continue;
            }
            if (!$this->sameEventDirectoryIdentity($directory, $directoryIdentity)
                || !$this->removeEventRecoveryArtifact(
                    $path,
                    $artifact['identity'],
                )
            ) {
                return false;
            }
        }
        return $this->sameEventDirectoryIdentity($directory, $directoryIdentity)
            && $this->eventPublicationArtifacts($directory) === [];
    }

    /**
     * A process may already have loaded event from the still-valid old target
     * when an earlier publisher crashed before switching its staging file.
     * Explicit --install-deps must therefore reconcile retained artifacts
     * before the ordinary ready return, without touching clean scan dirs.
     *
     * @param array<string,mixed> $runtime
     * @return array{ok:bool,target:?string}
     */
    private function recoverLoadedEventPublications(array $runtime): array
    {
        $visited = [];
        foreach ((array)($runtime['scan_dirs'] ?? []) as $candidate) {
            if (!\is_string($candidate) || $candidate === '') {
                continue;
            }
            $directory = \rtrim($candidate, '/\\');
            if ($directory === ''
                || isset($visited[$directory])
                || !$this->isSafeEventDirectory($directory)
            ) {
                continue;
            }
            $visited[$directory] = true;
            $target = $directory . DIRECTORY_SEPARATOR . self::EVENT_INI_NAME;
            $artifacts = $this->eventPublicationArtifacts($directory);
            if (!\is_array($artifacts)) {
                return ['ok' => false, 'target' => $target];
            }
            if ($artifacts === []) {
                continue;
            }
            if (!\is_writable($directory)) {
                return ['ok' => false, 'target' => $target];
            }

            $lockPath = $directory . DIRECTORY_SEPARATOR . self::EVENT_LOCK_NAME;
            $eventLock = $this->acquireExclusiveRuntimeLock(
                $lockPath,
                'WLS ext-event 配置锁',
            );
            $lock = $eventLock['handle'];
            if ($lock === null) {
                return ['ok' => false, 'target' => $target];
            }
            try {
                if (!$this->openedEventFileMatchesPath($lock, $lockPath)
                    || !$this->recoverEventIniPublication($target)
                ) {
                    return ['ok' => false, 'target' => $target];
                }
            } finally {
                @\flock($lock, LOCK_UN);
                @\fclose($lock);
            }
        }
        return ['ok' => true, 'target' => null];
    }

    /**
     * @return array<string,array{kind:'legacy'|'staging'|'backup',identity:array<string|int,mixed>,content:string}>|false
     */
    private function eventPublicationArtifacts(string $directory): array|false
    {
        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            return false;
        }
        $artifacts = [];
        $counts = ['legacy' => 0, 'staging' => 0, 'backup' => 0];
        $visited = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if (++$visited > self::EVENT_MAX_RECOVERY_DIRECTORY_ENTRIES) {
                    return false;
                }
                $folded = \strtolower($leaf);
                if (!\str_starts_with(
                    $folded,
                    self::EVENT_LEGACY_TEMP_PREFIX,
                )) {
                    continue;
                }
                if (!\str_starts_with(
                    $leaf,
                    self::EVENT_LEGACY_TEMP_PREFIX,
                )) {
                    return false;
                }
                $kind = null;
                if (\preg_match('/\A\.wls-event-staging-[a-f0-9]{24}\z/D', $leaf) === 1) {
                    $kind = 'staging';
                } elseif (\preg_match('/\A\.wls-event-backup-[a-f0-9]{16}\z/D', $leaf) === 1) {
                    $kind = 'backup';
                } elseif (\preg_match('/\A\.wls-event-(?:[A-Za-z0-9]{6}|[A-Za-z0-9]{20})\z/D', $leaf) === 1) {
                    $kind = 'legacy';
                } else {
                    return false;
                }
                if (++$counts[$kind] > self::EVENT_MAX_RECOVERY_FILES_PER_KIND) {
                    return false;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $leaf;
                $inspected = $this->inspectEventRecoveryArtifact(
                    $path,
                    $directory,
                    $kind === 'backup',
                );
                if ($inspected === false) {
                    return false;
                }
                $artifacts[$path] = [
                    'kind' => $kind,
                    'identity' => $inspected['identity'],
                    'content' => $inspected['content'],
                ];
            }
        } finally {
            @\closedir($handle);
        }
        \ksort($artifacts, SORT_STRING);
        return $artifacts;
    }

    /** @return array{identity:array<string|int,mixed>,content:string}|false */
    private function inspectEventRecoveryArtifact(
        string $path,
        string $directory,
        bool $mustBeManaged,
    ): array|false {
        $directoryIdentity = @\lstat($directory);
        $before = @\lstat($path);
        if (!\is_array($directoryIdentity)
            || !\is_array($before)
            || !$this->isSafeEventPublicationFileStatus(
                $before,
                $directoryIdentity,
            )
            || (int)($before['size'] ?? -1) < 0
            || (int)($before['size'] ?? -1) > self::MAX_EVENT_INI_BYTES
        ) {
            return false;
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            return false;
        }
        try {
            $opened = @\fstat($handle);
            $named = @\lstat($path);
            if (!\is_array($opened)
                || !\is_array($named)
                || !$this->sameEventPublicationState($before, $opened)
                || !$this->sameEventPublicationState($opened, $named)
            ) {
                return false;
            }
            $content = @\stream_get_contents(
                $handle,
                self::MAX_EVENT_INI_BYTES + 1,
            );
            $after = @\lstat($path);
            if (!\is_string($content)
                || \strlen($content) > self::MAX_EVENT_INI_BYTES
                || !\is_array($after)
                || !$this->sameEventPublicationState($named, $after)
                || ($mustBeManaged
                    && !\str_contains($content, self::EVENT_MANAGED_MARKER))
            ) {
                return false;
            }
            return ['identity' => $after, 'content' => $content];
        } finally {
            @\fclose($handle);
        }
    }

    /** @return array{exists:bool,valid:bool,identity:array<string|int,mixed>,content:string}|false */
    private function eventManagedTargetSnapshot(
        string $target,
        mixed $directoryIdentity,
    ): array|false {
        if (!\is_array($directoryIdentity)) {
            return false;
        }
        $before = @\lstat($target);
        if (!\is_array($before)) {
            return \file_exists($target) || \is_link($target)
                ? false
                : ['exists' => false, 'valid' => false, 'identity' => [], 'content' => ''];
        }
        if (!$this->isSafeEventPublicationFileStatus(
            $before,
            $directoryIdentity,
        ) || (int)($before['size'] ?? -1) < 0
            || (int)($before['size'] ?? -1) > self::MAX_EVENT_INI_BYTES
        ) {
            return false;
        }
        $handle = @\fopen($target, 'rb');
        if (!\is_resource($handle)) {
            return false;
        }
        try {
            $opened = @\fstat($handle);
            $named = @\lstat($target);
            $content = @\stream_get_contents(
                $handle,
                self::MAX_EVENT_INI_BYTES + 1,
            );
            $after = @\lstat($target);
            if (!\is_array($opened)
                || !\is_array($named)
                || !\is_array($after)
                || !\is_string($content)
                || \strlen($content) > self::MAX_EVENT_INI_BYTES
                || !$this->sameEventPublicationState($before, $opened)
                || !$this->sameEventPublicationState($opened, $named)
                || !$this->sameEventPublicationState($named, $after)
            ) {
                return false;
            }
            return [
                'exists' => true,
                'valid' => \str_contains($content, self::EVENT_MANAGED_MARKER),
                'identity' => $after,
                'content' => $content,
            ];
        } finally {
            @\fclose($handle);
        }
    }

    /** @param resource $handle @param array<string|int,mixed> $directoryIdentity @return array<string|int,mixed>|null */
    private function sealedEventStagingIdentity(
        $handle,
        string $path,
        array $directoryIdentity,
        string $content,
    ): ?array {
        $created = @\fstat($handle);
        $named = @\lstat($path);
        if (!\is_array($created)
            || !\is_array($named)
            || !$this->sameEventPublicationState($created, $named)
            || !$this->isSafeEventPublicationFileStatus(
                $created,
                $directoryIdentity,
            )
            || @\fseek($handle, 0) !== 0
        ) {
            return null;
        }
        $length = \strlen($content);
        $offset = 0;
        while ($offset < $length) {
            $written = @\fwrite($handle, \substr($content, $offset));
            if (!\is_int($written) || $written < 1) {
                return null;
            }
            $offset += $written;
        }
        if (!@\fflush($handle)
            || (\function_exists('fsync') && !@\fsync($handle))
            || (\function_exists('fchmod')
                ? !@\fchmod($handle, 0600)
                : !@\chmod($path, 0600))
        ) {
            return null;
        }
        $sealed = @\fstat($handle);
        $sealedNamed = @\lstat($path);
        return \is_array($sealed)
            && \is_array($sealedNamed)
            && $this->sameEventPublicationState($sealed, $sealedNamed)
            && $this->isSafeEventPublicationFileStatus(
                $sealed,
                $directoryIdentity,
            )
            && (int)($sealed['size'] ?? -1) === $length
            && (PHP_OS_FAMILY === 'Windows'
                || (((int)($sealed['mode'] ?? 0)) & 0777) === 0600)
            ? $sealed
            : null;
    }

    private function allocateEventRecoveryPath(
        string $directory,
        string $prefix,
        int $randomBytes,
    ): ?string {
        $artifacts = $this->eventPublicationArtifacts($directory);
        if (!\is_array($artifacts)) {
            return null;
        }
        $kind = $prefix === self::EVENT_BACKUP_PREFIX ? 'backup' : 'staging';
        $count = \count(\array_filter(
            $artifacts,
            static fn (array $artifact): bool => $artifact['kind'] === $kind,
        ));
        if ($count >= self::EVENT_MAX_RECOVERY_FILES_PER_KIND) {
            return null;
        }
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $path = $directory . DIRECTORY_SEPARATOR . $prefix
                . \bin2hex(\random_bytes($randomBytes));
            if (!\file_exists($path) && !\is_link($path)) {
                return $path;
            }
        }
        return null;
    }

    /** @param array<string|int,mixed> $directoryIdentity */
    private function isSafeEventPublicationFileStatus(
        array $status,
        array $directoryIdentity,
    ): bool {
        return (((int)($status['mode'] ?? 0)) & 0170000) === 0100000
            && (int)($status['nlink'] ?? 0) === 1
            && (PHP_OS_FAMILY === 'Windows'
                || ((int)($status['uid'] ?? -1) === (int)($directoryIdentity['uid'] ?? -2)
                    && (int)($status['gid'] ?? -1) === (int)($directoryIdentity['gid'] ?? -2)));
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private function sameEventPublicationState(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'uid', 'gid'] as $key) {
            if ((int)($left[$key] ?? -1) !== (int)($right[$key] ?? -2)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string|int,mixed> $identity */
    private function eventPathHasIdentity(string $path, array $identity): bool
    {
        $current = @\lstat($path);
        return \is_array($current)
            && $this->sameEventPublicationState($identity, $current);
    }

    /** @param array<string|int,mixed> $directoryIdentity */
    private function sameEventDirectoryIdentity(
        string $directory,
        array $directoryIdentity,
    ): bool {
        $current = @\lstat($directory);
        return \is_array($current)
            && $this->sameRuntimeFileIdentity($directoryIdentity, $current)
            && (((int)($current['mode'] ?? 0)) & 0170000) === 0040000
            && (((int)($current['mode'] ?? 0)) & 07777)
                === (((int)($directoryIdentity['mode'] ?? -1)) & 07777)
            && (PHP_OS_FAMILY === 'Windows'
                || ((int)($current['uid'] ?? -1) === (int)($directoryIdentity['uid'] ?? -2)
                    && (int)($current['gid'] ?? -1) === (int)($directoryIdentity['gid'] ?? -2)));
    }

    /**
     * @param array<string,array{kind:string,identity:array<string|int,mixed>,content:string}> $left
     * @param array<string,array{kind:string,identity:array<string|int,mixed>,content:string}> $right
     */
    private function sameEventArtifactInventory(array $left, array $right): bool
    {
        if (\array_keys($left) !== \array_keys($right)) {
            return false;
        }
        foreach ($left as $path => $artifact) {
            $current = $right[$path] ?? null;
            if (!\is_array($current)
                || !\hash_equals($artifact['kind'], $current['kind'])
                || !\hash_equals($artifact['content'], $current['content'])
                || !$this->sameEventPublicationState(
                    $artifact['identity'],
                    $current['identity'],
                )
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array{exists:bool,valid:bool,identity:array<string|int,mixed>,content:string} $left
     * @param array{exists:bool,valid:bool,identity:array<string|int,mixed>,content:string} $right
     */
    private function sameEventManagedTargetSnapshot(array $left, array $right): bool
    {
        return $left['exists'] === $right['exists']
            && $left['valid'] === $right['valid']
            && \hash_equals($left['content'], $right['content'])
            && (!$left['exists']
                || $this->sameEventPublicationState(
                    $left['identity'],
                    $right['identity'],
                ));
    }

    /** @param array<string|int,mixed> $identity */
    private function removeEventRecoveryArtifact(
        string $path,
        array $identity,
    ): bool {
        if (!$this->eventPathHasIdentity($path, $identity)
            || !@\unlink($path)
        ) {
            return false;
        }
        \clearstatcache(true, $path);
        return !\file_exists($path) && !\is_link($path);
    }

    private function removePublishedEventIni(string $target): bool
    {
        if (!\file_exists($target) && !\is_link($target)) {
            return true;
        }
        $directory = \dirname($target);
        $directoryIdentity = @\lstat($directory);
        $snapshot = $this->eventManagedTargetSnapshot($target, $directoryIdentity);
        return \is_array($directoryIdentity)
            && \is_array($snapshot)
            && $snapshot['exists']
            && $snapshot['valid']
            && $this->sameEventDirectoryIdentity($directory, $directoryIdentity)
            && $this->removeEventRecoveryArtifact(
                $target,
                $snapshot['identity'],
            );
    }

    private function readBoundedEventIni(string $target): string|false
    {
        $directoryIdentity = @\lstat(\dirname($target));
        $snapshot = $this->eventManagedTargetSnapshot(
            $target,
            $directoryIdentity,
        );
        return \is_array($snapshot) && $snapshot['exists']
            ? $snapshot['content']
            : false;
    }

    /** @return array<string, mixed> */
    private function probeFreshEventChild(
        string $phpBinary,
        string $target,
        ?\Closure $childProbeOverride,
    ): array {
        if ($childProbeOverride !== null) {
            return (array)$childProbeOverride($phpBinary, $target);
        }
        if ($phpBinary === '' || !\is_file($phpBinary) || !\is_executable($phpBinary)) {
            return ['exit_code' => -1, 'output' => '', 'stderr' => 'PHP_BINARY is not executable'];
        }

        $script = <<<'PHP'
$scanned = php_ini_scanned_files();
$files = is_string($scanned) ? preg_split('/,\s*/', trim($scanned)) : [];
$payload = [
    'loaded' => extension_loaded('event'),
    'classes' => class_exists('EventBase', false) && class_exists('EventBufferEvent', false),
    'scanned_files' => array_values(array_filter(array_map('trim', is_array($files) ? $files : []), 'strlen')),
];
echo json_encode($payload, JSON_UNESCAPED_SLASHES);
exit(($payload['loaded'] && $payload['classes']) ? 0 : 9);
PHP;
        $process = $this->runEventProcess([$phpBinary, '-r', $script]);
        $decoded = \json_decode((string)($process['output'] ?? ''), true);
        if (\is_array($decoded)) {
            $process += $decoded;
        }
        return $process;
    }

    /** @param array<string, mixed> $probe */
    private function eventProbePassed(array $probe, string $target): bool
    {
        if ((int)($probe['exit_code'] ?? -1) !== 0
            || ($probe['loaded'] ?? false) !== true
            || ($probe['classes'] ?? false) !== true
        ) {
            return false;
        }
        $expected = \realpath($target) ?: $target;
        foreach ((array)($probe['scanned_files'] ?? []) as $scannedFile) {
            if (!\is_string($scannedFile) || $scannedFile === '') {
                continue;
            }
            $actual = \realpath($scannedFile) ?: $scannedFile;
            if (\hash_equals($expected, $actual)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $command @return array{exit_code:int,output:string,stderr:string} */
    private function runEventProcess(array $command): array
    {
        try {
            $result = GatewayBoundedCommandRunner::run(
                $command,
                (float)self::EVENT_CHILD_TIMEOUT_SECONDS,
            );
        } catch (\InvalidArgumentException $exception) {
            return ['exit_code' => -1, 'output' => '', 'stderr' => $exception->getMessage()];
        }
        $output = \substr((string)($result['stdout'] ?? ''), 0, self::EVENT_MAX_CAPTURE_BYTES);
        $stderr = \substr((string)($result['stderr'] ?? ''), 0, self::EVENT_MAX_CAPTURE_BYTES);

        return [
            'exit_code' => (int)($result['code'] ?? 125),
            'output' => $output,
            'stderr' => $stderr,
        ];
    }

    /** @param array<string, mixed> $runtime */
    private function eventDiagnostics(array $runtime): string
    {
        return 'php_binary=' . (string)($runtime['php_binary'] ?? '')
            . '; loaded_ini=' . (string)($runtime['loaded_ini'] ?? '(none)')
            . '; scan_dirs=' . \implode(PATH_SEPARATOR, \array_map('strval', (array)($runtime['scan_dirs'] ?? [])))
            . '; extension_dir=' . (string)($runtime['extension_dir'] ?? '')
            . '; extension_binary=' . (string)($runtime['extension_binary'] ?? '');
    }

    /** @return array{status:'ready'|'absent'|'failed',changed:bool,message:string,diagnostics:string,target:?string} */
    private function eventConfigurationResult(
        string $status,
        bool $changed,
        string $message,
        string $diagnostics,
        ?string $target = null,
    ): array {
        return \compact('status', 'changed', 'message', 'diagnostics', 'target');
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

    private function canUseSharedFdPrimitives(): bool
    {
        foreach (['proc_open', 'proc_close', 'proc_get_status', 'posix_setsid', 'posix_kill'] as $function) {
            if (!\function_exists($function)) {
                return false;
            }
        }

        return \is_dir('/dev/fd') || \is_dir('/proc/self/fd');
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

    /** @return array{handle:resource|null,error:string} */
    private function acquireInstallLock(): array
    {
        $directory = Env::VAR_DIR . 'server' . DS . 'locks';
        if (!$this->ensureSafeRuntimeLockDirectory($directory)) {
            return [
                'handle' => null,
                'error' => 'WLS 运行时依赖安装锁目录不是无符号链接的安全绝对路径。',
            ];
        }

        return $this->acquireExclusiveRuntimeLock(
            $directory . DS . 'runtime_dependency_install.lock',
            'WLS 运行时依赖安装锁',
        );
    }

    private function ensureSafeRuntimeLockDirectory(string $directory): bool
    {
        $directory = \rtrim($directory, '/\\');
        if ($directory === '') {
            return false;
        }
        if ($this->runtimeLockDirectoryStatus($directory) !== null) {
            return true;
        }
        if (\file_exists($directory) || \is_link($directory)) {
            return false;
        }

        $missing = [];
        $current = $directory;
        while (!\is_dir($current)) {
            if (\file_exists($current) || \is_link($current)) {
                return false;
            }
            $missing[] = $current;
            if (\count($missing) > 32) {
                return false;
            }
            $parent = \dirname($current);
            if ($parent === $current) {
                return false;
            }
            $current = $parent;
        }
        if ($this->runtimeLockDirectoryStatus($current) === null) {
            return false;
        }

        foreach (\array_reverse($missing) as $path) {
            if (!@\mkdir($path, 0755) && !\is_dir($path)) {
                return false;
            }
            if ($this->runtimeLockDirectoryStatus($path) === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<string|int,mixed>|null
     */
    private function runtimeLockDirectoryStatus(string $directory): ?array
    {
        if ($directory === '' || \is_link($directory) || !\is_dir($directory)) {
            return null;
        }
        $real = \realpath($directory);
        if (!\is_string($real)
            || !$this->sameRuntimePath($real, $directory)
        ) {
            return null;
        }
        $status = @\lstat($directory);
        if (!\is_array($status)
            || (((int)$status['mode']) & 0170000) !== 0040000
        ) {
            return null;
        }
        return $status;
    }

    private function sameRuntimePath(string $left, string $right): bool
    {
        $normalize = static function (string $path): string {
            if (PHP_OS_FAMILY === 'Windows') {
                $path = \str_replace('/', '\\', $path);
                if (\str_starts_with($path, '\\\\?\\UNC\\')) {
                    $path = '\\\\' . \substr($path, 8);
                } elseif (\str_starts_with($path, '\\\\?\\')) {
                    $path = \substr($path, 4);
                }
                return \strtolower(\rtrim($path, '\\'));
            }
            $path = \rtrim($path, '/');
            return $path === '' ? '/' : $path;
        };

        return \hash_equals($normalize($left), $normalize($right));
    }

    /** @return array{handle:resource|null,error:string} */
    private function acquireExclusiveRuntimeLock(string $path, string $label): array
    {
        $directory = \dirname($path);
        $directoryBefore = $this->runtimeLockDirectoryStatus($directory);
        if ($directoryBefore === null) {
            return [
                'handle' => null,
                'error' => $label . '目录不是无符号链接的安全绝对路径。',
            ];
        }
        $deadline = $this->monotonicLockSeconds() + $this->lockWaitSeconds;
        $handle = null;

        for ($attempt = 0; $attempt < 8; ++$attempt) {
            \clearstatcache(true, $path);
            $before = @\lstat($path);
            $created = false;
            if (\is_array($before)) {
                $unsafe = $this->runtimeLockStatusError(
                    $before,
                    $directoryBefore,
                    $label,
                );
                if ($unsafe !== null) {
                    return ['handle' => null, 'error' => $unsafe];
                }
                $handle = @\fopen($path, 'r+b');
            } else {
                if (\file_exists($path) || \is_link($path)) {
                    return [
                        'handle' => null,
                        'error' => $label . '路径状态不确定，已拒绝跟随。',
                    ];
                }
                $handle = @\fopen($path, 'x+b');
                $created = \is_resource($handle);
            }

            if (!\is_resource($handle)) {
                \clearstatcache(true, $path);
                if ($before === false && \is_array(@\lstat($path))) {
                    if ($this->monotonicLockSeconds() >= $deadline) {
                        return [
                            'handle' => null,
                            'error' => $label . '等待超时。',
                        ];
                    }
                    $this->pauseRuntimeLockRetry($deadline);
                    continue;
                }
                return [
                    'handle' => null,
                    'error' => '无法以不跟随方式打开' . $label . '。',
                ];
            }

            $opened = @\fstat($handle);
            \clearstatcache(true, $path);
            $named = @\lstat($path);
            $directoryAfterOpen = $this->runtimeLockDirectoryStatus($directory);
            $unsafe = \is_array($opened)
                ? $this->runtimeLockStatusError($opened, $directoryBefore, $label)
                : $label . '打开后无法读取文件身份。';
            if ($unsafe === null && \is_array($named)) {
                $unsafe = $this->runtimeLockStatusError(
                    $named,
                    $directoryBefore,
                    $label,
                );
            }
            if ($unsafe !== null
                || !\is_array($opened)
                || !\is_array($named)
                || $directoryAfterOpen === null
                || !$this->sameRuntimeFileIdentity($directoryBefore, $directoryAfterOpen)
                || (!$created
                    && (!\is_array($before)
                        || !$this->sameRuntimeFileIdentity($before, $opened)))
                || !$this->sameRuntimeFileIdentity($opened, $named)
            ) {
                @\fclose($handle);
                return [
                    'handle' => null,
                    'error' => $unsafe ?? $label . '在打开期间发生路径身份变化。',
                ];
            }
            break;
        }

        if (!\is_resource($handle)) {
            return [
                'handle' => null,
                'error' => '无法稳定打开' . $label . '。',
            ];
        }

        $locked = false;
        do {
            if (@\flock($handle, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            if ($this->monotonicLockSeconds() >= $deadline) {
                break;
            }
            $this->pauseRuntimeLockRetry($deadline);
        } while (true);
        if (!$locked) {
            @\fclose($handle);
            return ['handle' => null, 'error' => $label . '等待超时。'];
        }

        $openedAfterLock = @\fstat($handle);
        \clearstatcache(true, $path);
        $namedAfterLock = @\lstat($path);
        $directoryAfterLock = $this->runtimeLockDirectoryStatus($directory);
        $unsafe = \is_array($openedAfterLock)
            ? $this->runtimeLockStatusError(
                $openedAfterLock,
                $directoryBefore,
                $label,
            )
            : $label . '加锁后无法读取文件身份。';
        if ($unsafe === null && \is_array($namedAfterLock)) {
            $unsafe = $this->runtimeLockStatusError(
                $namedAfterLock,
                $directoryBefore,
                $label,
            );
        }
        if ($unsafe !== null
            || !\is_array($openedAfterLock)
            || !\is_array($namedAfterLock)
            || $directoryAfterLock === null
            || !$this->sameRuntimeFileIdentity($directoryBefore, $directoryAfterLock)
            || !$this->sameRuntimeFileIdentity($openedAfterLock, $namedAfterLock)
        ) {
            @\flock($handle, LOCK_UN);
            @\fclose($handle);
            return [
                'handle' => null,
                'error' => $unsafe ?? $label . '加锁后发生路径身份变化。',
            ];
        }

        $sealed = PHP_OS_FAMILY === 'Windows'
            || (\function_exists('fchmod')
                ? @\fchmod($handle, 0600)
                : @\chmod($path, 0600));
        $sealedOpened = @\fstat($handle);
        \clearstatcache(true, $path);
        $sealedNamed = @\lstat($path);
        if (!$sealed
            || !\is_array($sealedOpened)
            || !\is_array($sealedNamed)
            || !$this->sameRuntimeFileIdentity($sealedOpened, $sealedNamed)
            || $this->runtimeLockStatusError(
                $sealedOpened,
                $directoryBefore,
                $label,
            ) !== null
            || (PHP_OS_FAMILY !== 'Windows'
                && (((int)$sealedOpened['mode']) & 0777) !== 0600)
        ) {
            @\flock($handle, LOCK_UN);
            @\fclose($handle);
            return [
                'handle' => null,
                'error' => '无法将' . $label . '封印为 0600 的单链接普通文件。',
            ];
        }

        return ['handle' => $handle, 'error' => ''];
    }

    /**
     * @param array<string|int,mixed> $status
     * @param array<string|int,mixed> $directoryStatus
     */
    private function runtimeLockStatusError(
        array $status,
        array $directoryStatus,
        string $label,
    ): ?string {
        if ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000
            || (int)($status['nlink'] ?? 0) !== 1
        ) {
            return $label . '必须是单链接普通文件。';
        }
        if (PHP_OS_FAMILY !== 'Windows'
            && ((int)($status['uid'] ?? -1) !== (int)($directoryStatus['uid'] ?? -2)
                || (int)($status['gid'] ?? -1) !== (int)($directoryStatus['gid'] ?? -2))
        ) {
            return $label . '所有者与其目录不匹配。';
        }
        return null;
    }

    /**
     * @param array<string|int,mixed> $left
     * @param array<string|int,mixed> $right
     */
    private function sameRuntimeFileIdentity(array $left, array $right): bool
    {
        return (int)($left['dev'] ?? -1) === (int)($right['dev'] ?? -2)
            && (int)($left['ino'] ?? -1) === (int)($right['ino'] ?? -2);
    }

    private function monotonicLockSeconds(): float
    {
        $now = \hrtime(true) / 1_000_000_000;
        if (!\is_finite($now) || $now <= 0.0) {
            throw new \RuntimeException(
                'Runtime dependency lock monotonic clock is invalid.'
            );
        }
        return $now;
    }

    private function pauseRuntimeLockRetry(float $deadline): void
    {
        $remaining = $deadline - $this->monotonicLockSeconds();
        if ($remaining <= 0.0) {
            return;
        }
        @\usleep((int)\max(
            1,
            \min(
                self::LOCK_RETRY_MICROSECONDS,
                \ceil($remaining * 1_000_000),
            ),
        ));
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
        try {
            $result = GatewayBoundedCommandRunner::run(
                $command,
                (float)\max(1, $timeoutSeconds),
                \defined('BP') ? (string)\constant('BP') : null,
                false,
            );
        } catch (\InvalidArgumentException $exception) {
            return [
                'success' => false,
                'exit_code' => 126,
                'output' => $exception->getMessage(),
                'timed_out' => false,
            ];
        }
        $exitCode = (int)($result['code'] ?? 125);
        $output = \substr((string)($result['output'] ?? ''), 0, self::MAX_CAPTURE_BYTES);
        if ($streamOutput && $output !== '') {
            echo $output;
        }

        return [
            'success' => $exitCode === 0,
            'exit_code' => $exitCode,
            'output' => $output,
            'timed_out' => $exitCode === 124,
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
