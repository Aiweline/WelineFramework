<?php
declare(strict_types=1);
namespace Weline\Acl\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 安全日志模型
 */
#[Table(comment: '安全日志表')]
#[Index(name: 'idx_event_type', columns: ['event_type'], comment: '事件类型索引')]
#[Index(name: 'idx_user_id', columns: ['user_id'], comment: '用户ID索引')]
#[Index(name: 'idx_ip', columns: ['ip'], comment: 'IP地址索引')]
#[Index(name: 'idx_created_at', columns: ['created_at'], comment: '创建时间索引')]
class SecurityLog extends Model
{
#[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '日志ID')]
    public const schema_fields_ID = 'log_id';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '事件类型')]
    public const schema_fields_EVENT_TYPE = 'event_type';
    #[Col(type: 'int', nullable: true, comment: '用户ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col(type: 'varchar', length: 45, nullable: true, comment: 'IP地址')]
    public const schema_fields_IP = 'ip';
    #[Col(type: 'text', nullable: true, comment: 'User Agent')]
    public const schema_fields_USER_AGENT = 'user_agent';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '日志消息')]
    public const schema_fields_MESSAGE = 'message';
    #[Col(type: 'text', nullable: true, comment: '详细信息')]
    public const schema_fields_DETAILS = 'details';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    /**
     * 事件类型常量
     */
    public const EVENT_LOGIN_FAILED = 'login_failed';
    public const EVENT_LOGIN_SUCCESS = 'login_success';
    public const EVENT_PERMISSION_DENIED = 'permission_denied';
    public const EVENT_ACL_VIOLATION = 'acl_violation';
    public const EVENT_SUSPICIOUS_ACTIVITY = 'suspicious_activity';
    /**
     * 记录安全事件
     * 
     * @param string $eventType
     * @param string $message
     * @param array $details
     * @param int|null $userId
     * @return bool
     */
    public static function log(string $eventType, string $message, array $details = [], ?int $userId = null): bool
    {
        try {
            $log = w_obj(self::class);
            $log->setData(self::schema_fields_EVENT_TYPE, $eventType);
            $log->setData(self::schema_fields_MESSAGE, $message);
            $log->setData(self::schema_fields_DETAILS, json_encode($details, JSON_UNESCAPED_UNICODE));
            $log->setData(self::schema_fields_USER_ID, $userId);
            $log->setData(self::schema_fields_IP, (string)\w_env('server.remote_addr', ''));
            $log->setData(self::schema_fields_USER_AGENT, (string)\w_env('server.http_user_agent', ''));
            return $log->save();
        } catch (\Exception $e) {
            return false;
        }
    }
}
