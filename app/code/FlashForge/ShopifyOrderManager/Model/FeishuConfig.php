<?php

namespace FlashForge\ShopifyOrderManager\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 飞书通知配置模型
 */
class FeishuConfig extends Model
{
    public const table = 'shopify_feishu_config';
    public const primary_key = 'config_id';
    
    public const fields_ID = 'config_id';
    public const fields_WEBHOOK_URL = 'webhook_url';
    public const fields_SECRET = 'secret';
    public const fields_ENABLE_ERROR_NOTIFY = 'enable_error_notify';
    public const fields_ENABLE_OVERDUE_NOTIFY = 'enable_overdue_notify';
    public const fields_NOTIFY_KEYWORDS = 'notify_keywords';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    // 状态常量
    public const STATUS_ACTIVE = 1;
    public const STATUS_INACTIVE = 0;
    
    public array $_unit_primary_keys = ['config_id'];

    /**
     * 设置模型
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级模型
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑（如果需要）
    }

    /**
     * 安装数据表
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('飞书通知配置表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'primary key auto_increment',
                    '配置ID'
                )
                ->addColumn(
                    self::fields_WEBHOOK_URL,
                    TableInterface::column_type_TEXT,
                    0,
                    'not null',
                    'Webhook URL'
                )
                ->addColumn(
                    self::fields_SECRET,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'null',
                    '签名密钥'
                )
                ->addColumn(
                    self::fields_ENABLE_ERROR_NOTIFY,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    '启用错误通知'
                )
                ->addColumn(
                    self::fields_ENABLE_OVERDUE_NOTIFY,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    '启用超时通知'
                )
                ->addColumn(
                    self::fields_NOTIFY_KEYWORDS,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '通知关键词JSON'
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    '状态'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    'default current_timestamp',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    'default current_timestamp',
                    '更新时间'
                )
                ->create();
        }
    }

    /**
     * 获取活跃的飞书配置
     */
    public function getActiveConfig(): ?array
    {
        $config = $this->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->find()
            ->fetch();
            
        return $config->getId() ? $config->getData() : null;
    }

    /**
     * 保存或更新配置
     */
    public function saveConfig(array $configData): bool
    {
        // 先禁用所有配置
        $this->update([self::fields_STATUS => self::STATUS_INACTIVE]);
        
        // 创建新配置
        $this->setData($configData);
        $this->setData(self::fields_STATUS, self::STATUS_ACTIVE);
        
        return $this->save();
    }
}
