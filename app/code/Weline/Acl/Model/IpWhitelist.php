<?php
declare(strict_types=1);

namespace Weline\Acl\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;

/**
 * IP白名单模型
 */
class IpWhitelist extends Model
{
    public const fields_ID = 'id';
    public const fields_IP = 'ip';
    public const fields_DESCRIPTION = 'description';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

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
            $setup->createTable('IP白名单表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_IP, TableInterface::column_type_VARCHAR, 45, 'not null', 'IP地址')
                ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_VARCHAR, 255, 'null', '描述')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_INTEGER, 1, 'not null default 1', '是否启用')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, null, 'not null DEFAULT CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, null, 'not null DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', '更新时间')
                ->addIndex('KEY', 'idx_ip', self::fields_IP, 'IP地址索引')
                ->addIndex('KEY', 'idx_is_active', self::fields_IS_ACTIVE, '启用状态索引')
                ->create();
        }
    }

    /**
     * 检查IP是否在白名单中
     * 
     * @param string $ip
     * @return bool
     */
    public static function isWhitelisted(string $ip): bool
    {
        try {
            $count = w_obj(self::class)
                ->reset()
                ->where(self::fields_IP, $ip)
                ->where(self::fields_IS_ACTIVE, 1)
                ->count();
            return $count > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
}

