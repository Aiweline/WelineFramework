<?php
declare(strict_types=1);

/**
 * Weline CDN - 攻击日志模型
 *
 * 记录所有攻击检测和 CDN 防护操作日志
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Cdn\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: 'CDN攻击日志表')]
#[Index(name: 'idx_domain', columns: ['domain'])]
#[Index(name: 'idx_attack_type', columns: ['attack_type'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_created_at', columns: ['created_at'])]
class AttackLog extends Model
{
    public const schema_table = 'cdn_attack_log';
    public const schema_primary_key = 'log_id';

    /**
     * Primary key
     */
    public string $_primary_key = 'log_id';

    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['log_id'];

    /**
     * Field name constants
     */
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '日志ID')]
    public const schema_fields_LOG_ID = 'log_id';
    #[Col('varchar', 255, nullable: false, comment: '被攻击域名')]
    public const schema_fields_DOMAIN = 'domain';
    #[Col('varchar', 50, nullable: false, default: 'unknown', comment: '攻击类型')]
    public const schema_fields_ATTACK_TYPE = 'attack_type';
    #[Col('varchar', 45, comment: '攻击者IP')]
    public const schema_fields_ATTACKER_IP = 'attacker_ip';
    #[Col('int', nullable: false, default: 0, comment: '攻击次数')]
    public const schema_fields_ATTACK_COUNT = 'attack_count';
    #[Col('text', comment: '攻击原因')]
    public const schema_fields_REASON = 'reason';
    #[Col('varchar', 50, nullable: false, default: 'detected', comment: '执行动作')]
    public const schema_fields_ACTION = 'action';
    #[Col('text', comment: 'CDN响应')]
    public const schema_fields_CDN_RESPONSE = 'cdn_response';
    #[Col('varchar', 20, nullable: false, default: 'active', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', comment: '攻击开始时间')]
    public const schema_fields_STARTED_AT = 'started_at';
    #[Col('datetime', comment: '攻击结束时间')]
    public const schema_fields_ENDED_AT = 'ended_at';
    #[Col('int', comment: '持续时间(秒)')]
    public const schema_fields_DURATION = 'duration';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    
    /**
     * 攻击类型常量
     */
    public const TYPE_RATE_LIMIT = 'rate_limit';
    public const TYPE_PATH_SCAN = 'path_scan';
    public const TYPE_MALICIOUS_PATTERN = 'malicious_pattern';
    public const TYPE_BAD_USER_AGENT = 'bad_user_agent';
    public const TYPE_PROTECTED_PATH = 'protected_path';
    public const TYPE_SLOWLORIS = 'slowloris';
    public const TYPE_UNKNOWN = 'unknown';
    
    /**
     * 动作常量
     */
    public const ACTION_DETECTED = 'detected';
    public const ACTION_CDN_NOTIFIED = 'cdn_notified';
    public const ACTION_RECOVERED = 'recovered';
    
    /**
     * 状态常量
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RECOVERED = 'recovered';
    public const STATUS_FAILED = 'failed';

    /**
     * Initialize model
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    /**
     * 获取主键字段名
     */
    public function getIdFieldName(): string
    {
        return self::schema_fields_LOG_ID;
    }
/**
     * 记录攻击日志
     *
     * @param string $domain 被攻击域名
     * @param string $attackType 攻击类型
     * @param string $attackerIp 攻击者IP
     * @param int $attackCount 攻击次数
     * @param string $reason 原因
     * @param string $action 动作
     * @param array $cdnResponse CDN响应
     * @return static
     */
    public static function log(
        string $domain,
        string $attackType,
        string $attackerIp,
        int $attackCount,
        string $reason,
        string $action = self::ACTION_DETECTED,
        array $cdnResponse = []
    ): static {
        $log = new static();
        $log->setData(self::schema_fields_DOMAIN, $domain);
        $log->setData(self::schema_fields_ATTACK_TYPE, $attackType);
        $log->setData(self::schema_fields_ATTACKER_IP, $attackerIp);
        $log->setData(self::schema_fields_ATTACK_COUNT, $attackCount);
        $log->setData(self::schema_fields_REASON, $reason);
        $log->setData(self::schema_fields_ACTION, $action);
        $log->setData(self::schema_fields_CDN_RESPONSE, \json_encode($cdnResponse, JSON_UNESCAPED_UNICODE));
        $log->setData(self::schema_fields_STATUS, self::STATUS_ACTIVE);
        $log->setData(self::schema_fields_STARTED_AT, \date('Y-m-d H:i:s'));
        $log->save();
        
        return $log;
    }
    
    /**
     * 标记攻击已恢复
     *
     * @param string $domain 域名
     * @return int 更新的记录数
     */
    public static function markRecovered(string $domain): int
    {
        $log = new static();
        $now = \date('Y-m-d H:i:s');
        
        // 查找该域名最近的活跃攻击记录
        $activeLog = $log->reset()
            ->where(self::schema_fields_DOMAIN, $domain)
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::schema_fields_LOG_ID, 'DESC')
            ->find()
            ->fetch();
        
        if ($activeLog->getId()) {
            $startedAt = \strtotime($activeLog->getData(self::schema_fields_STARTED_AT));
            $duration = \time() - $startedAt;
            
            $activeLog->setData(self::schema_fields_STATUS, self::STATUS_RECOVERED);
            $activeLog->setData(self::schema_fields_ACTION, self::ACTION_RECOVERED);
            $activeLog->setData(self::schema_fields_ENDED_AT, $now);
            $activeLog->setData(self::schema_fields_DURATION, $duration);
            $activeLog->save();
            
            return 1;
        }
        
        return 0;
    }
    
    /**
     * 获取攻击类型标签
     */
    public function getAttackTypeLabel(): string
    {
        $types = [
            self::TYPE_RATE_LIMIT => __('频率限制'),
            self::TYPE_PATH_SCAN => __('路径扫描'),
            self::TYPE_MALICIOUS_PATTERN => __('恶意特征'),
            self::TYPE_BAD_USER_AGENT => __('恶意UA'),
            self::TYPE_PROTECTED_PATH => __('保护路径'),
            self::TYPE_SLOWLORIS => __('Slowloris'),
            self::TYPE_UNKNOWN => __('未知'),
        ];
        
        $type = $this->getData(self::schema_fields_ATTACK_TYPE);
        return $types[$type] ?? $type;
    }
    
    /**
     * 获取状态标签
     */
    public function getStatusLabel(): string
    {
        $statuses = [
            self::STATUS_ACTIVE => __('进行中'),
            self::STATUS_RECOVERED => __('已恢复'),
            self::STATUS_FAILED => __('处理失败'),
        ];
        
        $status = $this->getData(self::schema_fields_STATUS);
        return $statuses[$status] ?? $status;
    }
    
    /**
     * 获取动作标签
     */
    public function getActionLabel(): string
    {
        $actions = [
            self::ACTION_DETECTED => __('检测到攻击'),
            self::ACTION_CDN_NOTIFIED => __('已通知CDN'),
            self::ACTION_RECOVERED => __('已恢复'),
        ];
        
        $action = $this->getData(self::schema_fields_ACTION);
        return $actions[$action] ?? $action;
    }
    
    /**
     * 获取持续时间格式化字符串
     */
    public function getDurationFormatted(): string
    {
        $duration = (int) $this->getData(self::schema_fields_DURATION);
        
        if ($duration <= 0) {
            $startedAt = $this->getData(self::schema_fields_STARTED_AT);
            if ($startedAt) {
                $duration = \time() - \strtotime($startedAt);
            }
        }
        
        if ($duration < 60) {
            return $duration . __('秒');
        } elseif ($duration < 3600) {
            return \round($duration / 60, 1) . __('分钟');
        } else {
            return \round($duration / 3600, 1) . __('小时');
        }
    }
}
