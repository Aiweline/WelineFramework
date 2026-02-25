<?php
declare(strict_types=1);

/**
 * Weline Server - 启动命令
 * 
 * 跨平台多进程服务器，支持 Windows/Linux/Mac
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\System\Process\Processer;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Console\Console\Server\Stop as CliStop;
use Weline\Server\Console\Server\Stop as MainStop;
use Weline\Server\Service\CliServerService;
use Weline\Server\Service\SslCertificateService;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Strategy\ServerStrategyFactory;
use Weline\Server\Strategy\ServerStrategyInterface;
use Weline\Server\Strategy\ServerConfig;

/**
 * server:start - 启动常驻内存服务器
 */
class Start extends CommandAbstract
{
    /**
     * 默认 HTTP 端口（直连省去 Nginx）
     */
    public const DEFAULT_PORT = 80;
    
    /**
     * 默认 HTTPS 端口
     */
    public const DEFAULT_PORT_HTTPS = 443;
    
    /**
     * 默认端口（80/443）被占用时的备用端口
     */
    public const DEFAULT_PORT_FALLBACK = 9981;
    
    /**
     * 可用的进程控制函数
     */
    protected array $availableFunctions = [];
    
    /**
     * 使用的启动方式
     */
    protected string $usedMethod = '';
    
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // --cli / -cli：强制使用 PHP 内置 CLI 服务器
        $useCli = isset($args['cli']);
        if (!$useCli) {
            foreach ($args as $key => $val) {
                if (\is_int($key) && ($val === '--cli' || $val === '-cli')) {
                    $useCli = true;
                    break;
                }
            }
        }
        if ($useCli) {
            $this->startCliServer($args, $data);
            return;
        }
        
        // --strategy / -strategy：使用跨平台优化策略模式
        // Linux/Mac 使用 SO_REUSEPORT 直连模式
        // Windows 使用 Dispatcher TCP 透传模式
        $useStrategy = isset($args['strategy']);
        if (!$useStrategy) {
            foreach ($args as $key => $val) {
                if (\is_int($key) && ($val === '--strategy' || $val === '-strategy')) {
                    $useStrategy = true;
                    break;
                }
            }
        }
        
        // --strategy-info：显示策略信息
        $showStrategyInfo = isset($args['strategy-info']);
        if (!$showStrategyInfo) {
            foreach ($args as $key => $val) {
                if (\is_int($key) && ($val === '--strategy-info' || $val === '-strategy-info')) {
                    $showStrategyInfo = true;
                    break;
                }
            }
        }
        
        if ($showStrategyInfo) {
            $this->showStrategyInfo();
            return;
        }
        
        if ($useStrategy) {
            $result = $this->executeWithStrategy($args);
            if (!$result) {
                $this->printer->error(__('策略模式启动失败'));
            }
            return;
        }
        
        // 检测可用函数
        $this->detectAvailableFunctions();
        
        // Weline Server 不可用时自动回退到 CLI 服务器
        $cliService = ObjectManager::getInstance(CliServerService::class);
        if (!$cliService->isWelineServerAvailable()) {
            $this->printer->warning(__('Weline Server 不可用：%{1}', [$cliService->getUnavailableReason()]));
            $this->printer->note(__('自动回退到 PHP 内置 CLI 服务器...'));
            $this->printer->note(__(''));
            $this->startCliServer($args, $data);
            return;
        }
        
        // 解析实例名称
        $instanceName = $this->parseInstanceName($args);
        
        // 仅运行 Master 进程（由 daemon 模式后台启动时调用，内部使用）
        if (isset($args['master-only']) || getenv('WLS_MASTER_ONLY')) {
            $this->runMasterOnly($instanceName);
            return;
        }
        
        // --frontend / -frontend：前台运行（不后台）
        $frontend = isset($args['frontend']);
        if (!$frontend) {
            foreach ($args as $key => $val) {
                if (\is_int($key) && ($val === '--frontend' || $val === '-frontend')) {
                    $frontend = true;
                    break;
                }
            }
        }
        
        // -log / --log：启用进程日志（覆盖 env 配置 system.processer.log）
        $enableLog = isset($args['log']);
        if (!$enableLog) {
            foreach ($args as $key => $val) {
                if (\is_int($key) && ($val === '--log' || $val === '-log')) {
                    $enableLog = true;
                    break;
                }
            }
        }
        if ($enableLog) {
            Processer::setLogEnabled(true);
        }
        
        // 获取配置（命令行参数 > 已保存实例配置 > env配置 > 默认值）
        $config = $this->getServerConfig($instanceName, $args);
        
        // 提示配置来源（已保存的实例配置时特别提示，让用户知道为什么不用指定端口）
        $source = $config['source'] ?? '';
        if (\is_string($source) && \str_contains($source, $instanceName) && $this->loadSavedInstanceConfig($instanceName) !== null) {
            $savedConfig = $this->loadSavedInstanceConfig($instanceName);
            $savedPort = $savedConfig['port'] ?? '?';
            $savedHost = $savedConfig['host'] ?? '?';
            $this->printer->note(__('使用已保存的实例配置：%{1} (%{2}:%{3})', [$instanceName, $savedHost, $savedPort]));
        }
        
        $host = $config['host'];
        $port = $config['port'];
        $count = $config['worker_count'];
        // 如果指定了 --frontend，强制前台运行
        $daemon = $frontend ? false : $config['daemon'];
        
        // --no-ssl 时仅 HTTP（端口保持 80）；否则默认启用 HTTPS
        $noSsl = !empty($config['no_ssl']);
        $portExplicit = isset($args['port']) || isset($args['p']);
        
        if ($noSsl) {
            $sslEnabled = false;
            $sslCert = '';
            $sslKey = '';
            $sslResult = ['success' => true, 'is_new' => false, 'issuer' => '', 'expires_at' => ''];
            if ($port === self::DEFAULT_PORT) {
                $port = self::DEFAULT_PORT;  // 保持 80
                $config['port'] = $port;
            }
            $this->printer->note(__('以 HTTP 运行（端口 %{1}）。由 env.server.https=false 或 --no-ssl 生效。', [$port]));
        } else {
            // Windows 下未安装 event 时允许强制运行 SSL；提示延后到「服务器已在后台运行」之后输出（后台模式）或此处输出（前台模式）
            $isMasterOnly = isset($args['master-only']) || getenv('WLS_MASTER_ONLY');
            if (IS_WIN && !\extension_loaded('event') && !$isMasterOnly && !$daemon) {
                $this->printWindowsEventHttpsWarning();
            }
            
            $sslResult = $this->ensureSslCertificate($instanceName, $config);
            if (!$sslResult['success']) {
                $this->printer->error($sslResult['message']);
                return;
            }
            $sslCert = $sslResult['cert_path'];
            $sslKey = $sslResult['key_path'];
            $sslEnabled = true;
            // 默认 80 且启用 HTTPS 时使用 443
            if ($port === self::DEFAULT_PORT) {
                $port = self::DEFAULT_PORT_HTTPS;
                $config['port'] = $port;
                
            }
            if ($sslResult['is_new'] ?? false) {
                $this->printer->success(__('已生成新证书：%{1}', [$sslResult['issuer']]));
            } else {
                $this->printer->note(__('使用已有证书：%{1}', [$sslResult['issuer']]));
            }
            if (!empty($sslResult['expires_at'])) {
                $this->printer->note(__('证书有效期至：%{1}', [$sslResult['expires_at']]));
            }
        }
        
        // 检查是否强制重启（-r）及是否强制直接切换（-f：不等待 worker 空闲，直接停再启）
        $forceRestart = isset($args['r']) || isset($args['restart']) || isset($args['force']);
        $forceSwitch = isset($args['f']); // -f：直接切换，不进入平滑重启（不开维护模式、不等待）
        $mainStop = ObjectManager::getInstance(MainStop::class);
        $occupantWls = $mainStop->findWelineServerInstanceNameByPort($port);
        $cliStatus = $cliService->getCliServerStatus();
        $occupantCli = $cliStatus && (($cliStatus['port'] ?? 0) === (int) $port);

        // 同端口已被其他 WLS 实例占用 → 报错提示，不自动停旧实例（支持多实例并行）
        if ($occupantWls !== null && $occupantWls !== $instanceName) {
            $this->printer->error(__('端口 %{1} 已被 Weline Server 实例 [%{2}] 占用！', [$port, $occupantWls]));
            $this->printer->note('');
            $this->printer->setup(__('解决方案：'));
            $this->printer->note('  ' . __('1. 使用 -p 参数指定其他端口启动新实例：'));
            $this->printer->note('     php bin/w server:start ' . $instanceName . ' -p ' . ($port + 1000));
            $this->printer->note('  ' . __('2. 或先停止实例 [%{1}]：', [$occupantWls]));
            $this->printer->note('     php bin/w server:stop ' . $occupantWls);
            $this->printer->note('  ' . __('3. 查看所有运行中的实例：'));
            $this->printer->note('     php bin/w server:status --all');
            $this->printer->note('');
            return;
        }
        // CLI 服务器占用该端口 → 先停
        if ($occupantCli) {
            $this->printer->note(__('端口 %{1} 已被 PHP 内置服务器占用，正在停止...', [$port]));
            ObjectManager::getInstance(CliStop::class)->execute(['force' => true, 'f' => true], []);
            \sleep(2);
        }
        // 本实例已运行：未指定 -r 则提示并退出；指定 -r 则平滑重启（先维护模式+等待）或 -f 直接切换
        $maintenanceEnabledByUs = false;
        if ($occupantWls === $instanceName || $this->isServerRunning($instanceName, $port)) {
            if (!$forceRestart) {
                $this->showAlreadyRunningInfo($instanceName, $port);
                return;
            }
            // 强制重启：先停旧 Master，其通过 IPC 广播 shutdown，子进程收后不复活
            if ($forceSwitch) {
                $this->printer->warning(__('检测到服务器已运行，-f 直接切换（不等待）...'));
                $this->stopExistingServer($instanceName, $port, $count);
                // Windows 下端口释放需要更长时间（TIME_WAIT 状态）
                // 等待最多 3 秒让端口完全释放
                $maxWaitMs = 3000;
                $waitStep = 200;
                $waited = 0;
                while ($waited < $maxWaitMs) {
                    \usleep($waitStep * 1000);
                    $waited += $waitStep;
                    // 检查主要端口是否已释放
                    if (!Processer::isPortInUse($port)) {
                        break;
                    }
                }
            } else {
                $this->printer->warning(__('检测到服务器已运行，平滑重启：先开启维护模式，通过健康检查等待请求处理完成...'));
                $this->enableMaintenanceMode();
                $maintenanceEnabledByUs = true;
                
                // 通过健康检查接口智能等待
                $maxWait = (int) ($args['wait'] ?? 30);
                $maxWait = $maxWait > 0 ? $maxWait : 30;
                $waited = $this->waitForIdleWorkers($host, $port, $count, $maxWait, $sslEnabled ?? false);
                
                if ($waited) {
                    $this->printer->success(__('所有 Worker 已空闲，开始切换...'));
                } else {
                    $this->printer->warning(__('等待超时，强制切换...'));
                }
                
                $this->stopExistingServer($instanceName, $port, $count);
                \sleep(1);
            }
        }

        // ========== 架构模式检测：直连模式 vs Dispatcher 模式 ==========
        // 
        // 直连模式：多 Worker 直接监听同一端口，内核负载均衡（SO_REUSEPORT）
        //   - 要求：Linux 3.9+ 内核
        //   - 优势：无单点瓶颈，性能最佳
        //   - 架构：客户端 → Worker1/2/3/...(直接处理 SSL)
        //
        // Dispatcher 模式（降级方案）：单进程 Dispatcher 分发给多 Worker
        //   - 适用：Windows 或不支持 SO_REUSEPORT 的系统
        //   - 架构：客户端 → Dispatcher(单进程SSL) → Worker(多进程HTTP)
        //
        // --direct: 启用直连模式（SO_REUSEPORT，多 Worker 复用同一端口）
        // --no-dispatcher: 禁用 Dispatcher，每个 Worker 使用独立端口
        // --dispatcher / --force-dispatcher: 强制 Dispatcher 模式
        $directMode = isset($args['direct']);
        $noDispatcher = isset($args['no-dispatcher']) || isset($args['no_dispatcher']);
        $forceDispatcher = isset($args['dispatcher']) || isset($args['force-dispatcher']);

        // 检测 SO_REUSEPORT 支持（Linux 3.9+, macOS）
        $supportsReusePort = false;
        if (!IS_WIN && PHP_OS === 'Linux') {
            $release = \php_uname('r');
            if (\version_compare($release, '3.9', '>=')) {
                $supportsReusePort = true;
            }
        } elseif (!IS_WIN && PHP_OS === 'Darwin') {
            // macOS 也支持 SO_REUSEPORT（默认走直连模式）
            $supportsReusePort = true;
        }
        
        // 决定使用哪种架构
        // 优先级：
        //   1. 单 Worker：无需 Dispatcher（直接监听）
        //   2. --direct：显式启用直连模式（需要 SO_REUSEPORT 支持）
        //   3. --no-dispatcher：禁用 Dispatcher，每个 Worker 独立端口
        //   4. 默认：Dispatcher 模式（所有平台统一，稳定可靠）
        if ($count <= 1) {
            // 单 Worker 无需 Dispatcher
            $dispatcherEnabled = false;
        } elseif ($directMode) {
            // --direct：显式请求直连模式
            if ($supportsReusePort) {
                $dispatcherEnabled = false;
                $this->printer->success(__('启用直连模式：%{1} 个 Worker 直接监听端口 %{2}（SO_REUSEPORT）', [$count, $port]));
            } else {
                $this->printer->warning(__('当前系统不支持 SO_REUSEPORT，无法启用直连模式，回退到 Dispatcher 模式'));
                $dispatcherEnabled = true;
            }
        } elseif ($noDispatcher) {
            // --no-dispatcher：每个 Worker 独立端口（无 Dispatcher，无直连）
            $dispatcherEnabled = false;
            $this->printer->note(__('禁用 Dispatcher，每个 Worker 使用独立端口（智能分配）'));
        } else {
            // 默认：Dispatcher 模式（所有平台统一）
            $dispatcherEnabled = true;
            $this->printer->note(__('使用 Dispatcher 模式（TCP 透传）'));
        }
        
        $workerBasePort = (int) ($config['worker_base_port'] ?? 10000);
        
        // 计算 Worker 端口
        // - Dispatcher 模式：Worker 监听内网高端口，Dispatcher 监听主端口
        // - 直连模式（--direct）：所有 Worker 直接监听主端口（SO_REUSEPORT）
        // - 独立端口模式（--no-dispatcher）：每个 Worker 使用独立端口，需智能分配
        $useDirectMode = !$dispatcherEnabled && $supportsReusePort && $directMode;
        if ($dispatcherEnabled) {
            // Dispatcher 模式：Worker 使用内网高端口
            $workerPort = $workerBasePort + $port;
        } elseif ($useDirectMode) {
            // 直连模式：所有 Worker 复用主端口
            $workerPort = $port;
        } else {
            // 独立端口模式：Worker 使用独立端口（从 workerBasePort 开始）
            $workerPort = $workerBasePort + $port;
        }
        // Dispatcher 只做 TCP 透传和流量控制，不做 SSL 握手
        // SSL 握手始终由 Worker 处理（无论是否使用 Dispatcher）
        $workerSslEnabled = $sslEnabled;
        
        // ========== HTTP Redirect 端口计算（HTTPS 模式始终启动） ==========
        $httpRedirectPort = 0;
        $explicitRedirectPort = false;
        if ($sslEnabled) {
            // 优先级：命令行参数 > 配置文件 > 自动计算
            // 检查是否明确指定（命令行参数或配置文件中明确设置为 0 表示禁用）
            $explicitRedirectPort = $config['http_redirect_port_explicit'] ?? false;
            // 检查配置文件中是否明确设置了 http_redirect_port（包括 0）
            $configHasRedirectPort = isset($config['http_redirect_port']);
            $configRedirectPort = (int)($config['http_redirect_port'] ?? 0);
            
            if ($explicitRedirectPort) {
                // 命令行明确指定（包括 0，表示禁用）
                $httpRedirectPort = $configRedirectPort;
                if ($httpRedirectPort === 0) {
                    $this->printer->note(__('HTTP 重定向已禁用（--http-redirect-port 0）'));
                }
            } elseif ($configHasRedirectPort) {
                // 配置文件明确指定（包括 0，表示禁用）
                $httpRedirectPort = $configRedirectPort;
                if ($httpRedirectPort === 0) {
                    $this->printer->note(__('HTTP 重定向已禁用（env.server.http_redirect_port = 0）'));
                }
            } else {
                // 未配置，自动计算：httpsPort - 463（如 443→80, 9443→9980）
                $httpRedirectPort = $port - 463;
                // 确保端口合法
                if ($httpRedirectPort <= 0 || $httpRedirectPort > 65535) {
                    $httpRedirectPort = 80;  // 回退到默认 80
                }
            }
            
            // 验证端口范围（0 表示禁用，允许；其他值必须在 1-65535）
            if ($httpRedirectPort !== 0 && ($httpRedirectPort < 1 || $httpRedirectPort > 65535)) {
                $this->printer->error(__('HTTP 重定向端口无效: %{1}，将使用默认端口 80', [$httpRedirectPort]));
                $httpRedirectPort = 80;
            }
        }
        
        // 非显式端口：若被非框架进程占用，则自动跳过到可用端口
        if (!$portExplicit && Processer::isPortInUse($port) && !Processer::isPortUsedByWeline($port)) {
            $nextPort = Processer::findAvailablePort($port + 1, 200);
            if ($nextPort > 0 && $nextPort !== $port) {
                $this->printer->warning(__('端口 %{1} 被非框架进程占用，自动切换到可用端口 %{2}', [$port, $nextPort]));
                $port = $nextPort;
                $config['port'] = $port;
                $workerPort = $dispatcherEnabled ? ($workerBasePort + $port) : $port;
                if ($sslEnabled && $httpRedirectPort > 0 && !$explicitRedirectPort) {
                    $httpRedirectPort = $port - 463;
                    if ($httpRedirectPort <= 0 || $httpRedirectPort > 65535) {
                        $httpRedirectPort = 80;
                    }
                }
            }
        }

        // Dispatcher 模式或独立端口模式：Worker 端口段需智能分配
        // - WLS 进程占用的端口：释放后分配给新进程
        // - 非 WLS 进程占用的端口：跳过，使用下一个可用端口
        if ($dispatcherEnabled || (!$useDirectMode && $count > 1)) {
            $nextWorkerPort = $this->findAvailableWorkerPortBaseWithRelease($workerPort, $count);
            if ($nextWorkerPort !== $workerPort) {
                $this->printer->warning(__('Worker 端口段 %{1}-%{2} 存在端口占用，自动切换到 %{3}-%{4}', [
                    $workerPort,
                    $workerPort + $count - 1,
                    $nextWorkerPort,
                    $nextWorkerPort + $count - 1
                ]));
                $workerPort = $nextWorkerPort;
            }
        }

        // 自动计算的 HTTP Redirect 端口若被非框架进程占用，则自动跳过
        if ($sslEnabled && $httpRedirectPort > 0 && !$explicitRedirectPort
            && Processer::isPortInUse($httpRedirectPort)
            && !Processer::isPortUsedByWeline($httpRedirectPort)
        ) {
            $nextRedirectPort = Processer::findAvailablePort($httpRedirectPort + 1, 200);
            if ($nextRedirectPort > 0 && $nextRedirectPort !== $httpRedirectPort) {
                $this->printer->warning(__('HTTP 重定向端口 %{1} 被非框架进程占用，自动切换到 %{2}', [$httpRedirectPort, $nextRedirectPort]));
                $httpRedirectPort = $nextRedirectPort;
            }
        }

        // Linux/Mac 非 root 绑定特权端口时，自动触发 sudo 密码输入并重启当前命令
        if (!$this->ensurePrivilegedPortPermission($port, $httpRedirectPort, $sslEnabled)) {
            return;
        }
        
        // Linux/macOS 下检测 socket 权限（即使高端口也可能因系统安全设置需要 sudo）
        if (!$this->ensureUnixSocketPermission($host, $port)) {
            return;
        }
        
        // 显示启动信息
        // 使用 $useDirectMode 而非重新计算，确保与架构选择逻辑一致
        $this->showStartupInfo($instanceName, $host, $port, $count, $daemon, $config['source'], $sslEnabled, $dispatcherEnabled, $workerPort, $httpRedirectPort, $useDirectMode);

        // 80/443 端口自我处理提示（特权端口、单端口建议）
        if (!$dispatcherEnabled && !$useDirectMode && $count > 1) {
            // 独立端口模式
            $this->printer->note(__('提示：当前为独立端口模式，%{1} 个 Worker 分别监听端口 %{2}-%{3}。', [$count, $workerPort, $workerPort + $count - 1]));
        } elseif ($useDirectMode && $count > 1) {
            // 直连模式
            $this->printer->note(__('提示：当前为 SO_REUSEPORT 直连模式，多 Worker 复用同一端口 %{1}。', [$port]));
        }

        // 检查端口是否被占用（框架进程占用时最多重试 3 次，仍占用则按 Master 前缀清理逃逸 Master 后再试）
        if ($dispatcherEnabled) {
            // Dispatcher 模式：检查主端口（Dispatcher 用）+ Worker 内网端口
            if (!$this->checkAndReleasePort($host, $port, $forceRestart, 'Dispatcher', $instanceName)) {
                if (!empty($maintenanceEnabledByUs)) {
                    $this->disableMaintenanceMode();
                    $this->printer->note(__('维护模式已关闭（端口检查未通过）。'));
                }
                return;
            }
            if (!$this->checkAndReleasePorts($host, $workerPort, $count, $forceRestart, $instanceName)) {
                if (!empty($maintenanceEnabledByUs)) {
                    $this->disableMaintenanceMode();
                    $this->printer->note(__('维护模式已关闭（端口检查未通过）。'));
                }
                return;
            }
        } else {
            // 直连模式：
            // - SO_REUSEPORT: 多 Worker 复用同一端口，只检查主端口
            // - 非 SO_REUSEPORT: 仍按连续端口检查
            $checkResult = $supportsReusePort
                ? $this->checkAndReleasePort($host, $port, $forceRestart, 'Worker(Main)', $instanceName)
                : $this->checkAndReleasePorts($host, $port, $count, $forceRestart, $instanceName);
            if (!$checkResult) {
                if (!empty($maintenanceEnabledByUs)) {
                    $this->disableMaintenanceMode();
                    $this->printer->note(__('维护模式已关闭（端口检查未通过）。'));
                }
                return;
            }
        }
        
        // ========== 检查 HTTP 重定向端口（在启动前检测，避免启动到一半才报错） ==========
        if ($sslEnabled && $httpRedirectPort > 0) {
            if (!$this->checkAndReleasePort($host, $httpRedirectPort, $forceRestart, 'HTTP Redirect', $instanceName)) {
                if (!empty($maintenanceEnabledByUs)) {
                    $this->disableMaintenanceMode();
                    $this->printer->note(__('维护模式已关闭（HTTP 重定向端口检查未通过）。'));
                }
                $this->printer->note(__(''));
                $this->printer->setup(__('解决方案：'));
                $this->printer->note(__('  1. 停止占用端口 %{1} 的进程', [$httpRedirectPort]));
                $this->printer->note(__('  2. 或使用 --http-redirect-port 参数指定其他端口'));
                $this->printer->note(__('  3. 或在 env.php 中配置 http_redirect_port'));
                $this->printer->note(__('  4. 或使用 --http-redirect-port 0 禁用 HTTP 重定向'));
                return;
            }
        }
        
        // 创建 Worker 脚本路径（Dispatcher 模式下使用非 SSL 脚本）
        $workerScript = $this->ensureWorkerScript($workerSslEnabled);
        
        // 保存实例信息（Master 将从这里读取配置并启动所有进程）
        $this->saveInstanceInfo($instanceName, $host, $port, $count, $daemon, $sslEnabled, $sslCert, $sslKey, [], $dispatcherEnabled, $workerPort, $httpRedirectPort, $frontend, $enableLog, $useDirectMode);
        
        // 保存实例配置（配置记忆：下次 server:start <name> 直接使用相同配置）
        $this->saveInstanceConfig($instanceName, $args, $config);
        
        // 将实际的 host/port/https 同步到 env.php，供 http:req 等 CLI 工具读取
        $this->syncServerConfigToEnv($host, $port, $sslEnabled);
        
        // 显示优化建议
        $this->showOptimizationTips($count, $config['mode'] ?? 'io');
        
        // 显示使用说明（按实际协议显示 http/https）
        $this->showUsageInfo($host, $port, $instanceName, $sslEnabled);
        
        // ========== 开发模式热重载支持 ==========
        $this->startHotReloadIfEnabled($config, $instanceName);
        // ========== 热重载结束 ==========
        
        // 平滑重启时由我们开启的维护模式，启动完成后关闭
        if (!empty($maintenanceEnabledByUs)) {
            $this->disableMaintenanceMode();
            $this->printer->success(__('维护模式已关闭。'));
        }
        
        // ========== Master 进程负责启动所有进程 ==========
        // Master 统一管理：Dispatcher、Worker、HTTP Redirect
        $config['worker_port'] = $workerPort;
        $config['dispatcher_enabled'] = $dispatcherEnabled;
        // 同步 daemon 标志到 config（$daemon 已根据 --frontend 参数覆盖，
        // 但 $config['daemon'] 仍是 env 默认值 true，导致 MasterProcess::log() 跳过控制台输出）
        $config['daemon'] = $daemon;
        
        if ($daemon) {
            $this->startMasterInBackground($instanceName, $sslEnabled, $host, $port);
            return;
        }
        
        // 前台运行：Master 将占用当前终端
        $this->printer->note(__('Master 进程启动中，将管理所有 Worker 和 Dispatcher...'));
        if (\function_exists('flush')) {
            @\flush();
        }
        
        // Master 负责启动所有进程（不再传递 workerPids，由 Master 自己启动）
        $this->runMasterProcess($instanceName, $config, $workerScript, [], $sslCert, $sslKey, $sslEnabled, $httpRedirectPort, $frontend);
    }
    
    /**
     * 仅运行 Master 进程（由 startMasterInBackground 通过子进程调用，从实例文件恢复状态）
     */
    protected function runMasterOnly(string $instanceName): void
    {
        $instanceFile = Env::VAR_DIR . 'server' . DS . 'instances' . DS . $instanceName . '.json';
        if (!\is_file($instanceFile)) {
            $this->printer->error(__('实例文件不存在：%{1}', [$instanceFile]));
            return;
        }
        $content = \file_get_contents($instanceFile);
        $data = \json_decode($content, true);
        if (!\is_array($data)) {
            $this->printer->error(__('实例文件无效'));
            return;
        }
        $sslEnabled = (bool)($data['ssl_enabled'] ?? false);
        $dispatcherEnabled = (bool)($data['dispatcher_enabled'] ?? false);
        // Dispatcher 只做 TCP 透传，SSL 握手始终由 Worker 处理
        $workerScript = $this->ensureWorkerScript($sslEnabled);
        $port = (int)($data['port'] ?? 443);
        $config = [
            'host' => (string)($data['host'] ?? '127.0.0.1'),
            'port' => $port,
            'worker_count' => (int)($data['count'] ?? 1),
            'worker_port' => (int)($data['worker_port'] ?? $data['port'] ?? 443),
            'daemon' => true,
        ];
        // HTTPS 模式始终启动 HTTP Redirect Worker（从实例文件或智能计算）
        $httpRedirectPort = 0;
        if ($sslEnabled) {
            // 优先使用实例文件中保存的端口，否则智能计算
            $httpRedirectPort = (int)($data['http_redirect_port'] ?? 0);
            if ($httpRedirectPort <= 0) {
                $httpRedirectPort = $port - 463;
                if ($httpRedirectPort <= 0 || $httpRedirectPort > 65535) {
                    $httpRedirectPort = 80;
                }
            }
        }
        $workerPids = \array_values($data['worker_pids'] ?? []);
        // 读取前台模式标记
        $frontend = (bool)($data['frontend'] ?? false);
        
        // 读取进程日志开关（-log 参数传递过来的标记）
        $enableLog = (bool)($data['enable_log'] ?? false);
        if ($enableLog) {
            Processer::setLogEnabled(true);
        }
        
        // 读取运行模式（策略模式使用）
        $masterMode = (string)($data['master_mode'] ?? MasterProcess::MODE_LEGACY);
        $mainPort = (int)($data['main_port'] ?? $port);
        
        /** @var MasterProcess $master */
        $master = ObjectManager::getInstance(MasterProcess::class);
        $master->setPrinter($this->printer)
            ->setMode($masterMode)
            ->setMainPort($mainPort)
            ->init($instanceName, $config, $workerScript, (string)($data['ssl_cert'] ?? ''), (string)($data['ssl_key'] ?? ''), $sslEnabled, $httpRedirectPort, $frontend)
            ->setWorkerPids($workerPids)
            ->setDispatcherPid((int)($data['dispatcher_pid'] ?? 0))
            ->setHttpRedirectPid((int)($data['http_redirect_pid'] ?? 0))
            ->setResurrectionMode(true)
            ->run();
    }
    
    /**
     * 在后台启动 Master 进程（默认模式：启动后立即返回，不阻塞终端）
     * Windows：用 PowerShell Start-Process 独立启动 Master，避免 cmd/batch 退出时牵连子进程导致 Master 被关。
     * 传参使用 -ArgumentList 数组，保证 server:start instanceName --master-only 被正确解析。
     * 后台模式下将 Windows + HTTPS 相关提示放在「服务器已在后台运行」之后，便于用户看到。
     */
    protected function startMasterInBackground(string $instanceName, bool $sslEnabled = false, string $host = '127.0.0.1', int $port = 443): void
    {
        $phpBinary = \defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $script = BP . 'bin' . DS . 'w';
        
        $masterName = MasterProcess::getMasterProcessName($instanceName);
        if (IS_WIN) {
            $bp = \str_replace("'", "''", BP);
            $phpBin = \str_replace("'", "''", $phpBinary);
            $scriptRel = 'bin' . DS . 'w';
            $argList = "'" . $scriptRel . "','server:start','" . \str_replace("'", "''", $instanceName) . "','--master-only','--name=" . \str_replace("'", "''", $masterName) . "'";
            $psCmd = "Set-Location -LiteralPath '" . $bp . "'; Start-Process -FilePath '" . $phpBin . "' -ArgumentList " . $argList . " -WindowStyle Hidden -WorkingDirectory '" . $bp . "'";
            $fullCmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "' . \str_replace('"', '\"', $psCmd) . '"';
            @\exec($fullCmd . ' 2>NUL');
        } else {
            // macOS/Linux：如果当前是 root，显式用 sudo -n 确保后台进程也以 root 运行
            // nohup 在某些情况下可能不正确继承 sudo 的 root 权限（如通过 passthru 启动的临时 sudo 会话）
            // sudo -n (non-interactive) 在已有 root 权限时不需要密码
            $isRoot = \function_exists('posix_geteuid') && (int)\posix_geteuid() === 0;
            if ($isRoot) {
                $this->printer->note(__('以 root 权限启动后台 Master...'));
                // 使用 sudo -E -n 保留环境变量并以 root 运行
                $cmd = \sprintf('sudo -E -n %s %s server:start %s --master-only --name=%s', $phpBinary, \escapeshellarg($script), \escapeshellarg($instanceName), \escapeshellarg($masterName));
            } else {
                $cmd = \sprintf('%s %s server:start %s --master-only --name=%s', $phpBinary, \escapeshellarg($script), \escapeshellarg($instanceName), \escapeshellarg($masterName));
            }
            Processer::create($cmd, false);
        }
        
        $this->printer->success(__('服务器已在后台运行。使用 php bin/w server:status 查看状态，php bin/w server:stop 停止服务。'));
        
        // Windows + HTTPS 时在「服务器已在后台运行」之后集中提示，避免被前面输出淹没
        if (IS_WIN && $sslEnabled) {
            if (!\extension_loaded('event')) {
                $this->printWindowsEventHttpsWarning();
            }
            $this->showWindowsNginxProxyHint($host, $port);
        }
    }
    
    /**
     * 输出 Windows 下未安装 event 时的 HTTPS 提示（SSL 握手阻塞约 60s，建议安装 event）
     * 与 showWindowsNginxProxyHint 相同的框式格式化输出。
     */
    protected function printWindowsEventHttpsWarning(): void
    {
        $extDir = \ini_get('extension_dir');
        $extDirAbs = $extDir;
        if ($extDir) {
            if (\preg_match('#^[a-zA-Z]:[\\\\/]|^/#', $extDir)) {
                $extDirAbs = \is_dir($extDir) ? \realpath($extDir) : $extDir;
            } else {
                $phpDir = \defined('PHP_BINARY') && PHP_BINARY ? \dirname(PHP_BINARY) : '';
                $candidate = $phpDir ? $phpDir . \DIRECTORY_SEPARATOR . \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $extDir) : $extDir;
                $extDirAbs = \is_dir($candidate) ? \realpath($candidate) : $candidate;
            }
        }
        $extPath = $extDirAbs ?: $extDir ?: __('(未知)');
        $iniFile = \php_ini_loaded_file() ?: __('(未找到，请 php --ini 查看)');
        echo "\n";
        $this->printer->warning(__('╔══════════════════════════════════════════════════════════════════════════════╗'));
        $this->printer->warning(__('║  【Windows + HTTPS】当前未安装 PHP event 扩展。                              ║'));
        $this->printer->warning(__('║  在此环境下运行 HTTPS 会出现 SSL 握手阻塞（每次新连接可能卡住约 60 秒）。  ║'));
        $this->printer->warning(__('║  强烈建议安装 event 后再使用 HTTPS。                                        ║'));
        $this->printer->warning(__('╠══════════════════════════════════════════════════════════════════════════════╣'));
        $this->printer->warning(__('║  下载 event：https://windows.php.net/downloads/pecl/releases/event/           ║'));
        $this->printer->warning(__('║  选 3.0.x，按 PHP 版本/ts|nts/x64|x86 选 zip，php_event.dll 放入 ext 目录，  ║'));
        $this->printer->warning(__('║  在 php.ini 添加 extension=event。                                           ║'));
        $this->printer->warning(__('║  ext 目录：%{1}                                                                ║', [$extPath]));
        $this->printer->warning(__('║  php.ini：%{1}                                                                 ║', [$iniFile]));
        $this->printer->warning(__('╠══════════════════════════════════════════════════════════════════════════════╣'));
        $this->printer->warning(__('║  当前已允许继续启动 HTTPS；若无法接受握手阻塞，请安装 event 或使用         ║'));
        $this->printer->warning(__('║  --no-ssl / env.server.https=false 仅跑 HTTP。                               ║'));
        $this->printer->warning(__('╚══════════════════════════════════════════════════════════════════════════════╝'));
    }
    
    /**
     * 运行 Master 进程（监控并自动重启 Worker；HTTPS 启用时可自动启动 HTTP 重定向进程）
     */
    protected function runMasterProcess(string $instanceName, array $config, string $workerScript, array $workerPids, string $sslCert = '', string $sslKey = '', bool $sslEnabled = false, int $httpRedirectPort = 0, bool $frontend = false): void
    {
        $masterPid = \getmypid();
        
        // 更新实例信息，记录 Master PID
        $this->updateInstanceMasterInfo($instanceName, $masterPid, true);
        
        $this->printer->note(__(''));
        $this->printer->success(__('╔══════════════════════════════════════════════════════════════════════════════╗'));
        $this->printer->success(__('║  Master 进程将监控并自动重启异常退出的 Worker                              ║'));
        $this->printer->success(__('╚══════════════════════════════════════════════════════════════════════════════╝'));
        $this->printer->note(__(''));
        $this->printer->note(__('Master PID: %{1}', [$masterPid]));
        if ($sslEnabled && $httpRedirectPort > 0) {
            $this->printer->note(__('HTTP 重定向: 端口 %{1} → HTTPS（不计入 Worker 数）', [$httpRedirectPort]));
        }
        $this->printer->note(__('健康检查间隔: 5 秒'));
        $this->printer->note(__('按 Ctrl+C 停止服务'));
        $this->printer->note(__(''));
        
        /** @var MasterProcess $master */
        $master = ObjectManager::getInstance(MasterProcess::class);
        try {
            $master->setPrinter($this->printer)
                ->init($instanceName, $config, $workerScript, $sslCert, $sslKey, $sslEnabled, $httpRedirectPort, $frontend)
                ->setWorkerPids($workerPids)
                ->run();
        } catch (\RuntimeException $e) {
            // HTTP 重定向端口被占用或其他启动错误
            $this->printer->error(__('服务器启动失败'));
            $this->printer->error($e->getMessage());
            $this->printer->note(__(''));
            $this->printer->note(__('解决方案：'));
            $this->printer->note(__('  1. 停止占用端口的进程'));
            $this->printer->note(__('  2. 或使用 --http-redirect-port 参数指定其他端口'));
            $this->printer->note(__('  3. 或在 env.php 中配置 http_redirect_port'));
            $this->printer->note(__(''));
            throw $e;
        }
    }
    
    /**
     * 更新实例的 Master 信息
     */
    protected function updateInstanceMasterInfo(string $instanceName, int $masterPid, bool $enabled): void
    {
        $instanceDir = Env::VAR_DIR . 'server' . DS . 'instances' . DS;
        $instanceFile = $instanceDir . $instanceName . '.json';
        
        if (!\file_exists($instanceFile)) {
            return;
        }
        
        $content = \file_get_contents($instanceFile);
        $data = \json_decode($content, true);
        
        if (\is_array($data)) {
            $data['master_enabled'] = $enabled;
            $data['master_pid'] = $masterPid;
            $data['master_started_at'] = \date('Y-m-d H:i:s');
            \file_put_contents($instanceFile, \json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * 开启维护模式（平滑重启时先开启，避免新请求进入）
     * 
     * 使用框架的维护模式配置，框架会自动处理维护页面显示
     */
    protected function enableMaintenanceMode(): void
    {
        Env::getInstance()->setConfig('maintenance', true);
    }

    /**
     * 关闭维护模式（平滑重启完成后关闭）
     */
    protected function disableMaintenanceMode(): void
    {
        Env::getInstance()->setConfig('maintenance', false);
    }
    
    /**
     * 通过健康检查接口等待所有 Worker 空闲
     * 
     * @param string $host 主机地址
     * @param int $port 端口
     * @param int $workerCount Worker 数量
     * @param int $maxWait 最大等待秒数
     * @param bool $sslEnabled 是否 HTTPS
     * @return bool 是否成功等待到空闲
     */
    protected function waitForIdleWorkers(string $host, int $port, int $workerCount, int $maxWait, bool $sslEnabled = false): bool
    {
        $startTime = \time();
        $checkInterval = 500000; // 500ms
        $scheme = $sslEnabled ? 'https' : 'http';
        $healthUrl = "/_wls/health";
        
        $this->printer->note(__('正在检测 Worker 状态（最长等待 %{1} 秒）...', [$maxWait]));
        
        $lastActiveRequests = -1;
        
        while ((\time() - $startTime) < $maxWait) {
            $totalActiveRequests = 0;
            $healthyWorkers = 0;
            
            // 检查所有 Worker 端口的健康状态
            for ($i = 0; $i < $workerCount; $i++) {
                $workerPort = $port + $i;
                $health = $this->checkWorkerHealth($host, $workerPort, $sslEnabled);
                
                if ($health !== null) {
                    $healthyWorkers++;
                    $totalActiveRequests += ($health['active_requests'] ?? 0);
                }
            }
            
            // 只在状态变化时输出
            if ($totalActiveRequests !== $lastActiveRequests) {
                if ($totalActiveRequests > 0) {
                    $this->printer->note(__('当前有 %{1} 个请求正在处理...', [$totalActiveRequests]));
                }
                $lastActiveRequests = $totalActiveRequests;
            }
            
            // 所有 Worker 都空闲
            if ($healthyWorkers > 0 && $totalActiveRequests === 0) {
                return true;
            }
            
            // 如果没有健康的 Worker，说明服务器可能已经停止或无法访问
            if ($healthyWorkers === 0) {
                $this->printer->note(__('无法连接到 Worker，直接切换...'));
                return true;
            }
            
            \usleep($checkInterval);
        }
        
        return false;
    }
    
    /**
     * 检查单个 Worker 的健康状态
     * 
     * 优先尝试 SSL，失败后回退到 TCP（Windows 兼容性）
     * 
     * @param string $host 主机地址
     * @param int $port 端口
     * @param bool $sslEnabled 是否 HTTPS（会同时尝试 SSL 和 TCP）
     * @return array|null 健康信息或 null（连接失败）
     */
    protected function checkWorkerHealth(string $host, int $port, bool $sslEnabled = false): ?array
    {
        $socket = null;
        
        // 优先尝试 SSL 连接
        if ($sslEnabled) {
            $context = \stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ]);
            $socket = @\stream_socket_client(
                "ssl://{$host}:{$port}",
                $errno,
                $errstr,
                2,
                STREAM_CLIENT_CONNECT,
                $context
            );
        }
        
        // 如果 SSL 失败或未启用 SSL，尝试 TCP（Windows 兼容性回退）
        if (!$socket) {
            $socket = @\stream_socket_client(
                "tcp://{$host}:{$port}",
                $errno,
                $errstr,
                2,
                STREAM_CLIENT_CONNECT
            );
        }
        
        if (!$socket) {
            return null;
        }
        
        // 发送 HTTP 请求
        $request = "GET /_wls/health HTTP/1.1\r\n";
        $request .= "Host: {$host}:{$port}\r\n";
        $request .= "Connection: close\r\n";
        $request .= "\r\n";
        
        @\fwrite($socket, $request);
        
        // 读取响应
        $response = '';
        \stream_set_timeout($socket, 2);
        while (!@\feof($socket)) {
            $chunk = @\fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }
        @\fclose($socket);
        
        if (empty($response)) {
            return null;
        }
        
        // 解析 JSON body
        $parts = \explode("\r\n\r\n", $response, 2);
        $body = $parts[1] ?? '';
        
        if (empty($body)) {
            return null;
        }
        
        $data = \json_decode($body, true);
        
        if (!\is_array($data)) {
            return null;
        }
        
        // 健康检查返回 maintenance 或 healthy 都算成功
        $status = $data['status'] ?? '';
        if ($status !== 'healthy' && $status !== 'maintenance') {
            return null;
        }
        
        return $data;
    }
    
    /**
     * 启动 PHP 内置 CLI 服务器（委托给 Framework）
     */
    protected function startCliServer(array $args, array $data): void
    {
        $this->printer->note(__(''));
        $this->printer->note(__('╔════════════════════════════════════════════════════╗'));
        $this->printer->note(__('║        PHP 内置 CLI 服务器                         ║'));
        $this->printer->note(__('╚════════════════════════════════════════════════════╝'));
        $this->printer->note(__(''));
        
        // 移除 --cli，避免 Framework 解析异常
        $cliArgs = $args;
        unset($cliArgs['cli']);
        
        $cliStart = ObjectManager::getInstance(\Weline\Server\Console\Console\Server\Start::class);
        $cliStart->execute($cliArgs, $data);
    }
    
    /**
     * 获取服务器配置
     * 优先级：命令行参数 > env.servers[实例名] > env.server > 默认值
     */
    protected function getServerConfig(string $instanceName, array $args): array
    {
        // 默认配置（文件监听默认关闭，避免频繁触发热重载导致 Worker 不断重启）
        $defaults = [
            'host' => '127.0.0.1',
            'port' => self::DEFAULT_PORT,
            'worker_count' => 'auto',
            'mode' => 'io',
            'daemon' => true,
            'hot_reload' => false,  // 默认值，实际启用逻辑：开发模式默认 true，生产模式默认 false
            'ssl_cert' => '',  // SSL 证书路径
            'ssl_key' => '',   // SSL 私钥路径
            'worker_base_port' => 10000,  // Dispatcher 模式下 Worker 内网端口基数（实际端口 = base + 外网端口）
            'source' => __('默认值'),
        ];
        
        $config = $defaults;
        
        // 1. 加载已保存的实例配置（配置记忆）
        // 优先级：命令行参数 > env 配置 > 已保存实例配置 > 默认值
        $savedConfig = $this->loadSavedInstanceConfig($instanceName);
        if ($savedConfig) {
            $config = \array_merge($config, $savedConfig);
            $config['source'] = __('已保存实例配置 (%{1})', [$instanceName]);
        }
        
        // 读取 env 配置
        $envConfig = Env::getInstance()->getConfig();
        
        // 2. 检查多实例配置 servers[实例名]
        if ($instanceName !== 'default' && isset($envConfig['servers'][$instanceName])) {
            $instanceConfig = $envConfig['servers'][$instanceName];
            $config = \array_merge($config, $instanceConfig);
            $config['source'] = __('env.servers.%{1}', [$instanceName]);
        }
        // 3. 检查默认服务器配置 server
        elseif (isset($envConfig['server']) && \is_array($envConfig['server'])) {
            $config = \array_merge($config, $envConfig['server']);
            $config['source'] = __('env.server');
        }
        // env.server.https = false 时也禁用 HTTPS（与 --no-ssl 一致，供生成地址等使用）
        if (isset($config['https']) && $config['https'] === false) {
            $config['no_ssl'] = true;
        }
        
        // 4. 命令行参数覆盖（最高优先级）
        $hasCliOverride = false;
        if (isset($args['host']) || isset($args['h'])) {
            $config['host'] = $args['host'] ?? $args['h'];
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        if (isset($args['port']) || isset($args['p'])) {
            $config['port'] = (int) ($args['port'] ?? $args['p']);
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        if (isset($args['count']) || isset($args['c'])) {
            $config['worker_count'] = (int) ($args['count'] ?? $args['c']);
            $config['source'] = __('命令行参数');
            $hasCliOverride = true;
        }
        // 默认一律后台运行；仅显式传入 --no-daemon 时前台运行（忽略 env 中的 daemon 配置）
        // 带 -r/--restart 时强制后台，避免被框架或 env 误判为前台
        $requestNoDaemon = (isset($args['no-daemon']) || isset($args['no_daemon']))
            && !(isset($args['r']) || isset($args['restart']));
        $config['daemon'] = !$requestNoDaemon;
        
        // --no-ssl / --http-only：仅 HTTP，不启用 HTTPS（Windows 下可不装 event）
        if (isset($args['no-ssl']) || isset($args['no_ssl']) || isset($args['http-only'])) {
            $config['no_ssl'] = true;
        }
        // SSL 证书配置（命令行参数优先）
        if (isset($args['ssl-cert'])) {
            $config['ssl_cert'] = $args['ssl-cert'];
        }
        if (isset($args['ssl-key'])) {
            $config['ssl_key'] = $args['ssl-key'];
        }
        
        // SSL 域名配置（用于证书生成）
        if (isset($args['ssl-domain']) || isset($args['domain'])) {
            $config['ssl_domain'] = $args['ssl-domain'] ?? $args['domain'] ?? '';
        }
        
        // HTTP 重定向端口配置（命令行参数优先）
        // 注意：需要区分"未指定"和"明确指定 0"（0 表示禁用重定向）
        if (isset($args['http-redirect-port']) || isset($args['redirect-port'])) {
            $redirectPortArg = $args['http-redirect-port'] ?? $args['redirect-port'] ?? null;
            // 即使值为 0，也认为是用户明确指定（禁用重定向）
            if ($redirectPortArg !== null) {
                $config['http_redirect_port'] = (int) $redirectPortArg;
                $config['http_redirect_port_explicit'] = true; // 标记为明确指定
                $config['source'] = __('命令行参数');
            }
        }
        
        // 如果未显式配置 SSL，检查是否有已存在的证书可用
        if (empty($config['ssl_cert']) && empty($config['ssl_key'])) {
            $autoSsl = $this->autoDetectSslCertificates();
            if ($autoSsl) {
                $config['ssl_cert'] = $autoSsl['cert'];
                $config['ssl_key'] = $autoSsl['key'];
                $config['ssl_domain'] = $autoSsl['domain'] ?? '';
            }
        }
        
        // 生成多域名证书映射文件（用于 SNI 支持）
        $this->generateCertificateMap();
        
        // 4. 计算实际 Worker 数量（智能模式）
        $config['worker_count'] = $this->calculateWorkerCount(
            $config['worker_count'],
            $config['mode'] ?? 'io'
        );
        
        return $config;
    }
    
    /**
     * 确保 SSL 证书可用
     * 
     * 逻辑：
     * 1. 如果已有有效证书，直接使用
     * 2. 开发环境/本地域名：自动生成自签证书
     * 3. 生产环境/公网域名：自动申请 Let's Encrypt 证书
     * 
     * @param string $instanceName 实例名称
     * @param array $config 服务器配置
     * @return array ['success' => bool, 'cert_path' => string, 'key_path' => string, ...]
     */
    protected function ensureSslCertificate(string $instanceName, array $config): array
    {
        /** @var SslCertificateService $sslService */
        $sslService = ObjectManager::getInstance(SslCertificateService::class);
        $host = $config['host'] ?? '127.0.0.1';
        
        // 智能判断是否为本地/内网环境（127.x, 10.x, 172.16-31.x, 192.168.x, localhost, *.local 等）
        $needsLocalCert = $sslService->needsSelfSignedCertificate($host);
        
        // 1. 如果命令行或配置中已指定证书，验证并使用
        if (!empty($config['ssl_cert']) && !empty($config['ssl_key'])) {
            $certPath = $config['ssl_cert'];
            $keyPath = $config['ssl_key'];
            
            if (!\is_file($certPath)) {
                return ['success' => false, 'message' => __('SSL 证书文件不存在：%{1}', [$certPath])];
            }
            if (!\is_file($keyPath)) {
                return ['success' => false, 'message' => __('SSL 私钥文件不存在：%{1}', [$keyPath])];
            }
            
            // 本地/内网环境：仅使用适用于当前 host 的证书，否则触发自动签发
            if ($needsLocalCert && !$sslService->certificateMatchesHost($certPath, $host)) {
                $config['ssl_cert'] = '';
                $config['ssl_key'] = '';
            } else {
                $certInfo = $sslService->parseCertificate($certPath);
                return [
                    'success' => true,
                    'cert_path' => $certPath,
                    'key_path' => $keyPath,
                    'issuer' => $certInfo['issuer'] ?? __('手动配置'),
                    'expires_at' => $certInfo['expires_at'] ?? '',
                    'is_new' => false,
                ];
            }
        }
        
        // 2. 确定域名
        $domain = $config['ssl_domain'] ?? '';
        if (empty($domain)) {
            // 本地回环 IP 用 localhost 作为证书域名；内网 IP 直接用 IP；域名保持原样
            if ($host === '127.0.0.1' || $host === '::1') {
                $domain = 'localhost';
            } elseif (\filter_var($host, FILTER_VALIDATE_IP)) {
                $domain = $host;  // 内网 IP 如 192.168.1.100 直接作为域名
            } else {
                $domain = $host;
            }
        }
        
        // 3. 使用 SslCertificateService 自动获取或生成证书
        $this->printer->note(__('正在为 %{1} 准备 SSL 证书...', [$domain]));
        
        $webroot = \defined('PUB') ? PUB : '';
        $email = Env::get('admin_email', 'admin@' . $domain);
        
        $result = $sslService->ensureCertificate($domain, $webroot, $email);
        
        return $result;
    }
    
    /**
     * 自动检测 app/etc/ssl/ 目录下的 SSL 证书
     * 
     * 目录结构：app/etc/ssl/{domain}/
     *   - fullchain.pem / privkey.pem (Let's Encrypt 格式)
     *   - cert.pem / key.pem
     *   - ssl.crt / ssl.key
     * 
     * 也兼容旧格式：app/etc/ 下直接放置的证书
     */
    protected function autoDetectSslCertificates(): ?array
    {
        $etcDir = \dirname(Env::path_ENV_FILE) . DS;
        $sslDir = $etcDir . 'ssl' . DS;
        
        // 支持的证书文件名格式（按优先级）
        $certFormats = [
            ['cert' => 'fullchain.pem', 'key' => 'privkey.pem'],  // Let's Encrypt 格式
            ['cert' => 'cert.pem', 'key' => 'key.pem'],
            ['cert' => 'ssl.crt', 'key' => 'ssl.key'],
            ['cert' => 'ssl.pem', 'key' => 'ssl.key'],
            ['cert' => 'server.crt', 'key' => 'server.key'],
            ['cert' => 'certificate.crt', 'key' => 'private.key'],
        ];
        
        // 1. 优先检查多域名目录结构：app/etc/ssl/{domain}/
        if (\is_dir($sslDir)) {
            $domains = @\scandir($sslDir);
            if ($domains) {
                foreach ($domains as $domain) {
                    if ($domain === '.' || $domain === '..' || !\is_dir($sslDir . $domain)) {
                        continue;
                    }
                    
                    $domainDir = $sslDir . $domain . DS;
                    
                    foreach ($certFormats as $format) {
                        $certPath = $domainDir . $format['cert'];
                        $keyPath = $domainDir . $format['key'];
                        
                        if (\is_file($certPath) && \is_file($keyPath)) {
                            return [
                                'cert' => $certPath,
                                'key' => $keyPath,
                                'domain' => $domain,
                                'format' => $format['cert'] . ' / ' . $format['key'],
                            ];
                        }
                    }
                }
            }
        }
        
        // 2. 兼容旧格式：app/etc/ 下直接放置的证书
        foreach ($certFormats as $format) {
            $certPath = $etcDir . $format['cert'];
            $keyPath = $etcDir . $format['key'];
            
            if (\is_file($certPath) && \is_file($keyPath)) {
                return [
                    'cert' => $certPath,
                    'key' => $keyPath,
                    'domain' => 'default',
                    'format' => $format['cert'] . ' / ' . $format['key'],
                ];
            }
        }
        
        return null;
    }
    
    /**
     * 生成多域名证书映射文件
     * 
     * 扫描 app/etc/ssl/{domain}/ 目录，生成 SNI 证书映射
     */
    protected function generateCertificateMap(): void
    {
        $etcDir = \dirname(Env::path_ENV_FILE) . DS;
        $sslDir = $etcDir . 'ssl' . DS;
        $mapFile = Env::VAR_DIR . 'server' . DS . 'ssl_certificate_map.json';
        
        // 确保目录存在
        $mapDir = \dirname($mapFile);
        if (!\is_dir($mapDir)) {
            @\mkdir($mapDir, 0755, true);
        }
        
        $certFormats = [
            ['cert' => 'fullchain.pem', 'key' => 'privkey.pem'],
            ['cert' => 'cert.pem', 'key' => 'key.pem'],
            ['cert' => 'ssl.crt', 'key' => 'ssl.key'],
        ];
        
        $map = [];
        
        if (\is_dir($sslDir)) {
            $domains = @\scandir($sslDir);
            if ($domains) {
                foreach ($domains as $domain) {
                    if ($domain === '.' || $domain === '..' || !\is_dir($sslDir . $domain)) {
                        continue;
                    }
                    
                    $domainDir = $sslDir . $domain . DS;
                    
                    foreach ($certFormats as $format) {
                        $certPath = $domainDir . $format['cert'];
                        $keyPath = $domainDir . $format['key'];
                        
                        if (\is_file($certPath) && \is_file($keyPath)) {
                            $map[$domain] = [
                                'cert' => $certPath,
                                'key' => $keyPath,
                            ];
                            break;
                        }
                    }
                }
            }
        }
        
        // 保存映射文件
        \file_put_contents($mapFile, \json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    /**
     * 计算 Worker 数量（智能模式）
     */
    protected function calculateWorkerCount($workerCount, string $mode): int
    {
        // 如果是具体数字，直接返回
        if (\is_int($workerCount) && $workerCount > 0) {
            return $workerCount;
        }
        
        // 如果是数字字符串，转换
        if (\is_string($workerCount) && \is_numeric($workerCount)) {
            return (int) $workerCount;
        }
        
        // 智能模式：根据环境、CPU 核心数和工作模式计算
        $deployMode = Env::getInstance()->getConfig('deploy') ?? 'dev';
        
        // 开发环境：固定 2 个 Worker，便于调试且节省资源
        if ($deployMode === 'dev') {
            return 2;
        }
        
        // 生产环境：根据 CPU 核心数和工作模式计算
        $cpuCount = $this->getCpuCoreCount();
        
        // 根据工作模式计算
        if ($mode === 'cpu') {
            // CPU 密集型：Worker = CPU 核心数
            $count = $cpuCount;
        } else {
            // I/O 密集型（默认）：Worker = CPU 核心数 * 2
            $count = $cpuCount * 2;
        }
        
        // 限制范围：最少 2 个，最多 16 个
        return \min(\max(2, $count), 16);
    }
    
    /**
     * 获取 CPU 核心数
     */
    protected function getCpuCoreCount(): int
    {
        if (IS_WIN) {
            return (int) (\getenv('NUMBER_OF_PROCESSORS') ?: 4);
        }
        
        // Linux/Mac
        $nproc = @\shell_exec('nproc 2>/dev/null');
        if ($nproc) {
            return (int) \trim($nproc);
        }
        
        // Mac 备用方案
        $sysctl = @\shell_exec('sysctl -n hw.ncpu 2>/dev/null');
        if ($sysctl) {
            return (int) \trim($sysctl);
        }
        
        return 4; // 默认 4 核
    }
    
    /**
     * 解析实例名称
     */
    protected function parseInstanceName(array $args): string
    {
        // 选项值（需要跳过的）
        $optionValues = [];
        $valueOptions = ['port', 'p', 'host', 'h', 'count', 'c'];
        foreach ($valueOptions as $opt) {
            if (isset($args[$opt])) {
                $optionValues[] = (string) $args[$opt];
            }
        }
        
        // 收集位置参数（排除选项值）
        $positionalArgs = [];
        foreach ($args as $key => $arg) {
            if (\is_int($key) && !\str_starts_with((string)$arg, '-')) {
                $strArg = (string) $arg;
                // 排除选项值
                if (!\in_array($strArg, $optionValues, true)) {
                    $positionalArgs[] = $strArg;
                }
            }
        }
        
        \array_shift($positionalArgs); // 移除命令名
        
        $instanceName = $positionalArgs[0] ?? 'default';
        
        // 验证实例名称（不能是纯数字，避免与选项值混淆）
        if (!\preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $instanceName)) {
            // 如果是纯数字，视为无效，使用默认值
            if (\preg_match('/^\d+$/', $instanceName)) {
                return 'default';
            }
            $this->printer->error(__('无效的实例名称：%{1}，只允许字母开头，包含字母、数字、下划线和横线', [$instanceName]));
            exit(1);
        }
        
        return $instanceName;
    }
    
    
    /**
     * 检测可用的进程控制函数
     */
    protected function detectAvailableFunctions(): void
    {
        $this->availableFunctions = [
            'proc_open' => \function_exists('proc_open') && !$this->isFunctionDisabled('proc_open'),
            'proc_close' => \function_exists('proc_close') && !$this->isFunctionDisabled('proc_close'),
            'pcntl_fork' => \function_exists('pcntl_fork') && !$this->isFunctionDisabled('pcntl_fork'),
            'exec' => \function_exists('exec') && !$this->isFunctionDisabled('exec'),
            'popen' => \function_exists('popen') && !$this->isFunctionDisabled('popen'),
            'shell_exec' => \function_exists('shell_exec') && !$this->isFunctionDisabled('shell_exec'),
        ];
    }
    
    /**
     * 检查函数是否被禁用
     */
    protected function isFunctionDisabled(string $function): bool
    {
        $disabled = \explode(',', \ini_get('disable_functions') ?: '');
        $disabled = \array_map('trim', $disabled);
        return \in_array($function, $disabled, true);
    }
    
    /**
     * 显示启动信息
     */
    protected function showStartupInfo(string $instanceName, string $host, int $port, int $count, bool $daemon, string $source = '', bool $sslEnabled = false, bool $dispatcherEnabled = false, int $workerPort = 0, int $httpRedirectPort = 0, bool $directReusePortEnabled = false): void
    {
        $this->printer->setup(__('Weline Server'));
        echo "\n";
        
        $cpuCores = $this->getCpuCoreCount();
        $protocol = $sslEnabled ? 'https' : 'http';
        $workerPort = $workerPort ?: $port;
        
        $this->printer->note('╔══════════════════════════════════════════════════════════════╗');
        $this->printer->note('║                   服务器启动配置                               ║');
        $this->printer->note('╠══════════════════════════════════════════════════════════════╣');
        $this->printer->note(\sprintf('║  实例名称：%-50s║', $instanceName));
        $this->printer->note(\sprintf('║  监听地址：%-50s║', "{$protocol}://{$host}:{$port}"));
        $this->printer->note(\sprintf('║  Worker 数：%-49s║', "{$count} (CPU: {$cpuCores} 核)"));
        
        if ($dispatcherEnabled) {
            $this->printer->note(\sprintf('║  流量分发：%-50s║', __('Dispatcher 模式（TCP 透传）')));
            $dispatcherProtocol = $sslEnabled ? 'TCP→SSL' : 'TCP';
            $this->printer->note(\sprintf('║  Dispatcher：%-48s║', "端口 {$port} ({$dispatcherProtocol})"));
            $workerProtocol = $sslEnabled ? 'SSL' : 'HTTP';
            $this->printer->note(\sprintf('║  Worker 端口：%-47s║', "{$workerPort} - " . ($workerPort + $count - 1) . " ({$workerProtocol})"));
        } else {
            if ($directReusePortEnabled) {
                $this->printer->note(\sprintf('║  端口模式：%-50s║', __('SO_REUSEPORT 同端口复用')));
                $this->printer->note(\sprintf('║  Worker 端口：%-47s║', "{$port} (共享端口)"));
            } else {
                $this->printer->note(\sprintf('║  端口范围：%-50s║', "{$port} - " . ($port + $count - 1)));
            }
        }
        
        // HTTPS 模式显示 HTTP 重定向端口
        if ($sslEnabled && $httpRedirectPort > 0) {
            $this->printer->note(\sprintf('║  HTTP 重定向：%-47s║', "端口 {$httpRedirectPort} → HTTPS"));
        }
        
        $this->printer->note(\sprintf('║  运行模式：%-50s║', $daemon ? __('后台运行（默认）') : __('前台运行')));
        $this->printer->note(\sprintf('║  SSL/HTTPS：%-49s║', $sslEnabled ? __('已启用') : __('未启用')));
        $this->printer->note(\sprintf('║  平台：%-54s║', IS_WIN ? 'Windows' : 'Linux/Mac'));
        $this->printer->note(\sprintf('║  配置来源：%-50s║', $source ?: __('智能模式')));
        $this->printer->note('╚══════════════════════════════════════════════════════════════╝');
        echo "\n";
        
        // 显示访问地址表格
        $this->showAccessUrls($host, $port, $sslEnabled, $httpRedirectPort);
        
        // 显示函数状态
        $this->showFunctionStatus();
    }
    
    /**
     * 80/443 标准端口自我处理提示
     * 
     * - Linux/Mac：特权端口需 root 或 setcap，否则提示
     * - 80/443 通常单端口监听，多 Worker 会占用 port~port+count-1
     */
    protected function showStandardPortHints(int $port, int $count, bool $sslEnabled): void
    {
        if ($port !== 80 && $port !== 443) {
            return;
        }
        
        $isPrivileged = ($port === 80 || $port === 443);
        
        if ($isPrivileged && !IS_WIN) {
            $this->printer->note(__('提示：端口 %{1} 为特权端口，Linux/Mac 下需 root 或 setcap 才能绑定。', [$port]));
            $this->printer->note(__('  • 以 root 运行：sudo php bin/w server:start -p %{1}', [$port]));
            $this->printer->note(__('  • 或授权能力：sudo setcap cap_net_bind_service=+ep $(which php)'));
            $this->printer->note(__('  • 或 Nginx 反代：Nginx 监听 %{1}，proxy_pass 到本机高端口（如 9981）', [$port]));
            echo "\n";
        }
        
        if ($port === 80 && $count > 1) {
            $this->printer->note(__('提示：HTTP 标准端口 80 通常单进程监听；当前 Worker 数 %{1} 将占用端口 %{2}-%{3}。若只需 80，请 -c 1。', [$count, $port, $port + $count - 1]));
            echo "\n";
        }
        
        if ($port === 443 && $count > 1) {
            $this->printer->note(__('提示：HTTPS 标准端口 443 通常单进程监听；当前 Worker 数 %{1} 将占用端口 %{2}-%{3}。若只需 443，请 -c 1。', [$count, $port, $port + $count - 1]));
            echo "\n";
        }
    }

    /**
     * 检查特权端口权限，不足时自动使用 sudo 重新执行并触发密码输入。
     *
     * @return bool true=可继续执行；false=当前进程应终止（已交给 sudo 子进程或提示失败）
     */
    protected function ensurePrivilegedPortPermission(int $mainPort, int $httpRedirectPort, bool $sslEnabled): bool
    {
        if (IS_WIN) {
            return true;
        }
        if (!\function_exists('posix_geteuid')) {
            // 无法判断权限时不阻断（保持兼容）
            return true;
        }
        if ((int)\posix_geteuid() === 0) {
            return true;
        }
        if (\getenv('WLS_SUDO_RELAUNCHED') === '1') {
            // 已经尝试过 sudo，避免死循环
            return true;
        }

        $privilegedPorts = [];
        if ($mainPort > 0 && $mainPort < 1024) {
            $privilegedPorts[] = $mainPort;
        }
        if ($sslEnabled && $httpRedirectPort > 0 && $httpRedirectPort < 1024) {
            $privilegedPorts[] = $httpRedirectPort;
        }
        $privilegedPorts = \array_values(\array_unique($privilegedPorts));
        if (empty($privilegedPorts)) {
            return true;
        }

        // 检测是否为交互式终端（多种方式兜底）
        $interactive = false;
        // 方式1：posix_isatty 检测 STDIN
        if (\defined('STDIN') && \function_exists('posix_isatty') && @\posix_isatty(STDIN)) {
            $interactive = true;
        }
        // 方式2：检查 /dev/tty 是否可读（Mac/Linux 通用）
        if (!$interactive && @\is_readable('/dev/tty')) {
            $interactive = true;
        }
        // 方式3：检查 TERM 环境变量是否存在（终端环境通常设置）
        if (!$interactive && \getenv('TERM')) {
            $interactive = true;
        }
        // 如果无法确定是否交互式，尝试直接执行 sudo（sudo 自己会报错如果无法获取密码）
        // 仅当明确判定为非交互式且无法继续时才提示
        $canPassthru = \function_exists('passthru');
        $canProcOpen = \function_exists('proc_open');
        if (!$interactive && !$canPassthru && !$canProcOpen) {
            $this->printer->error(__('端口 %{1} 需要 root 权限，请使用 sudo 重新执行。', [\implode(', ', $privilegedPorts)]));
            return false;
        }
        $rawArgv = $_SERVER['argv'] ?? [];
        if (!\is_array($rawArgv) || empty($rawArgv)) {
            $this->printer->error(__('无法自动重启为 sudo，请手动执行：sudo php bin/w server:start ...'));
            return false;
        }
        $parts = \array_merge([PHP_BINARY], $rawArgv);
        $escaped = \array_map('escapeshellarg', $parts);
        $relaunchCommand = 'sudo env WLS_SUDO_RELAUNCHED=1 ' . \implode(' ', $escaped);

        $this->printer->warning(__('检测到特权端口 %{1}，需要 root 权限。', [\implode(', ', $privilegedPorts)]));
        $this->printer->note(__('将执行命令：%{1}', [$relaunchCommand]));

        // 询问用户是否继续
        echo __('是否使用 sudo 继续？[Y/n] ');
        $input = \trim((string)@\fgets(STDIN));
        if ($input !== '' && !\in_array(\strtolower($input), ['y', 'yes', '是', ''], true)) {
            $this->printer->note(__('已取消。你可以手动执行：%{1}', [$relaunchCommand]));
            return false;
        }

        $exitCode = 0;
        if ($canPassthru) {
            @\passthru($relaunchCommand, $exitCode);
        } elseif ($canProcOpen) {
            // passthru 被禁用时用 proc_open 继承 STDIN/STDOUT/STDERR，支持交互式输入 sudo 密码
            $proc = @\proc_open(
                $relaunchCommand,
                [0 => STDIN, 1 => STDOUT, 2 => STDERR],
                $pipes,
                null,
                null
            );
            if (\is_resource($proc)) {
                $exitCode = (int) \proc_close($proc);
            } else {
                $exitCode = -1;
            }
        } else {
            // 两个函数都不可用，只能提示
            $this->printer->error(__('端口 %{1} 需要 root 权限；passthru/proc_open 均不可用，请使用 sudo 重新执行：', [\implode(', ', $privilegedPorts)]));
            $this->printer->note($relaunchCommand);
            return false;
        }
        if ($exitCode !== 0) {
            $this->printer->error(__('sudo 执行失败，退出码：%{1}', [(string)$exitCode]));
        }
        return false;
    }
    
    /**
     * Linux/macOS 下检测 socket 绑定权限，必要时询问用户使用 sudo。
     * 
     * 某些情况下即使高端口也可能需要权限：
     * - macOS：防火墙、沙盒、SIP 保护等
     * - Linux：SELinux、AppArmor、容器沙盒等
     * 
     * 此方法在启动前尝试绑定端口，失败时提示用户使用 sudo。
     *
     * @return bool true=可继续执行；false=当前进程应终止
     */
    protected function ensureUnixSocketPermission(string $host, int $port): bool
    {
        // Windows 不需要此检测
        if (IS_WIN) {
            return true;
        }
        
        // 仅 Linux 和 macOS 需要此检测
        if (PHP_OS !== 'Darwin' && PHP_OS !== 'Linux') {
            return true;
        }
        
        // 已是 root 或已 sudo 重启过，无需检测
        if (\function_exists('posix_geteuid') && (int)\posix_geteuid() === 0) {
            return true;
        }
        if (\getenv('WLS_SUDO_RELAUNCHED') === '1') {
            return true;
        }
        
        // 尝试绑定端口测试权限
        $testSocket = @\stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND
        );
        
        if ($testSocket) {
            @\fclose($testSocket);
            return true;
        }
        
        // 绑定失败，检查是否为权限问题
        $isPermissionError = \stripos($errstr, 'permission') !== false 
            || \stripos($errstr, 'denied') !== false
            || $errno === 13; // EACCES
        
        if (!$isPermissionError) {
            // 非权限问题（如端口已占用），不触发 sudo
            return true;
        }
        
        // 权限问题，询问用户是否使用 sudo
        $platform = PHP_OS === 'Darwin' ? 'macOS' : 'Linux';
        $this->printer->warning(__('%{1} 检测到 socket 权限问题：%{2}', [$platform, $errstr]));
        $this->printer->note(__('端口 %{1} 绑定需要更高权限（可能由防火墙或系统安全设置引起）。', [$port]));
        
        // 构建 sudo 重启命令
        $rawArgv = $_SERVER['argv'] ?? [];
        if (!\is_array($rawArgv) || empty($rawArgv)) {
            $this->printer->error(__('无法自动重启为 sudo，请手动执行：sudo php bin/w server:start ...'));
            return false;
        }
        $parts = \array_merge([PHP_BINARY], $rawArgv);
        $escaped = \array_map('escapeshellarg', $parts);
        $relaunchCommand = 'sudo env WLS_SUDO_RELAUNCHED=1 ' . \implode(' ', $escaped);
        
        $this->printer->note(__('将执行命令：%{1}', [$relaunchCommand]));
        
        echo __('是否使用 sudo 继续？[Y/n] ');
        $input = \trim((string)@\fgets(STDIN));
        if ($input !== '' && !\in_array(\strtolower($input), ['y', 'yes', '是', ''], true)) {
            $this->printer->note(__('已取消。你可以手动执行：%{1}', [$relaunchCommand]));
            return false;
        }
        
        $exitCode = 0;
        if (\function_exists('passthru')) {
            @\passthru($relaunchCommand, $exitCode);
        } elseif (\function_exists('proc_open')) {
            $proc = @\proc_open(
                $relaunchCommand,
                [0 => STDIN, 1 => STDOUT, 2 => STDERR],
                $pipes,
                null,
                null
            );
            if (\is_resource($proc)) {
                $exitCode = (int) \proc_close($proc);
            } else {
                $exitCode = -1;
            }
        } else {
            $this->printer->error(__('passthru/proc_open 均不可用，请手动执行：'));
            $this->printer->note($relaunchCommand);
            return false;
        }
        
        if ($exitCode !== 0) {
            $this->printer->error(__('sudo 执行失败，退出码：%{1}', [(string)$exitCode]));
        }
        return false;
    }
    
    /**
     * 显示访问地址表格
     * 
     * @param string $host 主机地址
     * @param int $port 端口
     * @param bool $sslEnabled 是否启用 HTTPS
     * @param int $httpRedirectPort HTTP 重定向端口
     */
    protected function showAccessUrls(string $host, int $port, bool $sslEnabled, int $httpRedirectPort = 0): void
    {
        $protocol = $sslEnabled ? 'https' : 'http';
        $baseUrl = "{$protocol}://{$host}";
        
        // 非默认端口时显示端口号
        $defaultPort = $sslEnabled ? 443 : 80;
        if ($port != $defaultPort) {
            $baseUrl .= ":{$port}";
        }
        
        // 获取后端路径（使用新的 area_routes 配置）
        $adminPath = \Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?: 'admin';
        $apiPath = \Weline\Framework\App\Env::getAreaRoutePrefix('rest_frontend') ?: 'api';
        $apiAdminPath = \Weline\Framework\App\Env::getAreaRoutePrefix('rest_backend') ?: 'api_admin';
        
        // 表格宽度
        $tableWidth = 76;
        $colType = 16;
        $colUrl = $tableWidth - $colType - 5;
        
        $this->printer->success('╔' . \str_repeat('═', $tableWidth) . '╗');
        $this->printer->success('║' . \str_pad(__('  访问地址'), $tableWidth, ' ', STR_PAD_RIGHT) . '║');
        $this->printer->success('╠' . \str_repeat('═', $colType) . '╤' . \str_repeat('═', $tableWidth - $colType - 1) . '╣');
        
        // 前端地址
        $frontendUrl = $baseUrl . '/';
        $this->printer->success('║ ' . \str_pad(__('前端'), $colType - 2, ' ') . '│ ' . \str_pad($frontendUrl, $colUrl - 1, ' ') . '║');
        
        // 后端地址
        $backendUrl = $baseUrl . '/' . $adminPath;
        $this->printer->success('║ ' . \str_pad(__('后端'), $colType - 2, ' ') . '│ ' . \str_pad($backendUrl, $colUrl - 1, ' ') . '║');
        
        // 分隔线后显示 REST API 与 HTTP 重定向（放在最后）
        $this->printer->success('╟' . \str_repeat('─', $colType) . '┼' . \str_repeat('─', $tableWidth - $colType - 1) . '╢');
        $apiUrl = $baseUrl . '/' . $apiPath . '/';
        $this->printer->success('║ ' . \str_pad(__('REST API 前端'), $colType - 2, ' ') . '│ ' . \str_pad($apiUrl, $colUrl - 1, ' ') . '║');
        $apiAdminUrl = $baseUrl . '/' . $apiAdminPath . '/';
        $this->printer->success('║ ' . \str_pad(__('REST API 后端'), $colType - 2, ' ') . '│ ' . \str_pad($apiAdminUrl, $colUrl - 1, ' ') . '║');
        if ($sslEnabled && $httpRedirectPort > 0) {
            $httpUrl = "http://{$host}:{$httpRedirectPort}/ → HTTPS";
            $this->printer->success('║ ' . \str_pad(__('HTTP 重定向'), $colType - 2, ' ') . '│ ' . \str_pad($httpUrl, $colUrl - 1, ' ') . '║');
        }
        
        $this->printer->success('╚' . \str_repeat('═', $colType) . '╧' . \str_repeat('═', $tableWidth - $colType - 1) . '╝');
        echo "\n";
    }
    
    /**
     * 显示函数状态
     */
    protected function showFunctionStatus(): void
    {
        $status = [];
        $importantFuncs = ['proc_open', 'pcntl_fork', 'exec'];
        
        foreach ($importantFuncs as $func) {
            $available = $this->availableFunctions[$func] ?? false;
            $icon = $available ? '✓' : '✗';
            $status[] = "{$func}: {$icon}";
        }
        
        $this->printer->note(__('进程函数：%{1}', [\implode(' | ', $status)]));
        echo "\n";
    }
    
    /**
     * 检查服务器是否已运行
     * 
     * 检测优先级（快→慢）：
     * 1. Processer 文件映射获取 PID（毫秒级，最快！）
     * 2. 端口检测（服务是否可用，与 server:status 一致）
     * 3. 实例文件中的 PID（兼容旧数据）
     * 
     * 注：进程名仅用于判断是否可以安全杀死，不用于存活检测
     */
    protected function isServerRunning(string $instanceName, int $port): bool
    {
        // 检查实例文件（无实例文件 = 从未启动过）
        $instanceFile = Env::VAR_DIR . 'server' . DS . 'instances' . DS . $instanceName . '.json';
        if (!\is_file($instanceFile)) {
            return false;
        }
        
        $instanceData = \json_decode(\file_get_contents($instanceFile), true);
        if (!$instanceData) {
            return false;
        }
        
        $count = (int) ($instanceData['count'] ?? 4);
        $workerPortBase = (int)($instanceData['worker_port'] ?? $port);
        
        // ========== 方案1：Processer 文件映射获取 PID（最快！） ==========
        // Worker 和 Dispatcher 启动时会调用 Processer::setPid 保存映射
        // getData() 直接读文件（< 1ms），isRunningByPid() 用 tasklist 精确匹配（10-50ms）
        
        // 检查 Dispatcher PID
        $dispatcherProcessName = '--name=weline-dispatcher-' . $instanceName;
        $dispatcherPid = (int) Processer::getData($dispatcherProcessName, 'pid');
        if ($dispatcherPid > 0 && Processer::isRunningByPid($dispatcherPid)) {
            return true;
        }
        
        // 检查 Worker PIDs
        for ($i = 1; $i <= $count; $i++) {
            $workerProcessName = '--name=weline-master-' . $instanceName . '-worker-' . $i;
            $workerPid = (int) Processer::getData($workerProcessName, 'pid');
            if ($workerPid > 0 && Processer::isRunningByPid($workerPid)) {
                return true;
            }
        }
        
        // ========== 方案2：端口检测（服务是否可用） ==========
        // 与 server:status 使用相同的 Processer::isPortInUse 逻辑
        
        // 检查主端口（Dispatcher 或直连）
        if (Processer::isPortInUse($port)) {
            return true;
        }
        
        // 检查 Worker 端口
        for ($i = 0; $i < $count; $i++) {
            $workerPort = $workerPortBase + $i;
            if (Processer::isPortInUse($workerPort)) {
                return true;
            }
        }
        
        // ========== 方案3：实例文件中的 PID（兼容旧数据） ==========
        // 检查 Master PID
        $masterPid = (int)($instanceData['master_pid'] ?? 0);
        if ($masterPid > 0 && Processer::processExists($masterPid)) {
            return true;
        }
        
        // 检查实例文件中的 Dispatcher PID
        $instDispatcherPid = (int)($instanceData['dispatcher_pid'] ?? 0);
        if ($instDispatcherPid > 0 && Processer::processExists($instDispatcherPid)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 显示服务器已运行的提示信息
     */
    protected function showAlreadyRunningInfo(string $instanceName, int $port): void
    {
        echo "\n";
        $this->printer->success(__('服务器实例 [%{1}] 已在运行中（端口 %{2}）', [$instanceName, $port]));
        echo "\n";
        
        $this->printer->setup(__('如需重启该实例：'));
        $this->printer->note('  php bin/w server:start ' . ($instanceName !== 'default' ? $instanceName . ' ' : '') . '-r');
        $this->printer->note('  ' . __('或使用 -r -f 强制切换（不等待请求完成）：'));
        $this->printer->note('  php bin/w server:start ' . ($instanceName !== 'default' ? $instanceName . ' ' : '') . '-r -f');
        echo "\n";
        
        $this->printer->setup(__('如需启动另一个实例（多实例并行）：'));
        $this->printer->note('  php bin/w server:start <name> -p <port>');
        $this->printer->note('  ' . __('示例：php bin/w server:start api -p %{1}', [$port + 1000]));
        $this->printer->note('  ' . __('首次指定端口后会自动记住，下次只需：php bin/w server:start api'));
        echo "\n";
        
        $this->printer->setup(__('其他操作：'));
        $this->printer->note('  ' . __('查看所有实例：php bin/w server:status --all'));
        $this->printer->note('  ' . __('停止该实例：php bin/w server:stop') . ($instanceName !== 'default' ? ' ' . $instanceName : ''));
        $this->printer->note('  ' . __('停止所有实例：php bin/w server:stop --all'));
        echo "\n";
    }
    
    /**
     * 停止现有服务器
     *
     * 委托给 server:stop 统一执行：先停 Master，再按进程名杀 Worker/Dispatcher 并清理 PID 文件，
     * 避免重复逻辑与 var/process/pid 残留。
     */
    protected function stopExistingServer(string $instanceName, int $port, int $count): void
    {
        $mainStop = ObjectManager::getInstance(MainStop::class);
        $mainStop->execute([0 => 'server:stop', 1 => $instanceName, 'force' => true, 'f' => true], []);
    }
    
    /**
     * 检查并释放端口
     * 
     * 注意：只杀框架进程（通过 --name=weline-xxx 识别），非框架进程不乱杀，提示用户手动处理。
     */
    /**
     * 检查并释放单个端口
     * 框架进程占用时最多尝试 3 次；仍杀不死则按 Master 前缀清理逃逸 Master 后再试一次。
     *
     * @param string $instanceName 实例名，用于按前缀清理逃逸 Master
     */
    protected function checkAndReleasePort(string $host, int $port, bool $forceRelease = false, string $label = 'Port', string $instanceName = 'default'): bool
    {
        $this->printer->note(__('检查 %{1} 端口 %{2} 可用性...', [$label, $port]));
        
        if (!Processer::isPortInUse($port)) {
            $this->printer->success(__('%{1} 端口 %{2} 可用 ✓', [$label, $port]));
            return true;
        }

        $isWelineProcess = Processer::isPortUsedByWeline($port);
        if (!$forceRelease) {
            $this->printer->error(__('%{1} 端口 %{2} 已被占用', [$label, $port]));
            $this->printer->note(__('使用 -r 参数强制重启（仅杀框架进程），或手动停止占用该端口的进程'));
            $this->printer->note(__('或使用: php bin/w server:kill-port %{1} -f', [$port]));
            return false;
        }
        if (!$isWelineProcess) {
            $this->printer->error(__('%{1} 端口 %{2} 被非框架进程占用，不予杀死', [$label, $port]));
            $this->printer->note(__('请手动停止占用该端口的进程，或更换端口'));
            $this->printer->note(__('或使用: php bin/w server:kill-port %{1} -f', [$port]));
            return false;
        }

        $this->printer->warning(__('%{1} 端口 %{2} 已被框架进程占用，强制释放...', [$label, $port]));
        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $released = Processer::killProcessByPort($port);
            if (!$released) {
                $released = Processer::forceReleasePort($port);
            }
            if (IS_WIN && !$released) {
                $waited = 0;
                while ($waited < 3000 && Processer::isPortInUse($port)) {
                    \usleep(300000);
                    $waited += 300;
                }
                $released = !Processer::isPortInUse($port);
            }
            if (!Processer::isPortInUse($port)) {
                $this->printer->success(__('%{1} 端口 %{2} 可用 ✓', [$label, $port]));
                return true;
            }
            if ($attempt < $maxAttempts) {
                \usleep(500000);
            }
        }

        // 三次仍杀不死：存在逃逸 Master 在不断拉起子进程，从 var/process 按 Master 前缀找并杀
        $this->printer->warning(__('端口 %{1} 经 %{2} 次仍占用，按 Master 前缀清理逃逸进程...', [$port, $maxAttempts]));
        $masterPrefix = MasterProcess::MASTER_PROCESS_NAME_PREFIX . $instanceName;
        $pnamesInProcessDir = Processer::getProcessNamesByPrefix($masterPrefix);
        if (\count($pnamesInProcessDir) > 0) {
            $this->printer->note(__('  从 var/process 发现 %{1} 个匹配 Master，正在按前缀杀死', [\count($pnamesInProcessDir)]));
        }
        $killed = Processer::killByProcessNamePrefix($masterPrefix);
        if ($killed > 0) {
            $this->printer->note(__('  已按前缀清理 %{1} 个逃逸 Master 进程', [$killed]));
        }
        \sleep(1);
        $released = Processer::killProcessByPort($port) || Processer::forceReleasePort($port);
        if (!Processer::isPortInUse($port)) {
            $this->printer->success(__('%{1} 端口 %{2} 可用 ✓', [$label, $port]));
            return true;
        }

        $this->printer->error(__('无法释放 %{1} 端口 %{2}', [$label, $port]));
        $this->printer->note(__('请尝试: php bin/w server:kill-port %{1} -f', [$port]));
        return false;
    }
    
    /**
     * 检查并释放多个端口（Worker 端口）
     * 框架进程占用时最多尝试 3 轮；仍杀不死则按 Master 前缀清理逃逸 Master 后再试一轮。
     *
     * @param string $instanceName 实例名，用于按前缀清理逃逸 Master
     */
    protected function checkAndReleasePorts(string $host, int $port, int $count, bool $forceRelease = false, string $instanceName = 'default'): bool
    {
        $this->printer->note(__('检查 Worker 端口可用性...'));
        
        $portsInUse = [];
        for ($i = 0; $i < $count; $i++) {
            $currentPort = $port + $i;
            if (Processer::isPortInUse($currentPort)) {
                $portsInUse[] = $currentPort;
            }
        }
        if (empty($portsInUse)) {
            $this->printer->success(__('端口检查通过'));
            echo "\n";
            return true;
        }

        if (!$forceRelease) {
            $this->printer->error(__('端口 %{1} 已被占用', [$portsInUse[0]]));
            $this->printer->note(__('使用 -r 参数强制重启（仅杀框架进程），或手动停止占用该端口的进程'));
            return false;
        }
        foreach ($portsInUse as $p) {
            if (!Processer::isPortUsedByWeline($p)) {
                $this->printer->error(__('端口 %{1} 被非框架进程占用，不予杀死', [$p]));
                $this->printer->note(__('请手动停止占用该端口的进程，或更换端口'));
                return false;
            }
        }

        $maxAttempts = 3;
        for ($round = 1; $round <= $maxAttempts; $round++) {
            foreach ($portsInUse as $p) {
                Processer::killProcessByPort($p);
                Processer::forceReleasePort($p);
            }
            if (IS_WIN) {
                \usleep(500000);
            }
            $stillInUse = [];
            foreach ($portsInUse as $p) {
                if (Processer::isPortInUse($p)) {
                    $stillInUse[] = $p;
                }
            }
            $portsInUse = $stillInUse;
            if (empty($portsInUse)) {
                $this->printer->success(__('端口检查通过'));
                echo "\n";
                return true;
            }
            if ($round < $maxAttempts) {
                \usleep(500000);
            }
        }

        // 三轮仍杀不死：从 var/process 按 Master 前缀找并杀逃逸 Master 后再试
        $this->printer->warning(__('端口经 %{1} 轮仍占用，按 Master 前缀清理逃逸进程...', [$maxAttempts]));
        $masterPrefix = MasterProcess::MASTER_PROCESS_NAME_PREFIX . $instanceName;
        $pnamesInProcessDir = Processer::getProcessNamesByPrefix($masterPrefix);
        if (\count($pnamesInProcessDir) > 0) {
            $this->printer->note(__('  从 var/process 发现 %{1} 个匹配 Master，正在按前缀杀死', [\count($pnamesInProcessDir)]));
        }
        $killed = Processer::killByProcessNamePrefix($masterPrefix);
        if ($killed > 0) {
            $this->printer->note(__('  已按前缀清理 %{1} 个逃逸 Master 进程', [$killed]));
        }
        \sleep(1);
        foreach ($portsInUse as $p) {
            Processer::killProcessByPort($p);
            Processer::forceReleasePort($p);
        }
        $stillInUse = [];
        foreach ($portsInUse as $p) {
            if (Processer::isPortInUse($p)) {
                $stillInUse[] = $p;
            }
        }
        if (empty($stillInUse)) {
            $this->printer->success(__('端口检查通过'));
            echo "\n";
            return true;
        }

        $this->printer->error(__('无法释放端口 %{1}', [$stillInUse[0]]));
        $this->printer->note(__('请尝试: php bin/w server:kill-port %{1} -f', [$stillInUse[0]]));
        return false;
    }

    /**
     * 查找 Dispatcher 模式下可用的 Worker 连续端口段（仅跳过非框架占用）
     */
    protected function findAvailableWorkerPortBase(int $startPort, int $count, int $maxScan = 500): int
    {
        $base = $startPort;
        for ($attempt = 0; $attempt < $maxScan; $attempt++, $base++) {
            $hasNonFrameworkConflict = false;
            for ($i = 0; $i < $count; $i++) {
                $port = $base + $i;
                if (Processer::isPortInUse($port) && !Processer::isPortUsedByWeline($port)) {
                    $hasNonFrameworkConflict = true;
                    break;
                }
            }
            if (!$hasNonFrameworkConflict) {
                return $base;
            }
        }
        return $startPort;
    }
    
    /**
     * 智能端口分配：查找可用的 Worker 连续端口段
     * 
     * - WLS 进程占用的端口：释放后分配给新进程
     * - 非 WLS 进程占用的端口：跳过，使用下一个可用端口
     * 
     * @param int $startPort 起始端口
     * @param int $count Worker 数量
     * @param int $maxScan 最大扫描次数
     * @return int 可用的起始端口
     */
    protected function findAvailableWorkerPortBaseWithRelease(int $startPort, int $count, int $maxScan = 500): int
    {
        $base = $startPort;
        
        for ($attempt = 0; $attempt < $maxScan; $attempt++, $base++) {
            $hasNonFrameworkConflict = false;
            $welinePortsToRelease = [];
            
            // 检查这一段端口的占用情况
            for ($i = 0; $i < $count; $i++) {
                $port = $base + $i;
                
                if (!Processer::isPortInUse($port)) {
                    // 端口空闲，继续检查下一个
                    continue;
                }
                
                if (Processer::isPortUsedByWeline($port)) {
                    // WLS 进程占用，记录待释放
                    $welinePortsToRelease[] = $port;
                } else {
                    // 非 WLS 进程占用，跳过这一段
                    $hasNonFrameworkConflict = true;
                    break;
                }
            }
            
            if ($hasNonFrameworkConflict) {
                // 有非框架进程占用，跳到下一段
                continue;
            }
            
            // 释放 WLS 占用的端口
            foreach ($welinePortsToRelease as $portToRelease) {
                $pid = Processer::getProcessIdByPort($portToRelease);
                if ($pid > 0) {
                    $this->printer->note(__('释放 WLS 进程占用的端口 %{1} (PID: %{2})...', [$portToRelease, $pid]));
                    Processer::killByPid($pid);
                    // 等待进程退出
                    \usleep(100000); // 100ms
                }
            }
            
            // 再次确认端口已释放
            $allReleased = true;
            foreach ($welinePortsToRelease as $portToCheck) {
                Processer::clearPortCache($portToCheck);
                if (Processer::isPortInUse($portToCheck)) {
                    $allReleased = false;
                    break;
                }
            }
            
            if ($allReleased) {
                return $base;
            }
            
            // 如果释放失败，继续尝试下一段
        }
        
        return $startPort;
    }
    
    /**
     * 保存实例信息
     */
    protected function saveInstanceInfo(string $instanceName, string $host, int $port, int $count, bool $daemon, bool $sslEnabled = false, string $sslCert = '', string $sslKey = '', array $workerPids = [], bool $dispatcherEnabled = false, int $workerPort = 0, int $httpRedirectPort = 0, bool $frontend = false, bool $enableLog = false, bool $useDirectMode = false): void
    {
        $instanceDir = Env::VAR_DIR . 'server' . DS . 'instances' . DS;
        if (!\is_dir($instanceDir)) {
            @\mkdir($instanceDir, 0755, true);
        }
        
        $instanceFile = $instanceDir . $instanceName . '.json';
        $instanceData = [
            'name' => $instanceName,
            'host' => $host,
            'port' => $port,
            'count' => $count,
            'daemon' => $daemon,
            'ssl_enabled' => $sslEnabled,
            'ssl_cert' => $sslCert,
            'ssl_key' => $sslKey,
            'started_at' => \date('Y-m-d H:i:s'),
            'started_timestamp' => \time(),
            'pid' => \getmypid(),
            'worker_pids' => $workerPids,
            'master_enabled' => false,
            'master_pid' => 0,
            // Dispatcher 模式信息
            'dispatcher_enabled' => $dispatcherEnabled,
            'dispatcher_port' => $dispatcherEnabled ? $port : 0,
            'dispatcher_pid' => 0,
            'worker_port' => $workerPort ?: $port,  // Worker 实际监听的端口（Dispatcher 模式下为内网端口）
            // HTTP 重定向端口（HTTPS 模式下用于 HTTP→HTTPS 跳转）
            'http_redirect_port' => $httpRedirectPort,
            // 前台模式（重启 Worker 时保持可见窗口）
            'frontend' => $frontend,
            // 进程日志开关（-log 参数或 env 配置 system.processer.log）
            'enable_log' => $enableLog,
        ];

        // 设置 Master 运行模式
        // - 直连模式（SO_REUSEPORT）：所有 Worker 共用主端口
        // - 独立端口模式：每个 Worker 使用独立端口
        // - Dispatcher 模式：Worker 使用内网端口，Dispatcher 监听主端口
        if ($useDirectMode) {
            $instanceData['master_mode'] = MasterProcess::MODE_LINUX_DIRECT;
            $instanceData['main_port'] = $port;
        } elseif (!$dispatcherEnabled) {
            // 独立端口模式（非直连、非 Dispatcher）
            $instanceData['master_mode'] = MasterProcess::MODE_LEGACY;
            $instanceData['main_port'] = $workerPort;
        } else {
            // Dispatcher 模式
            $instanceData['master_mode'] = MasterProcess::MODE_LEGACY;
            $instanceData['main_port'] = 0;
        }
        
        \file_put_contents($instanceFile, \json_encode($instanceData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 将实际的 host/port/https 同步到 env.php 的 server 配置
     *
     * http:req 等 CLI 工具依赖 env.server.{host,port,https} 构建请求 URL，
     * 若 server:start 实际使用的端口与 env 不一致（如自动从 80→443 或备用 9981），
     * 会导致 http:req 请求到错误地址。此方法在启动时自动同步，保证一致。
     */
    protected function syncServerConfigToEnv(string $host, int $port, bool $sslEnabled): void
    {
        $env = Env::getInstance();
        $serverConfig = $env->get('server') ?? [];
        if (!\is_array($serverConfig)) {
            $serverConfig = [];
        }
        
        $needsUpdate = false;
        if (($serverConfig['host'] ?? null) !== $host) {
            $needsUpdate = true;
        }
        if (($serverConfig['port'] ?? null) !== $port) {
            $needsUpdate = true;
        }
        if (($serverConfig['https'] ?? null) !== $sslEnabled) {
            $needsUpdate = true;
        }
        
        if ($needsUpdate) {
            $serverConfig['host'] = $host;
            $serverConfig['port'] = $port;
            $serverConfig['https'] = $sslEnabled;
            $env->setConfig('server', $serverConfig);
        }
    }
    
    /*----------------------------------------实例配置记忆（Config Shorthand）------------------------------------------*/
    
    /**
     * 获取实例配置文件目录
     */
    protected function getInstanceConfigDir(): string
    {
        return Env::VAR_DIR . 'server' . DS . 'config' . DS;
    }
    
    /**
     * 获取实例配置文件路径
     */
    protected function getInstanceConfigFile(string $instanceName): string
    {
        return $this->getInstanceConfigDir() . $instanceName . '.json';
    }
    
    /**
     * 加载已保存的实例配置
     * 
     * 当用户首次使用 server:start api -p 8443 启动后，配置会被记住。
     * 下次运行 server:start api 时自动加载已保存的端口、地址、Worker 数等配置。
     * 
     * @param string $instanceName 实例名称
     * @return array|null 已保存的配置，未保存时返回 null
     */
    protected function loadSavedInstanceConfig(string $instanceName): ?array
    {
        $configFile = $this->getInstanceConfigFile($instanceName);
        if (!\is_file($configFile)) {
            return null;
        }
        $content = @\file_get_contents($configFile);
        if ($content === false) {
            return null;
        }
        $data = \json_decode($content, true);
        if (!\is_array($data) || empty($data)) {
            return null;
        }
        return $data;
    }
    
    /**
     * 保存实例配置（配置记忆）
     * 
     * 将命令行指定的参数（端口、地址、Worker 数等）保存到实例配置文件。
     * 下次用相同实例名启动时，无需再次指定这些参数。
     * 
     * 仅保存用户显式指定的配置项，不保存运行时状态（如 PID、Worker PIDs 等）。
     * 
     * @param string $instanceName 实例名称
     * @param array $args 命令行参数
     * @param array $config 最终合并后的配置
     */
    protected function saveInstanceConfig(string $instanceName, array $args, array $config): void
    {
        $configDir = $this->getInstanceConfigDir();
        if (!\is_dir($configDir)) {
            @\mkdir($configDir, 0755, true);
        }
        
        // 仅保存可复用的配置项（不包含运行时状态）
        $savedConfig = [];
        
        // 从当前合并后的配置中提取可复用项
        $persistKeys = ['host', 'port', 'mode', 'no_ssl', 'ssl_cert', 'ssl_key', 'ssl_domain', 'http_redirect_port', 'worker_base_port'];
        foreach ($persistKeys as $key) {
            if (isset($config[$key])) {
                $savedConfig[$key] = $config[$key];
            }
        }
        
        // 记录保存时间
        $savedConfig['saved_at'] = \date('Y-m-d H:i:s');
        
        $configFile = $this->getInstanceConfigFile($instanceName);
        \file_put_contents($configFile, \json_encode($savedConfig, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
    }
    
    /*----------------------------------------实例配置记忆结束------------------------------------------*/
    
    /**
     * 更新实例的 Worker PID 列表
     */
    protected function updateInstanceWorkerPids(string $instanceName, array $workerPids): void
    {
        $instanceDir = Env::VAR_DIR . 'server' . DS . 'instances' . DS;
        $instanceFile = $instanceDir . $instanceName . '.json';
        
        if (!\file_exists($instanceFile)) {
            return;
        }
        
        $content = \file_get_contents($instanceFile);
        $data = \json_decode($content, true);
        
        if (!\is_array($data)) {
            return;
        }
        
        $data['worker_pids'] = $workerPids;
        
        \file_put_contents($instanceFile, \json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 确保 Worker 脚本存在
     * 
     * 注意：不再覆盖已有文件，bin/worker.php 和 bin/worker_ssl.php 已集成框架路由
     */
    protected function ensureWorkerScript(bool $sslEnabled = false): string
    {
        $suffix = $sslEnabled ? '_ssl' : '';
        $workerScript = BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Server' . DS . 'bin' . DS . "worker{$suffix}.php";
        $scriptDir = \dirname($workerScript);
        
        if (!\is_dir($scriptDir)) {
            @\mkdir($scriptDir, 0755, true);
        }
        
        // 只在文件不存在时创建（不覆盖已有的框架集成版本）
        if (!\file_exists($workerScript)) {
            $script = $sslEnabled ? $this->getSslWorkerScriptContent() : $this->getWorkerScriptContent();
            \file_put_contents($workerScript, $script);
        }
        
        return $workerScript;
    }
    
    /**
     * 获取 Worker 脚本内容
     */
    protected function getWorkerScriptContent(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

/**
 * Weline Server Worker 独立进程
 * 
 * 用法: php worker.php <host> <port> <worker_id> [instance_name]
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

// 获取参数
$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 9981);
$workerId = (int) ($argv[3] ?? 1);
$instanceName = $argv[4] ?? 'default';

// 静默模式，不输出到控制台
error_reporting(0);

// 创建 socket
$context = stream_context_create([
    'socket' => [
        'backlog' => 1024,
        'so_reuseaddr' => true,
    ]
]);

$socket = @stream_socket_server(
    "tcp://{$host}:{$port}",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);

if (!$socket) {
    exit(1);
}

stream_set_blocking($socket, false);

$connections = [];
$requestCount = 0;

// 事件循环
while (true) {
    $read = array_merge([$socket], $connections);
    $write = [];
    $except = [];
    
    $changed = @stream_select($read, $write, $except, 0, 100000);
    
    if ($changed === false) {
        continue;
    }
    
    // 新连接
    if (in_array($socket, $read)) {
        $conn = @stream_socket_accept($socket, 0);
        if ($conn) {
            stream_set_blocking($conn, false);
            $connections[(int)$conn] = $conn;
        }
        $key = array_search($socket, $read);
        unset($read[$key]);
    }
    
    // 处理连接
    foreach ($read as $conn) {
        $data = @fread($conn, 65535);
        
        if ($data === false || $data === '') {
            @fclose($conn);
            unset($connections[(int)$conn]);
            continue;
        }
        
        $requestCount++;
        
        // 高性能响应
        $body = "Hello Weline Server! Instance: {$instanceName}, Worker: {$workerId}, Port: {$port}, Request: {$requestCount}";
        $response = "HTTP/1.1 200 OK\r\n";
        $response .= "Content-Type: text/plain; charset=utf-8\r\n";
        $response .= "Content-Length: " . strlen($body) . "\r\n";
        $response .= "Connection: keep-alive\r\n";
        $response .= "\r\n";
        $response .= $body;
        
        @fwrite($conn, $response);
    }
}
PHP;
    }
    
    /**
     * 获取 SSL Worker 脚本内容
     */
    protected function getSslWorkerScriptContent(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

/**
 * Weline Server Worker 独立进程 (SSL/HTTPS)
 * 
 * 用法: php worker_ssl.php <host> <port> <worker_id> <instance_name> <ssl_cert> <ssl_key>
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

// 获取参数
$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 9981);
$workerId = (int) ($argv[3] ?? 1);
$instanceName = $argv[4] ?? 'default';
$sslCert = $argv[5] ?? '';
$sslKey = $argv[6] ?? '';

// 静默模式，不输出到控制台
error_reporting(0);

// 确定最高支持的 TLS 版本
$cryptoMethod = STREAM_CRYPTO_METHOD_TLS_SERVER;
if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')) {
    // PHP 7.4+ 支持 TLS 1.3（最高协议）
    $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_3_SERVER | STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_1_SERVER | STREAM_CRYPTO_METHOD_TLSv1_0_SERVER;
} elseif (defined('STREAM_CRYPTO_METHOD_TLSv1_2_SERVER')) {
    // TLS 1.2
    $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_1_SERVER | STREAM_CRYPTO_METHOD_TLSv1_0_SERVER;
}

// 创建 SSL 上下文（支持所有协议，默认使用最高版本）
$context = stream_context_create([
    'socket' => [
        'backlog' => 1024,
        'so_reuseaddr' => true,
    ],
    'ssl' => [
        'local_cert' => $sslCert,
        'local_pk' => $sslKey,
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
        'disable_compression' => true,
        'crypto_method' => $cryptoMethod,
        // 安全密码套件（优先使用高强度加密）
        'ciphers' => 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384:HIGH:!aNULL:!MD5:!RC4',
        // 禁用不安全的协议
        'single_dh_use' => true,
        'honor_cipher_order' => true,
    ]
]);

$socket = @stream_socket_server(
    "ssl://{$host}:{$port}",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);

if (!$socket) {
    exit(1);
}

stream_set_blocking($socket, false);

$connections = [];
$requestCount = 0;

// 事件循环
while (true) {
    $read = array_merge([$socket], $connections);
    $write = [];
    $except = [];
    
    $changed = @stream_select($read, $write, $except, 0, 100000);
    
    if ($changed === false) {
        continue;
    }
    
    // 新连接
    if (in_array($socket, $read)) {
        $conn = @stream_socket_accept($socket, 0);
        if ($conn) {
            stream_set_blocking($conn, false);
            $connections[(int)$conn] = $conn;
        }
        $key = array_search($socket, $read);
        unset($read[$key]);
    }
    
    // 处理连接
    foreach ($read as $conn) {
        $data = @fread($conn, 65535);
        
        if ($data === false || $data === '') {
            @fclose($conn);
            unset($connections[(int)$conn]);
            continue;
        }
        
        $requestCount++;
        
        // 高性能响应
        $body = "Hello Weline Server (HTTPS)! Instance: {$instanceName}, Worker: {$workerId}, Port: {$port}, Request: {$requestCount}";
        $response = "HTTP/1.1 200 OK\r\n";
        $response .= "Content-Type: text/plain; charset=utf-8\r\n";
        $response .= "Content-Length: " . strlen($body) . "\r\n";
        $response .= "Connection: keep-alive\r\n";
        $response .= "\r\n";
        $response .= $body;
        
        @fwrite($conn, $response);
    }
}
PHP;
    }
    
    /**
     * 启动 Workers
     * 
     * @return array 成功启动的 Worker PID 列表
     */
    protected function startWorkers(string $instanceName, string $host, int $port, int $count, string $workerScript, string $sslCert = '', string $sslKey = '', bool $frontend = false): array
    {
        $this->printer->note(__('启动 Worker 进程...'));
        echo "\n";
        
        $phpBinary = PHP_BINARY;
        $pids = [];
        $sslEnabled = !empty($sslCert) && !empty($sslKey);
        
        for ($i = 0; $i < $count; $i++) {
            $workerPort = $port + $i;
            $workerId = $i + 1;
            
            $result = $this->startSingleWorker($phpBinary, $workerScript, $host, $workerPort, $workerId, $instanceName, $sslCert, $sslKey, $frontend);
            
            if ($result['success']) {
                $protocol = $sslEnabled ? 'HTTPS' : 'HTTP';
                $this->printer->success(__('  ├─ Worker #%{1} (%{2} 端口: %{3}) - 启动成功', [$workerId, $protocol, $workerPort]));
                $pids[] = $result['pid'];
            } else {
                $this->printer->error(__('  ├─ Worker #%{1} (端口: %{2}) - 启动失败', [$workerId, $workerPort]));
            }
        }
        
        echo "\n";
        
        // 启动时不校验进程状态（避免检测延迟导致误报），用户可用 server:status 自行校验
        return $pids;
    }
    
    /**
     * 启动 Dispatcher 进程（流量分发器）
     * 
     * Dispatcher 监听主端口（如 443），将请求转发给 Worker（监听内网端口）
     * 
     * @return int Dispatcher PID，失败返回 0
     */
    protected function startDispatcher(string $instanceName, string $host, int $dispatcherPort, int $workerBasePort, int $workerCount, bool $frontend = false): int
    {
        $this->printer->note(__('启动 Dispatcher 进程...'));
        
        $phpBinary = PHP_BINARY;
        $dispatcherScript = \dirname(__DIR__, 2) . DS . 'bin' . DS . 'dispatcher.php';
        
        if (!\is_file($dispatcherScript)) {
            $this->printer->error(__('Dispatcher 脚本不存在: %{1}', [$dispatcherScript]));
            return 0;
        }
        
        $logDir = Env::VAR_DIR . 'log' . DS;
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }
        
        // 统一进程名
        $processName = 'weline-dispatcher-' . $instanceName;
        
        // 使用进程管理器统一创建进程
        // 参数格式: <host> <port> <worker_base_port> <worker_count> <instance_name>
        $command = "\"{$phpBinary}\" \"{$dispatcherScript}\" {$host} {$dispatcherPort} {$workerBasePort} {$workerCount} {$instanceName} --name={$processName}";
        if ($frontend) {
            $command .= " --frontend";
        }
        // macOS 上 nohup 可能不正确继承 sudo 权限，使用 sudo -E -n 确保子进程以 root 运行
        if (!IS_WIN && \function_exists('posix_geteuid') && (int)\posix_geteuid() === 0) {
            $command = 'sudo -E -n ' . $command;
        }
        
        $pid = Processer::create($command, true, $frontend);
        
        if ($pid > 0) {
            $this->updateInstanceDispatcherPid($instanceName, $pid);
        }
        
        // 前端模式或后台模式：如果 PID 获取失败，通过端口检测
        if ($pid <= 0) {
            $maxWait = $frontend ? 3000 : 500;
            $waitStep = 100;
            $waited = 0;
            
            while ($waited < $maxWait) {
                \usleep($waitStep * 1000);
                $waited += $waitStep;
                
                $pid = Processer::getProcessIdByPort($dispatcherPort);
                if ($pid > 0) {
                    Processer::setPid($processName, $pid);
                    $this->updateInstanceDispatcherPid($instanceName, $pid);
                    break;
                }
            }
        }
        
        return $pid;
    }
    
    /**
     * 更新实例的 Dispatcher PID
     */
    protected function updateInstanceDispatcherPid(string $instanceName, int $dispatcherPid): void
    {
        $instanceDir = Env::VAR_DIR . 'server' . DS . 'instances' . DS;
        $instanceFile = $instanceDir . $instanceName . '.json';
        
        if (!\file_exists($instanceFile)) {
            return;
        }
        
        $content = \file_get_contents($instanceFile);
        $data = \json_decode($content, true);
        if (!\is_array($data)) {
            return;
        }
        
        $data['dispatcher_pid'] = $dispatcherPid;
        \file_put_contents($instanceFile, \json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 启动单个 Worker
     */
    protected function startSingleWorker(string $phpBinary, string $workerScript, string $host, int $port, int $workerId, string $instanceName, string $sslCert = '', string $sslKey = '', bool $frontend = false): array
    {
        $logDir = Env::VAR_DIR . 'log' . DS;
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . "worker-{$port}.log";
        
        // 方案1：proc_open（最可靠）
        if ($this->availableFunctions['proc_open'] && $this->availableFunctions['proc_close']) {
            $result = $this->startWithProcOpen($phpBinary, $workerScript, $host, $port, $workerId, $instanceName, $logFile, $sslCert, $sslKey, $frontend);
            if ($result['success']) {
                $this->usedMethod = 'proc_open';
                return $result;
            }
        }
        
        // 方案2：pcntl_fork（仅 Linux/Mac）
        if (!IS_WIN && $this->availableFunctions['pcntl_fork']) {
            $result = $this->startWithPcntlFork($phpBinary, $workerScript, $host, $port, $workerId, $instanceName, $logFile, $sslCert, $sslKey, $frontend);
            if ($result['success']) {
                $this->usedMethod = 'pcntl_fork';
                return $result;
            }
        }
        
        // 方案3（备用）：exec + 批处理/nohup
        if ($this->availableFunctions['exec']) {
            $result = $this->startWithExec($phpBinary, $workerScript, $host, $port, $workerId, $instanceName, $logFile, $sslCert, $sslKey, $frontend);
            if ($result['success']) {
                $this->usedMethod = IS_WIN ? 'exec (bat)' : 'exec (nohup)';
                return $result;
            }
        }
        
        return ['success' => false, 'pid' => 0, 'error' => __('没有可用的进程创建函数')];
    }
    
    /**
     * 使用 proc_open 启动
     */
    protected function startWithProcOpen(string $phpBinary, string $workerScript, string $host, int $port, int $workerId, string $instanceName, string $logFile, string $sslCert = '', string $sslKey = '', bool $frontend = false): array
    {
        // 进程名包含实例名和 Worker ID，便于多 Master 架构下识别和管理
        $processName = 'weline-master-' . $instanceName . '-worker-' . $workerId;
        $command = "\"{$phpBinary}\" \"{$workerScript}\" {$host} {$port} {$workerId} {$instanceName}";
        if ($sslCert && $sslKey) {
            $command .= " \"{$sslCert}\" \"{$sslKey}\"";
            // TCP 透传模式：Worker 需要延迟 SSL 握手
            // Dispatcher 透传原始 TCP 字节，Worker 在 accept 后手动启用 SSL
            $command .= " --defer-ssl";
        }
        $command .= " --name={$processName}";
        if ($frontend) {
            $command .= " --frontend";
        }
        // macOS 上 nohup 可能不正确继承 sudo 权限，使用 sudo -E -n 确保子进程以 root 运行
        if (!IS_WIN && \function_exists('posix_geteuid') && (int)\posix_geteuid() === 0) {
            $command = 'sudo -E -n ' . $command;
        }
        
        // 使用进程管理器统一创建进程
        $pid = Processer::create($command, true, $frontend);
        
        // 前端模式或后台模式：如果 PID 获取失败，通过端口检测
        // Windows 前台模式下 Processer::create 通常返回 0，需要等待并检测端口
        if ($pid <= 0) {
            // 等待进程启动并绑定端口
            // SSL Worker 需要更长的启动时间（加载框架、证书等）
            $maxWait = $frontend ? 3000 : 2000; // 后台模式等待 2 秒，前台模式 3 秒
            $waitStep = 100; // 每次检测间隔 100ms
            $waited = 0;
            
            while ($waited < $maxWait) {
                \usleep($waitStep * 1000);
                $waited += $waitStep;
                
                $detectedPid = Processer::getProcessIdByPort($port);
                if ($detectedPid > 0) {
                    Processer::setPid($processName, $detectedPid);
                    $pid = $detectedPid;
                    break;
                }
            }
        }
        
        return ['success' => $pid > 0 || $frontend, 'pid' => $pid, 'error' => $pid > 0 ? '' : 'Processer::create failed'];
    }
    
    /**
     * 使用 pcntl_fork 启动（Linux/Mac）
     */
    protected function startWithPcntlFork(string $phpBinary, string $workerScript, string $host, int $port, int $workerId, string $instanceName, string $logFile, string $sslCert = '', string $sslKey = '', bool $frontend = false): array
    {
        $pid = \pcntl_fork();
        
        if ($pid === -1) {
            return ['success' => false, 'pid' => 0, 'error' => 'pcntl_fork failed'];
        }
        
        if ($pid === 0) {
            // 子进程
            if (\function_exists('posix_setsid')) {
                \posix_setsid();
            }
            
            // 进程名包含实例名和 Worker ID
            $processName = 'weline-master-' . $instanceName . '-worker-' . $workerId;
            $command = "\"{$phpBinary}\" \"{$workerScript}\" {$host} {$port} {$workerId} {$instanceName}";
            if ($sslCert && $sslKey) {
                $command .= " \"{$sslCert}\" \"{$sslKey}\"";
                // TCP 透传模式：Worker 需要延迟 SSL 握手
                $command .= " --defer-ssl";
            }
            $command .= " --name={$processName}";
            if ($frontend) {
                $command .= " --frontend";
            }
            $command .= " > \"{$logFile}\" 2>&1";
            @\exec($command);
            exit(0);
        }
        
        return ['success' => true, 'pid' => $pid, 'error' => ''];
    }
    
    /**
     * 使用 exec 启动（备用方案）
     * 
     * 注意：此方法现在统一使用 Processer::create，保留作为备用入口
     */
    protected function startWithExec(string $phpBinary, string $workerScript, string $host, int $port, int $workerId, string $instanceName, string $logFile, string $sslCert = '', string $sslKey = '', bool $frontend = false): array
    {
        // 进程名包含实例名和 Worker ID，便于多 Master 架构下识别和管理
        $processName = 'weline-master-' . $instanceName . '-worker-' . $workerId;
        $command = "\"{$phpBinary}\" \"{$workerScript}\" {$host} {$port} {$workerId} {$instanceName}";
        if ($sslCert && $sslKey) {
            $command .= " \"{$sslCert}\" \"{$sslKey}\"";
            // TCP 透传模式：Worker 需要延迟 SSL 握手
            $command .= " --defer-ssl";
        }
        $command .= " --name={$processName}";
        if ($frontend) {
            $command .= " --frontend";
        }
        // macOS 上 nohup 可能不正确继承 sudo 权限，使用 sudo -E -n 确保子进程以 root 运行
        if (!IS_WIN && \function_exists('posix_geteuid') && (int)\posix_geteuid() === 0) {
            $command = 'sudo -E -n ' . $command;
        }
        
        // 使用进程管理器统一创建进程
        $pid = Processer::create($command, true, $frontend);
        
        // 前端模式或后台模式：如果 PID 获取失败，通过端口检测
        // Windows 前台模式下 Processer::create 通常返回 0，需要等待并检测端口
        if ($pid <= 0) {
            // 等待进程启动并绑定端口
            // SSL Worker 需要更长的启动时间（加载框架、证书等）
            $maxWait = $frontend ? 3000 : 2000; // 后台模式等待 2 秒，前台模式 3 秒
            $waitStep = 100; // 每次检测间隔 100ms
            $waited = 0;
            
            while ($waited < $maxWait) {
                \usleep($waitStep * 1000);
                $waited += $waitStep;
                
                $detectedPid = Processer::getProcessIdByPort($port);
                if ($detectedPid > 0) {
                    Processer::setPid($processName, $detectedPid);
                    $pid = $detectedPid;
                    break;
                }
            }
        }
        
        return ['success' => $pid > 0 || $frontend, 'pid' => $pid, 'error' => $pid > 0 ? '' : 'Processer::create failed'];
    }
    
    /**
     * 验证 Workers 状态
     */
    protected function verifyWorkers(string $host, int $port, int $count, bool $sslEnabled = false): void
    {
        $this->printer->note(__('验证进程状态...'));
        echo "\n";
        
        $runningCount = 0;
        $protocol = $sslEnabled ? 'SSL' : 'TCP';
        
        for ($i = 0; $i < $count; $i++) {
            $workerPort = $port + $i;
            $workerId = $i + 1;
            
            // 对于 SSL，使用 stream_socket_client 带 SSL 上下文
            if ($sslEnabled) {
                $context = \stream_context_create([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ]
                ]);
                $socket = @\stream_socket_client("ssl://{$host}:{$workerPort}", $errno, $errstr, 2, STREAM_CLIENT_CONNECT, $context);
            } else {
                $socket = @\fsockopen($host, $workerPort, $errno, $errstr, 2);
            }
            
            if ($socket) {
                \fclose($socket);
                $this->printer->success(__('  ├─ Worker #%{1} (%{2}:%{3}) - 运行中 ✓', [$workerId, $protocol, $workerPort]));
                $runningCount++;
            } else {
                $this->printer->error(__('  ├─ Worker #%{1} (:%{2}) - 未响应 ✗', [$workerId, $workerPort]));
            }
        }
        
        echo "\n";
        $this->printer->setup(__('启动结果：%{1}/%{2} 个进程运行中', [$runningCount, $count]));
        $this->printer->note(__('启动方式：%{1}', [$this->usedMethod ?: 'unknown']));
    }
    
    /**
     * 获取推荐的最佳性能配置
     */
    protected function getRecommendedConfig(): array
    {
        $cpuCores = $this->getCpuCoreCount();
        
        return [
            // Worker 配置
            'worker_count' => [
                'io' => $cpuCores * 2,      // I/O 密集型
                'cpu' => $cpuCores,         // CPU 密集型
            ],
            // PHP 扩展
            'extensions' => [
                'opcache' => __('字节码缓存，提升 PHP 执行速度 50%+'),
                'sockets' => __('原生 Socket 支持，提升网络性能'),
            ],
            // PHP 函数
            'functions' => [
                'proc_open' => __('进程控制核心函数，支持精确的 PID 管理'),
                'pcntl_fork' => __('真正的进程分叉，共享内存，性能最优（仅 Linux/Mac）'),
            ],
            // PHP 配置
            'ini_settings' => [
                'memory_limit' => ['recommended' => '256M', 'min' => 128, 'unit' => 'M', 'desc' => __('内存限制')],
                'max_execution_time' => ['recommended' => '0', 'desc' => __('执行时间限制（0=无限制）')],
                'opcache.enable_cli' => ['recommended' => '1', 'desc' => __('CLI 模式开启 OPCache')],
                'opcache.jit' => ['recommended' => 'tracing', 'desc' => __('JIT 编译器（PHP 8+）')],
                'opcache.jit_buffer_size' => ['recommended' => '128M', 'desc' => __('JIT 缓冲区大小')],
            ],
        ];
    }
    
    /**
     * 检测性能问题并收集建议
     */
    protected function detectPerformanceIssues(int $workerCount, string $mode): array
    {
        $issues = [];
        $recommended = $this->getRecommendedConfig();
        $cpuCores = $this->getCpuCoreCount();
        
        // 0. 检查事件循环（最重要的性能因素！）
        $eventLoopIssues = $this->detectEventLoopIssues();
        $issues = \array_merge($issues, $eventLoopIssues);
        
        // 1. 检查 Worker 数量
        // Windows 上多进程开销大，推荐值不超过 CPU 核心数
        $multiplier = $mode === 'io' ? 2 : 1;
        $recommendedWorkers = $recommended['worker_count'][$mode] ?? $cpuCores * $multiplier;
        
        // Windows 上限制最大推荐值
        if (IS_WIN) {
            $recommendedWorkers = \min($recommendedWorkers, $cpuCores);
            $multiplier = 1;
        }
        
        // 限制在合理范围内（2-16）
        $recommendedWorkers = \min(\max(2, $recommendedWorkers), 16);
        
        if ($workerCount < $recommendedWorkers) {
            $platformNote = IS_WIN ? __('（Windows 建议不超过 CPU 核心数）') : '';
            $issues['worker_count'] = [
                'level' => 'info',
                'current' => $workerCount,
                'recommended' => $recommendedWorkers,
                'message' => __('当前 Worker 数：%{1}，推荐：%{2}', [$workerCount, $recommendedWorkers]) . $platformNote,
                'action' => __('使用 -c %{1} 参数或在 env.server.worker_count 设置', [$recommendedWorkers]),
            ];
        }
        
        // 2. 检查 PHP 扩展
        foreach ($recommended['extensions'] as $ext => $benefit) {
            if (!\extension_loaded($ext)) {
                $issues["ext_{$ext}"] = [
                    'level' => 'warning',
                    'message' => __('缺少扩展：%{1}', [$ext]),
                    'benefit' => $benefit,
                    'action' => __('在 php.ini 中启用：extension=%{1}', [$ext]),
                ];
            }
        }
        
        // 3. 检查 PHP 函数
        if (!$this->availableFunctions['proc_open']) {
            $issues['func_proc_open'] = [
                'level' => 'warning',
                'message' => __('函数被禁用：proc_open'),
                'benefit' => $recommended['functions']['proc_open'],
                'action' => __('从 disable_functions 中移除 proc_open'),
            ];
        }
        if (!IS_WIN && !$this->availableFunctions['pcntl_fork']) {
            $issues['func_pcntl_fork'] = [
                'level' => 'warning',
                'message' => __('函数被禁用：pcntl_fork'),
                'benefit' => $recommended['functions']['pcntl_fork'],
                'action' => __('从 disable_functions 中移除 pcntl_fork'),
            ];
        }
        
        // 4. 检查 PHP 配置
        // 内存限制
        $memoryLimit = \ini_get('memory_limit');
        $memoryMb = $this->parseMemoryLimit($memoryLimit);
        if ($memoryMb > 0 && $memoryMb < 128) {
            $issues['memory_limit'] = [
                'level' => 'warning',
                'current' => $memoryLimit,
                'recommended' => '256M',
                'message' => __('内存限制较低：%{1}', [$memoryLimit]),
                'action' => __('在 php.ini 设置 memory_limit = 256M'),
            ];
        }
        
        // OPCache CLI
        if (\extension_loaded('opcache')) {
            $opcacheCliEnabled = \ini_get('opcache.enable_cli');
            if (!$opcacheCliEnabled || $opcacheCliEnabled === '0') {
                $issues['opcache_cli'] = [
                    'level' => 'info',
                    'message' => __('OPCache CLI 模式未启用'),
                    'benefit' => __('启用后可提升 CLI 脚本执行速度'),
                    'action' => __('在 php.ini 设置 opcache.enable_cli = 1'),
                ];
            }
            
            // JIT（PHP 8+）
            if (\version_compare(PHP_VERSION, '8.0.0', '>=')) {
                $jit = \ini_get('opcache.jit');
                if (empty($jit) || $jit === '0' || $jit === 'off') {
                    $issues['opcache_jit'] = [
                        'level' => 'info',
                        'message' => __('JIT 编译器未启用'),
                        'benefit' => __('PHP 8 JIT 可提升 CPU 密集型任务性能 2-3 倍'),
                        'action' => __('在 php.ini 设置 opcache.jit = tracing'),
                    ];
                }
            }
        }
        
        return $issues;
    }
    
    /**
     * 检测事件循环问题
     */
    protected function detectEventLoopIssues(): array
    {
        $issues = [];
        
        // 检查是否安装了 event 扩展
        $hasEvent = \extension_loaded('event');
        
        if (!$hasEvent) {
            $issues['event_loop'] = [
                'level' => 'critical', // 最高优先级
                'message' => __('未安装 event 扩展，使用 stream_select 回退方案'),
                'benefit' => __('安装后性能提升 100-200%%，QPS 从 15,000 提升至 30,000+'),
                'action' => IS_WIN 
                    ? __('Windows: 下载 php_event.dll 并在 php.ini 中添加 extension=event')
                    : __('Linux/Mac: pecl install event && echo "extension=event" >> php.ini'),
                'current_performance' => '15,000 QPS',
                'optimal_performance' => '30,000-50,000 QPS',
            ];
        }
        
        // 检查 ev 扩展（更高性能，可选）
        $hasEv = \extension_loaded('ev');
        if (!$hasEv && $hasEvent) {
            // 已有 event，ev 是可选优化
            $issues['ev_extension'] = [
                'level' => 'info',
                'message' => __('可选：安装 ev 扩展可获得更高性能'),
                'benefit' => __('基于 libev，比 libevent 更轻量'),
                'action' => __('pecl install ev'),
            ];
        }
        
        return $issues;
    }
    
    /**
     * 解析内存限制字符串为 MB
     */
    protected function parseMemoryLimit(string $limit): int
    {
        $limit = \trim($limit);
        if ($limit === '-1') {
            return -1; // 无限制
        }
        
        $unit = \strtolower(\substr($limit, -1));
        $value = (int) $limit;
        
        return match ($unit) {
            'g' => $value * 1024,
            'm' => $value,
            'k' => (int) ($value / 1024),
            default => (int) ($value / 1024 / 1024),
        };
    }
    
    /**
     * 显示优化建议
     */
    protected function showOptimizationTips(int $workerCount, string $mode = 'io'): void
    {
        // 检测性能问题
        $issues = $this->detectPerformanceIssues($workerCount, $mode);
        
        if (empty($issues)) {
            echo "\n";
            $this->printer->success(__('✅ 当前配置已达最佳性能！'));
            return;
        }
        
        echo "\n";
        $this->printer->warning(__('📊 性能优化建议'));
        echo "\n";
        
        // 按级别分组
        $criticals = [];
        $warnings = [];
        $infos = [];
        
        foreach ($issues as $key => $issue) {
            if ($issue['level'] === 'critical') {
                $criticals[$key] = $issue;
            } elseif ($issue['level'] === 'warning') {
                $warnings[$key] = $issue;
            } else {
                $infos[$key] = $issue;
            }
        }
        
        // 显示关键问题（严重影响性能）
        if (!empty($criticals)) {
            $this->printer->error(__('🚨 关键性能问题（强烈建议解决）：'));
            echo "\n";
            foreach ($criticals as $issue) {
                $this->printer->error("  ✖ {$issue['message']}");
                if (isset($issue['benefit'])) {
                    $this->printer->warning("    → {$issue['benefit']}");
                }
                if (isset($issue['current_performance']) && isset($issue['optimal_performance'])) {
                    $this->printer->note(__('    当前性能：%{1} → 优化后：%{2}', [$issue['current_performance'], $issue['optimal_performance']]));
                }
                $this->printer->success("    ✓ {$issue['action']}");
                echo "\n";
            }
        }
        
        // 显示警告级别的问题（影响性能）
        if (!empty($warnings)) {
            $this->printer->warning(__('⚠️ 影响性能的配置：'));
            echo "\n";
            foreach ($warnings as $issue) {
                $this->printer->warning("  • {$issue['message']}");
                if (isset($issue['benefit'])) {
                    $this->printer->note("    → {$issue['benefit']}");
                }
                $this->printer->note("    ✓ {$issue['action']}");
            }
            echo "\n";
        }
        
        // 显示信息级别的建议（可选优化）
        if (!empty($infos)) {
            $this->printer->note(__('💡 可选优化：'));
            echo "\n";
            foreach ($infos as $issue) {
                $this->printer->note("  • {$issue['message']}");
                if (isset($issue['benefit'])) {
                    $this->printer->note("    → {$issue['benefit']}");
                }
                $this->printer->note("    ✓ {$issue['action']}");
            }
            echo "\n";
        }
        
        // PHP 配置文件位置
        $this->printer->note(__('📁 PHP 配置文件：%{1}', [\php_ini_loaded_file() ?: 'php.ini']));
        echo "\n";
        
        // 总结
        if (!empty($criticals)) {
            $this->printer->setup(__('🔥 解决关键问题后，性能将提升 100-200%%！'));
        } else {
            $this->printer->success(__('💪 优化后，服务器性能将有质的飞跃！'));
        }
    }
    
    /**
     * 显示 Windows 下 Nginx/Caddy HTTPS 代理提示
     *
     * Windows 上 PHP 的 SSL accept 会阻塞约 60 秒（底层 OpenSSL/WinSock 限制），
     * 故建议使用 TCP 模式，由 Nginx 或 Caddy 做 SSL 终结并反代到本端口。
     */
    protected function showWindowsNginxProxyHint(string $host, int $port): void
    {
        echo "\n";
        $this->printer->warning(__('╔══════════════════════════════════════════════════════════════════════════════╗'));
        $this->printer->warning(__('║  Windows 环境：建议使用 TCP 模式，用 Nginx 或 Caddy 做 SSL 处理。            ║'));
        $this->printer->warning(__('║  由 Nginx/Caddy 监听 443 做 HTTPS，反代到本端口。                            ║'));
        $this->printer->warning(__('╠══════════════════════════════════════════════════════════════════════════════╣'));
        $this->printer->warning(__('║  Nginx 配置示例（假设 Nginx HTTPS 监听 443，反代到 %{1}）：          ║', [$port]));
        $this->printer->warning(__('║                                                                              ║'));
        $this->printer->warning(__('║    server {                                                                  ║'));
        $this->printer->warning(__('║        listen 443 ssl;                                                       ║'));
        $this->printer->warning(__('║        server_name your-domain.com;                                          ║'));
        $this->printer->warning(__('║        ssl_certificate     /path/to/cert.pem;                                ║'));
        $this->printer->warning(__('║        ssl_certificate_key /path/to/key.pem;                                 ║'));
        $this->printer->warning(__('║        location / {                                                          ║'));
        $this->printer->warning(__('║            proxy_pass http://%{1}:%{2};                                    ║', [$host, $port]));
        $this->printer->warning(__('║            proxy_set_header Host $host;                                      ║'));
        $this->printer->warning(__('║            proxy_set_header X-Real-IP $remote_addr;                          ║'));
        $this->printer->warning(__('║        }                                                                     ║'));
        $this->printer->warning(__('║    }                                                                         ║'));
        $this->printer->warning(__('║                                                                              ║'));
        $this->printer->warning(__('║  配置完成后，访问 https://your-domain.com 即可                              ║'));
        $this->printer->warning(__('╚══════════════════════════════════════════════════════════════════════════════╝'));
    }

    /**
     * 显示使用说明（含各区域入口地址）
     */
    protected function showUsageInfo(string $host, int $port, string $instanceName, bool $sslEnabled = false): void
    {
        $scheme = $sslEnabled ? 'https' : 'http';
        // 默认端口（HTTP 80 或 HTTPS 443）不在 URL 中显示
        $portNum = (int)$port;
        $portSuffix = (($portNum == 80 && !$sslEnabled) || ($portNum == 443 && $sslEnabled)) ? '' : ':' . $port;
        $baseUrl = $scheme . '://' . $host . $portSuffix . '/';
        $testUrl = $baseUrl;

        $backendPrefix = Env::getAreaRoutePrefix('backend') ?? '';
        $restBackendPrefix = Env::getAreaRoutePrefix('rest_backend') ?? '';
        $restFrontendPrefix = Env::getAreaRoutePrefix('rest_frontend') ?? 'api';

        $urlFrontend = rtrim($baseUrl, '/') . '/';
        // 后台入口 = 密钥路径 + /admin（不输出单独的 /admin 地址）
        $urlBackend = rtrim($baseUrl, '/') . '/' . ($backendPrefix !== '' ? $backendPrefix . '/' : '') . 'admin';
        $urlRestBackend = rtrim($baseUrl, '/') . '/' . ($restBackendPrefix !== '' ? $restBackendPrefix . '/' : '');
        $urlRestFrontend = rtrim($baseUrl, '/') . '/' . ($restFrontendPrefix !== '' ? $restFrontendPrefix . '/' : '');

        echo "\n";
        $usageLines = [
            __('╔══════════════════════════════════════════════════════════════╗'),
            __('║                      使用说明                                  ║'),
            __('╠══════════════════════════════════════════════════════════════╣'),
            __('║  前台/首页：%{1}  ║', [$urlFrontend]),
            __('║  后台入口：%{1}  ║', [$urlBackend]),
            __('║  后台 REST 接口：%{1}  ║', [$urlRestBackend]),
            __('║  前台 REST 接口：%{1}  ║', [$urlRestFrontend]),
            __('╠══════════════════════════════════════════════════════════════╣'),
            __('║  测试请求：curl %{1}  ║', [$testUrl]),
            __('║  查看状态：php bin/w server:status %{1}                    ║', [$instanceName]),
            __('║  停止服务：php bin/w server:stop %{1}                      ║', [$instanceName]),
            __('║  压力测试：php bin/w server:benchmark                       ║'),
            __('║  优化指南：php bin/w server:doc                             ║'),
            __('╚══════════════════════════════════════════════════════════════╝'),
        ];

        foreach ($usageLines as $line) {
            $this->printer->note($line);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('启动 Weline 常驻内存 HTTP 服务器');
    }
    
    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:start [name]',
            __('启动 Weline 高性能常驻内存服务器'),
            [
                '[name]' => __('实例名称（默认：default）'),
                '--cli' => __('使用 PHP 内置 CLI 服务器（开发模式，无 HTTPS）'),
                '--strategy' => __('使用跨平台优化策略模式（Linux: SO_REUSEPORT 直连; Windows: TCP 透传）'),
                '--strategy-info' => __('显示可用策略信息'),
                '-h, --host <ip>' => __('监听地址（默认：127.0.0.1）'),
                '-p, --port <port>' => __('基础端口（默认：80/443，HTTPS 时用 443；可 -p 9981 等自定义）'),
                '-c, --count <n>' => __('Worker 进程数（默认：auto 智能模式）'),
                '--no-daemon' => __('前台运行（查看实时日志）'),
                '-m, --mode <mode>' => __('运行模式：io（I/O密集）或 cpu（CPU密集）'),
                '-r, --restart' => __('平滑重启：开维护模式（新请求返回503）、通过健康检查等待现有请求完成后切换'),
                '-f' => __('与 -r 同用时直接切换（不开维护模式、不等待）；仅 --cli 时 -f 表示前台运行'),
                '--wait <秒>' => __('平滑重启最长等待秒数，默认 30（实际会通过健康检查尽快切换）'),
                '--no-ssl' => __('仅 HTTP，不启用 HTTPS（Windows 下可不装 event 扩展）'),
                '--ssl-cert <path>' => __('SSL 证书文件路径（启用 HTTPS）'),
                '--ssl-key <path>' => __('SSL 私钥文件路径（启用 HTTPS）'),
                '--http-redirect-port <port>' => __('HTTP 重定向端口（HTTPS 模式专用，默认：自动计算或 80）'),
                '--redirect-port <port>' => __('HTTP 重定向端口（--http-redirect-port 的简写）'),
                '--direct' => __('直连模式：多 Worker 直接监听同一端口（Linux/Mac SO_REUSEPORT）'),
                '--no-dispatcher' => __('独立端口模式：禁用 Dispatcher，每个 Worker 使用独立端口'),
                '--dispatcher' => __('强制 Dispatcher 模式（默认）'),
                '--help' => __('显示帮助信息'),
            ],
            [
                __('配置优先级') => __('命令行参数 > 已保存实例配置 > env.servers.[name] > env.server > 默认值'),
                __('多实例支持') => __('可同时运行多个命名实例，每个实例使用不同端口。首次指定 -p 后配置会自动记住，下次直接用实例名启动'),
                __('配置记忆') => __('首次 server:start api -p 8443 会保存配置，之后 server:start api 自动使用端口 8443'),
                __('智能模式') => __('worker_count 设为 "auto" 时：开发环境固定 2 个 Worker，生产环境根据 CPU 核心数自动计算'),
                __('事件循环') => __('自动选择最优：Event 扩展 > stream_select'),
                __('多进程') => __('优先级：proc_open > pcntl_fork > exec'),
                __('HTTPS 支持') => __('自动检测 app/etc/ 下的证书，或手动指定 --ssl-cert 和 --ssl-key'),
                __('禁用 HTTPS') => __('env.server.https = false 或 命令行 --no-ssl，二者任一即可；同时影响 http:request 等生成地址'),
                __('SSL 协议') => __('支持 TLS 1.0/1.1/1.2/1.3，默认使用最高可用版本'),
                __('Master 进程') => __('默认启用，持续监控 Worker 状态，Worker 崩溃自动重启；HTTPS 时自动启动 HTTP 重定向进程'),
                __('80/443 端口') => __('默认监听 80/443 省去 Nginx；HTTPS 时自动用 443，可 -p 9981 等改端口；Linux/Mac 特权端口需 root/setcap'),
                __('HTTP 重定向端口') => __('HTTPS 模式下自动启动 HTTP 重定向进程，默认自动计算（HTTPS端口-463，如 443→80）；可通过 --http-redirect-port 指定；设为 0 禁用重定向'),
            ],
            [
                __('启动默认实例') => 'php bin/w server:start',
                __('使用 CLI 服务器') => 'php bin/w server:start --cli',
                __('启动命名实例（首次需指定端口）') => 'php bin/w server:start api -p 8443',
                __('再次启动已配置实例（自动记忆）') => 'php bin/w server:start api',
                __('同时运行多个实例') => 'php bin/w server:start web -p 8443 && php bin/w server:start api -p 9443',
                __('启动 8 个进程') => 'php bin/w server:start -c 8',
                __('CPU密集模式') => 'php bin/w server:start -m cpu',
                __('平滑重启') => 'php bin/w server:start -r',
                __('强制重启（不等待）') => 'php bin/w server:start -r -f',
                __('启用 HTTPS') => 'php bin/w server:start --ssl-cert /path/to/cert.pem --ssl-key /path/to/key.pem',
                __('Windows 无 HTTPS 运行') => 'php bin/w server:start --no-ssl',
                __('指定 HTTP 重定向端口') => 'php bin/w server:start --http-redirect-port 8080',
                __('禁用 HTTP 重定向') => 'php bin/w server:start --http-redirect-port 0',
                __('策略模式（跨平台优化）') => 'php bin/w server:start --strategy',
                __('查看所有实例状态') => 'php bin/w server:status --all',
                __('停止指定实例') => 'php bin/w server:stop api',
                __('停止所有实例') => 'php bin/w server:stop --all',
                __('压力测试') => 'php bin/w server:benchmark',
            ]
        );
    }
    
    
    // ========== 热重载支持方法 ==========
    
    /**
     * 根据配置启动热重载监控
     * 
     * 开发模式下默认启用热重载，生产模式默认关闭
     * 文件变更时触发 code 级别重载（Worker 重启加载新代码）
     */
    protected function startHotReloadIfEnabled(array $config, string $instanceName): void
    {
        // 检查开发模式
        $isDevMode = (Env::getInstance()->getConfig('deploy') ?? '') === 'dev';
        
        // 开发模式默认启用热重载，生产模式默认关闭
        // 可通过 env.server.hot_reload 显式覆盖
        $hotReload = $config['hot_reload'] ?? $isDevMode;
        if (!$hotReload) {
            return;
        }
        
        // 仅非守护进程模式支持热重载（前台运行时）
        if ($config['daemon'] ?? true) {
            $this->printer->note(__('热重载仅在前台模式 (--no-daemon) 下生效'));
            $this->printer->note(__('使用 "php bin/w s:up --hot" 手动触发热更新'));
            return;
        }
        
        $this->printer->note(__('启动热重载监控...'));
        
        // 获取监控配置
        $serverEnv = Env::getInstance()->getConfig('server') ?? [];
        $watchDirs = $serverEnv['watch_dirs'] ?? ['app/code', 'app/etc'];
        $watchInterval = (float) ($serverEnv['watch_interval'] ?? 1);
        
        // 转换为绝对路径
        $absoluteDirs = [];
        foreach ($watchDirs as $dir) {
            $absoluteDirs[] = BP . \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);
        }
        
        $this->printer->success(__('热重载已启用'));
        $this->printer->note(__('监控目录：%{1}', [\implode(', ', $watchDirs)]));
        $this->printer->note(__('检查间隔：%{1} 秒', [$watchInterval]));
        echo "\n";
        
        // 启动文件监控（变更时通知所有 WLS Worker 重载，与 CLI 命令重载机制一致）
        $this->runFileWatcher($absoluteDirs, $watchInterval);
    }
    
    /**
     * 文件监控进程名（符合 Processer 规范：--name 标识）
     */
    protected const FILE_WATCHER_PROCESS_NAME = 'weline-file-watcher';

    /**
     * 运行文件监控器（子进程模式）
     *
     * 遵循 Processer 进程管理规范：注册、检测、终止均通过 Processer
     * 文件监控在独立子进程中运行，主进程负责信号处理
     */
    protected function runFileWatcher(array $watchDirs, float $interval): void
    {
        $configDir = Env::VAR_DIR . 'tmp' . DS;
        if (!\is_dir($configDir)) {
            @\mkdir($configDir, 0755, true);
        }
        $configFile = $configDir . 'file_watcher_' . \getmypid() . '_' . \time() . '.json';
        \file_put_contents($configFile, \json_encode([
            'watch_dirs' => $watchDirs,
            'check_interval' => $interval,
        ]));

        $watcherScript = \dirname(__DIR__, 2) . DS . 'bin' . DS . 'file_watcher.php';
        $phpBinary = \defined('PHP_BINARY') && \PHP_BINARY ? \PHP_BINARY : 'php';
        $processName = "\"{$phpBinary}\" \"{$watcherScript}\" \"{$configFile}\" --name=" . self::FILE_WATCHER_PROCESS_NAME;

        // 若已存在同进程，先销毁
        if (Processer::running($processName)) {
            Processer::destroy($processName);
        }

        $this->printer->note(__('按 Ctrl+C 停止监控...'));
        echo "\n";

        // 方案1：pcntl_fork（Linux/Mac），主进程可正确处理信号
        if (!IS_WIN && $this->availableFunctions['pcntl_fork']) {
            $this->runFileWatcherWithFork($phpBinary, $watcherScript, $configFile, $processName);
            return;
        }

        // 方案2：proc_open（Windows 或 pcntl 不可用）
        $this->runFileWatcherWithProcOpen($phpBinary, $watcherScript, $configFile, $processName);
    }

    /**
     * 使用 pcntl_fork 运行文件监控子进程
     */
    protected function runFileWatcherWithFork(string $phpBinary, string $watcherScript, string $configFile, string $processName): void
    {
        $pid = \pcntl_fork();
        if ($pid === -1) {
            $this->printer->error(__('创建文件监控子进程失败'));
            @\unlink($configFile);
            return;
        }
        if ($pid === 0) {
            if (\function_exists('posix_setsid')) {
                \posix_setsid();
            }
            \pcntl_exec($phpBinary, [$watcherScript, $configFile, '--name=' . self::FILE_WATCHER_PROCESS_NAME]);
            exit(1);
        }

        Processer::setPid($processName, $pid);

        $shutdown = false;
        \pcntl_async_signals(true);
        \pcntl_signal(\SIGINT, function () use (&$shutdown) {
            $shutdown = true;
        });
        \pcntl_signal(\SIGTERM, function () use (&$shutdown) {
            $shutdown = true;
        });

        while (!$shutdown) {
            $result = \pcntl_waitpid($pid, $status, \WNOHANG);
            if ($result === $pid) {
                break;
            }
            if ($result === -1) {
                break;
            }
            \pcntl_signal_dispatch();
            \usleep(200000);
        }

        if ($shutdown && Processer::isRunningByPid($pid)) {
            Processer::killByPid($pid);
            \pcntl_waitpid($pid, $status);
        }
        Processer::destroy($processName);
        @\unlink($configFile);
    }

    /**
     * 使用 proc_open 运行文件监控子进程
     */
    protected function runFileWatcherWithProcOpen(string $phpBinary, string $watcherScript, string $configFile, string $processName): void
    {
        $descriptorspec = [
            0 => ['pipe', 'r'],
        ];
        $command = [$phpBinary, $watcherScript, $configFile, '--name=' . self::FILE_WATCHER_PROCESS_NAME];
        $proc = @\proc_open($command, $descriptorspec, $pipes, null, null, ['bypass_shell' => true]);
        if (!\is_resource($proc)) {
            $this->printer->error(__('创建文件监控子进程失败'));
            @\unlink($configFile);
            return;
        }
        if (isset($pipes[0])) {
            \fclose($pipes[0]);
        }

        $status = \proc_get_status($proc);
        $pid = $status['pid'] ?? 0;
        if ($pid > 0) {
            Processer::setPid($processName, $pid);
        }

        $shutdown = false;
        if (!IS_WIN && \function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(true);
            \pcntl_signal(\SIGINT, function () use (&$shutdown) {
                $shutdown = true;
            });
            \pcntl_signal(\SIGTERM, function () use (&$shutdown) {
                $shutdown = true;
            });
        }

        while (true) {
            $status = \proc_get_status($proc);
            if (!$status || !$status['running']) {
                break;
            }
            if ($shutdown) {
                Processer::killByPid($pid);
                break;
            }
            if (!IS_WIN) {
                \pcntl_signal_dispatch();
            }
            \usleep(200000);
        }
        \proc_close($proc);
        Processer::destroy($processName);
        @\unlink($configFile);
    }
    
    // ========== 策略模式支持（跨平台优化架构） ==========
    
    /**
     * 获取策略工厂
     * 
     * @return ServerStrategyFactory
     */
    protected function getStrategyFactory(): ServerStrategyFactory
    {
        static $factory = null;
        if ($factory === null) {
            $factory = new ServerStrategyFactory();
        }
        return $factory;
    }
    
    /**
     * 获取当前平台的最优策略
     * 
     * @return ServerStrategyInterface
     */
    protected function getOptimalStrategy(): ServerStrategyInterface
    {
        return $this->getStrategyFactory()->getStrategy();
    }
    
    /**
     * 显示策略信息
     * 
     * @param string $format 输出格式: table|json|text
     */
    public function showStrategyInfo(string $format = 'table'): void
    {
        $factory = $this->getStrategyFactory();
        $comparison = $factory->getStrategyComparison();
        
        if ($format === 'json') {
            echo \json_encode($comparison, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            return;
        }
        
        $this->printer->note(__('可用服务器启动策略:'));
        echo "\n";
        
        foreach ($comparison as $info) {
            $supported = $info['supported'] ? '✓' : '✗';
            $current = $info['supported'] ? ' (当前)' : '';
            $this->printer->printList([
                __('标识') => $info['identifier'],
                __('名称') => $info['name'] . $current,
                __('支持') => $supported,
                __('优先级') => $info['priority'],
                __('架构') => $info['architecture'],
            ]);
            echo "\n";
        }
    }
    
    /**
     * 使用策略模式启动服务器
     * 
     * 这是新的跨平台优化启动方式：
     * - Linux/Mac: SO_REUSEPORT 直连模式
     * - Windows: Dispatcher TCP 透传模式
     * 
     * @param array $args 命令行参数
     * @return bool 是否启动成功
     */
    protected function executeWithStrategy(array $args = []): bool
    {
        // 构建 ServerConfig
        $instanceName = $args['instance'] ?? $args['name'] ?? 'default';
        $config = $this->getServerConfig($instanceName, $args);
        
        // 检查是否前台运行
        $frontend = !empty($args['frontend']) || \in_array('--frontend', $args, true) || \in_array('-frontend', $args, true);
        
        // 获取 SSL 证书
        $sslCert = '';
        $sslKey = '';
        $sslEnabled = empty($args['no-ssl']) && empty($config['no_ssl']);
        
        if ($sslEnabled) {
            $sslResult = $this->ensureSslCertificate($instanceName, $config);
            if (!$sslResult['success']) {
                $this->printer->error($sslResult['message']);
                return false;
            }
            $sslCert = $sslResult['cert_path'];
            $sslKey = $sslResult['key_path'];
        }
        
        // 创建配置对象
        $serverConfig = new ServerConfig([
            'instance_name' => $instanceName,
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => (int) ($config['port'] ?? ($sslEnabled ? 443 : 80)),
            'worker_count' => (int) ($config['worker_count'] ?? 4),
            'worker_base_port' => (int) ($config['worker_base_port'] ?? 10443),
            'ssl_cert' => $sslCert,
            'ssl_key' => $sslKey,
            'frontend' => $frontend,
            'http_redirect_port' => (int) ($config['http_redirect_port'] ?? 80),
            'http_redirect_enabled' => $sslEnabled && (($config['http_redirect_port'] ?? 80) > 0),
            'log_dir' => Env::VAR_DIR . 'log' . DS,
            'bin_dir' => \dirname(__DIR__, 2) . DS . 'bin' . DS,
        ]);
        
        // Linux/Mac 非 root 绑定特权端口时，自动触发 sudo 密码输入并重启当前命令
        if (!$this->ensurePrivilegedPortPermission($serverConfig->port, $serverConfig->httpRedirectPort, $sslEnabled)) {
            return true;
        }
        
        // 获取最优策略
        $strategy = $this->getOptimalStrategy();
        
        // 设置日志回调
        $strategy->setLogCallback(function (string $message, string $level) {
            switch ($level) {
                case 'ERROR':
                    $this->printer->error($message);
                    break;
                case 'WARN':
                    $this->printer->warning($message);
                    break;
                default:
                    $this->printer->note($message);
            }
        });
        
        // 显示策略信息
        $this->printer->success(__('使用策略: %{1}', [$strategy->getName()]));
        $this->printer->note(__('架构: %{1}', [$strategy->getArchitectureDescription()]));
        echo "\n";
        
        // 启动服务器
        return $strategy->start($serverConfig);
    }
    
    /**
     * 使用策略模式停止服务器
     * 
     * @param string $instanceName 实例名称
     * @return bool 是否停止成功
     */
    protected function stopWithStrategy(string $instanceName): bool
    {
        $strategy = $this->getOptimalStrategy();
        return $strategy->stop($instanceName);
    }
    
    /**
     * 获取策略模式下的服务器状态
     * 
     * @param string $instanceName 实例名称
     * @return array
     */
    protected function getStrategyStatus(string $instanceName): array
    {
        $strategy = $this->getOptimalStrategy();
        return $strategy->getStatus($instanceName);
    }
}
