<?php
declare(strict_types=1);

/**
 * Weline Server - 攻击探测器
 *
 * 在 Dispatcher 层面进行简单的攻击探测：
 * 1. 频率限制（同一 IP 短时间大量请求）
 * 2. 路径扫描检测（访问大量不存在的路径）
 * 3. 恶意特征检测（SQL 注入、XSS 等模式）
 * 4. 异常 User-Agent 检测
 * 5. 慢速攻击检测（Slowloris）
 *
 * 检测到攻击后，在请求头中添加 X-Weline-Attack-Signal
 * 由框架 Server 模块的事件监听器处理并通知 CDN
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Security;

use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\AttackLogService;

class AttackDetector
{
    /**
     * 单例实例
     */
    private static ?self $instance = null;
    
    /**
     * IP 请求计数器
     * 格式: [ip => ['count' => int, 'first_time' => int, 'paths' => [path => count]]]
     * @var array<string, array{count: int, first_time: int, paths: array}>
     */
    private array $ipCounters = [];
    
    /**
     * 被封禁的 IP
     * 格式: [ip => expire_time]，永久封禁用 PHP_INT_MAX
     * @var array<string, int>
     */
    private array $blockedIps = [];

    /**
     * 永久封禁的 IP（持久化到文件，仅后台解禁可恢复）
     * @var array<string, true>
     */
    private array $permanentBannedIps = [];
    
    /**
     * 攻击事件日志
     * @var array<int, array>
     */
    private array $attackLogs = [];
    
    /**
     * SSL 握手失败计数器
     * 格式: [ip => ['count' => int, 'window_start' => int]]
     * @var array<string, array{count: int, window_start: int}>
     */
    private array $sslFailureCounts = [];
    
    /**
     * 服务器实例名称（用于日志记录）
     */
    private string $instanceName = 'default';
    
    /**
     * 规则配置
     */
    private array $rules = [];
    
    /**
     * 默认规则
     */
    private array $defaultRules = [
        // 频率限制（登录重定向循环会导致同一 IP 短时间内大量请求，max_requests 不宜过低以免误封）
        'rate_limit' => [
            'enabled' => true,
            'window' => 60,           // 时间窗口（秒）
            'max_requests' => 200,    // 最大请求数（原 100，提高以避免 admin↔login 重定向循环误封）
            'block_duration' => 300,  // 封禁时长（秒）
        ],
        // 路径级限流（可精细控制 Query API 等路径）
        'path_rate_limits' => [
            'enabled' => true,
            'rules' => [
                [
                    'path' => '/api/framework/query',
                    'window' => 60,
                    'max_requests' => 120,
                    'block_duration' => 120,
                    'enabled' => true,
                ],
                [
                    'path' => '/api_admin/framework/query',
                    'window' => 60,
                    'max_requests' => 300,
                    'block_duration' => 120,
                    'enabled' => true,
                ],
            ],
        ],
        // CDN 回源 IP 白名单（Dispatcher 可据此跳过攻击探测）
        'cdn_trusted_ips' => [
            'enabled' => true,
            'ips' => [],
        ],
        
        // IP 白名单（完全跳过所有攻击检测，用于开发/测试环境）
        // 可在 env.php 中配置：server.attack_detector.ip_whitelist
        'ip_whitelist' => [
            'enabled' => true,
            'ips' => [],  // 支持单个 IP 或 CIDR 格式，如 ['192.168.1.100', '10.0.0.0/8']
        ],
        
        // 路径扫描检测
        'path_scan' => [
            'enabled' => true,
            'window' => 60,           // 时间窗口
            'max_unique_paths' => 50, // 最大不同路径数
            'block_duration' => 600,
        ],
        
        // 恶意特征检测
        'malicious_patterns' => [
            'enabled' => true,
            'patterns' => [
                // SQL 注入
                '/(\bunion\b.*\bselect\b|\bor\b\s+\d+=\d+|\band\b\s+\d+=\d+|\'.*--)/i',
                // XSS
                '/<script[^>]*>|javascript:|on\w+\s*=/i',
                // 路径遍历
                '/\.\.\/|\.\.\\\\/',
                // 命令注入
                '/;|\||`|\$\(|>\s*\//',
                // PHP 文件包含
                '/php:\/\/|data:\/\/|expect:\/\//i',
            ],
            'block_duration' => 3600,
        ],
        
        // 恶意 User-Agent
        'bad_user_agents' => [
            'enabled' => true,
            'patterns' => [
                '/^$/i',                          // 空 UA
                '/^-$/i',                         // 单破折号
                '/sqlmap/i',                      // SQLMap
                '/nikto/i',                       // Nikto
                '/nmap/i',                        // Nmap
                '/masscan/i',                     // Masscan
                '/python-requests.*\d\.\d/i',     // 默认 Python requests（可选）
                '/curl\/\d/i',                    // curl（可选，生产环境酌情启用）
            ],
            'block_duration' => 300,
        ],
        
        // 慢速攻击检测（配置保留；detect() 中尚未接入，需 Dispatcher 层按连接状态统计后调用）
        'slowloris' => [
            'enabled' => true,
            'max_incomplete_conns' => 10, // 同一 IP 最大未完成连接数
            'incomplete_timeout' => 30,    // 请求不完整超时
        ],
        
        // 敏感路径保护
        'protected_paths' => [
            'enabled' => true,
            'paths' => [
                '/.git/',
                '/.svn/',
                '/.env',
                '/wp-admin',
                '/wp-login',
                '/phpmyadmin',
                '/admin.php',
                '/config.php',
                '/install.php',
                '/setup.php',
            ],
            'block_duration' => 1800,
        ],
        
        // SSL 握手失败检测（Dispatcher 快速关闭模式检测）
        // 注意：fast_close_threshold 不能太大，否则会误伤正常的快速请求
        // 真正的 SSL 握手失败通常在 50-100ms 内断开（客户端拒绝证书 → SSL alert → 断开）
        // 
        // 重要：自签名证书场景下，浏览器预连接（Preconnect）可能频繁触发此检测，
        // 因为浏览器会预先建立连接，但发现证书不信任后立即断开。
        // 阈值设置需要考虑这种正常行为，避免误封合法用户。
        'ssl_handshake_failure' => [
            'enabled' => true,
            'window' => 60,                 // 统计窗口（秒）
            'max_failures' => 30,           // 触发封禁的最大失败次数（提高容错，适应自签名证书场景）
            'block_duration' => 60,         // 封禁时长（秒）- 降低到 1 分钟，快速解封
            'fast_close_threshold' => 0.2,  // 快速关闭阈值（秒）- 降低到 200ms，只捕获真正的 SSL 失败
        ],

        // 扫描路径即永久封禁：命中任意配置路径则立即永久封禁 IP，仅后台解禁可恢复
        'ban_on_path_match' => [
            'enabled' => true,
            'paths' => [
                '/wp-admin',
                '/wp-login',
                '/wp-content',
                '/wp-includes',
                '/xmlrpc.php',
                '/phpmyadmin',
                '/.env',
                '/.git/',
                '/admin.php',
                '/config.php',
                '/install.php',
                '/setup.php',
                '/wp-admin/setup-config.php',
                '/wp-admin/install.php',
            ],
        ],

        // 攻击信号后：被标记为攻击者的 IP，在「禁止路径」之外的请求多次则封禁
        'attack_signaled_follow_ban' => [
            'enabled' => true,
            'signal_ttl' => 3600,           // 攻击信号保留时长（秒）
            'window' => 60,                  // 统计窗口
            'max_requests' => 15,           // 信号后窗口内允许的请求数（超出则封禁）
            'block_duration' => 600,        // 封禁时长（秒）
        ],

        // 流量暴增 + 非框架路由：请求量暴增 2 倍时，对「非框架路由」的连续请求达到次数则封禁
        'traffic_spike' => [
            'enabled' => true,
            'window' => 60,                  // 统计窗口（秒）
            'multiplier' => 2.0,             // 当前窗口请求量 >= 上一窗口 * multiplier 视为暴增
            'spike_mode_duration' => 120,    // 暴增后持续严格检测的时长（秒）
        ],
        'unknown_route_ban' => [
            'enabled' => true,
            'consecutive_count' => 5,       // 连续几次非框架路由请求后封禁
            'block_duration' => 300,        // 封禁时长（秒），0 表示仅本次拦截不记时长
            'only_in_spike_mode' => true,   // 是否仅在流量暴增时启用（false 则始终启用）
        ],

        // 解封后再触发：连续 3 次「解封后又触发未知路由封禁」则改为永久封禁
        'unblock_retrigger_permanent' => [
            'enabled' => true,
            'count' => 3,
        ],
    ];
    
    /**
     * 上次规则检查时间
     */
    private int $lastRulesCheck = 0;
    
    /**
     * 规则检查间隔（秒）
     */
    private int $rulesCheckInterval = 5;
    
    /**
     * 上次清理时间
     */
    private int $lastCleanup = 0;
    
    /**
     * 清理间隔（秒）
     */
    private int $cleanupInterval = 60;
    
    /**
     * 统计信息
     */
    private array $stats = [
        'total_requests' => 0,
        'blocked_requests' => 0,
        'rate_limit_blocks' => 0,
        'path_rate_limit_blocks' => 0,
        'path_scan_blocks' => 0,
        'malicious_blocks' => 0,
        'bad_ua_blocks' => 0,
        'protected_path_blocks' => 0,
        'ssl_failure_blocks' => 0,
        'ssl_failure_total' => 0,
        'ban_on_path_blocks' => 0,
        'attack_signaled_follow_blocks' => 0,
        'unknown_route_blocks' => 0,
    ];

    /** 攻击信号 IP：命中过任意攻击规则的 IP，在 signal_ttl 内对其「规则外」请求计数，超限则封禁 */
    private array $attackSignaledIps = [];
    /** 攻击信号后的请求计数 [ip => ['count' => int, 'window_start' => int]] */
    private array $attackSignaledRequestCount = [];
    /** 连续非框架路由请求次数 [ip => int]，命中已知路由时清零 */
    private array $consecutiveUnknownRoute = [];
    /** 解封后再触发次数 [ip => int]，用于满 3 次改永久封禁 */
    private array $unblockRetriggerCount = [];
    /** 框架已知路由路径（前缀集合），用于判断是否「非框架路由」 */
    private array $knownRoutePaths = [];
    private int $knownRoutePathsLoadedAt = 0;
    private int $knownRoutePathsReloadInterval = 300;
    /** 流量暴增：当前窗口 [start, count]、上一窗口 [start, count] */
    private array $requestCountSpikeWindow = ['current' => [0, 0], 'previous' => [0, 0]];
    private int $spikeModeUntil = 0;
    
    /**
     * 私有构造函数
     */
    private function __construct()
    {
        $this->rules = $this->defaultRules;
        $this->loadRules();
        $this->loadPermanentBannedIps();
    }
    
    /**
     * 获取单例实例
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 设置服务器实例名称（用于日志记录）
     */
    public function setInstanceName(string $name): self
    {
        $this->instanceName = $name;
        return $this;
    }
    
    /**
     * 重置实例（用于测试）
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
    
    /**
     * 加载规则配置
     */
    private function loadRules(): void
    {
        $rulesFile = $this->getRulesFilePath();
        if (\is_file($rulesFile)) {
            $rules = @\json_decode(\file_get_contents($rulesFile), true);
            if (\is_array($rules)) {
                $this->rules = \array_replace_recursive($this->defaultRules, $rules);
            }
        }
        
        // 从 env.php 加载 IP 白名单配置
        $this->loadEnvWhitelist();
    }
    
    /**
     * 从 env.php 加载 IP 白名单
     * 配置路径：server.attack_detector.ip_whitelist
     */
    private function loadEnvWhitelist(): void
    {
        try {
            $envConfig = \Weline\Framework\App\Env::getInstance()->getConfig('server');
            if (!empty($envConfig['attack_detector']['ip_whitelist'])) {
                $envWhitelist = $envConfig['attack_detector']['ip_whitelist'];
                
                // 合并到规则中
                if (isset($envWhitelist['enabled'])) {
                    $this->rules['ip_whitelist']['enabled'] = (bool) $envWhitelist['enabled'];
                }
                
                if (!empty($envWhitelist['ips']) && \is_array($envWhitelist['ips'])) {
                    // 合并 IP 列表（去重）
                    $this->rules['ip_whitelist']['ips'] = \array_unique(\array_merge(
                        $this->rules['ip_whitelist']['ips'] ?? [],
                        $envWhitelist['ips']
                    ));
                }
            }
        } catch (\Throwable $e) {
            // env.php 读取失败时忽略，使用默认规则
        }
    }
    
    /**
     * 检查 IP 是否在白名单中
     *
     * @param string $ip 要检查的 IP
     * @return bool 是否在白名单中
     */
    public function isWhitelisted(string $ip): bool
    {
        $config = $this->rules['ip_whitelist'] ?? [];
        
        if (empty($config['enabled']) || empty($config['ips'])) {
            return false;
        }
        
        foreach ($config['ips'] as $whitelistEntry) {
            // 精确匹配
            if ($whitelistEntry === $ip) {
                return true;
            }
            
            // CIDR 匹配（如 10.0.0.0/8）
            if (\strpos($whitelistEntry, '/') !== false) {
                if ($this->ipMatchesCidr($ip, $whitelistEntry)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * 检查 IP 是否匹配 CIDR 格式
     *
     * @param string $ip IP 地址
     * @param string $cidr CIDR 格式（如 192.168.1.0/24）
     * @return bool
     */
    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = \explode('/', $cidr, 2);
        $bits = (int) $bits;
        
        // 处理 IPv4
        if (\filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) 
            && \filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = \ip2long($ip);
            $subnetLong = \ip2long($subnet);
            $mask = -1 << (32 - $bits);
            
            return ($ipLong & $mask) === ($subnetLong & $mask);
        }
        
        // 简单的 IPv6 支持（可后续扩展）
        // 暂不实现，直接返回 false
        
        return false;
    }
    
    /**
     * 获取规则文件路径
     */
    public static function getRulesFilePath(): string
    {
        return BP . 'var' . DS . 'server' . DS . 'security-rules.json';
    }
    
    /**
     * 获取规则更新标记文件路径
     */
    public static function getRulesUpdateFlagPath(): string
    {
        return BP . 'var' . DS . 'server' . DS . 'security-rules-update.flag';
    }

    /**
     * 永久封禁 IP 列表文件路径
     */
    public static function getPermanentBannedFilePath(): string
    {
        return BP . 'var' . DS . 'server' . DS . 'permanent-banned-ips.json';
    }

    private function loadPermanentBannedIps(): void
    {
        // 先移除当前内存中已记录的永久封禁 IP（避免重载后与文件不一致时残留）
        foreach (\array_keys($this->permanentBannedIps) as $ip) {
            unset($this->blockedIps[$ip]);
        }
        $this->permanentBannedIps = [];

        $file = self::getPermanentBannedFilePath();
        if (!\is_file($file)) {
            return;
        }
        $raw = @\file_get_contents($file);
        if ($raw === false || $raw === '') {
            return;
        }
        $data = @\json_decode($raw, true);
        if (\is_array($data) && isset($data['ips']) && \is_array($data['ips'])) {
            $this->permanentBannedIps = \array_fill_keys($data['ips'], true);
            foreach ($this->permanentBannedIps as $ip => $_) {
                $this->blockedIps[$ip] = \PHP_INT_MAX;
            }
        }
    }

    private function savePermanentBannedIps(): void
    {
        $file = self::getPermanentBannedFilePath();
        $dir = \dirname($file);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        $ips = \array_keys($this->permanentBannedIps);
        @\file_put_contents($file, \json_encode(['ips' => $ips], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 重新加载规则与永久封禁列表
     */
    public function reload(): void
    {
        $this->loadRules();
        $this->loadPermanentBannedIps();
    }
    
    /**
     * 检查规则更新
     */
    public function checkRulesUpdate(): void
    {
        $now = \time();
        if ($now - $this->lastRulesCheck < $this->rulesCheckInterval) {
            return;
        }
        $this->lastRulesCheck = $now;
        
        $flagFile = self::getRulesUpdateFlagPath();
        if (\is_file($flagFile)) {
            $flagMtime = @\filemtime($flagFile);
            static $lastFlagMtime = 0;
            
            if ($flagMtime > $lastFlagMtime) {
                $lastFlagMtime = $flagMtime;
                $this->reload();
            }
        }
    }
    
    /**
     * 检测请求是否为攻击
     *
     * @param string $clientIp 客户端 IP
     * @param string $uri 请求 URI
     * @param string $method 请求方法
     * @param array $headers 请求头
     * @param string $body 请求体（可选）
     * @return array{is_attack: bool, type: string, reason: string, should_block: bool}
     */
    public function detect(
        string $clientIp,
        string $uri,
        string $method = 'GET',
        array $headers = [],
        string $body = ''
    ): array {
        $this->stats['total_requests']++;
        
        // 检查规则更新
        $this->checkRulesUpdate();
        
        // 定期清理
        $this->maybeCleanup();
        
        // 0. 白名单检查（完全跳过所有攻击检测）
        if ($this->isWhitelisted($clientIp)) {
            return [
                'is_attack' => false,
                'type' => 'whitelisted',
                'reason' => '',
                'should_block' => false,
            ];
        }
        
        // 提取请求信息用于日志记录
        $requestInfo = [
            'ip' => $clientIp,
            'uri' => $uri,
            'method' => $method,
            'user_agent' => $headers['user-agent'] ?? $headers['User-Agent'] ?? '',
            'domain' => $headers['host'] ?? $headers['Host'] ?? '',
            'headers' => $headers,
            'instance' => $this->instanceName ?? 'default',
        ];
        
        // 1. 检查是否已被封禁（含永久封禁）
        if ($this->isBlocked($clientIp)) {
            $this->stats['blocked_requests']++;
            $result = [
                'is_attack' => true,
                'type' => 'blocked',
                'reason' => isset($this->permanentBannedIps[$clientIp]) ? 'IP 已永久封禁（仅后台可解禁）' : 'IP 已被临时封禁',
                'should_block' => true,
            ];
            $this->persistAttackLog($result, $requestInfo);
            return $result;
        }

        // 1.5 扫描路径即永久封禁：命中配置路径则立即永久封禁，仅后台解禁可恢复（并记入攻击记录）
        $banOnPathResult = $this->checkBanOnPathMatch($clientIp, $uri);
        if ($banOnPathResult['is_attack']) {
            $this->stats['ban_on_path_blocks']++;
            $this->persistAttackLog($banOnPathResult, $requestInfo);
            $this->markAttackSignaled($clientIp);
            return $banOnPathResult;
        }

        // 1.6 攻击信号后：已被标记为攻击者的 IP，在禁止路径外的请求多次则封禁
        $attackSignaledResult = $this->checkAttackSignaledFollowBan($clientIp, $uri);
        if ($attackSignaledResult['is_attack']) {
            $this->stats['attack_signaled_follow_blocks']++;
            $this->persistAttackLog($attackSignaledResult, $requestInfo);
            return $attackSignaledResult;
        }

        // 1.7 流量暴增检测（更新暴增状态）
        $this->updateTrafficSpike();
        // 1.8 非框架路由连续请求：暴增时（或始终）连续 N 次命中非框架路由则封禁，解封后再触发 3 次则永久封禁
        $unknownRouteResult = $this->checkUnknownRouteBan($clientIp, $uri);
        if ($unknownRouteResult['is_attack']) {
            $this->stats['unknown_route_blocks']++;
            $this->persistAttackLog($unknownRouteResult, $requestInfo);
            $this->markAttackSignaled($clientIp);
            return $unknownRouteResult;
        }
        
        // 2. 频率限制检测
        $rateResult = $this->checkRateLimit($clientIp);
        if ($rateResult['is_attack']) {
            $this->stats['rate_limit_blocks']++;
            $requestInfo['request_count'] = $this->ipCounters[$clientIp]['count'] ?? 1;
            $this->persistAttackLog($rateResult, $requestInfo);
            $this->markAttackSignaled($clientIp);
            return $rateResult;
        }

        // 3. 路径级限流
        $pathRateResult = $this->checkPathRateLimit($clientIp, $uri);
        if ($pathRateResult['is_attack']) {
            $this->stats['path_rate_limit_blocks']++;
            $this->persistAttackLog($pathRateResult, $requestInfo);
            $this->markAttackSignaled($clientIp);
            return $pathRateResult;
        }

        // 4. 路径扫描检测
        $pathResult = $this->checkPathScan($clientIp, $uri);
        if ($pathResult['is_attack']) {
            $this->stats['path_scan_blocks']++;
            $requestInfo['unique_paths'] = $this->ipCounters[$clientIp]['unique_paths'] ?? 0;
            $this->persistAttackLog($pathResult, $requestInfo);
            $this->markAttackSignaled($clientIp);
            return $pathResult;
        }

        // 5. 敏感路径保护
        $protectedResult = $this->checkProtectedPaths($clientIp, $uri);
        if ($protectedResult['is_attack']) {
            $this->stats['protected_path_blocks']++;
            $this->persistAttackLog($protectedResult, $requestInfo);
            $this->markAttackSignaled($clientIp);
            return $protectedResult;
        }

        // 6. 恶意特征检测
        $maliciousResult = $this->checkMaliciousPatterns($clientIp, $uri, $body);
        if ($maliciousResult['is_attack']) {
            $this->stats['malicious_blocks']++;
            $this->persistAttackLog($maliciousResult, $requestInfo);
            $this->markAttackSignaled($clientIp);
            return $maliciousResult;
        }

        // 7. 恶意 User-Agent 检测
        $uaResult = $this->checkUserAgent($clientIp, $headers);
        if ($uaResult['is_attack']) {
            $this->stats['bad_ua_blocks']++;
            $this->persistAttackLog($uaResult, $requestInfo);
            $this->markAttackSignaled($clientIp);
            return $uaResult;
        }
        
        return [
            'is_attack' => false,
            'type' => 'none',
            'reason' => '',
            'should_block' => false,
        ];
    }
    
    /**
     * 持久化攻击日志到数据库
     *
     * @param array $detection 检测结果
     * @param array $requestInfo 请求信息
     */
    private function persistAttackLog(array $detection, array $requestInfo): void
    {
        try {
            AttackLogService::log($detection, $requestInfo);
        } catch (\Throwable $e) {
            // 日志记录失败不应影响主流程
        }
    }
    
    /**
     * 检查 IP 是否被封禁
     */
    private function isBlocked(string $ip): bool
    {
        if (!isset($this->blockedIps[$ip])) {
            return false;
        }
        
        if ($this->blockedIps[$ip] < \time()) {
            unset($this->blockedIps[$ip]);
            return false;
        }
        
        return true;
    }
    
    /**
     * 封禁 IP（临时）
     */
    private function blockIp(string $ip, int $duration): void
    {
        $this->blockedIps[$ip] = \time() + $duration;
        $this->logAttack($ip, 'block', "IP 被封禁 {$duration} 秒");
    }

    /**
     * 永久封禁 IP：写入持久化列表，仅后台解禁可恢复
     */
    private function blockIpPermanent(string $ip): void
    {
        if (isset($this->permanentBannedIps[$ip])) {
            return;
        }
        $this->permanentBannedIps[$ip] = true;
        $this->blockedIps[$ip] = \PHP_INT_MAX;
        $this->savePermanentBannedIps();
        $this->logAttack($ip, 'block_permanent', 'IP 已永久封禁（命中扫描路径，仅后台可解禁）');
    }

    /**
     * 标记 IP 为攻击信号（命中任意攻击规则后记录，用于后续「规则外多次请求即封禁」）
     */
    private function markAttackSignaled(string $ip): void
    {
        $rule = $this->rules['attack_signaled_follow_ban'] ?? [];
        if (!($rule['enabled'] ?? true)) {
            return;
        }
        $ttl = (int)($rule['signal_ttl'] ?? 3600);
        $this->attackSignaledIps[$ip] = \time() + $ttl;
    }

    /**
     * 请求 URI 是否命中「禁止路径」（ban_on_path_match.paths）
     */
    private function isUriForbiddenPath(string $uri): bool
    {
        $paths = $this->rules['ban_on_path_match']['paths'] ?? [];
        if (!\is_array($paths) || $paths === []) {
            return false;
        }
        $path = \strtolower((string)(\parse_url($uri, PHP_URL_PATH) ?? '/'));
        foreach ($paths as $p) {
            $p = \strtolower(\trim((string)$p));
            if ($p !== '' && \str_contains($path, $p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 攻击信号后：被标记的 IP 在禁止路径外的请求超过次数则封禁
     */
    private function checkAttackSignaledFollowBan(string $ip, string $uri): array
    {
        $rule = $this->rules['attack_signaled_follow_ban'] ?? [];
        if (!($rule['enabled'] ?? true)) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        $now = \time();
        if (!isset($this->attackSignaledIps[$ip]) || $this->attackSignaledIps[$ip] < $now) {
            unset($this->attackSignaledIps[$ip], $this->attackSignaledRequestCount[$ip]);
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        if ($this->isUriForbiddenPath($uri)) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        $window = (int)($rule['window'] ?? 60);
        $maxRequests = (int)($rule['max_requests'] ?? 15);
        $blockDuration = (int)($rule['block_duration'] ?? 600);
        if (!isset($this->attackSignaledRequestCount[$ip])) {
            $this->attackSignaledRequestCount[$ip] = ['count' => 0, 'window_start' => $now];
        }
        $bucket = &$this->attackSignaledRequestCount[$ip];
        if ($now - $bucket['window_start'] > $window) {
            $bucket = ['count' => 0, 'window_start' => $now];
        }
        $bucket['count']++;
        if ($bucket['count'] <= $maxRequests) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        $this->blockIp($ip, $blockDuration);
        return [
            'is_attack' => true,
            'type' => 'attack_signaled_follow_ban',
            'reason' => "攻击信号后规则外请求过多: {$bucket['count']} 次/{$window}秒 → 已封禁 {$blockDuration} 秒",
            'should_block' => true,
        ];
    }

    /**
     * 从 generated/routers 加载框架已知路由路径（用于判断是否「非框架路由」）
     */
    private function loadKnownRoutePaths(): void
    {
        $now = \time();
        if ($now - $this->knownRoutePathsLoadedAt < $this->knownRoutePathsReloadInterval) {
            return;
        }
        $this->knownRoutePathsLoadedAt = $now;
        $paths = [];
        if (!\class_exists(Env::class, false)) {
            return;
        }
        $files = Env::router_files_PATH ?? [];
        if (!\is_array($files)) {
            return;
        }
        foreach ($files as $file) {
            if (!\is_file($file)) {
                continue;
            }
            try {
                $routes = @(include $file);
                if (!\is_array($routes)) {
                    continue;
                }
                foreach (\array_keys($routes) as $key) {
                    $path = \trim((string)\preg_replace('/::.*$/', '', $key));
                    if ($path !== '') {
                        $path = '/' . \trim($path, '/');
                        $paths[$path] = true;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        $this->knownRoutePaths = \array_keys($paths);
    }

    private function isPathKnownRoute(string $path): bool
    {
        $this->loadKnownRoutePaths();
        $path = '/' . \trim(\strtolower((string)$path), '/');
        if ($path === '/' && \in_array('/', $this->knownRoutePaths, true)) {
            return true;
        }
        foreach ($this->knownRoutePaths as $r) {
            if ($path === $r || \str_starts_with($path, $r . '/') || \str_starts_with($r, $path . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * 更新流量暴增状态：当前窗口请求量 >= 上一窗口 * multiplier 则进入 spike 模式
     */
    private function updateTrafficSpike(): void
    {
        $rule = $this->rules['traffic_spike'] ?? [];
        if (!($rule['enabled'] ?? true)) {
            return;
        }
        $now = \time();
        $window = (int)($rule['window'] ?? 60);
        $multiplier = (float)($rule['multiplier'] ?? 2.0);
        $spikeDuration = (int)($rule['spike_mode_duration'] ?? 120);
        $cur = &$this->requestCountSpikeWindow['current'];
        $prev = &$this->requestCountSpikeWindow['previous'];
        if ($cur[0] === 0) {
            $cur = [$now, 0];
            $prev = [$now - $window, 0];
        }
        $cur[1]++;
        if ($now - $cur[0] >= $window) {
            $oldPrevCount = $prev[1];
            $prev = [$cur[0], $cur[1]];
            $cur = [$now, 0];
            if ($oldPrevCount > 0 && $prev[1] >= $oldPrevCount * $multiplier) {
                $this->spikeModeUntil = $now + $spikeDuration;
            }
        }
        if ($this->spikeModeUntil > 0 && $now > $this->spikeModeUntil) {
            $this->spikeModeUntil = 0;
        }
    }

    /**
     * 非框架路由连续请求：达到次数则封禁；解封后再触发满 3 次则永久封禁
     */
    private function checkUnknownRouteBan(string $ip, string $uri): array
    {
        $rule = $this->rules['unknown_route_ban'] ?? [];
        if (!($rule['enabled'] ?? true)) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        $onlyInSpike = (bool)($rule['only_in_spike_mode'] ?? true);
        if ($onlyInSpike && $this->spikeModeUntil < \time()) {
            $this->consecutiveUnknownRoute[$ip] = 0;
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        $path = (string)(\parse_url($uri, PHP_URL_PATH) ?? '/');
        if ($this->isPathKnownRoute($path)) {
            $this->consecutiveUnknownRoute[$ip] = 0;
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        $consecutive = (int)($this->consecutiveUnknownRoute[$ip] ?? 0);
        $consecutive++;
        $this->consecutiveUnknownRoute[$ip] = $consecutive;
        $threshold = (int)($rule['consecutive_count'] ?? 5);
        $blockDuration = (int)($rule['block_duration'] ?? 300);
        if ($consecutive < $threshold) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        $unblockRule = $this->rules['unblock_retrigger_permanent'] ?? [];
        $retriggerLimit = (\is_array($unblockRule) && ($unblockRule['enabled'] ?? true)) ? (int)($unblockRule['count'] ?? 3) : 0;
        $usePermanent = $retriggerLimit > 0 && (int)($this->unblockRetriggerCount[$ip] ?? 0) >= $retriggerLimit;
        $this->consecutiveUnknownRoute[$ip] = 0;
        if ($usePermanent) {
            $this->blockIpPermanent($ip);
            $this->unblockRetriggerCount[$ip] = 0;
            return [
                'is_attack' => true,
                'type' => 'unknown_route_ban',
                'reason' => "非框架路由连续请求 {$consecutive} 次（解封后再触发达 {$retriggerLimit} 次）→ 已永久封禁",
                'should_block' => true,
            ];
        }
        if ($blockDuration > 0) {
            $this->blockIp($ip, $blockDuration);
        }
        return [
            'is_attack' => true,
            'type' => 'unknown_route_ban',
            'reason' => "非框架路由连续请求 {$consecutive} 次 → 已封禁 " . ($blockDuration > 0 ? "{$blockDuration} 秒" : "并记录"),
            'should_block' => true,
        ];
    }

    /**
     * 扫描路径即永久封禁：URI 命中配置的任意路径则立即永久封禁
     */
    private function checkBanOnPathMatch(string $ip, string $uri): array
    {
        $rule = $this->rules['ban_on_path_match'] ?? [];
        if (!($rule['enabled'] ?? true)) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        $paths = $rule['paths'] ?? [];
        if (!\is_array($paths) || $paths === []) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        $path = \strtolower((string)(\parse_url($uri, PHP_URL_PATH) ?? '/'));
        foreach ($paths as $pattern) {
            $p = \strtolower(\trim((string)$pattern));
            if ($p !== '' && \str_contains($path, $p)) {
                $this->blockIpPermanent($ip);
                return [
                    'is_attack' => true,
                    'type' => 'ban_on_path_match',
                    'reason' => "访问扫描路径: {$pattern} → IP 已永久封禁，仅后台可解禁",
                    'should_block' => true,
                ];
            }
        }
        return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
    }
    
    /**
     * 频率限制检测
     */
    private function checkRateLimit(string $ip): array
    {
        $rule = $this->rules['rate_limit'] ?? [];
        if (!($rule['enabled'] ?? true)) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        
        $now = \time();
        $window = $rule['window'] ?? 60;
        $maxRequests = $rule['max_requests'] ?? 100;
        $blockDuration = $rule['block_duration'] ?? 300;
        
        if (!isset($this->ipCounters[$ip])) {
            $this->ipCounters[$ip] = [
                'count' => 0,
                'first_time' => $now,
                'paths' => [],
            ];
        }
        
        $counter = &$this->ipCounters[$ip];
        
        // 检查时间窗口
        if ($now - $counter['first_time'] > $window) {
            // 重置计数器
            $counter['count'] = 0;
            $counter['first_time'] = $now;
            $counter['paths'] = [];
        }
        
        $counter['count']++;
        
        if ($counter['count'] > $maxRequests) {
            $this->blockIp($ip, $blockDuration);
            return [
                'is_attack' => true,
                'type' => 'rate_limit',
                'reason' => "请求频率过高: {$counter['count']} 次/{$window}秒",
                'should_block' => true,
            ];
        }
        
        return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
    }

    private function checkPathRateLimit(string $ip, string $uri): array
    {
        $rule = $this->rules['path_rate_limits'] ?? [];
        if (!($rule['enabled'] ?? true)) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        $rules = $rule['rules'] ?? [];
        if (!\is_array($rules) || $rules === []) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }

        $path = (string)(\parse_url($uri, PHP_URL_PATH) ?? '/');
        $matched = null;
        foreach ($rules as $item) {
            if (!\is_array($item) || !($item['enabled'] ?? true)) {
                continue;
            }
            $targetPath = (string)($item['path'] ?? '');
            if ($targetPath === '') {
                continue;
            }
            if ($path === $targetPath || \str_starts_with($path, \rtrim($targetPath, '/') . '/')) {
                $matched = $item;
                break;
            }
        }
        if ($matched === null) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }

        $window = (int)($matched['window'] ?? 60);
        $maxRequests = (int)($matched['max_requests'] ?? 60);
        $blockDuration = (int)($matched['block_duration'] ?? 60);
        $counterKey = '__path_rate__' . (string)($matched['path'] ?? $path);
        $now = \time();

        if (!isset($this->ipCounters[$ip])) {
            $this->ipCounters[$ip] = [
                'count' => 0,
                'first_time' => $now,
                'paths' => [],
            ];
        }
        if (!isset($this->ipCounters[$ip]['paths'][$counterKey])) {
            $this->ipCounters[$ip]['paths'][$counterKey] = ['count' => 0, 'first_time' => $now];
        }

        $bucket = &$this->ipCounters[$ip]['paths'][$counterKey];
        if (!\is_array($bucket)) {
            $bucket = ['count' => 0, 'first_time' => $now];
        }

        if (($now - (int)($bucket['first_time'] ?? $now)) > $window) {
            $bucket = ['count' => 0, 'first_time' => $now];
        }
        $bucket['count'] = (int)($bucket['count'] ?? 0) + 1;

        if ($bucket['count'] > $maxRequests) {
            $this->blockIp($ip, $blockDuration);
            return [
                'is_attack' => true,
                'type' => 'path_rate_limit',
                'reason' => "路径限流触发: {$path} {$bucket['count']}/{$window}s",
                'should_block' => true,
            ];
        }

        return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
    }
    
    /**
     * 路径扫描检测
     */
    private function checkPathScan(string $ip, string $uri): array
    {
        $rule = $this->rules['path_scan'] ?? [];
        if (!($rule['enabled'] ?? true)) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        
        $maxUniquePaths = $rule['max_unique_paths'] ?? 50;
        $blockDuration = $rule['block_duration'] ?? 600;
        
        if (!isset($this->ipCounters[$ip])) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        
        $counter = &$this->ipCounters[$ip];
        
        // 记录访问路径
        $path = \parse_url($uri, PHP_URL_PATH) ?? '/';
        // 仅统计真实 URI 路径，避免与 path_rate_limits 的内部计数桶冲突
        foreach ($counter['paths'] as $key => $value) {
            if (\str_starts_with((string)$key, '__path_rate__')) {
                continue;
            }
            if (!\is_numeric($value)) {
                $counter['paths'][$key] = 0;
            }
        }
        if (!isset($counter['paths'][$path])) {
            $counter['paths'][$path] = 0;
        }
        $counter['paths'][$path]++;
        
        $uniquePathCount = 0;
        foreach ($counter['paths'] as $key => $value) {
            if (\str_starts_with((string)$key, '__path_rate__')) {
                continue;
            }
            $uniquePathCount++;
        }
        
        if ($uniquePathCount > $maxUniquePaths) {
            $this->blockIp($ip, $blockDuration);
            return [
                'is_attack' => true,
                'type' => 'path_scan',
                'reason' => "路径扫描检测: {$uniquePathCount} 个不同路径",
                'should_block' => true,
            ];
        }
        
        return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
    }
    
    /**
     * 敏感路径保护
     */
    private function checkProtectedPaths(string $ip, string $uri): array
    {
        $rule = $this->rules['protected_paths'] ?? [];
        if (!($rule['enabled'] ?? true)) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        
        $protectedPaths = $rule['paths'] ?? [];
        $blockDuration = $rule['block_duration'] ?? 1800;
        
        $path = \strtolower(\parse_url($uri, PHP_URL_PATH) ?? '/');
        
        foreach ($protectedPaths as $protected) {
            if (\str_contains($path, \strtolower($protected))) {
                $this->blockIp($ip, $blockDuration);
                return [
                    'is_attack' => true,
                    'type' => 'protected_path',
                    'reason' => "访问受保护路径: {$protected}",
                    'should_block' => true,
                ];
            }
        }
        
        return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
    }
    
    /**
     * 恶意特征检测
     */
    private function checkMaliciousPatterns(string $ip, string $uri, string $body): array
    {
        $rule = $this->rules['malicious_patterns'] ?? [];
        if (!($rule['enabled'] ?? true)) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        
        $patterns = $rule['patterns'] ?? [];
        $blockDuration = $rule['block_duration'] ?? 3600;
        
        // 检查 URI
        $fullInput = $uri . ' ' . $body;
        
        foreach ($patterns as $pattern) {
            if (@\preg_match($pattern, $fullInput)) {
                $this->blockIp($ip, $blockDuration);
                return [
                    'is_attack' => true,
                    'type' => 'malicious_pattern',
                    'reason' => "检测到恶意特征",
                    'should_block' => true,
                ];
            }
        }
        
        return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
    }
    
    /**
     * 恶意 User-Agent 检测
     */
    private function checkUserAgent(string $ip, array $headers): array
    {
        // 没有 headers 时跳过检测（TCP 代理模式下 SSL 握手阶段没有 HTTP 头）
        if (empty($headers)) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        
        $rule = $this->rules['bad_user_agents'] ?? [];
        if (!($rule['enabled'] ?? true)) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        
        $patterns = $rule['patterns'] ?? [];
        $blockDuration = $rule['block_duration'] ?? 300;
        
        // 获取 User-Agent
        $ua = '';
        $hasUaHeader = false;
        foreach ($headers as $name => $value) {
            if (\strtolower($name) === 'user-agent') {
                $ua = \is_array($value) ? ($value[0] ?? '') : $value;
                $hasUaHeader = true;
                break;
            }
        }
        
        // 有 headers 但没有 User-Agent 头时，也跳过（某些合法请求可能不带 UA）
        // 只有明确发送了空 UA 或恶意 UA 才检测
        if (!$hasUaHeader) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        
        foreach ($patterns as $pattern) {
            if (@\preg_match($pattern, $ua)) {
                $this->blockIp($ip, $blockDuration);
                return [
                    'is_attack' => true,
                    'type' => 'bad_user_agent',
                    'reason' => "恶意 User-Agent: " . \substr($ua, 0, 50),
                    'should_block' => true,
                ];
            }
        }
        
        return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
    }
    
    /**
     * 记录攻击日志
     */
    private function logAttack(string $ip, string $type, string $reason): void
    {
        $this->attackLogs[] = [
            'time' => \time(),
            'ip' => $ip,
            'type' => $type,
            'reason' => $reason,
        ];
        
        // 限制日志大小
        if (\count($this->attackLogs) > 1000) {
            $this->attackLogs = \array_slice($this->attackLogs, -500);
        }
    }
    
    /**
     * 定期清理过期数据
     */
    private function maybeCleanup(): void
    {
        $now = \time();
        if ($now - $this->lastCleanup < $this->cleanupInterval) {
            return;
        }
        $this->lastCleanup = $now;
        
        // 清理过期的封禁（永久封禁 expireTime === PHP_INT_MAX 不清理）
        foreach ($this->blockedIps as $ip => $expireTime) {
            if ($expireTime !== \PHP_INT_MAX && $expireTime < $now) {
                unset($this->blockedIps[$ip]);
            }
        }
        
        // 清理过期的计数器
        $window = $this->rules['rate_limit']['window'] ?? 60;
        foreach ($this->ipCounters as $ip => $counter) {
            if ($now - $counter['first_time'] > $window * 2) {
                unset($this->ipCounters[$ip]);
            }
        }
        
        // 清理过期的攻击日志（保留 1 小时）
        $this->attackLogs = \array_filter($this->attackLogs, function ($log) use ($now) {
            return $now - $log['time'] < 3600;
        });
        
        // 清理过期的 SSL 握手失败计数器
        $sslWindow = $this->rules['ssl_handshake_failure']['window'] ?? 60;
        foreach ($this->sslFailureCounts as $ip => $counter) {
            if ($now - $counter['window_start'] > $sslWindow * 2) {
                unset($this->sslFailureCounts[$ip]);
            }
        }
        // 清理过期的攻击信号与相关计数
        foreach ($this->attackSignaledIps as $ip => $expire) {
            if ($expire < $now) {
                unset($this->attackSignaledIps[$ip], $this->attackSignaledRequestCount[$ip]);
            }
        }
    }
    
    /**
     * 获取统计信息
     */
    public function getStats(): array
    {
        return [
            'total_requests' => $this->stats['total_requests'],
            'blocked_requests' => $this->stats['blocked_requests'],
            'rate_limit_blocks' => $this->stats['rate_limit_blocks'],
            'path_rate_limit_blocks' => $this->stats['path_rate_limit_blocks'],
            'path_scan_blocks' => $this->stats['path_scan_blocks'],
            'malicious_blocks' => $this->stats['malicious_blocks'],
            'bad_ua_blocks' => $this->stats['bad_ua_blocks'],
            'protected_path_blocks' => $this->stats['protected_path_blocks'],
            'ban_on_path_blocks' => $this->stats['ban_on_path_blocks'],
            'attack_signaled_follow_blocks' => $this->stats['attack_signaled_follow_blocks'],
            'unknown_route_blocks' => $this->stats['unknown_route_blocks'],
            'blocked_ips_count' => \count($this->blockedIps),
            'permanent_banned_ips_count' => \count($this->permanentBannedIps),
            'tracked_ips_count' => \count($this->ipCounters),
            'attack_logs_count' => \count($this->attackLogs),
            'block_rate' => $this->stats['total_requests'] > 0 
                ? \round($this->stats['blocked_requests'] / $this->stats['total_requests'] * 100, 2) 
                : 0,
        ];
    }
    
    /**
     * 获取最近的攻击日志
     */
    public function getRecentAttackLogs(int $limit = 100): array
    {
        return \array_slice($this->attackLogs, -$limit);
    }
    
    /**
     * 获取被封禁的 IP 列表（含永久封禁）
     */
    public function getBlockedIps(): array
    {
        $now = \time();
        $result = [];
        
        foreach ($this->blockedIps as $ip => $expireTime) {
            if ($expireTime > $now || $expireTime === \PHP_INT_MAX) {
                $result[$ip] = [
                    'expire_time' => $expireTime === \PHP_INT_MAX ? null : $expireTime,
                    'remaining_seconds' => $expireTime === \PHP_INT_MAX ? null : $expireTime - $now,
                    'permanent' => $expireTime === \PHP_INT_MAX,
                ];
            }
        }
        
        return $result;
    }
    
    /**
     * 手动封禁 IP
     */
    public function manualBlock(string $ip, int $duration = 3600): void
    {
        $this->blockIp($ip, $duration);
    }
    
    /**
     * 手动解封 IP（含永久封禁列表）；解封时增加「解封后再触发」计数，满 3 次再触发则永久封禁
     */
    public function unblock(string $ip): void
    {
        unset($this->blockedIps[$ip]);
        if (isset($this->permanentBannedIps[$ip])) {
            unset($this->permanentBannedIps[$ip]);
            $this->savePermanentBannedIps();
        }
        $rule = $this->rules['unblock_retrigger_permanent'] ?? [];
        if (\is_array($rule) && ($rule['enabled'] ?? true)) {
            $this->unblockRetriggerCount[$ip] = (int)($this->unblockRetriggerCount[$ip] ?? 0) + 1;
        }
    }

    /**
     * 清空全部封禁（含永久封禁列表，用于运维）
     */
    public function clearAllBlocks(): void
    {
        $this->blockedIps = [];
        $this->permanentBannedIps = [];
        $this->savePermanentBannedIps();
    }
    
    /**
     * 获取当前规则
     */
    public function getRules(): array
    {
        return $this->rules;
    }
    
    /**
     * 更新规则
     */
    public function updateRules(array $rules): void
    {
        $this->rules = \array_replace_recursive($this->defaultRules, $rules);
        
        // 保存合并后的规则到文件，保证重载时与当前内存一致（避免只保存前端片段导致缺省项丢失）
        $rulesFile = self::getRulesFilePath();
        $dir = \dirname($rulesFile);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        @\file_put_contents($rulesFile, \json_encode($this->rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        // 写入更新标记
        @\file_put_contents(self::getRulesUpdateFlagPath(), (string) \time());

        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventData = [
            'rules' => $rules,
            'merged_rules' => $this->rules,
            'instance' => $this->instanceName,
        ];
        $eventsManager->dispatch('Weline_Server::integration::security_rules_updated', $eventData);
    }
    
    /**
     * 生成攻击信号头
     *
     * @param array $detection 检测结果
     * @param string $domain 域名
     * @return string 信号头值（JSON 格式）
     */
    public static function generateSignalHeader(array $detection, string $domain): string
    {
        return \json_encode([
            'type' => $detection['type'],
            'domain' => $domain,
            'timestamp' => \time(),
            'reason' => $detection['reason'],
        ], JSON_UNESCAPED_UNICODE);
    }
    
    // ============================
    // SSL 握手失败追踪（Dispatcher 快速关闭检测）
    // ============================
    
    /**
     * 记录一次疑似 SSL 握手失败
     *
     * 当 Dispatcher 检测到连接在极短时间内关闭（快速关闭模式），视为疑似 SSL 握手失败。
     * 在滑动窗口内累计，超过阈值后封禁该 IP。
     *
     * @param string $ip       客户端 IP
     * @param float  $duration 连接存活时长（秒）
     * @return array{banned: bool, count: int, threshold: int, ban_duration: int}
     */
    public function recordSslFailure(string $ip, float $duration = 0.0): array
    {
        // 白名单 IP 跳过 SSL 失败记录
        if ($this->isWhitelisted($ip)) {
            return ['banned' => false, 'count' => 0, 'threshold' => 0, 'ban_duration' => 0, 'whitelisted' => true];
        }
        
        $rule = $this->rules['ssl_handshake_failure'] ?? [];
        if (!($rule['enabled'] ?? true)) {
            return ['banned' => false, 'count' => 0, 'threshold' => 0, 'ban_duration' => 0];
        }
        
        $now = \time();
        $window = $rule['window'] ?? 60;
        $maxFailures = $rule['max_failures'] ?? 5;
        $blockDuration = $rule['block_duration'] ?? 600;
        
        $this->stats['ssl_failure_total']++;
        
        // 已被封禁则直接返回
        if ($this->isBlocked($ip)) {
            return ['banned' => true, 'count' => $maxFailures, 'threshold' => $maxFailures, 'ban_duration' => $blockDuration];
        }
        
        // 初始化或重置过期的窗口
        if (!isset($this->sslFailureCounts[$ip]) || ($now - $this->sslFailureCounts[$ip]['window_start']) > $window) {
            $this->sslFailureCounts[$ip] = ['count' => 0, 'window_start' => $now];
        }
        
        $this->sslFailureCounts[$ip]['count']++;
        $count = $this->sslFailureCounts[$ip]['count'];
        
        // 超过阈值 → 封禁
        if ($count >= $maxFailures) {
            $this->blockIp($ip, $blockDuration);
            $this->stats['ssl_failure_blocks']++;
            
            $this->logAttack($ip, 'ssl_handshake_failure', "SSL 握手失败频繁: {$window}秒内 {$count} 次失败（最后一次连接存活 " . \round($duration, 1) . "秒）→ 已封禁 {$blockDuration} 秒");
            
            // 清除计数器（已封禁）
            unset($this->sslFailureCounts[$ip]);
            
            return ['banned' => true, 'count' => $count, 'threshold' => $maxFailures, 'ban_duration' => $blockDuration];
        }
        
        return ['banned' => false, 'count' => $count, 'threshold' => $maxFailures, 'ban_duration' => $blockDuration];
    }
    
    /**
     * 检查 IP 是否因 SSL 握手失败被封禁（复用 isBlocked 逻辑）
     *
     * @param string $ip 客户端 IP
     * @return bool 是否被封禁
     */
    public function isSslBanned(string $ip): bool
    {
        return $this->isBlocked($ip);
    }
    
    /**
     * 获取 SSL 握手失败的快速关闭阈值（秒）
     *
     * @return float 连接存活 < 此值视为疑似 SSL 失败
     */
    public function getSslFastCloseThreshold(): float
    {
        return (float) ($this->rules['ssl_handshake_failure']['fast_close_threshold'] ?? 5.0);
    }
    
    /**
     * 获取 SSL 失败统计
     *
     * @return array{total: int, blocks: int, tracked_ips: int}
     */
    public function getSslFailureStats(): array
    {
        return [
            'total' => $this->stats['ssl_failure_total'],
            'blocks' => $this->stats['ssl_failure_blocks'],
            'tracked_ips' => \count($this->sslFailureCounts),
        ];
    }
}
