<?php
declare(strict_types=1);

/**
 * Weline Server - 攻击信号文件服务
 *
 * 非阻塞方式管理攻击信号文件，用于 Dispatcher 和 Cron 之间的通信。
 * 
 * 工作流程：
 * 1. Dispatcher 检测到攻击 → recordAttack() 写入信号文件（非阻塞）
 * 2. Server Cron 定时任务 → checkAndBroadcast() 检测文件更新 → 广播 CDN 事件
 * 3. 攻击恢复 → 一段时间无新攻击 → checkRecovery() → 关闭防护模式
 *
 * 文件结构：
 * - var/server/attack-signals/active.json      当前活跃攻击信号
 * - var/server/attack-signals/history/         历史攻击记录
 * - var/server/attack-signals/cdn-notified.json CDN 已通知标记
 * - var/server/attack-signals/attack-mode.json  攻击模式状态
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Service;

class AttackSignalFileService
{
    /**
     * 信号文件目录
     */
    private const SIGNAL_DIR = 'var' . DS . 'server' . DS . 'attack-signals';
    
    /**
     * 活跃攻击信号文件
     */
    private const ACTIVE_FILE = 'active.json';
    
    /**
     * CDN 已通知标记文件
     */
    private const CDN_NOTIFIED_FILE = 'cdn-notified.json';
    
    /**
     * 攻击模式状态文件
     */
    private const ATTACK_MODE_FILE = 'attack-mode.json';
    
    /**
     * 攻击计数文件
     */
    private const ATTACK_COUNT_FILE = 'attack-count.json';
    
    /**
     * 触发 CDN 通知的攻击阈值
     */
    private const ATTACK_THRESHOLD = 5;
    
    /**
     * 攻击恢复超时时间（秒）- 无新攻击后多久关闭防护
     */
    private const RECOVERY_TIMEOUT = 300; // 5分钟
    
    /**
     * 攻击计数窗口（秒）
     */
    private const ATTACK_WINDOW = 60;
    
    /**
     * 获取信号目录路径
     */
    private static function getSignalDir(): string
    {
        return BP . self::SIGNAL_DIR;
    }
    
    /**
     * 确保目录存在
     */
    private static function ensureDir(): void
    {
        $dir = self::getSignalDir();
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        
        $historyDir = $dir . DS . 'history';
        if (!\is_dir($historyDir)) {
            @\mkdir($historyDir, 0755, true);
        }
    }
    
    /**
     * 记录攻击信号（非阻塞）
     * 
     * 由 Dispatcher 调用，快速写入文件后立即返回
     *
     * @param string $ip 攻击者 IP
     * @param string $domain 被攻击域名
     * @param string $type 攻击类型
     * @param string $reason 攻击原因
     * @param array $extra 额外信息
     */
    public static function recordAttack(
        string $ip,
        string $domain,
        string $type,
        string $reason,
        array $extra = []
    ): void {
        self::ensureDir();
        
        $signal = [
            'ip' => $ip,
            'domain' => $domain,
            'type' => $type,
            'reason' => $reason,
            'timestamp' => \time(),
            'extra' => $extra,
        ];
        
        // 1. 追加到活跃攻击列表
        $activeFile = self::getSignalDir() . DS . self::ACTIVE_FILE;
        $active = self::readJsonFile($activeFile);
        
        if (!isset($active['signals'])) {
            $active['signals'] = [];
        }
        
        // 保留最近 100 条信号
        $active['signals'][] = $signal;
        if (\count($active['signals']) > 100) {
            $active['signals'] = \array_slice($active['signals'], -100);
        }
        
        $active['last_attack'] = \time();
        $active['updated_at'] = \time();
        
        self::writeJsonFile($activeFile, $active);
        
        // 2. 更新攻击计数
        self::incrementAttackCount($ip, $domain, $type);
    }
    
    /**
     * 增加攻击计数
     */
    private static function incrementAttackCount(string $ip, string $domain, string $type): void
    {
        $countFile = self::getSignalDir() . DS . self::ATTACK_COUNT_FILE;
        $data = self::readJsonFile($countFile);
        
        $now = \time();
        $windowStart = $now - self::ATTACK_WINDOW;
        
        // 清理过期的计数
        if (!isset($data['counts'])) {
            $data['counts'] = [];
        }
        
        $data['counts'] = \array_filter($data['counts'], function ($item) use ($windowStart) {
            return ($item['timestamp'] ?? 0) >= $windowStart;
        });
        
        // 添加新计数
        $data['counts'][] = [
            'ip' => $ip,
            'domain' => $domain,
            'type' => $type,
            'timestamp' => $now,
        ];
        
        // 统计
        $data['total_in_window'] = \count($data['counts']);
        $data['by_type'] = [];
        $data['by_ip'] = [];
        $data['by_domain'] = [];
        
        foreach ($data['counts'] as $item) {
            $t = $item['type'] ?? 'unknown';
            $i = $item['ip'] ?? 'unknown';
            $d = $item['domain'] ?? 'unknown';
            
            $data['by_type'][$t] = ($data['by_type'][$t] ?? 0) + 1;
            $data['by_ip'][$i] = ($data['by_ip'][$i] ?? 0) + 1;
            $data['by_domain'][$d] = ($data['by_domain'][$d] ?? 0) + 1;
        }
        
        $data['updated_at'] = $now;
        
        self::writeJsonFile($countFile, $data);
    }
    
    /**
     * 检查是否应该通知 CDN
     * 
     * @return bool
     */
    public static function shouldNotifyCdn(): bool
    {
        $countFile = self::getSignalDir() . DS . self::ATTACK_COUNT_FILE;
        $data = self::readJsonFile($countFile);
        
        $total = $data['total_in_window'] ?? 0;
        
        if ($total < self::ATTACK_THRESHOLD) {
            return false;
        }
        
        // 检查是否已经通知过
        $notifiedFile = self::getSignalDir() . DS . self::CDN_NOTIFIED_FILE;
        $notified = self::readJsonFile($notifiedFile);
        
        $lastNotified = $notified['last_notified'] ?? 0;
        $now = \time();
        
        // 如果已经在攻击模式中，不重复通知
        if (self::isInAttackMode()) {
            return false;
        }
        
        // 如果最近通知过（5分钟内），不重复通知
        if ($now - $lastNotified < 300) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 标记已通知 CDN
     */
    public static function markCdnNotified(): void
    {
        self::ensureDir();
        
        $notifiedFile = self::getSignalDir() . DS . self::CDN_NOTIFIED_FILE;
        $data = [
            'last_notified' => \time(),
            'notified_at' => \date('Y-m-d H:i:s'),
        ];
        
        self::writeJsonFile($notifiedFile, $data);
    }
    
    /**
     * 设置攻击模式
     * 
     * @param bool $enabled 是否启用
     * @param array $domains 受影响的域名列表
     */
    public static function setAttackMode(bool $enabled, array $domains = []): void
    {
        self::ensureDir();
        
        $modeFile = self::getSignalDir() . DS . self::ATTACK_MODE_FILE;
        
        if ($enabled) {
            $data = [
                'enabled' => true,
                'started_at' => \time(),
                'domains' => $domains,
                'updated_at' => \time(),
            ];
        } else {
            $data = [
                'enabled' => false,
                'ended_at' => \time(),
                'updated_at' => \time(),
            ];
        }
        
        self::writeJsonFile($modeFile, $data);
    }
    
    /**
     * 检查是否处于攻击模式
     * 
     * @return bool
     */
    public static function isInAttackMode(): bool
    {
        $modeFile = self::getSignalDir() . DS . self::ATTACK_MODE_FILE;
        $data = self::readJsonFile($modeFile);
        
        return (bool) ($data['enabled'] ?? false);
    }
    
    /**
     * 获取攻击模式信息
     * 
     * @return array
     */
    public static function getAttackModeInfo(): array
    {
        $modeFile = self::getSignalDir() . DS . self::ATTACK_MODE_FILE;
        return self::readJsonFile($modeFile);
    }
    
    /**
     * 检查是否应该恢复（关闭攻击模式）
     * 
     * @return bool
     */
    public static function shouldRecover(): bool
    {
        if (!self::isInAttackMode()) {
            return false;
        }
        
        $activeFile = self::getSignalDir() . DS . self::ACTIVE_FILE;
        $active = self::readJsonFile($activeFile);
        
        $lastAttack = $active['last_attack'] ?? 0;
        $now = \time();
        
        // 如果超过恢复超时时间没有新攻击，则应该恢复
        return ($now - $lastAttack) >= self::RECOVERY_TIMEOUT;
    }
    
    /**
     * 获取攻击摘要（用于 CDN 通知）
     * 
     * @return array
     */
    public static function getAttackSummary(): array
    {
        $countFile = self::getSignalDir() . DS . self::ATTACK_COUNT_FILE;
        $data = self::readJsonFile($countFile);
        
        $activeFile = self::getSignalDir() . DS . self::ACTIVE_FILE;
        $active = self::readJsonFile($activeFile);
        
        // 获取最近的攻击 IP
        $recentIps = [];
        $signals = $active['signals'] ?? [];
        foreach (\array_slice($signals, -20) as $signal) {
            $ip = $signal['ip'] ?? '';
            if ($ip && !\in_array($ip, $recentIps)) {
                $recentIps[] = $ip;
            }
        }
        
        // 获取最近的受攻击域名
        $domains = [];
        foreach ($signals as $signal) {
            $domain = $signal['domain'] ?? '';
            if ($domain && !\in_array($domain, $domains)) {
                $domains[] = $domain;
            }
        }
        
        return [
            'total' => $data['total_in_window'] ?? 0,
            'by_type' => $data['by_type'] ?? [],
            'by_ip' => $data['by_ip'] ?? [],
            'by_domain' => $data['by_domain'] ?? [],
            'recent_ips' => \array_slice($recentIps, 0, 10),
            'domains' => $domains,
            'last_attack' => $active['last_attack'] ?? 0,
            'window_seconds' => self::ATTACK_WINDOW,
        ];
    }
    
    /**
     * 获取活跃攻击信号
     * 
     * @param int $limit 限制数量
     * @return array
     */
    public static function getActiveSignals(int $limit = 50): array
    {
        $activeFile = self::getSignalDir() . DS . self::ACTIVE_FILE;
        $active = self::readJsonFile($activeFile);
        
        $signals = $active['signals'] ?? [];
        
        return \array_slice($signals, -$limit);
    }
    
    /**
     * 清理过期数据
     */
    public static function cleanup(): void
    {
        self::ensureDir();
        
        $now = \time();
        
        // 清理活跃信号（保留最近 1 小时的）
        $activeFile = self::getSignalDir() . DS . self::ACTIVE_FILE;
        $active = self::readJsonFile($activeFile);
        
        if (isset($active['signals'])) {
            $hourAgo = $now - 3600;
            $active['signals'] = \array_filter($active['signals'], function ($signal) use ($hourAgo) {
                return ($signal['timestamp'] ?? 0) >= $hourAgo;
            });
            $active['signals'] = \array_values($active['signals']);
            self::writeJsonFile($activeFile, $active);
        }
        
        // 清理攻击计数
        $countFile = self::getSignalDir() . DS . self::ATTACK_COUNT_FILE;
        $data = self::readJsonFile($countFile);
        
        if (isset($data['counts'])) {
            $windowStart = $now - self::ATTACK_WINDOW;
            $data['counts'] = \array_filter($data['counts'], function ($item) use ($windowStart) {
                return ($item['timestamp'] ?? 0) >= $windowStart;
            });
            $data['counts'] = \array_values($data['counts']);
            $data['total_in_window'] = \count($data['counts']);
            self::writeJsonFile($countFile, $data);
        }
    }
    
    /**
     * 归档当前攻击记录到历史
     */
    public static function archiveToHistory(): void
    {
        self::ensureDir();
        
        $activeFile = self::getSignalDir() . DS . self::ACTIVE_FILE;
        $active = self::readJsonFile($activeFile);
        
        if (empty($active['signals'])) {
            return;
        }
        
        // 写入历史文件
        $historyFile = self::getSignalDir() . DS . 'history' . DS . \date('Y-m-d_H-i-s') . '.json';
        self::writeJsonFile($historyFile, $active);
        
        // 清空活跃信号
        self::writeJsonFile($activeFile, ['signals' => [], 'updated_at' => \time()]);
    }
    
    /**
     * 读取 JSON 文件
     */
    private static function readJsonFile(string $file): array
    {
        if (!\is_file($file)) {
            return [];
        }
        
        $content = @\file_get_contents($file);
        if ($content === false) {
            return [];
        }
        
        $data = \json_decode($content, true);
        return \is_array($data) ? $data : [];
    }
    
    /**
     * 写入 JSON 文件（原子写入）
     */
    private static function writeJsonFile(string $file, array $data): bool
    {
        $content = \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        // 使用临时文件 + 重命名实现原子写入
        $tmpFile = $file . '.tmp.' . \getmypid();
        
        if (@\file_put_contents($tmpFile, $content, LOCK_EX) === false) {
            return false;
        }
        
        if (@\rename($tmpFile, $file) === false) {
            @\unlink($tmpFile);
            return false;
        }
        
        return true;
    }
    
    /**
     * 获取恢复超时时间
     */
    public static function getRecoveryTimeout(): int
    {
        return self::RECOVERY_TIMEOUT;
    }
    
    /**
     * 获取攻击阈值
     */
    public static function getAttackThreshold(): int
    {
        return self::ATTACK_THRESHOLD;
    }
}
