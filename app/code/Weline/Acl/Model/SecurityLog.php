<?php
declare(strict_types=1);

namespace Weline\Acl\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;

/**
 * 安全日志模型
 */
class SecurityLog extends Model
{
    public const fields_ID = 'log_id';
    public const fields_EVENT_TYPE = 'event_type';
    public const fields_USER_ID = 'user_id';
    public const fields_IP = 'ip';
    public const fields_USER_AGENT = 'user_agent';
    public const fields_MESSAGE = 'message';
    public const fields_DETAILS = 'details';
    public const fields_CREATED_AT = 'created_at';

    /**
     * 事件类型常量
     */
    public const EVENT_LOGIN_FAILED = 'login_failed';
    public const EVENT_LOGIN_SUCCESS = 'login_success';
    public const EVENT_PERMISSION_DENIED = 'permission_denied';
    public const EVENT_ACL_VIOLATION = 'acl_violation';
    public const EVENT_SUSPICIOUS_ACTIVITY = 'suspicious_activity';

    /**
     * 开发模式下的设置（每次 module:upgrade 都会触发）
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 模块更新时执行
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑：可以在这里添加新字段、修改字段等
    }

    /**
     * 模块安装时执行
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('安全日志表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '日志ID')
                ->addColumn(self::fields_EVENT_TYPE, TableInterface::column_type_VARCHAR, 50, 'not null', '事件类型')
                ->addColumn(self::fields_USER_ID, TableInterface::column_type_INTEGER, null, 'unsigned', '用户ID')
                ->addColumn(self::fields_IP, TableInterface::column_type_VARCHAR, 45, 'null', 'IP地址')
                ->addColumn(self::fields_USER_AGENT, TableInterface::column_type_TEXT, null, 'null', 'User Agent')
                ->addColumn(self::fields_MESSAGE, TableInterface::column_type_VARCHAR, 255, 'not null', '日志消息')
                ->addColumn(self::fields_DETAILS, TableInterface::column_type_TEXT, null, 'null', '详细信息')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, null, 'not null DEFAULT CURRENT_TIMESTAMP', '创建时间')
                ->addIndex('KEY', 'idx_event_type', self::fields_EVENT_TYPE, '事件类型索引')
                ->addIndex('KEY', 'idx_user_id', self::fields_USER_ID, '用户ID索引')
                ->addIndex('KEY', 'idx_ip', self::fields_IP, 'IP地址索引')
                ->addIndex('KEY', 'idx_created_at', self::fields_CREATED_AT, '创建时间索引')
                ->create();
        }
    }

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
            $log->setData(self::fields_EVENT_TYPE, $eventType);
            $log->setData(self::fields_MESSAGE, $message);
            $log->setData(self::fields_DETAILS, json_encode($details, JSON_UNESCAPED_UNICODE));
            $log->setData(self::fields_USER_ID, $userId);
            $log->setData(self::fields_IP, $_SERVER['REMOTE_ADDR'] ?? '');
            $log->setData(self::fields_USER_AGENT, $_SERVER['HTTP_USER_AGENT'] ?? '');
            return $log->save();
        } catch (\Exception $e) {
            return false;
        }
    }
}

