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
     * 格式: [ip => expire_time]
     * @var array<string, int>
     */
    private array $blockedIps = [];
    
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
        // 频率限制
        'rate_limit' => [
            'enabled' => true,
            'window' => 60,           // 时间窗口（秒）
            'max_requests' => 100,    // 最大请求数
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
        
        // 慢速攻击检测
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
        'ssl_handshake_failure' => [
            'enabled' => true,
            'window' => 60,                // 统计窗口（秒）
            'max_failures' => 5,           // 触发封禁的最大失败次数
            'block_duration' => 600,       // 封禁时长（秒）
            'fast_close_threshold' => 5.0, // 快速关闭阈值（秒），连接存活 < 此值视为疑似 SSL 失败
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
    ];
    
    /**
     * 私有构造函数
     */
    private function __construct()
    {
        $this->rules = $this->defaultRules;
        $this->loadRules();
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
     * 重新加载规则
     */
    public function reload(): void
    {
        $this->loadRules();
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
        
        // 1. 检查是否已被封禁
        if ($this->isBlocked($clientIp)) {
            $this->stats['blocked_requests']++;
            $result = [
                'is_attack' => true,
                'type' => 'blocked',
                'reason' => 'IP 已被临时封禁',
                'should_block' => true,
            ];
            $this->persistAttackLog($result, $requestInfo);
            return $result;
        }
        
        // 2. 频率限制检测
        $rateResult = $this->checkRateLimit($clientIp);
        if ($rateResult['is_attack']) {
            $this->stats['rate_limit_blocks']++;
            $requestInfo['request_count'] = $this->ipCounters[$clientIp]['count'] ?? 1;
            $this->persistAttackLog($rateResult, $requestInfo);
            return $rateResult;
        }
        
        // 3. 路径扫描检测
        $pathRateResult = $this->checkPathRateLimit($clientIp, $uri);
        if ($pathRateResult['is_attack']) {
            $this->stats['path_rate_limit_blocks']++;
            $this->persistAttackLog($pathRateResult, $requestInfo);
            return $pathRateResult;
        }

        // 4. 路径扫描检测
        $pathResult = $this->checkPathScan($clientIp, $uri);
        if ($pathResult['is_attack']) {
            $this->stats['path_scan_blocks']++;
            $requestInfo['unique_paths'] = $this->ipCounters[$clientIp]['unique_paths'] ?? 0;
            $this->persistAttackLog($pathResult, $requestInfo);
            return $pathResult;
        }
        
        // 5. 敏感路径保护
        $protectedResult = $this->checkProtectedPaths($clientIp, $uri);
        if ($protectedResult['is_attack']) {
            $this->stats['protected_path_blocks']++;
            $this->persistAttackLog($protectedResult, $requestInfo);
            return $protectedResult;
        }
        
        // 6. 恶意特征检测
        $maliciousResult = $this->checkMaliciousPatterns($clientIp, $uri, $body);
        if ($maliciousResult['is_attack']) {
            $this->stats['malicious_blocks']++;
            $this->persistAttackLog($maliciousResult, $requestInfo);
            return $maliciousResult;
        }
        
        // 7. 恶意 User-Agent 检测
        $uaResult = $this->checkUserAgent($clientIp, $headers);
        if ($uaResult['is_attack']) {
            $this->stats['bad_ua_blocks']++;
            $this->persistAttackLog($uaResult, $requestInfo);
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
     * 封禁 IP
     */
    private function blockIp(string $ip, int $duration): void
    {
        $this->blockedIps[$ip] = \time() + $duration;
        $this->logAttack($ip, 'block', "IP 被封禁 {$duration} 秒");
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
        $rule = $this->rules['bad_user_agents'] ?? [];
        if (!($rule['enabled'] ?? true)) {
            return ['is_attack' => false, 'type' => 'none', 'reason' => '', 'should_block' => false];
        }
        
        $patterns = $rule['patterns'] ?? [];
        $blockDuration = $rule['block_duration'] ?? 300;
        
        // 获取 User-Agent
        $ua = '';
        foreach ($headers as $name => $value) {
            if (\strtolower($name) === 'user-agent') {
                $ua = \is_array($value) ? ($value[0] ?? '') : $value;
                break;
            }
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
        
        // 清理过期的封禁
        foreach ($this->blockedIps as $ip => $expireTime) {
            if ($expireTime < $now) {
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
            'blocked_ips_count' => \count($this->blockedIps),
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
     * 获取被封禁的 IP 列表
     */
    public function getBlockedIps(): array
    {
        $now = \time();
        $result = [];
        
        foreach ($this->blockedIps as $ip => $expireTime) {
            if ($expireTime > $now) {
                $result[$ip] = [
                    'expire_time' => $expireTime,
                    'remaining_seconds' => $expireTime - $now,
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
     * 手动解封 IP
     */
    public function unblock(string $ip): void
    {
        unset($this->blockedIps[$ip]);
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
        
        // 保存到文件
        $rulesFile = self::getRulesFilePath();
        $dir = \dirname($rulesFile);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        @\file_put_contents($rulesFile, \json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
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
