<?php
declare(strict_types=1);

/**
 * Weline Server - 攻击信号服务
 *
 * 管理攻击信号的发送和接收：
 * 1. Dispatcher 检测到攻击后，在请求头添加信号
 * 2. 框架监听 App 初始事件，解析信号
 * 3. 通知 CDN 开启防护模式
 *
 * 信号头格式：
 * X-Weline-Attack-Signal: {"type":"rate_limit","domain":"example.com","timestamp":1234567890,"reason":"..."}
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Security;

class AttackSignalService
{
    /**
     * 攻击信号头名称
     */
    public const HEADER_NAME = 'X-Weline-Attack-Signal';
    
    /**
     * 攻击模式激活头（CDN 下发）
     */
    public const ATTACK_MODE_HEADER = 'X-Weline-Under-Attack';
    
    /**
     * 攻击类型常量
     */
    public const TYPE_RATE_LIMIT = 'rate_limit';
    public const TYPE_PATH_SCAN = 'path_scan';
    public const TYPE_MALICIOUS = 'malicious_pattern';
    public const TYPE_BAD_UA = 'bad_user_agent';
    public const TYPE_PROTECTED_PATH = 'protected_path';
    public const TYPE_BLOCKED = 'blocked';
    public const TYPE_DDOS = 'ddos';
    
    /**
     * 攻击模式状态
     */
    private static bool $underAttackMode = false;
    
    /**
     * 攻击模式激活时间
     */
    private static int $attackModeActivatedAt = 0;
    
    /**
     * 攻击模式持续时间（秒）
     */
    private static int $attackModeDuration = 300;
    
    /**
     * 最近的攻击信号
     * @var array<int, array>
     */
    private static array $recentSignals = [];
    
    /**
     * 信号阈值（多少信号触发 CDN 通知）
     */
    private static int $signalThreshold = 10;
    
    /**
     * 信号时间窗口（秒）
     */
    private static int $signalWindow = 60;
    
    /**
     * 上次 CDN 通知时间
     */
    private static int $lastCdnNotification = 0;
    
    /**
     * CDN 通知冷却时间（秒）
     */
    private static int $cdnNotificationCooldown = 60;
    
    /**
     * 解析攻击信号头
     *
     * @param string $headerValue 信号头值
     * @return array|null 解析后的信号数据
     */
    public static function parseSignal(string $headerValue): ?array
    {
        $data = @\json_decode($headerValue, true);
        
        if (!\is_array($data)) {
            return null;
        }
        
        // 验证必要字段
        if (!isset($data['type'], $data['domain'], $data['timestamp'])) {
            return null;
        }
        
        return [
            'type' => $data['type'],
            'domain' => $data['domain'],
            'timestamp' => (int) $data['timestamp'],
            'reason' => $data['reason'] ?? '',
            'ip' => $data['ip'] ?? '',
        ];
    }
    
    /**
     * 生成攻击信号头值
     *
     * @param string $type 攻击类型
     * @param string $domain 域名
     * @param string $reason 原因
     * @param string $ip 攻击者 IP
     * @return string 信号头值
     */
    public static function generateSignal(
        string $type,
        string $domain,
        string $reason = '',
        string $ip = ''
    ): string {
        return \json_encode([
            'type' => $type,
            'domain' => $domain,
            'timestamp' => \time(),
            'reason' => $reason,
            'ip' => $ip,
        ], JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * 记录攻击信号
     *
     * @param array $signal 信号数据
     */
    public static function recordSignal(array $signal): void
    {
        $signal['recorded_at'] = \time();
        self::$recentSignals[] = $signal;
        
        // 清理过期信号
        $cutoff = \time() - self::$signalWindow;
        self::$recentSignals = \array_filter(self::$recentSignals, function ($s) use ($cutoff) {
            return $s['recorded_at'] >= $cutoff;
        });
        
        // 重新索引
        self::$recentSignals = \array_values(self::$recentSignals);
    }
    
    /**
     * 检查是否应该通知 CDN
     *
     * @return bool 是否应该通知
     */
    public static function shouldNotifyCdn(): bool
    {
        // 检查冷却时间
        if (\time() - self::$lastCdnNotification < self::$cdnNotificationCooldown) {
            return false;
        }
        
        // 检查信号数量
        $recentCount = \count(self::$recentSignals);
        
        return $recentCount >= self::$signalThreshold;
    }
    
    /**
     * 标记已通知 CDN
     */
    public static function markCdnNotified(): void
    {
        self::$lastCdnNotification = \time();
    }
    
    /**
     * 获取用于 CDN 通知的攻击摘要
     *
     * @return array 攻击摘要
     */
    public static function getAttackSummary(): array
    {
        $domains = [];
        $types = [];
        $ips = [];
        
        foreach (self::$recentSignals as $signal) {
            $domain = $signal['domain'] ?? '';
            $type = $signal['type'] ?? '';
            $ip = $signal['ip'] ?? '';
            
            if ($domain) {
                $domains[$domain] = ($domains[$domain] ?? 0) + 1;
            }
            if ($type) {
                $types[$type] = ($types[$type] ?? 0) + 1;
            }
            if ($ip) {
                $ips[$ip] = ($ips[$ip] ?? 0) + 1;
            }
        }
        
        return [
            'signal_count' => \count(self::$recentSignals),
            'domains' => $domains,
            'attack_types' => $types,
            'attacker_ips' => $ips,
            'window_seconds' => self::$signalWindow,
            'timestamp' => \time(),
        ];
    }
    
    /**
     * 激活攻击模式
     *
     * @param int $duration 持续时间（秒）
     */
    public static function activateAttackMode(int $duration = 300): void
    {
        self::$underAttackMode = true;
        self::$attackModeActivatedAt = \time();
        self::$attackModeDuration = $duration;
    }
    
    /**
     * 关闭攻击模式
     */
    public static function deactivateAttackMode(): void
    {
        self::$underAttackMode = false;
        self::$attackModeActivatedAt = 0;
    }
    
    /**
     * 检查是否处于攻击模式
     *
     * @return bool 是否处于攻击模式
     */
    public static function isUnderAttackMode(): bool
    {
        if (!self::$underAttackMode) {
            return false;
        }
        
        // 检查是否过期
        if (\time() - self::$attackModeActivatedAt > self::$attackModeDuration) {
            self::$underAttackMode = false;
            return false;
        }
        
        return true;
    }
    
    /**
     * 获取攻击模式信息
     *
     * @return array 攻击模式信息
     */
    public static function getAttackModeInfo(): array
    {
        $isActive = self::isUnderAttackMode();
        
        return [
            'active' => $isActive,
            'activated_at' => self::$attackModeActivatedAt,
            'duration' => self::$attackModeDuration,
            'remaining_seconds' => $isActive 
                ? \max(0, self::$attackModeDuration - (\time() - self::$attackModeActivatedAt))
                : 0,
        ];
    }
    
    /**
     * 从请求中检查攻击模式头（CDN 下发）
     *
     * @param array $headers 请求头
     * @return bool 是否检测到攻击模式激活信号
     */
    public static function checkAttackModeHeader(array $headers): bool
    {
        foreach ($headers as $name => $value) {
            if (\strtolower($name) === \strtolower(self::ATTACK_MODE_HEADER)) {
                $val = \is_array($value) ? ($value[0] ?? '') : $value;
                if (\strtolower($val) === 'true' || $val === '1') {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * 配置信号阈值
     *
     * @param int $threshold 阈值
     * @param int $window 时间窗口
     */
    public static function configure(int $threshold = 10, int $window = 60): void
    {
        self::$signalThreshold = $threshold;
        self::$signalWindow = $window;
    }
    
    /**
     * 获取最近的信号
     *
     * @param int $limit 数量限制
     * @return array 信号列表
     */
    public static function getRecentSignals(int $limit = 100): array
    {
        return \array_slice(self::$recentSignals, -$limit);
    }
    
    /**
     * 清除所有信号
     */
    public static function clearSignals(): void
    {
        self::$recentSignals = [];
    }
}
