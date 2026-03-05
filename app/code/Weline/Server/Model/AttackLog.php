<?php
declare(strict_types=1);

/**
 * Weline Server - 攻击日志模型
 * 
 * 记录检测到的攻击信号，持久化到数据库
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 攻击日志模型 - 存储 AttackDetector 检测到的攻击信息 */
#[Table(comment: '攻击日志表')]
#[Index(name: 'idx_instance', columns: ['instance'])]
#[Index(name: 'idx_attack_type', columns: ['attack_type'])]
#[Index(name: 'idx_ip', columns: ['ip'])]
#[Index(name: 'idx_domain', columns: ['domain'])]
#[Index(name: 'idx_severity', columns: ['severity'])]
#[Index(name: 'idx_blocked', columns: ['blocked'])]
#[Index(name: 'idx_created_at', columns: ['created_at'])]
#[Index(name: 'idx_ip_type', columns: ['ip', 'attack_type'])]
class AttackLog extends Model
{

    public const schema_table = 'weline_server_attack_log';
    public const schema_primary_key = 'attack_id';
    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: '攻击日志ID')]
    public const schema_fields_ID = 'attack_id';
    #[Col('varchar', 50, default: 'default', comment: '服务器实例')]
    public const schema_fields_INSTANCE = 'instance';
    #[Col('varchar', 50, nullable: false, comment: '攻击类型')]
    public const schema_fields_ATTACK_TYPE = 'attack_type';
    #[Col('varchar', 45, comment: '攻击者IP')]
    public const schema_fields_IP = 'ip';
    #[Col('varchar', 255, comment: '目标域名')]
    public const schema_fields_DOMAIN = 'domain';
    #[Col('varchar', 2000, comment: '请求URI')]
    public const schema_fields_URI = 'uri';
    #[Col('varchar', 10, default: 'GET', comment: '请求方法')]
    public const schema_fields_METHOD = 'method';
    #[Col('varchar', 500, comment: 'UserAgent')]
    public const schema_fields_USER_AGENT = 'user_agent';
    #[Col('varchar', 500, comment: '攻击原因')]
    public const schema_fields_REASON = 'reason';
    #[Col('int', 1, default: 1, comment: '是否拦截')]
    public const schema_fields_BLOCKED = 'blocked';
    #[Col('int', 11, default: 0, comment: '封禁时长秒')]
    public const schema_fields_BLOCK_DURATION = 'block_duration';
    #[Col('datetime', comment: '封禁过期时间')]
    public const schema_fields_BLOCK_EXPIRES_AT = 'block_expires_at';
    #[Col('int', 11, default: 1, comment: 'IP请求计数')]
    public const schema_fields_REQUEST_COUNT = 'request_count';
    #[Col('int', 11, default: 0, comment: '唯一路径数')]
    public const schema_fields_UNIQUE_PATHS = 'unique_paths';
    #[Col('varchar', 20, default: 'medium', comment: '严重程度')]
    public const schema_fields_SEVERITY = 'severity';
    #[Col('int', 1, default: 0, comment: '已通知CDN')]
    public const schema_fields_CDN_NOTIFIED = 'cdn_notified';
    #[Col('text', comment: '额外数据JSON')]
    public const schema_fields_EXTRA_DATA = 'extra_data';
    #[Col('datetime', comment: '记录时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    public const ATTACK_TYPE_RATE_LIMIT = 'rate_limit';
    public const ATTACK_TYPE_PATH_SCAN = 'path_scan';
    public const ATTACK_TYPE_MALICIOUS = 'malicious_pattern';
    public const ATTACK_TYPE_BAD_UA = 'bad_user_agent';
    public const ATTACK_TYPE_PROTECTED_PATH = 'protected_path';
    public const ATTACK_TYPE_BLOCKED = 'blocked';
    public const ATTACK_TYPE_DDOS = 'ddos';
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';
/**
     * 保存前自动设置时间戳
     */
    public function save_before(): void
    {
        parent::save_before();
        
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, \date('Y-m-d H:i:s'));
        }
    }
    
    // =============== Getter/Setter 方法 ===============
    
    public function getAttackId(): int
    {
        return (int) $this->getData(self::schema_fields_ID);
    }
    
    public function setInstance(string $instance): self
    {
        $this->setData(self::schema_fields_INSTANCE, $instance);
        return $this;
    }
    
    public function getInstance(): string
    {
        return (string) ($this->getData(self::schema_fields_INSTANCE) ?: 'default');
    }
    
    public function setAttackType(string $type): self
    {
        $this->setData(self::schema_fields_ATTACK_TYPE, $type);
        return $this;
    }
    
    public function getAttackType(): string
    {
        return (string) $this->getData(self::schema_fields_ATTACK_TYPE);
    }
    
    public function setIp(string $ip): self
    {
        $this->setData(self::schema_fields_IP, $ip);
        return $this;
    }
    
    public function getIp(): string
    {
        return (string) $this->getData(self::schema_fields_IP);
    }
    
    public function setDomain(string $domain): self
    {
        $this->setData(self::schema_fields_DOMAIN, $domain);
        return $this;
    }
    
    public function getDomain(): string
    {
        return (string) $this->getData(self::schema_fields_DOMAIN);
    }
    
    public function setUri(string $uri): self
    {
        // 限制 URI 长度
        $this->setData(self::schema_fields_URI, \substr($uri, 0, 2000));
        return $this;
    }
    
    public function getUri(): string
    {
        return (string) $this->getData(self::schema_fields_URI);
    }
    
    public function setMethod(string $method): self
    {
        $this->setData(self::schema_fields_METHOD, \strtoupper($method));
        return $this;
    }
    
    public function getMethod(): string
    {
        return (string) ($this->getData(self::schema_fields_METHOD) ?: 'GET');
    }
    
    public function setUserAgent(string $ua): self
    {
        $this->setData(self::schema_fields_USER_AGENT, \substr($ua, 0, 500));
        return $this;
    }
    
    public function getUserAgent(): string
    {
        return (string) $this->getData(self::schema_fields_USER_AGENT);
    }
    
    public function setReason(string $reason): self
    {
        $this->setData(self::schema_fields_REASON, \substr($reason, 0, 500));
        return $this;
    }
    
    public function getReason(): string
    {
        return (string) $this->getData(self::schema_fields_REASON);
    }
    
    public function setBlocked(bool $blocked): self
    {
        $this->setData(self::schema_fields_BLOCKED, $blocked ? 1 : 0);
        return $this;
    }
    
    public function isBlocked(): bool
    {
        return (bool) $this->getData(self::schema_fields_BLOCKED);
    }
    
    public function setBlockDuration(int $seconds): self
    {
        $this->setData(self::schema_fields_BLOCK_DURATION, $seconds);
        if ($seconds > 0) {
            $this->setData(self::schema_fields_BLOCK_EXPIRES_AT, \date('Y-m-d H:i:s', \time() + $seconds));
        }
        return $this;
    }
    
    public function getBlockDuration(): int
    {
        return (int) $this->getData(self::schema_fields_BLOCK_DURATION);
    }
    
    public function getBlockExpiresAt(): string
    {
        return (string) $this->getData(self::schema_fields_BLOCK_EXPIRES_AT);
    }
    
    public function setRequestCount(int $count): self
    {
        $this->setData(self::schema_fields_REQUEST_COUNT, $count);
        return $this;
    }
    
    public function getRequestCount(): int
    {
        return (int) ($this->getData(self::schema_fields_REQUEST_COUNT) ?: 1);
    }
    
    public function setUniquePaths(int $count): self
    {
        $this->setData(self::schema_fields_UNIQUE_PATHS, $count);
        return $this;
    }
    
    public function getUniquePaths(): int
    {
        return (int) $this->getData(self::schema_fields_UNIQUE_PATHS);
    }
    
    public function setSeverity(string $severity): self
    {
        $this->setData(self::schema_fields_SEVERITY, $severity);
        return $this;
    }
    
    public function getSeverity(): string
    {
        return (string) ($this->getData(self::schema_fields_SEVERITY) ?: self::SEVERITY_MEDIUM);
    }
    
    public function setCdnNotified(bool $notified): self
    {
        $this->setData(self::schema_fields_CDN_NOTIFIED, $notified ? 1 : 0);
        return $this;
    }
    
    public function isCdnNotified(): bool
    {
        return (bool) $this->getData(self::schema_fields_CDN_NOTIFIED);
    }
    
    public function setExtraData(array $data): self
    {
        $this->setData(self::schema_fields_EXTRA_DATA, \json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    
    public function getExtraData(): array
    {
        $json = $this->getData(self::schema_fields_EXTRA_DATA);
        if (empty($json)) {
            return [];
        }
        return \json_decode($json, true) ?: [];
    }
    
    // =============== 业务方法 ===============
    
    /**
     * 根据攻击类型获取严重程度
     */
    public static function getSeverityByType(string $type): string
    {
        return match ($type) {
            self::ATTACK_TYPE_MALICIOUS => self::SEVERITY_CRITICAL,
            self::ATTACK_TYPE_DDOS => self::SEVERITY_CRITICAL,
            self::ATTACK_TYPE_PROTECTED_PATH => self::SEVERITY_HIGH,
            self::ATTACK_TYPE_PATH_SCAN => self::SEVERITY_MEDIUM,
            self::ATTACK_TYPE_RATE_LIMIT => self::SEVERITY_MEDIUM,
            self::ATTACK_TYPE_BAD_UA => self::SEVERITY_LOW,
            default => self::SEVERITY_MEDIUM,
        };
    }
    
    /**
     * 记录攻击
     */
    public function logAttack(array $data): self
    {
        $type = $data['attack_type'] ?? self::ATTACK_TYPE_BLOCKED;
        
        $this->clearQuery()
            ->setInstance($data['instance'] ?? 'default')
            ->setAttackType($type)
            ->setIp($data['ip'] ?? '')
            ->setDomain($data['domain'] ?? '')
            ->setUri($data['uri'] ?? '')
            ->setMethod($data['method'] ?? 'GET')
            ->setUserAgent($data['user_agent'] ?? '')
            ->setReason($data['reason'] ?? '')
            ->setBlocked($data['blocked'] ?? true)
            ->setBlockDuration($data['block_duration'] ?? 0)
            ->setRequestCount($data['request_count'] ?? 1)
            ->setUniquePaths($data['unique_paths'] ?? 0)
            ->setSeverity($data['severity'] ?? self::getSeverityByType($type))
            ->setCdnNotified($data['cdn_notified'] ?? false)
            ->setExtraData($data['extra_data'] ?? [])
            ->save();
        
        return $this;
    }
    
    /**
     * 获取最近的攻击日志
     */
    public function getRecentAttacks(int $limit = 100, string $instance = ''): array
    {
        $query = $this->clearQuery();
        
        if ($instance) {
            $query->where(self::schema_fields_INSTANCE, $instance);
        }
        
        return $query
            ->order(self::schema_fields_CREATED_AT, 'DESC')
            ->pagination(1, $limit)
            ->select()
            ->fetchArray();
    }
    
    /**
     * 按 IP 获取攻击统计
     */
    public function getAttacksByIp(string $ip): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_IP, $ip)
            ->order(self::schema_fields_CREATED_AT, 'DESC')
            ->pagination(1, 100)
            ->select()
            ->fetchArray();
    }
    
    /**
     * 获取攻击统计
     */
    public function getStatistics(string $instance = '', int $days = 7): array
    {
        $cutoffDate = \date('Y-m-d H:i:s', \time() - ($days * 86400));
        
        $query = $this->clearQuery()
            ->where(self::schema_fields_CREATED_AT, $cutoffDate, '>=');
        
        if ($instance) {
            $query->where(self::schema_fields_INSTANCE, $instance);
        }
        
        $results = $query->select()->fetchArray();
        
        $stats = [
            'total_attacks' => \count($results),
            'blocked_attacks' => 0,
            'by_type' => [],
            'by_severity' => [
                self::SEVERITY_LOW => 0,
                self::SEVERITY_MEDIUM => 0,
                self::SEVERITY_HIGH => 0,
                self::SEVERITY_CRITICAL => 0,
            ],
            'top_ips' => [],
            'top_domains' => [],
            'cdn_notifications' => 0,
        ];
        
        $ipCounts = [];
        $domainCounts = [];
        
        foreach ($results as $row) {
            if ($row[self::schema_fields_BLOCKED]) {
                $stats['blocked_attacks']++;
            }
            
            $type = $row[self::schema_fields_ATTACK_TYPE];
            $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
            
            $severity = $row[self::schema_fields_SEVERITY];
            if (isset($stats['by_severity'][$severity])) {
                $stats['by_severity'][$severity]++;
            }
            
            $ip = $row[self::schema_fields_IP];
            if ($ip) {
                $ipCounts[$ip] = ($ipCounts[$ip] ?? 0) + 1;
            }
            
            $domain = $row[self::schema_fields_DOMAIN];
            if ($domain) {
                $domainCounts[$domain] = ($domainCounts[$domain] ?? 0) + 1;
            }
            
            if ($row[self::schema_fields_CDN_NOTIFIED]) {
                $stats['cdn_notifications']++;
            }
        }
        
        // 排序并取 Top 10
        \arsort($ipCounts);
        \arsort($domainCounts);
        
        $stats['top_ips'] = \array_slice($ipCounts, 0, 10, true);
        $stats['top_domains'] = \array_slice($domainCounts, 0, 10, true);
        
        return $stats;
    }
    
    /**
     * 清理过期日志
     */
    public function cleanupOldLogs(int $keepDays = 30): int
    {
        $cutoffDate = \date('Y-m-d H:i:s', \time() - ($keepDays * 86400));
        
        $count = $this->clearQuery()
            ->where(self::schema_fields_CREATED_AT, $cutoffDate, '<')
            ->count();
        
        if ($count > 0) {
            $this->clearQuery()
                ->where(self::schema_fields_CREATED_AT, $cutoffDate, '<')
                ->delete()
                ->fetch();
        }
        
        return $count;
    }
    
    /**
     * 获取攻击类型标签
     */
    public static function getTypeLabel(string $type): string
    {
        return match ($type) {
            self::ATTACK_TYPE_RATE_LIMIT => __('频率限制'),
            self::ATTACK_TYPE_PATH_SCAN => __('路径扫描'),
            self::ATTACK_TYPE_MALICIOUS => __('恶意特征'),
            self::ATTACK_TYPE_BAD_UA => __('恶意UA'),
            self::ATTACK_TYPE_PROTECTED_PATH => __('敏感路径'),
            self::ATTACK_TYPE_BLOCKED => __('已封禁'),
            self::ATTACK_TYPE_DDOS => __('DDoS攻击'),
            default => $type,
        };
    }
    
    /**
     * 获取严重程度标签
     */
    public static function getSeverityLabel(string $severity): string
    {
        return match ($severity) {
            self::SEVERITY_LOW => __('低'),
            self::SEVERITY_MEDIUM => __('中'),
            self::SEVERITY_HIGH => __('高'),
            self::SEVERITY_CRITICAL => __('严重'),
            default => $severity,
        };
    }
    
    /**
     * 获取严重程度颜色
     */
    public static function getSeverityColor(string $severity): string
    {
        return match ($severity) {
            self::SEVERITY_LOW => '#8b9a8b',     // 莫兰迪绿
            self::SEVERITY_MEDIUM => '#c4a35a', // 莫兰迪黄
            self::SEVERITY_HIGH => '#b87333',   // 莫兰迪橙
            self::SEVERITY_CRITICAL => '#a85a5a', // 莫兰迪红
            default => '#7d7870',
        };
    }
}

