<?php

declare(strict_types=1);

namespace Weline\Captcha\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 失败尝试模型
 */
class FailedAttempt extends \Weline\Framework\Database\Model
{
    public const table = 'weline_captcha_failed_attempt';
    public const primary_key = 'id';
    
    public const fields_ID = 'id';
    public const fields_IP = 'ip';
    public const fields_ATTEMPTED_AT = 'attempted_at';
    
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['ip', 'attempted_at'];
    
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
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('验证码失败尝试表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', 'ID')
                ->addColumn(self::fields_IP, TableInterface::column_type_VARCHAR, 45, 'not null', 'IP地址')
                ->addColumn(self::fields_ATTEMPTED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '尝试时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_ip', self::fields_IP, 'IP地址索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_attempted_at', self::fields_ATTEMPTED_AT, '尝试时间索引')
                ->create();
        }
    }
}
