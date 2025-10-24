<?php

namespace FlashForge\ShopifyOrderManager\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * Shopify店铺模型
 */
class Shop extends Model
{
    public const table = 'shopify_shops';
    public const primary_key = 'shop_id';
    
    public const fields_ID = 'shop_id';
    public const fields_NAME = 'shop_name';
    public const fields_SHOP_URL = 'shop_url';
    public const fields_API_KEY = 'api_key';
    public const fields_API_SECRET = 'api_secret';
    public const fields_ACCESS_TOKEN = 'access_token';
    public const fields_STATUS = 'status';
    public const fields_LAST_SYNC = 'last_sync_time';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    // 状态常量
    public const STATUS_ACTIVE = 1;
    public const STATUS_INACTIVE = 0;
    
    public array $_unit_primary_keys = ['shop_id'];
    public array $_index_sort_keys = ['shop_id', 'shop_name', 'status'];

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
            $setup->createTable('Shopify店铺配置表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'primary key auto_increment',
                    '店铺ID'
                )
                ->addColumn(
                    self::fields_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '店铺名称'
                )
                ->addColumn(
                    self::fields_SHOP_URL,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '店铺URL'
                )
                ->addColumn(
                    self::fields_API_KEY,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    'API Key'
                )
                ->addColumn(
                    self::fields_API_SECRET,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    'API Secret'
                )
                ->addColumn(
                    self::fields_ACCESS_TOKEN,
                    TableInterface::column_type_TEXT,
                    0,
                    'not null',
                    'Access Token'
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'default 1',
                    '状态：1启用，0禁用'
                )
                ->addColumn(
                    self::fields_LAST_SYNC,
                    TableInterface::column_type_DATETIME,
                    0,
                    'null',
                    '最后同步时间'
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
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'idx_shop_url',
                    self::fields_SHOP_URL,
                    '店铺URL唯一索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_status',
                    self::fields_STATUS,
                    '状态索引'
                )
                ->create();
        }
    }

    /**
     * 获取活跃店铺列表
     */
    public function getActiveShops(): array
    {
        return $this->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->select()
            ->fetchArray();
    }

    /**
     * 更新最后同步时间
     */
    public function updateLastSyncTime(int $shopId): bool
    {
        $result = $this->where(self::fields_ID, $shopId)
            ->update([
                self::fields_LAST_SYNC => date('Y-m-d H:i:s')
            ])->fetch();
        
        return $result !== false;
    }

    /**
     * 验证店铺配置
     */
    public function validateShopConfig(): bool
    {
        $requiredFields = [
            self::fields_NAME,
            self::fields_SHOP_URL,
            self::fields_API_KEY,
            self::fields_API_SECRET,
            self::fields_ACCESS_TOKEN
        ];

        foreach ($requiredFields as $field) {
            if (empty($this->getData($field))) {
                return false;
            }
        }

        return true;
    }
}
