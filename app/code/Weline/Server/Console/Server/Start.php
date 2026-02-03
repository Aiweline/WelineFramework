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

/**
 * server:start - 启动常驻内存服务器
 */
class Start extends CommandAbstract
{
    /**
     * 默认端口
     */
    public const DEFAULT_PORT = 9981;
    
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
        
        // 获取配置（命令行参数 > env配置 > 默认值）
        $config = $this->getServerConfig($instanceName, $args);
        
        $host = $config['host'];
        $port = $config['port'];
        $count = $config['worker_count'];
        $daemon = $config['daemon'];
        
        // 默认启用 HTTPS - 自动获取或生成证书
        $sslResult = $this->ensureSslCertificate($instanceName, $config);
        if (!$sslResult['success']) {
            $this->printer->error($sslResult['message']);
            return;
        }
        
        $sslCert = $sslResult['cert_path'];
        $sslKey = $sslResult['key_path'];
        $sslEnabled = true;
        
        // 显示证书信息
        if ($sslResult['is_new'] ?? false) {
            $this->printer->success(__('已生成新证书：%{1}', [$sslResult['issuer']]));
        } else {
            $this->printer->note(__('使用已有证书：%{1}', [$sslResult['issuer']]));
        }
        if (!empty($sslResult['expires_at'])) {
            $this->printer->note(__('证书有效期至：%{1}', [$sslResult['expires_at']]));
        }
        
        // 检查是否强制重启（-r）及是否强制直接切换（-f：不等待 worker 空闲，直接停再启）
        $forceRestart = isset($args['r']) || isset($args['restart']) || isset($args['force']);
        $forceSwitch = isset($args['f']); // -f：直接切换，不进入平滑重启（不开维护模式、不等待）
        $mainStop = ObjectManager::getInstance(MainStop::class);
        $occupantWls = $mainStop->findWelineServerInstanceNameByPort($port);
        $cliStatus = $cliService->getCliServerStatus();
        $occupantCli = $cliStatus && (($cliStatus['port'] ?? 0) === (int) $port);

        // 同端口只能存在一个服务器：其他 WLS 实例占用 → 先停
        if ($occupantWls !== null && $occupantWls !== $instanceName) {
            $this->printer->note(__('端口 %{1} 已被 Weline Server 实例 [%{2}] 占用，正在停止该实例...', [$port, $occupantWls]));
            $mainStop->stopWelineServerOnPort($port);
            \sleep(2);
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
            if ($forceSwitch) {
                $this->printer->warning(__('检测到服务器已运行，-f 直接切换（不等待）...'));
                $this->stopExistingServer($instanceName, $port, $count);
                \sleep(1);
            } else {
                $this->printer->warning(__('检测到服务器已运行，平滑重启：先开启维护模式，等待 Worker 无请求后再切换...'));
                $this->enableMaintenanceMode();
                $maintenanceEnabledByUs = true;
                $waitSeconds = (int) ($args['wait'] ?? 30);
                $waitSeconds = $waitSeconds > 0 ? $waitSeconds : 30;
                $this->printer->note(__('已开启维护模式，等待 %{1} 秒让当前请求处理完成...', [$waitSeconds]));
                \sleep($waitSeconds);
                $this->stopExistingServer($instanceName, $port, $count);
                \sleep(1);
            }
        }

        // 显示启动信息
        $this->showStartupInfo($instanceName, $host, $port, $count, $daemon, $config['source'], $sslEnabled);

        // 检查端口是否被占用（强制释放或报错）
        if (!$this->checkAndReleasePorts($host, $port, $count, $forceRestart)) {
            if (!empty($maintenanceEnabledByUs)) {
                $this->disableMaintenanceMode();
                $this->printer->note(__('维护模式已关闭（端口检查未通过）。'));
            }
            return;
        }
        
        // 保存实例信息
        $this->saveInstanceInfo($instanceName, $host, $port, $count, $daemon, $sslEnabled, $sslCert, $sslKey);
        
        // 创建 Worker 脚本
        $workerScript = $this->ensureWorkerScript($sslEnabled);
        
        // 启动多进程
        $this->startWorkers($instanceName, $host, $port, $count, $workerScript, $sslCert, $sslKey);
        
        // 显示优化建议
        $this->showOptimizationTips($count, $config['mode'] ?? 'io');
        
        // 显示使用说明
        $this->showUsageInfo($host, $port, $instanceName);

        // 平滑重启时由我们开启的维护模式，启动完成后关闭
        if (!empty($maintenanceEnabledByUs)) {
            $this->disableMaintenanceMode();
            $this->printer->success(__('维护模式已关闭。'));
        }
    }

    /**
     * 开启维护模式（平滑重启时先开启，避免新请求进入）
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
        // 默认配置
        $defaults = [
            'host' => '127.0.0.1',
            'port' => self::DEFAULT_PORT,
            'worker_count' => 'auto',
            'mode' => 'io',
            'daemon' => false,
            'ssl_cert' => '',  // SSL 证书路径
            'ssl_key' => '',   // SSL 私钥路径
            'source' => __('默认值'),
        ];
        
        $config = $defaults;
        
        // 读取 env 配置
        $envConfig = Env::getInstance()->getConfig();
        
        // 1. 检查多实例配置 servers[实例名]
        if ($instanceName !== 'default' && isset($envConfig['servers'][$instanceName])) {
            $instanceConfig = $envConfig['servers'][$instanceName];
            $config = \array_merge($config, $instanceConfig);
            $config['source'] = __('env.servers.%{1}', [$instanceName]);
        }
        // 2. 检查默认服务器配置 server
        elseif (isset($envConfig['server']) && \is_array($envConfig['server'])) {
            $serverConfig = $envConfig['server'];
            if (isset($serverConfig['worker_count']) || isset($serverConfig['mode'])) {
                $config = \array_merge($config, $serverConfig);
                $config['source'] = __('env.server');
            }
        }
        
        // 3. 命令行参数覆盖
        if (isset($args['host']) || isset($args['h'])) {
            $config['host'] = $args['host'] ?? $args['h'];
            $config['source'] = __('命令行参数');
        }
        if (isset($args['port']) || isset($args['p'])) {
            $config['port'] = (int) ($args['port'] ?? $args['p']);
            $config['source'] = __('命令行参数');
        }
        if (isset($args['count']) || isset($args['c'])) {
            $config['worker_count'] = (int) ($args['count'] ?? $args['c']);
            $config['source'] = __('命令行参数');
        }
        if (isset($args['d']) || isset($args['daemon'])) {
            $config['daemon'] = true;
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
            
            // 解析证书信息
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
        
        // 2. 确定域名
        $domain = $config['ssl_domain'] ?? '';
        if (empty($domain)) {
            // 从 host 获取域名，如果是 IP 则使用 localhost
            $host = $config['host'] ?? '127.0.0.1';
            if (\filter_var($host, FILTER_VALIDATE_IP)) {
                $domain = 'localhost';
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
        
        // 智能模式：根据 CPU 核心数和工作模式计算
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
    protected function showStartupInfo(string $instanceName, string $host, int $port, int $count, bool $daemon, string $source = '', bool $sslEnabled = false): void
    {
        $this->printer->setup(__('Weline Server'));
        echo "\n";
        
        $cpuCores = $this->getCpuCoreCount();
        $protocol = $sslEnabled ? 'https' : 'http';
        
        $this->printer->note('╔══════════════════════════════════════════════════════════════╗');
        $this->printer->note('║                   服务器启动配置                               ║');
        $this->printer->note('╠══════════════════════════════════════════════════════════════╣');
        $this->printer->note(\sprintf('║  实例名称：%-50s║', $instanceName));
        $this->printer->note(\sprintf('║  监听地址：%-50s║', "{$protocol}://{$host}:{$port}"));
        $this->printer->note(\sprintf('║  Worker 数：%-49s║', "{$count} (CPU: {$cpuCores} 核)"));
        $this->printer->note(\sprintf('║  端口范围：%-50s║', "{$port} - " . ($port + $count - 1)));
        $this->printer->note(\sprintf('║  运行模式：%-50s║', $daemon ? __('守护进程') : __('后台运行')));
        $this->printer->note(\sprintf('║  SSL/HTTPS：%-49s║', $sslEnabled ? __('已启用') : __('未启用')));
        $this->printer->note(\sprintf('║  平台：%-54s║', IS_WIN ? 'Windows' : 'Linux/Mac'));
        $this->printer->note(\sprintf('║  配置来源：%-50s║', $source ?: __('智能模式')));
        $this->printer->note('╚══════════════════════════════════════════════════════════════╝');
        echo "\n";
        
        // 显示函数状态
        $this->showFunctionStatus();
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
     */
    protected function isServerRunning(string $instanceName, int $port): bool
    {
        // 检查实例文件
        $instanceFile = Env::VAR_DIR . 'server' . DS . 'instances' . DS . $instanceName . '.json';
        if (\is_file($instanceFile)) {
            $instanceData = \json_decode(\file_get_contents($instanceFile), true);
            if ($instanceData && !empty($instanceData['workers'])) {
                // 验证进程是否真的在运行
                foreach ($instanceData['workers'] as $workerInfo) {
                    if (isset($workerInfo['pid']) && Processer::isRunningByPid($workerInfo['pid'])) {
                        return true;
                    }
                }
            }
        }
        
        // 检查端口是否被占用
        if (Processer::isPortInUse($port)) {
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
        $this->printer->success(__('✓ 服务器已在运行中'));
        echo "\n";
        
        $this->printer->note(__('实例名称：%{1}', [$instanceName]));
        $this->printer->note(__('监听端口：%{1}', [$port]));
        echo "\n";
        
        $this->printer->setup(__('如需重启服务器，请使用以下命令：'));
        $this->printer->note('  php bin/w server:start ' . ($instanceName !== 'default' ? $instanceName . ' ' : '') . '-r');
        $this->printer->note('  ' . __('或'));
        $this->printer->note('  php bin/w server:restart' . ($instanceName !== 'default' ? ' ' . $instanceName : ''));
        echo "\n";
        
        $this->printer->setup(__('其他操作：'));
        $this->printer->note('  ' . __('查看状态：php bin/w server:status'));
        $this->printer->note('  ' . __('停止服务：php bin/w server:stop') . ($instanceName !== 'default' ? ' ' . $instanceName : ''));
        echo "\n";
    }
    
    /**
     * 停止现有服务器
     */
    protected function stopExistingServer(string $instanceName, int $port, int $count): void
    {
        $instanceFile = Env::VAR_DIR . 'server' . DS . 'instances' . DS . $instanceName . '.json';
        
        // 读取实例信息并停止进程
        if (\is_file($instanceFile)) {
            $instanceData = \json_decode(\file_get_contents($instanceFile), true);
            if ($instanceData && !empty($instanceData['workers'])) {
                foreach ($instanceData['workers'] as $workerInfo) {
                    if (isset($workerInfo['pid'])) {
                        Processer::killByPid($workerInfo['pid']);
                    }
                }
            }
            // 删除实例文件
            @\unlink($instanceFile);
        }
        
        // 释放端口
        for ($i = 0; $i < $count; $i++) {
            $currentPort = $port + $i;
            if (Processer::isPortInUse($currentPort)) {
                Processer::killProcessByPort($currentPort);
            }
        }
        
        // 等待端口释放
        \sleep(1);
        $this->printer->success(__('已停止现有服务器'));
    }
    
    /**
     * 检查并释放端口
     */
    protected function checkAndReleasePorts(string $host, int $port, int $count, bool $forceRelease = false): bool
    {
        $this->printer->note(__('检查端口可用性...'));
        
        for ($i = 0; $i < $count; $i++) {
            $currentPort = $port + $i;
            if (Processer::isPortInUse($currentPort)) {
                if ($forceRelease) {
                    $this->printer->warning(__('端口 %{1} 已被占用，强制释放...', [$currentPort]));
                    Processer::killProcessByPort($currentPort);
                    \sleep(1);
                } else {
                    $this->printer->error(__('端口 %{1} 已被占用', [$currentPort]));
                    $this->printer->note(__('使用 -r 参数强制重启，或手动停止占用该端口的进程'));
                    return false;
                }
                
                if (Processer::isPortInUse($currentPort)) {
                    $this->printer->error(__('无法释放端口 %{1}，请手动停止占用该端口的进程', [$currentPort]));
                    return false;
                }
            }
        }
        
        $this->printer->success(__('端口检查通过'));
        echo "\n";
        return true;
    }
    
    /**
     * 保存实例信息
     */
    protected function saveInstanceInfo(string $instanceName, string $host, int $port, int $count, bool $daemon, bool $sslEnabled = false, string $sslCert = '', string $sslKey = ''): void
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
            'pid' => \getmypid(),
            'workers' => [],
        ];
        
        \file_put_contents($instanceFile, \json_encode($instanceData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 确保 Worker 脚本存在
     */
    protected function ensureWorkerScript(bool $sslEnabled = false): string
    {
        $suffix = $sslEnabled ? '_ssl' : '';
        $workerScript = BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Server' . DS . 'bin' . DS . "worker{$suffix}.php";
        $scriptDir = \dirname($workerScript);
        
        if (!\is_dir($scriptDir)) {
            @\mkdir($scriptDir, 0755, true);
        }
        
        // 总是更新脚本以确保最新版本
        $script = $sslEnabled ? $this->getSslWorkerScriptContent() : $this->getWorkerScriptContent();
        \file_put_contents($workerScript, $script);
        
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
     */
    protected function startWorkers(string $instanceName, string $host, int $port, int $count, string $workerScript, string $sslCert = '', string $sslKey = ''): void
    {
        $this->printer->note(__('启动 Worker 进程...'));
        echo "\n";
        
        $phpBinary = PHP_BINARY;
        $pids = [];
        $sslEnabled = !empty($sslCert) && !empty($sslKey);
        
        for ($i = 0; $i < $count; $i++) {
            $workerPort = $port + $i;
            $workerId = $i + 1;
            
            $result = $this->startSingleWorker($phpBinary, $workerScript, $host, $workerPort, $workerId, $instanceName, $sslCert, $sslKey);
            
            if ($result['success']) {
                $protocol = $sslEnabled ? 'HTTPS' : 'HTTP';
                $this->printer->success(__('  ├─ Worker #%{1} (%{2} 端口: %{3}) - 启动成功', [$workerId, $protocol, $workerPort]));
                $pids[] = $result['pid'];
            } else {
                $this->printer->error(__('  ├─ Worker #%{1} (端口: %{2}) - 启动失败', [$workerId, $workerPort]));
            }
        }
        
        echo "\n";
        
        // 等待进程启动
        \sleep(2);
        
        // 验证进程状态
        $this->verifyWorkers($host, $port, $count, $sslEnabled);
    }
    
    /**
     * 启动单个 Worker
     */
    protected function startSingleWorker(string $phpBinary, string $workerScript, string $host, int $port, int $workerId, string $instanceName, string $sslCert = '', string $sslKey = ''): array
    {
        $logDir = Env::VAR_DIR . 'log' . DS;
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . "worker-{$port}.log";
        
        // 方案1：proc_open（最可靠）
        if ($this->availableFunctions['proc_open'] && $this->availableFunctions['proc_close']) {
            $result = $this->startWithProcOpen($phpBinary, $workerScript, $host, $port, $workerId, $instanceName, $logFile, $sslCert, $sslKey);
            if ($result['success']) {
                $this->usedMethod = 'proc_open';
                return $result;
            }
        }
        
        // 方案2：pcntl_fork（仅 Linux/Mac）
        if (!IS_WIN && $this->availableFunctions['pcntl_fork']) {
            $result = $this->startWithPcntlFork($phpBinary, $workerScript, $host, $port, $workerId, $instanceName, $logFile, $sslCert, $sslKey);
            if ($result['success']) {
                $this->usedMethod = 'pcntl_fork';
                return $result;
            }
        }
        
        // 方案3（备用）：exec + 批处理/nohup
        if ($this->availableFunctions['exec']) {
            $result = $this->startWithExec($phpBinary, $workerScript, $host, $port, $workerId, $instanceName, $logFile, $sslCert, $sslKey);
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
    protected function startWithProcOpen(string $phpBinary, string $workerScript, string $host, int $port, int $workerId, string $instanceName, string $logFile, string $sslCert = '', string $sslKey = ''): array
    {
        $command = "\"{$phpBinary}\" \"{$workerScript}\" {$host} {$port} {$workerId} {$instanceName}";
        if ($sslCert && $sslKey) {
            $command .= " \"{$sslCert}\" \"{$sslKey}\"";
        }
        
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];
        
        $process = @\proc_open($command, $descriptorspec, $pipes);
        
        if (!\is_resource($process)) {
            return ['success' => false, 'pid' => 0, 'error' => 'proc_open failed'];
        }
        
        if (isset($pipes[0])) {
            \fclose($pipes[0]);
        }
        
        $status = @\proc_get_status($process);
        $pid = $status['pid'] ?? 0;
        
        return ['success' => true, 'pid' => $pid, 'error' => ''];
    }
    
    /**
     * 使用 pcntl_fork 启动（Linux/Mac）
     */
    protected function startWithPcntlFork(string $phpBinary, string $workerScript, string $host, int $port, int $workerId, string $instanceName, string $logFile, string $sslCert = '', string $sslKey = ''): array
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
            
            $command = "\"{$phpBinary}\" \"{$workerScript}\" {$host} {$port} {$workerId} {$instanceName}";
            if ($sslCert && $sslKey) {
                $command .= " \"{$sslCert}\" \"{$sslKey}\"";
            }
            $command .= " > \"{$logFile}\" 2>&1";
            @\exec($command);
            exit(0);
        }
        
        return ['success' => true, 'pid' => $pid, 'error' => ''];
    }
    
    /**
     * 使用 exec 启动（备用方案）
     */
    protected function startWithExec(string $phpBinary, string $workerScript, string $host, int $port, int $workerId, string $instanceName, string $logFile, string $sslCert = '', string $sslKey = ''): array
    {
        if (IS_WIN) {
            // Windows: 使用 PowerShell 静默启动（最可靠，完全无窗口）
            $workerScript = \str_replace('/', '\\', $workerScript);
            $logFile = \str_replace('/', '\\', $logFile);
            $phpBinary = \str_replace('/', '\\', $phpBinary);
            
            // 构建参数列表
            $args = "\"{$workerScript}\" {$host} {$port} {$workerId} {$instanceName}";
            if ($sslCert && $sslKey) {
                $sslCert = \str_replace('/', '\\', $sslCert);
                $sslKey = \str_replace('/', '\\', $sslKey);
                $args .= " \"{$sslCert}\" \"{$sslKey}\"";
            }
            
            // 使用 PowerShell Start-Process 静默启动
            // -WindowStyle Hidden 确保完全无窗口
            $psScript = <<<POWERSHELL
\$process = Start-Process -FilePath "{$phpBinary}" -ArgumentList '{$args}' -WindowStyle Hidden -PassThru
exit 0
POWERSHELL;
            
            // 创建临时 PS1 脚本
            $ps1File = Env::VAR_DIR . 'tmp' . DS . "start_worker_{$port}.ps1";
            $ps1Dir = \dirname($ps1File);
            if (!\is_dir($ps1Dir)) {
                @\mkdir($ps1Dir, 0755, true);
            }
            
            \file_put_contents($ps1File, $psScript);
            
            // 执行 PowerShell 脚本
            @\exec("powershell -NoProfile -ExecutionPolicy Bypass -File \"{$ps1File}\" > NUL 2>&1");
            
            // 延迟删除脚本文件
            @\usleep(200000); // 200ms
            @\unlink($ps1File);
            
            return ['success' => true, 'pid' => 0, 'error' => ''];
        } else {
            // Linux/Mac: nohup
            $command = "nohup \"{$phpBinary}\" \"{$workerScript}\" {$host} {$port} {$workerId} {$instanceName}";
            if ($sslCert && $sslKey) {
                $command .= " \"{$sslCert}\" \"{$sslKey}\"";
            }
            $command .= " > \"{$logFile}\" 2>&1 & echo \$!";
            $output = [];
            @\exec($command, $output);
            
            $pid = !empty($output[0]) && \is_numeric($output[0]) ? (int)$output[0] : 0;
            
            return ['success' => true, 'pid' => $pid, 'error' => ''];
        }
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
     * 显示使用说明
     */
    protected function showUsageInfo(string $host, int $port, string $instanceName): void
    {
        echo "\n";
        $this->printer->note(__('╔══════════════════════════════════════════════════════════════╗'));
        $this->printer->note(__('║                      使用说明                                  ║'));
        $this->printer->note(__('╠══════════════════════════════════════════════════════════════╣'));
        $this->printer->note(__('║  测试请求：curl http://%{1}:%{2}/                      ║', [$host, $port]));
        $this->printer->note(__('║  查看状态：php bin/w server:status %{1}                    ║', [$instanceName]));
        $this->printer->note(__('║  停止服务：php bin/w server:stop %{1}                      ║', [$instanceName]));
        $this->printer->note(__('║  压力测试：php bin/w server:benchmark                       ║'));
        $this->printer->note(__('║  优化指南：php bin/w server:doc                             ║'));
        $this->printer->note(__('╚══════════════════════════════════════════════════════════════╝'));
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
                '-h, --host <ip>' => __('监听地址（默认：127.0.0.1）'),
                '-p, --port <port>' => __('基础端口（默认：9981）'),
                '-c, --count <n>' => __('Worker 进程数（默认：auto 智能模式）'),
                '-d, --daemon' => __('守护进程模式（默认）'),
                '-m, --mode <mode>' => __('运行模式：io（I/O密集）或 cpu（CPU密集）'),
                '-r, --restart' => __('重启：默认平滑重启（先开维护模式、等待请求完成再切换）；与 -f 同用则直接切换'),
                '-f' => __('与 -r 同用时直接切换（不等维护模式、不等待）；仅 --cli 时 -f 表示前台运行'),
                '--wait <秒>' => __('平滑重启（-r 未加 -f）时等待秒数，默认 30'),
                '--ssl-cert <path>' => __('SSL 证书文件路径（启用 HTTPS）'),
                '--ssl-key <path>' => __('SSL 私钥文件路径（启用 HTTPS）'),
                '--help' => __('显示帮助信息'),
            ],
            [
                __('配置优先级') => __('命令行参数 > env.servers.[name] > env.server > 默认值'),
                __('智能模式') => __('worker_count 设为 "auto" 时根据 CPU 核心数和模式自动计算'),
                __('事件循环') => __('自动选择最优：Event 扩展 > stream_select'),
                __('多进程') => __('优先级：proc_open > pcntl_fork > exec'),
                __('HTTPS 支持') => __('自动检测 app/etc/ 下的证书，或手动指定 --ssl-cert 和 --ssl-key'),
                __('SSL 协议') => __('支持 TLS 1.0/1.1/1.2/1.3，默认使用最高可用版本'),
            ],
            [
                __('启动默认实例') => 'php bin/w server:start',
                __('使用 CLI 服务器') => 'php bin/w server:start --cli',
                __('短命令') => 'php bin/w ser:start',
                __('启动命名实例') => 'php bin/w server:start api -p 9000',
                __('启动 8 个进程') => 'php bin/w server:start -c 8',
                __('CPU密集模式') => 'php bin/w server:start -m cpu',
                __('平滑重启') => 'php bin/w server:start -r',
                __('直接切换（不等）') => 'php bin/w server:start -r -f',
                __('启用 HTTPS') => 'php bin/w server:start --ssl-cert /path/to/cert.pem --ssl-key /path/to/key.pem',
                __('查看状态') => 'php bin/w server:status',
                __('停止服务') => 'php bin/w server:stop',
                __('压力测试') => 'php bin/w server:benchmark',
                __('优化指南') => 'php bin/w server:doc',
            ]
        );
    }
    
    /**
     * 命令别名（ser:start 等同 server:start）
     */
    public function aliases(): array
    {
        return ['ser:start'];
    }
}
