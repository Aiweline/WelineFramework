<?php

declare(strict_types=1);

namespace Weline\Captcha\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 验证码结果模型
 */
class CaptchaResult extends \Weline\Framework\Database\Model
{
    public const table = 'weline_captcha_result';
    public const primary_key = 'id';
    
    public const fields_ID = 'id';
    public const fields_TOKEN = 'token';
    public const fields_CODE = 'code';
    public const fields_TYPE = 'type';
    public const fields_EXPIRES_AT = 'expires_at';
    public const fields_CREATED_AT = 'created_at';
    
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['token', 'expires_at'];
    
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
            $setup->createTable('验证码结果表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', 'ID')
                ->addColumn(self::fields_TOKEN, TableInterface::column_type_VARCHAR, 100, 'not null unique', '令牌')
                ->addColumn(self::fields_CODE, TableInterface::column_type_VARCHAR, 50, 'not null', '验证码')
                ->addColumn(self::fields_TYPE, TableInterface::column_type_VARCHAR, 50, 'not null', '类型')
                ->addColumn(self::fields_EXPIRES_AT, TableInterface::column_type_DATETIME, 0, 'not null', '过期时间')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_token', self::fields_TOKEN, '令牌唯一索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_expires_at', self::fields_EXPIRES_AT, '过期时间索引')
                ->create();
        }
    }
}
