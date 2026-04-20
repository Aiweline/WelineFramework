<?php
declare(strict_types=1);

/**
 * Weline Server - 路由缓存服务
 *
 * 管理 Dispatcher 透传模式下的路由信息缓存。
 * 实现「首次学习，后续直达」策略：
 * 1. 首次连接：透传到 Worker，Worker 自报告路由信息
 * 2. 后续连接：根据缓存的路由信息直接路由
 *
 * 缓存键设计：
 * - SNI 维度：SNI -> Worker 端口映射
 * - 客户端 IP 维度：IP -> 会话数据
 * - 连接 ID 维度：连接级别的 Keep-Alive 信息
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Dispatcher;

class RoutingCacheService
{
    /**
     * 单例实例
     */
    private static ?self $instance = null;
    
    /**
     * SNI -> Worker 端口映射
     * 格式: [sni => ['port' => int, 'expires' => int]]
     * @var array<string, array{port: int, expires: int}>
     */
    private array $sniCache = [];
    
    /**
     * 客户端 IP -> 路由信息
     * 格式: [ip => ['port' => int, 'sni' => string, 'expires' => int, 'hits' => int]]
     * @var array<string, array{port: int, sni: string, expires: int, hits: int}>
     */
    private array $ipCache = [];
    
    /**
     * 连接 ID -> 路由信息（Keep-Alive 场景）
     * 格式: [connId => ['port' => int, 'sni' => string, 'expires' => int]]
     * @var array<int, array{port: int, sni: string, expires: int}>
     */
    private array $connectionCache = [];
    
    /**
     * 路由策略配置
     * @var array<string, array{ports: int[], strategy: string}>
     */
    private array $routingRules = [];
    
    /**
     * 默认缓存过期时间（秒）
     */
    private int $defaultTtl = 3600;
    
    /**
     * 连接缓存过期时间（秒）
     */
    private int $connectionTtl = 120;
    
    /**
     * 最大 SNI 缓存条目
     */
    private int $maxSniEntries = 10000;
    
    /**
     * 最大 IP 缓存条目
     */
    private int $maxIpEntries = 50000;
    
    /**
     * 最大连接缓存条目
     */
    private int $maxConnectionEntries = 100000;
    
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
     *
     * PHP 8.4 优化：类型化数组结构提升访问性能
     *
     * @var array{sni_hits: int, sni_misses: int, ip_hits: int, ip_misses: int, conn_hits: int, conn_misses: int, total_routes: int}
     */
    private array $stats = [
        'sni_hits' => 0,
        'sni_misses' => 0,
        'ip_hits' => 0,
        'ip_misses' => 0,
        'conn_hits' => 0,
        'conn_misses' => 0,
        'total_routes' => 0,
    ];
    
    /**
     * 私有构造函数
     */
    private function __construct()
    {
        // 加载路由规则配置
        $this->loadRoutingRules();
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
     * 重置实例（用于测试）
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
    
    /**
     * 加载路由规则
     */
    private function loadRoutingRules(): void
    {
        $rulesFile = BP . 'var' . DS . 'server' . DS . 'routing-rules.json';
        if (\is_file($rulesFile)) {
            $rules = @\json_decode(\file_get_contents($rulesFile), true);
            if (\is_array($rules)) {
                $this->routingRules = $rules;
            }
        }
    }
    
    /**
     * 根据 SNI 查找路由
     *
     * @param string $sni SNI 主机名
     * @return int|null Worker 端口，未找到返回 null
     */
    public function getRouteBySni(string $sni): ?int
    {
        $sni = \strtolower($sni);
        $now = \time();
        
        // 检查缓存
        if (isset($this->sniCache[$sni])) {
            $entry = $this->sniCache[$sni];
            if ($entry['expires'] > $now) {
                $this->stats['sni_hits']++;
                return $entry['port'];
            }
            // 过期，删除
            unset($this->sniCache[$sni]);
        }
        
        $this->stats['sni_misses']++;
        return null;
    }
    
    /**
     * 根据客户端 IP 查找路由
     *
     * @param string $ip 客户端 IP
     * @return array|null 路由信息 ['port' => int, 'sni' => string]，未找到返回 null
     */
    public function getRouteByIp(string $ip): ?array
    {
        if ($this->isLoopbackIp($ip)) {
            $this->stats['ip_misses']++;
            return null;
        }

        $now = \time();
        
        if (isset($this->ipCache[$ip])) {
            $entry = $this->ipCache[$ip];
            if ($entry['expires'] > $now) {
                // 更新命中次数
                $this->ipCache[$ip]['hits']++;
                $this->stats['ip_hits']++;
                return ['port' => $entry['port'], 'sni' => $entry['sni']];
            }
            unset($this->ipCache[$ip]);
        }
        
        $this->stats['ip_misses']++;
        return null;
    }
    
    /**
     * 根据连接 ID 查找路由（Keep-Alive 场景）
     *
     * @param int $connId 连接 ID
     * @return array|null 路由信息 ['port' => int, 'sni' => string]，未找到返回 null
     */
    public function getRouteByConnection(int $connId): ?array
    {
        $now = \time();
        
        if (isset($this->connectionCache[$connId])) {
            $entry = $this->connectionCache[$connId];
            if ($entry['expires'] > $now) {
                $this->stats['conn_hits']++;
                return ['port' => $entry['port'], 'sni' => $entry['sni']];
            }
            unset($this->connectionCache[$connId]);
        }
        
        $this->stats['conn_misses']++;
        return null;
    }
    
    /**
     * 缓存 SNI 路由信息
     *
     * @param string $sni SNI 主机名
     * @param int $workerPort Worker 端口
     * @param int|null $ttl 过期时间（秒），null 使用默认值
     */
    public function cacheSniRoute(string $sni, int $workerPort, ?int $ttl = null): void
    {
        $sni = \strtolower($sni);
        $ttl = $ttl ?? $this->defaultTtl;
        
        // 检查容量限制
        if (\count($this->sniCache) >= $this->maxSniEntries) {
            $this->evictSniCache();
        }
        
        $this->sniCache[$sni] = [
            'port' => $workerPort,
            'expires' => \time() + $ttl,
        ];
        
        $this->stats['total_routes']++;
        $this->maybeCleanup();
    }
    
    /**
     * 缓存 IP 路由信息
     *
     * @param string $ip 客户端 IP
     * @param int $workerPort Worker 端口
     * @param string $sni SNI 主机名
     * @param int|null $ttl 过期时间（秒），null 使用默认值
     */
    public function cacheIpRoute(string $ip, int $workerPort, string $sni = '', ?int $ttl = null): void
    {
        if ($this->isLoopbackIp($ip)) {
            return;
        }

        $ttl = $ttl ?? $this->defaultTtl;
        
        // 检查容量限制
        if (\count($this->ipCache) >= $this->maxIpEntries) {
            $this->evictIpCache();
        }
        
        $this->ipCache[$ip] = [
            'port' => $workerPort,
            'sni' => $sni,
            'expires' => \time() + $ttl,
            'hits' => 0,
        ];
        
        $this->maybeCleanup();
    }
    
    /**
     * 缓存连接路由信息（Keep-Alive）
     *
     * @param int $connId 连接 ID
     * @param int $workerPort Worker 端口
     * @param string $sni SNI 主机名
     */
    public function cacheConnectionRoute(int $connId, int $workerPort, string $sni = ''): void
    {
        // 检查容量限制
        if (\count($this->connectionCache) >= $this->maxConnectionEntries) {
            $this->evictConnectionCache();
        }
        
        $this->connectionCache[$connId] = [
            'port' => $workerPort,
            'sni' => $sni,
            'expires' => \time() + $this->connectionTtl,
        ];
    }
    
    /**
     * 从 Worker 响应中学习路由信息
     *
     * Worker 在响应中添加以下头来自报告路由信息：
     * - X-Weline-Route-Hint: port=10443,sni=example.com,ttl=3600
     *
     * @param string $response Worker 响应
     * @param int $connId 连接 ID
     * @param string $clientIp 客户端 IP
     * @param string $sni SNI（如果已知）
     * @return array|null 提取的路由信息 ['port' => int, 'sni' => string, 'ttl' => int]
     */
    public function learnFromResponse(string $response, int $connId, string $clientIp, string $sni = ''): ?array
    {
        // 提取响应头部分
        $headerEnd = \strpos($response, "\r\n\r\n");
        if ($headerEnd === false) {
            return null;
        }

        $headers = \substr($response, 0, $headerEnd);

        // 检查是否是重定向响应（3xx 状态码）
        // 重定向响应不应该被缓存，因为它是临时状态，不代表正常的路由关系
        if (\preg_match('/^HTTP\/\d\.\d\s+3\d{2}\s+/i', $headers)) {
            return null;
        }

        // 查找 X-Weline-Route-Hint 头
        if (!\preg_match('/^X-Weline-Route-Hint:\s*(.+)$/mi', $headers, $matches)) {
            return null;
        }

        $hint = \trim($matches[1]);
        $parts = \explode(',', $hint);

        $routeInfo = ['port' => 0, 'sni' => $sni, 'ttl' => $this->defaultTtl];

        foreach ($parts as $part) {
            $part = \trim($part);
            if (\str_starts_with($part, 'port=')) {
                $routeInfo['port'] = (int) \substr($part, 5);
            } elseif (\str_starts_with($part, 'sni=')) {
                $routeInfo['sni'] = \strtolower(\substr($part, 4));
            } elseif (\str_starts_with($part, 'ttl=')) {
                $routeInfo['ttl'] = (int) \substr($part, 4);
            }
        }

        if ($routeInfo['port'] <= 0) {
            return null;
        }

        // 缓存学习到的路由信息
        if (!empty($routeInfo['sni'])) {
            $this->cacheSniRoute($routeInfo['sni'], $routeInfo['port'], $routeInfo['ttl']);
        }

        if (!empty($clientIp)) {
            $this->cacheIpRoute($clientIp, $routeInfo['port'], $routeInfo['sni'], $routeInfo['ttl']);
        }

        $this->cacheConnectionRoute($connId, $routeInfo['port'], $routeInfo['sni']);

        return $routeInfo;
    }
    
    /**
     * 移除响应中的内部路由头
     *
     * @param string $response 原始响应
     * @return string 清理后的响应
     */
    public function removeInternalHeaders(string $response): string
    {
        // 提取响应头部分
        $headerEnd = \strpos($response, "\r\n\r\n");
        if ($headerEnd === false) {
            return $response;
        }
        
        $headers = \substr($response, 0, $headerEnd);
        $body = \substr($response, $headerEnd + 4);
        
        // 移除 X-Weline-Route-Hint 头
        $headers = \preg_replace('/^X-Weline-Route-Hint:.*\r\n/mi', '', $headers);
        
        // 重新计算 Content-Length（如果有的话）
        // 由于我们只是移除头，不影响 body，Content-Length 不需要调整
        
        return $headers . "\r\n\r\n" . $body;
    }
    
    /**
     * 移除连接缓存
     *
     * @param int $connId 连接 ID
     */
    public function removeConnection(int $connId): void
    {
        unset($this->connectionCache[$connId]);
    }

    /**
     * 清除指定 IP 和 SNI 的路由缓存
     * 用于在检测到错误路由（如重定向循环）时清除缓存
     *
     * @param string $clientIp 客户端 IP
     * @param string $sni SNI 主机名
     */
    public function purgeRouteCache(string $clientIp, string $sni = ''): void
    {
        // 清除 IP 缓存
        if (!empty($clientIp)) {
            unset($this->ipCache[$clientIp]);
        }

        // 清除 SNI 缓存
        if (!empty($sni)) {
            $sni = \strtolower($sni);
            unset($this->sniCache[$sni]);
        }
    }
    
    /**
     * 刷新连接缓存过期时间
     *
     * @param int $connId 连接 ID
     */
    public function refreshConnection(int $connId): void
    {
        if (isset($this->connectionCache[$connId])) {
            $this->connectionCache[$connId]['expires'] = \time() + $this->connectionTtl;
        }
    }
    
    /**
     * 获取统计信息
     *
     * @return array 统计信息
     */
    public function getStats(): array
    {
        return [
            'sni_cache_size' => \count($this->sniCache),
            'ip_cache_size' => \count($this->ipCache),
            'connection_cache_size' => \count($this->connectionCache),
            'sni_hits' => $this->stats['sni_hits'],
            'sni_misses' => $this->stats['sni_misses'],
            'ip_hits' => $this->stats['ip_hits'],
            'ip_misses' => $this->stats['ip_misses'],
            'conn_hits' => $this->stats['conn_hits'],
            'conn_misses' => $this->stats['conn_misses'],
            'total_routes' => $this->stats['total_routes'],
            'sni_hit_rate' => $this->calculateHitRate($this->stats['sni_hits'], $this->stats['sni_misses']),
            'ip_hit_rate' => $this->calculateHitRate($this->stats['ip_hits'], $this->stats['ip_misses']),
        ];
    }
    
    /**
     * 计算命中率
     */
    private function calculateHitRate(int $hits, int $misses): float
    {
        $total = $hits + $misses;
        return $total > 0 ? \round($hits / $total * 100, 2) : 0.0;
    }

    /**
     * 本机开发场景下（127.0.0.1 / ::1）禁用 IP 粘连路由，
     * 避免所有流量长期固定到单个 Worker。
     */
    private function isLoopbackIp(string $ip): bool
    {
        $normalized = \strtolower(\trim($ip));
        return $normalized === '127.0.0.1'
            || $normalized === '::1'
            || \str_starts_with($normalized, '::ffff:127.');
    }
    
    /**
     * 清除所有缓存
     */
    public function purgeAll(): void
    {
        $this->sniCache = [];
        $this->ipCache = [];
        $this->connectionCache = [];
        $this->stats = [
            'sni_hits' => 0,
            'sni_misses' => 0,
            'ip_hits' => 0,
            'ip_misses' => 0,
            'conn_hits' => 0,
            'conn_misses' => 0,
            'total_routes' => 0,
        ];
    }
    
    /**
     * 定期清理过期条目
     */
    private function maybeCleanup(): void
    {
        $now = \time();
        if ($now - $this->lastCleanup < $this->cleanupInterval) {
            return;
        }
        $this->lastCleanup = $now;

        $this->removeExpiredEntries($this->sniCache, $now);
        $this->removeExpiredEntries($this->ipCache, $now);
        $this->removeExpiredEntries($this->connectionCache, $now);
    }
    
    /**
     * 驱逐 SNI 缓存（LRU-like，按过期时间排序）
     */
    private function evictSniCache(): void
    {
        /** @var array<string, int> $expiresAt */
        $expiresAt = [];
        foreach ($this->sniCache as $sni => $entry) {
            $expiresAt[$sni] = $entry['expires'];
        }

        $this->evictByPriority($this->sniCache, $expiresAt, \SORT_NUMERIC);
    }
    
    /**
     * 驱逐 IP 缓存（按过期时间 + 命中次数）
     */
    private function evictIpCache(): void
    {
        /** @var array<string, string> $priorities */
        $priorities = [];
        foreach ($this->ipCache as $ip => $entry) {
            $priorities[$ip] = \sprintf('%020d:%020d', $entry['hits'], $entry['expires']);
        }

        $this->evictByPriority($this->ipCache, $priorities, \SORT_STRING);
    }
    
    /**
     * 驱逐连接缓存（按过期时间排序）
     */
    private function evictConnectionCache(): void
    {
        /** @var array<int, int> $expiresAt */
        $expiresAt = [];
        foreach ($this->connectionCache as $connId => $entry) {
            $expiresAt[$connId] = $entry['expires'];
        }

        $this->evictByPriority($this->connectionCache, $expiresAt, \SORT_NUMERIC);
    }

    /**
     * @param array<array-key, array{expires: int}> $cache
     */
    private function removeExpiredEntries(array &$cache, int $now): void
    {
        $expiredKeys = [];
        foreach ($cache as $key => $entry) {
            if ($entry['expires'] <= $now) {
                $expiredKeys[] = $key;
            }
        }

        foreach ($expiredKeys as $key) {
            unset($cache[$key]);
        }
    }

    /**
     * @param array<array-key, mixed> $cache
     * @param array<array-key, int|string> $priorities
     */
    private function evictByPriority(array &$cache, array $priorities, int $sortFlag): void
    {
        if ($priorities === []) {
            return;
        }

        \asort($priorities, $sortFlag);

        $evictCount = \max(1, (int) (\count($cache) * 0.2));
        $evicted = 0;
        foreach ($priorities as $key => $_priority) {
            unset($cache[$key]);
            $evicted++;
            if ($evicted >= $evictCount) {
                break;
            }
        }
    }
    
    /**
     * 设置配置
     *
     * @param array $config 配置数组
     */
    public function configure(array $config): void
    {
        if (isset($config['default_ttl'])) {
            $this->defaultTtl = (int) $config['default_ttl'];
        }
        if (isset($config['connection_ttl'])) {
            $this->connectionTtl = (int) $config['connection_ttl'];
        }
        if (isset($config['max_sni_entries'])) {
            $this->maxSniEntries = (int) $config['max_sni_entries'];
        }
        if (isset($config['max_ip_entries'])) {
            $this->maxIpEntries = (int) $config['max_ip_entries'];
        }
        if (isset($config['max_connection_entries'])) {
            $this->maxConnectionEntries = (int) $config['max_connection_entries'];
        }
        if (isset($config['cleanup_interval'])) {
            $this->cleanupInterval = (int) $config['cleanup_interval'];
        }
    }
}
