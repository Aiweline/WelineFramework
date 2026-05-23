<?php
declare(strict_types=1);

/**
 * Weline Server - 性能优化指南服务
 * 
 * 动态生成性能优化文档，包含当前 PHP 环境信息
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
/**
 * OptimizationGuideService - 性能优化指南服务
 * 
 * 动态检测 PHP 环境并生成优化建议
 */
class OptimizationGuideService
{
    /**
     * 是否为 Windows 系统
     */
    protected bool $isWindows;
    
    /**
     * PHP 信息
     */
    protected array $phpInfo = [];
    
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->isWindows = \strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN';
        $this->collectPhpInfo();
    }
    
    /**
     * 收集 PHP 信息
     */
    protected function collectPhpInfo(): void
    {
        $this->phpInfo = [
            'version' => PHP_VERSION,
            'binary' => PHP_BINARY,
            'ini_path' => \php_ini_loaded_file() ?: __('未找到'),
            'ini_scanned' => \php_ini_scanned_files() ?: '',
            'ext_dir' => \ini_get('extension_dir'),
            'disable_functions' => \ini_get('disable_functions'),
            'extensions' => \get_loaded_extensions(),
            'os' => PHP_OS,
            'arch' => PHP_INT_SIZE === 8 ? 'x64' : 'x86',
            'thread_safe' => defined('ZEND_THREAD_SAFE') && ZEND_THREAD_SAFE,
        ];
    }
    
    /**
     * 获取 PHP 信息
     */
    public function getPhpInfo(): array
    {
        return $this->phpInfo;
    }
    
    /**
     * 检测优化项
     */
    public function detectOptimizations(): array
    {
        $optimizations = [];
        
        // 1. Event 扩展
        $optimizations['event'] = [
            'name' => __('Event 扩展'),
            'installed' => \extension_loaded('event'),
            'impact' => '+40-60%',
            'priority' => 'high',
            'description' => __('基于 libevent 的高性能事件循环'),
            'performance' => [
                'without' => '15,000-20,000 QPS',
                'with' => '30,000-50,000 QPS',
            ],
            'install' => $this->getEventInstallGuide(),
        ];
        
        // 2. pcntl_fork (Linux)
        $hasPcntl = \function_exists('pcntl_fork') && !$this->isFunctionDisabled('pcntl_fork');
        $optimizations['pcntl'] = [
            'name' => __('pcntl_fork 函数'),
            'installed' => $hasPcntl,
            'impact' => '+20-30%',
            'priority' => $this->isWindows ? 'skip' : 'medium',
            'description' => __('真正的进程分叉，共享内存，性能最优（仅 Linux/Mac）'),
            'performance' => [
                'without' => __('使用 exec 备用方案'),
                'with' => __('原生进程分叉，更低开销'),
            ],
            'install' => $this->getPcntlInstallGuide(),
        ];
        
        // 3. OPCache
        $hasOpcache = $this->hasOpcacheLoaded();
        $opcacheEnabled = $hasOpcache && \ini_get('opcache.enable_cli');
        $optimizations['opcache'] = [
            'name' => __('OPCache 扩展'),
            'installed' => $hasOpcache,
            'enabled_cli' => $opcacheEnabled,
            'impact' => '+30-50%',
            'priority' => 'high',
            'description' => __('字节码缓存，提升 PHP 执行速度'),
            'performance' => [
                'without' => __('每次请求重新编译'),
                'with' => __('使用缓存的字节码'),
            ],
            'install' => $this->getOpcacheInstallGuide(),
        ];
        
        // 4. JIT (PHP 8+)
        $hasJit = \version_compare(PHP_VERSION, '8.0.0', '>=');
        $jitEnabled = $hasOpcache && !empty(\ini_get('opcache.jit'));
        $optimizations['jit'] = [
            'name' => __('JIT 编译器'),
            'installed' => $hasJit,
            'enabled' => $jitEnabled,
            'impact' => '+100-200%',
            'priority' => 'high',
            'description' => __('PHP 8 即时编译器，显著提升 CPU 密集型任务性能'),
            'performance' => [
                'without' => __('解释执行'),
                'with' => __('编译执行，接近 C 语言性能'),
            ],
            'install' => $this->getJitInstallGuide(),
        ];
        
        // 5. Sockets 扩展
        $optimizations['sockets'] = [
            'name' => __('Sockets 扩展'),
            'installed' => \extension_loaded('sockets'),
            'impact' => '+10-15%',
            'priority' => 'medium',
            'description' => __('底层 Socket 操作，更精细的控制'),
            'performance' => [
                'without' => __('使用 stream_socket'),
                'with' => __('使用原生 socket'),
            ],
            'install' => $this->getSocketsInstallGuide(),
        ];
        
        // 6. proc_open 函数
        $hasProcOpen = \function_exists('proc_open') && !$this->isFunctionDisabled('proc_open');
        $optimizations['proc_open'] = [
            'name' => __('proc_open 函数'),
            'installed' => $hasProcOpen,
            'impact' => '+10-15%',
            'priority' => 'medium',
            'description' => __('进程控制核心函数，支持精确的 PID 管理'),
            'performance' => [
                'without' => __('使用 exec 备用'),
                'with' => __('精确控制子进程'),
            ],
            'install' => $this->getProcOpenInstallGuide(),
        ];
        
        // 7. HTTPS/SSL 配置
        $hasOpenssl = \extension_loaded('openssl');
        $optimizations['https'] = [
            'name' => __('HTTPS/SSL 配置'),
            'installed' => $hasOpenssl,
            'impact' => __('安全加密'),
            'priority' => 'optional',
            'description' => __('为服务器启用 HTTPS 加密传输，保护数据安全'),
            'performance' => [
                'without' => __('HTTP 明文传输'),
                'with' => __('HTTPS 加密传输'),
            ],
            'install' => $this->getHttpsInstallGuide(),
        ];
        
        return $optimizations;
    }
    
    /**
     * 获取 HTTPS 配置指南
     */
    protected function getHttpsInstallGuide(): array
    {
        $phpInfo = $this->phpInfo;
        
        return [
            'platform' => __('跨平台'),
            'available' => \extension_loaded('openssl'),
            'prerequisite' => [
                'name' => 'OpenSSL',
                'installed' => \extension_loaded('openssl'),
                'note' => __('需要 PHP 编译时启用 OpenSSL 支持'),
            ],
            'sections' => [
                [
                    'title' => __('一、获取 SSL 证书'),
                    'description' => __('您可以通过以下方式获取 SSL 证书'),
                    'options' => [
                        [
                            'name' => __('1. 免费证书（推荐）'),
                            'providers' => [
                                "Let's Encrypt" => 'https://letsencrypt.org/',
                                'ZeroSSL' => 'https://zerossl.com/',
                                'Cloudflare' => 'https://www.cloudflare.com/ssl/',
                            ],
                            'note' => __('使用 certbot 自动获取和续期'),
                        ],
                        [
                            'name' => __('2. 自签名证书（开发环境）'),
                            'command' => $this->isWindows
                                ? 'openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout server.key -out server.crt -subj "/CN=localhost"'
                                : 'openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout server.key -out server.crt -subj "/CN=localhost"',
                            'note' => __('仅用于开发测试，浏览器会显示安全警告'),
                        ],
                        [
                            'name' => __('3. 商业证书'),
                            'providers' => [
                                'DigiCert' => 'https://www.digicert.com/',
                                'Comodo' => 'https://www.comodo.com/',
                                'GlobalSign' => 'https://www.globalsign.com/',
                            ],
                            'note' => __('适合企业生产环境'),
                        ],
                    ],
                ],
                [
                    'title' => __('二、证书文件准备'),
                    'description' => __('确保证书文件格式正确'),
                    'steps' => [
                        [
                            'title' => __('证书文件 (cert.pem)'),
                            'description' => __('包含服务器证书，可选包含中间证书链'),
                            'format' => 'PEM 格式 (-----BEGIN CERTIFICATE-----)',
                        ],
                        [
                            'title' => __('私钥文件 (key.pem)'),
                            'description' => __('服务器私钥，必须无密码保护'),
                            'format' => 'PEM 格式 (-----BEGIN PRIVATE KEY-----)',
                            'security' => __('请妥善保管，设置严格权限 (chmod 600)'),
                        ],
                    ],
                ],
                [
                    'title' => __('三、启动 HTTPS 服务器'),
                    'description' => __('服务器支持多域名证书，自动检测 app/etc/ssl/ 目录'),
                    'multi_domain' => [
                        'description' => __('多域名证书目录结构（推荐）'),
                        'structure' => 'app/etc/ssl/{domain}/',
                        'example' => [
                            'app/etc/ssl/example.com/' => ['fullchain.pem', 'privkey.pem'],
                            'app/etc/ssl/api.example.com/' => ['fullchain.pem', 'privkey.pem'],
                            'app/etc/ssl/www.example.com/' => ['fullchain.pem', 'privkey.pem'],
                        ],
                        'note' => __('每个域名一个目录，自动匹配证书'),
                    ],
                    'auto_detect' => [
                        'description' => __('支持的证书文件名格式（按优先级）'),
                        'formats' => [
                            'fullchain.pem / privkey.pem (Let\'s Encrypt)',
                            'cert.pem / key.pem',
                            'ssl.crt / ssl.key',
                            'server.crt / server.key',
                        ],
                        'path' => 'app/etc/ssl/{domain}/',
                        'note' => __('将证书文件放入对应域名目录，服务器启动时自动检测'),
                    ],
                    'auto_request' => [
                        'title' => __('自动申请证书'),
                        'description' => __("使用 Let's Encrypt 自动申请免费证书"),
                        'commands' => [
                            __('查看证书状态') => 'php bin/w ssl:auto',
                            __('申请新证书') => 'php bin/w ssl:auto request -d localhost -e admin@localhost',
                            __('同步网站域名') => 'php bin/w ssl:auto sync -e admin@example.com',
                            __('续签到期证书') => 'php bin/w ssl:auto renew',
                        ],
                    ],
                    'commands' => [
                        [
                            'title' => __('自动检测方式（推荐）'),
                            'description' => __('将证书放入 app/etc/ 目录后直接启动'),
                            'command' => 'php bin/w server:start',
                            'note' => __('服务器自动检测并启用 HTTPS'),
                        ],
                        [
                            'title' => __('手动指定方式'),
                            'command' => 'php bin/w server:start --ssl-cert /path/to/cert.pem --ssl-key /path/to/key.pem',
                        ],
                        [
                            'title' => __('配置文件方式 (env.php)'),
                            'description' => __('在 env.php 中配置 SSL 证书'),
                            'config' => <<<'CONFIG'
'server' => [
    'host' => '0.0.0.0',
    'port' => 443,
    'worker_count' => 'auto',
    'ssl_cert' => '/path/to/cert.pem',
    'ssl_key' => '/path/to/key.pem',
],
CONFIG,
                        ],
                    ],
                    'protocols' => [
                        'title' => __('支持的 SSL/TLS 协议'),
                        'description' => __('服务器支持所有 TLS 协议，默认使用最高可用版本'),
                        'versions' => [
                            'TLS 1.3' => __('推荐，最安全（PHP 7.4+）'),
                            'TLS 1.2' => __('广泛支持，安全'),
                            'TLS 1.1' => __('兼容旧系统'),
                            'TLS 1.0' => __('兼容旧系统'),
                        ],
                    ],
                ],
                [
                    'title' => __('四、验证 HTTPS'),
                    'description' => __('确认 HTTPS 服务器正常运行'),
                    'steps' => [
                        [
                            'title' => __('浏览器访问'),
                            'url' => 'https://localhost:9981',
                        ],
                        [
                            'title' => __('命令行测试'),
                            'command' => 'curl -k https://localhost:9981',
                            'note' => __('-k 参数用于忽略自签名证书警告'),
                        ],
                    ],
                ],
                [
                    'title' => __('五、生产环境建议'),
                    'recommendations' => [
                        __('使用 443 端口（标准 HTTPS 端口）'),
                        __('配置 HTTP 到 HTTPS 重定向'),
                        __('启用 HSTS（HTTP Strict Transport Security）'),
                        __('定期更新证书（Let\'s Encrypt 证书有效期 90 天）'),
                        __('使用 TLS 1.2 或更高版本'),
                    ],
                ],
            ],
        ];
    }
    
    /**
     * 获取 Event 扩展安装指南
     */
    protected function getEventInstallGuide(): array
    {
        $phpInfo = $this->phpInfo;
        
        if ($this->isWindows) {
            // Windows 安装指南
            $arch = $phpInfo['arch'];
            $ts = $phpInfo['thread_safe'] ? 'ts' : 'nts';
            $version = \explode('.', $phpInfo['version']);
            $majorMinor = "{$version[0]}.{$version[1]}";
            
            return [
                'platform' => 'Windows',
                'steps' => [
                    [
                        'title' => __('1. 下载扩展'),
                        'description' => __('访问 PECL 下载对应版本'),
                        'url' => "https://pecl.php.net/package/event",
                        'note' => __('选择版本：PHP %{1}, %{2}, %{3}', [$majorMinor, $arch, $ts]),
                    ],
                    [
                        'title' => __('2. 复制 DLL 文件'),
                        'description' => __('将 php_event.dll 复制到扩展目录'),
                        'path' => $phpInfo['ext_dir'],
                        'command' => \sprintf('copy php_event.dll "%s"', $phpInfo['ext_dir']),
                    ],
                    [
                        'title' => __('3. 编辑 php.ini'),
                        'description' => __('在 php.ini 中添加扩展'),
                        'path' => $phpInfo['ini_path'],
                        'content' => 'extension=event',
                    ],
                    [
                        'title' => __('4. 重启服务器'),
                        'description' => __('重启 Weline Server'),
                        'command' => 'php bin/w server:stop && php bin/w server:start',
                    ],
                ],
            ];
        } else {
            // Linux/Mac 安装指南
            return [
                'platform' => 'Linux/Mac',
                'steps' => [
                    [
                        'title' => __('1. 安装依赖'),
                        'description' => __('安装 libevent 开发库'),
                        'commands' => [
                            'Ubuntu/Debian' => 'sudo apt-get install libevent-dev',
                            'CentOS/RHEL' => 'sudo yum install libevent-devel',
                            'macOS' => 'brew install libevent',
                        ],
                    ],
                    [
                        'title' => __('2. 安装扩展'),
                        'description' => __('使用 PECL 安装'),
                        'command' => 'pecl install event',
                    ],
                    [
                        'title' => __('3. 启用扩展'),
                        'description' => __('在 php.ini 中添加'),
                        'path' => $phpInfo['ini_path'],
                        'content' => 'extension=event',
                        'command' => \sprintf('echo "extension=event" >> "%s"', $phpInfo['ini_path']),
                    ],
                    [
                        'title' => __('4. 重启服务器'),
                        'description' => __('重启 Weline Server'),
                        'command' => 'php bin/w server:stop && php bin/w server:start',
                    ],
                ],
            ];
        }
    }
    
    /**
     * 获取 pcntl 安装指南
     */
    protected function getPcntlInstallGuide(): array
    {
        if ($this->isWindows) {
            return [
                'platform' => 'Windows',
                'available' => false,
                'note' => __('pcntl 扩展不支持 Windows 平台，系统将自动使用备用方案'),
            ];
        }
        
        $disabledFunctions = $this->phpInfo['disable_functions'];
        $isDisabled = \str_contains($disabledFunctions, 'pcntl_fork');
        
        return [
            'platform' => 'Linux/Mac',
            'available' => true,
            'is_disabled' => $isDisabled,
            'steps' => $isDisabled ? [
                [
                    'title' => __('1. 编辑 php.ini'),
                    'description' => __('从 disable_functions 中移除 pcntl_fork'),
                    'path' => $this->phpInfo['ini_path'],
                    'current' => $disabledFunctions,
                    'note' => __('找到 disable_functions 行，删除 pcntl_fork'),
                ],
                [
                    'title' => __('2. 重启 PHP-FPM（如有）'),
                    'command' => 'sudo systemctl restart php-fpm',
                ],
            ] : [
                [
                    'title' => __('已安装'),
                    'description' => __('pcntl_fork 已可用'),
                ],
            ],
        ];
    }
    
    /**
     * 获取 OPCache 安装指南
     */
    protected function getOpcacheInstallGuide(): array
    {
        $installed = $this->hasOpcacheLoaded();
        $cliEnabled = $installed && \ini_get('opcache.enable_cli');
        
        $steps = [];
        
        if (!$installed) {
            if ($this->isWindows) {
                $steps[] = [
                    'title' => __('1. 启用 OPCache'),
                    'description' => __('在 php.ini 中取消注释'),
                    'path' => $this->phpInfo['ini_path'],
                    'content' => 'zend_extension=opcache',
                ];
            } else {
                $steps[] = [
                    'title' => __('1. 安装 OPCache'),
                    'commands' => [
                        'Ubuntu/Debian' => 'sudo apt-get install php-opcache',
                        'CentOS/RHEL' => 'sudo yum install php-opcache',
                    ],
                ];
            }
        }
        
        if (!$cliEnabled) {
            $steps[] = [
                'title' => __('%{1}. 启用 CLI 模式', [\count($steps) + 1]),
                'description' => __('在 php.ini 中添加'),
                'path' => $this->phpInfo['ini_path'],
                'content' => "opcache.enable_cli=1",
            ];
        }
        
        $steps[] = [
            'title' => __('%{1}. 推荐配置', [\count($steps) + 1]),
            'description' => __('优化 OPCache 配置'),
            'content' => <<<INI
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0
INI,
        ];
        
        return [
            'platform' => 'all',
            'installed' => $installed,
            'cli_enabled' => $cliEnabled,
            'steps' => $steps,
        ];
    }

    private function hasOpcacheLoaded(): bool
    {
        return \extension_loaded('Zend OPcache') || \function_exists('opcache_get_status');
    }
    
    /**
     * 获取 JIT 安装指南
     */
    protected function getJitInstallGuide(): array
    {
        if (\version_compare(PHP_VERSION, '8.0.0', '<')) {
            return [
                'platform' => 'all',
                'available' => false,
                'note' => __('JIT 需要 PHP 8.0+，当前版本：%{1}', [PHP_VERSION]),
            ];
        }
        
        $jitEnabled = !empty(\ini_get('opcache.jit'));
        
        return [
            'platform' => 'all',
            'available' => true,
            'enabled' => $jitEnabled,
            'steps' => [
                [
                    'title' => __('1. 确保 OPCache 已启用'),
                    'description' => __('JIT 依赖 OPCache'),
                ],
                [
                    'title' => __('2. 配置 JIT'),
                    'description' => __('在 php.ini 中添加'),
                    'path' => $this->phpInfo['ini_path'],
                    'content' => <<<INI
opcache.jit=tracing
opcache.jit_buffer_size=128M
INI,
                ],
                [
                    'title' => __('3. 重启服务器'),
                    'command' => 'php bin/w server:stop && php bin/w server:start',
                ],
            ],
        ];
    }
    
    /**
     * 获取 Sockets 安装指南
     */
    protected function getSocketsInstallGuide(): array
    {
        $installed = \extension_loaded('sockets');
        
        if ($installed) {
            return [
                'platform' => 'all',
                'installed' => true,
                'steps' => [
                    ['title' => __('已安装'), 'description' => __('sockets 扩展已可用')],
                ],
            ];
        }
        
        if ($this->isWindows) {
            return [
                'platform' => 'Windows',
                'installed' => false,
                'steps' => [
                    [
                        'title' => __('1. 启用扩展'),
                        'description' => __('在 php.ini 中取消注释'),
                        'path' => $this->phpInfo['ini_path'],
                        'content' => 'extension=sockets',
                    ],
                ],
            ];
        }
        
        return [
            'platform' => 'Linux/Mac',
            'installed' => false,
            'steps' => [
                [
                    'title' => __('1. 安装扩展'),
                    'commands' => [
                        'Ubuntu/Debian' => 'sudo apt-get install php-sockets',
                        'CentOS/RHEL' => 'sudo yum install php-sockets',
                    ],
                ],
            ],
        ];
    }
    
    /**
     * 获取 proc_open 安装指南
     */
    protected function getProcOpenInstallGuide(): array
    {
        $isDisabled = $this->isFunctionDisabled('proc_open');
        
        if (!$isDisabled) {
            return [
                'platform' => 'all',
                'installed' => true,
                'steps' => [
                    ['title' => __('已可用'), 'description' => __('proc_open 函数已启用')],
                ],
            ];
        }
        
        return [
            'platform' => 'all',
            'installed' => false,
            'steps' => [
                [
                    'title' => __('1. 编辑 php.ini'),
                    'description' => __('从 disable_functions 中移除 proc_open'),
                    'path' => $this->phpInfo['ini_path'],
                    'note' => __('找到 disable_functions 行，删除 proc_open'),
                ],
            ],
        ];
    }
    
    /**
     * 检查函数是否被禁用
     */
    protected function isFunctionDisabled(string $function): bool
    {
        $disabled = \explode(',', $this->phpInfo['disable_functions']);
        $disabled = \array_map('trim', $disabled);
        return \in_array($function, $disabled, true);
    }
    
    /**
     * 获取优化总览
     */
    public function getOptimizationSummary(): array
    {
        $optimizations = $this->detectOptimizations();
        
        $installed = 0;
        $missing = 0;
        $skipped = 0;
        $totalImpact = 0;
        $potentialImpact = 0;
        
        foreach ($optimizations as $key => $opt) {
            if ($opt['priority'] === 'skip') {
                $skipped++;
                continue;
            }
            
            $impact = (int)\preg_replace('/[^0-9]/', '', \explode('-', $opt['impact'])[0]);
            
            if ($opt['installed'] ?? false) {
                $installed++;
                $totalImpact += $impact;
            } else {
                $missing++;
                $potentialImpact += $impact;
            }
        }
        
        return [
            'total' => \count($optimizations) - $skipped,
            'installed' => $installed,
            'missing' => $missing,
            'skipped' => $skipped,
            'current_boost' => "{$totalImpact}%",
            'potential_boost' => "{$potentialImpact}%",
            'optimizations' => $optimizations,
        ];
    }
    
    /**
     * 获取服务器状态
     */
    public function getServerStatus(): array
    {
        $instanceDir = Env::VAR_DIR . 'server' . DS . 'instances';
        $instances = [];
        
        if (\is_dir($instanceDir)) {
            foreach (\glob("{$instanceDir}/*.json") as $file) {
                $data = \json_decode(\file_get_contents($file), true);
                if ($data) {
                    $name = \pathinfo($file, PATHINFO_FILENAME);
                    $instances[$name] = $data;
                    $host = $data['host'] ?? '127.0.0.1';
                    $port = (int)($data['port'] ?? 9981);
                    // 0.0.0.0 / :: 为绑定地址，无法作为连接目标，用 127.0.0.1 检测本机端口
                    $checkHost = ($host === '0.0.0.0' || $host === '::') ? '127.0.0.1' : $host;
                    $instances[$name]['running'] = $this->isPortOpen($checkHost, $port);
                }
            }
        }
        
        return $instances;
    }
    
    /**
     * 检查端口是否开放
     */
    protected function isPortOpen(string $host, int $port): bool
    {
        $fp = @\fsockopen($host, $port, $errno, $errstr, 0.5);
        if ($fp) {
            \fclose($fp);
            return true;
        }
        return false;
    }
    
    /**
     * 生成访问 Token
     */
    public static function generateToken(): string
    {
        $tokenFile = Env::VAR_DIR . 'server' . DS . 'doc_token.txt';
        $tokenDir = \dirname($tokenFile);
        
        if (!\is_dir($tokenDir)) {
            @\mkdir($tokenDir, 0755, true);
        }
        
        // Token 有效期 24 小时
        if (\file_exists($tokenFile)) {
            $data = \json_decode(\file_get_contents($tokenFile), true);
            if ($data && ($data['expires'] ?? 0) > \time()) {
                return $data['token'];
            }
        }
        
        // 生成新 Token
        $token = \bin2hex(\random_bytes(16));
        \file_put_contents($tokenFile, \json_encode([
            'token' => $token,
            'expires' => \time() + 86400, // 24 小时
            'created' => \date('Y-m-d H:i:s'),
        ]));
        
        return $token;
    }
    
    /**
     * 验证 Token
     */
    public static function validateToken(string $token): bool
    {
        $tokenFile = Env::VAR_DIR . 'server' . DS . 'doc_token.txt';
        
        if (!\file_exists($tokenFile)) {
            return false;
        }
        
        $data = \json_decode(\file_get_contents($tokenFile), true);
        
        if (!$data) {
            return false;
        }
        
        // 检查过期
        if (($data['expires'] ?? 0) < \time()) {
            return false;
        }
        
        // 验证 Token
        return \hash_equals($data['token'] ?? '', $token);
    }
    
    /**
     * 检查是否为本地访问
     */
    public static function isLocalAccess(): bool
    {
        // CLI 模式
        if (PHP_SAPI === 'cli') {
            return true;
        }
        
        $remoteAddr = \w_env('server.remote_addr', '');
        
        // 本地 IP
        $localIps = ['127.0.0.1', '::1', 'localhost'];
        
        return \in_array($remoteAddr, $localIps, true);
    }
    
    /**
     * 获取文档 URL（后台页面，无需 Token，通过 AJAX 本地获取数据）
     */
    public function getDocumentUrl(): string
    {
        return "/weline_server/backend/optimization-guide";
    }
}
