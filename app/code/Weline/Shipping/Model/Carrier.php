<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Carrier extends AbstractModel
{
    public const table = 'w_shipping_carriers';
    
    public const fields_ID = 'carrier_id';
    public const fields_CARRIER_CODE = 'carrier_code';
    public const fields_CARRIER_NAME = 'carrier_name';
    public const fields_CARRIER_TYPE = 'carrier_type';
    public const fields_API_CONFIG = 'api_config';
    public const fields_TRACKING_URL_TEMPLATE = 'tracking_url_template';
    public const fields_TRACKING_API_ENDPOINT = 'tracking_api_endpoint';
    public const fields_TRACKING_API_METHOD = 'tracking_api_method';
    public const fields_TRACKING_SUPPORT_STATUS = 'tracking_support_status';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 快递公司类型常量
    public const TYPE_MANUAL = 'manual';
    public const TYPE_API = 'api';

    // 追踪支持状态常量
    public const TRACKING_SUPPORTED = 'supported';
    public const TRACKING_NOT_SUPPORTED = 'not_supported';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['carrier_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['carrier_id', 'carrier_code'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_table = self::table;
        $this->_primary_key = self::fields_ID;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('快递公司表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '快递公司ID'
                )
                ->addColumn(
                    self::fields_CARRIER_CODE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null unique',
                    '快递公司代码'
                )
                ->addColumn(
                    self::fields_CARRIER_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '快递公司名称'
                )
                ->addColumn(
                    self::fields_CARRIER_TYPE,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'not null',
                    '类型：manual/api'
                )
                ->addColumn(
                    self::fields_API_CONFIG,
                    TableInterface::column_type_TEXT,
                    null,
                    'default null',
                    'API配置（JSON格式，用于第三方API对接）'
                )
                ->addColumn(
                    self::fields_TRACKING_URL_TEMPLATE,
                    TableInterface::column_type_VARCHAR,
                    500,
                    'not null',
                    '物流跟踪URL模板（必填，强制要求支持追踪）'
                )
                ->addColumn(
                    self::fields_TRACKING_API_ENDPOINT,
                    TableInterface::column_type_VARCHAR,
                    500,
                    'default null',
                    '追踪API端点（用于API类型）'
                )
                ->addColumn(
                    self::fields_TRACKING_API_METHOD,
                    TableInterface::column_type_VARCHAR,
                    10,
                    'default \'GET\'',
                    'API请求方法：GET/POST'
                )
                ->addColumn(
                    self::fields_TRACKING_SUPPORT_STATUS,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'default \'supported\'',
                    '追踪支持状态：supported/not_supported'
                )
                ->addColumn(
                    self::fields_IS_ACTIVE,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 1',
                    '是否启用：1-是，0-否'
                )
                ->addColumn(
                    self::fields_SORT_ORDER,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default 0',
                    '排序'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                    '更新时间'
                )
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_carrier_code', self::fields_CARRIER_CODE, '快递公司代码唯一索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_carrier_type', self::fields_CARRIER_TYPE, '快递公司类型索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_tracking_support_status', self::fields_TRACKING_SUPPORT_STATUS, '追踪支持状态索引')
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void {}

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 保存前验证
     * 强制要求tracking_url_template必填
     */
    public function beforeSave(): void
    {
        $trackingUrlTemplate = $this->getData(self::fields_TRACKING_URL_TEMPLATE);
        if (empty($trackingUrlTemplate)) {
            throw new \RuntimeException(__('物流跟踪URL模板为必填项，所有快递公司必须支持追踪功能'));
        }
        parent::beforeSave();
    }

    /**
     * 生成追踪URL
     * 
     * @param string $trackingNumber 物流单号
     * @return string
     */
    public function generateTrackingUrl(string $trackingNumber): string
    {
        $template = $this->getData(self::fields_TRACKING_URL_TEMPLATE);
        return str_replace('{tracking_number}', urlencode($trackingNumber), $template);
    }

    /**
     * 获取API配置
     * 
     * @return array
     */
    public function getApiConfig(): array
    {
        $config = $this->getData(self::fields_API_CONFIG);
        if (empty($config)) {
            return [];
        }
        return json_decode($config, true) ?: [];
    }

    /**
     * 设置API配置
     * 
     * @param array $config
     * @return $this
     */
    public function setApiConfig(array $config): self
    {
        $this->setData(self::fields_API_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }
}

