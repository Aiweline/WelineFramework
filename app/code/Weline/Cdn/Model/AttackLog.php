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
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

class AttackLog extends Model
{
    public const table = 'cdn_attack_log';
    
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
    public const fields_LOG_ID = 'log_id';
    public const fields_DOMAIN = 'domain';
    public const fields_ATTACK_TYPE = 'attack_type';
    public const fields_ATTACKER_IP = 'attacker_ip';
    public const fields_ATTACK_COUNT = 'attack_count';
    public const fields_REASON = 'reason';
    public const fields_ACTION = 'action';
    public const fields_CDN_RESPONSE = 'cdn_response';
    public const fields_STATUS = 'status';
    public const fields_STARTED_AT = 'started_at';
    public const fields_ENDED_AT = 'ended_at';
    public const fields_DURATION = 'duration';
    public const fields_CREATED_AT = 'created_at';
    
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
        return self::fields_LOG_ID;
    }

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }
        
        $setup->createTable('CDN攻击日志表')
            ->addColumn(
                self::fields_LOG_ID,
                TableInterface::column_type_INTEGER,
                null,
                'primary key auto_increment',
                '日志ID'
            )
            ->addColumn(
                self::fields_DOMAIN,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '被攻击域名'
            )
            ->addColumn(
                self::fields_ATTACK_TYPE,
                TableInterface::column_type_VARCHAR,
                50,
                'not null default \'unknown\'',
                '攻击类型'
            )
            ->addColumn(
                self::fields_ATTACKER_IP,
                TableInterface::column_type_VARCHAR,
                45,
                'null',
                '攻击者IP'
            )
            ->addColumn(
                self::fields_ATTACK_COUNT,
                TableInterface::column_type_INTEGER,
                null,
                'not null default 0',
                '攻击次数'
            )
            ->addColumn(
                self::fields_REASON,
                TableInterface::column_type_TEXT,
                null,
                'null',
                '攻击原因'
            )
            ->addColumn(
                self::fields_ACTION,
                TableInterface::column_type_VARCHAR,
                50,
                'not null default \'detected\'',
                '执行动作'
            )
            ->addColumn(
                self::fields_CDN_RESPONSE,
                TableInterface::column_type_TEXT,
                null,
                'null',
                'CDN响应'
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_VARCHAR,
                20,
                'not null default \'active\'',
                '状态'
            )
            ->addColumn(
                self::fields_STARTED_AT,
                TableInterface::column_type_TIMESTAMP,
                null,
                'null',
                '攻击开始时间'
            )
            ->addColumn(
                self::fields_ENDED_AT,
                TableInterface::column_type_TIMESTAMP,
                null,
                'null',
                '攻击结束时间'
            )
            ->addColumn(
                self::fields_DURATION,
                TableInterface::column_type_INTEGER,
                null,
                'null',
                '持续时间(秒)'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                TableInterface::column_type_TIMESTAMP,
                null,
                'not null default CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addIndex(TableInterface::index_type_KEY, 'idx_domain', self::fields_DOMAIN)
            ->addIndex(TableInterface::index_type_KEY, 'idx_attack_type', self::fields_ATTACK_TYPE)
            ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS)
            ->addIndex(TableInterface::index_type_KEY, 'idx_created_at', self::fields_CREATED_AT)
            ->create();
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
        $log->setData(self::fields_DOMAIN, $domain);
        $log->setData(self::fields_ATTACK_TYPE, $attackType);
        $log->setData(self::fields_ATTACKER_IP, $attackerIp);
        $log->setData(self::fields_ATTACK_COUNT, $attackCount);
        $log->setData(self::fields_REASON, $reason);
        $log->setData(self::fields_ACTION, $action);
        $log->setData(self::fields_CDN_RESPONSE, \json_encode($cdnResponse, JSON_UNESCAPED_UNICODE));
        $log->setData(self::fields_STATUS, self::STATUS_ACTIVE);
        $log->setData(self::fields_STARTED_AT, \date('Y-m-d H:i:s'));
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
            ->where(self::fields_DOMAIN, $domain)
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::fields_LOG_ID, 'DESC')
            ->find()
            ->fetch();
        
        if ($activeLog->getId()) {
            $startedAt = \strtotime($activeLog->getData(self::fields_STARTED_AT));
            $duration = \time() - $startedAt;
            
            $activeLog->setData(self::fields_STATUS, self::STATUS_RECOVERED);
            $activeLog->setData(self::fields_ACTION, self::ACTION_RECOVERED);
            $activeLog->setData(self::fields_ENDED_AT, $now);
            $activeLog->setData(self::fields_DURATION, $duration);
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
        
        $type = $this->getData(self::fields_ATTACK_TYPE);
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
        
        $status = $this->getData(self::fields_STATUS);
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
        
        $action = $this->getData(self::fields_ACTION);
        return $actions[$action] ?? $action;
    }
    
    /**
     * 获取持续时间格式化字符串
     */
    public function getDurationFormatted(): string
    {
        $duration = (int) $this->getData(self::fields_DURATION);
        
        if ($duration <= 0) {
            $startedAt = $this->getData(self::fields_STARTED_AT);
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
