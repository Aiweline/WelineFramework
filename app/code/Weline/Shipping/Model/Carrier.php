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
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '快递公司表')]
#[Index(name: 'idx_carrier_code', columns: ['carrier_code'], type: 'UNIQUE')]
#[Index(name: 'idx_carrier_type', columns: ['carrier_type'])]
#[Index(name: 'idx_tracking_support_status', columns: ['tracking_support_status'])]
class Carrier extends AbstractModel
{
    public const schema_table = 'w_shipping_carriers';
    public const schema_primary_key = 'carrier_id';
    public const schema_primary_keys = ['carrier_id'];
    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '快递公司ID')]
    public const schema_fields_ID = 'carrier_id';
    #[Col('varchar', 50, nullable: false, unique: true, comment: '快递公司代码')]
    public const schema_fields_CARRIER_CODE = 'carrier_code';
    #[Col('varchar', 255, nullable: false, comment: '快递公司名称')]
    public const schema_fields_CARRIER_NAME = 'carrier_name';
    #[Col('varchar', 20, nullable: false, comment: '类型')]
    public const schema_fields_CARRIER_TYPE = 'carrier_type';
    #[Col('text', comment: 'API配置JSON')]
    public const schema_fields_API_CONFIG = 'api_config';
    #[Col('varchar', 500, nullable: false, comment: '物流跟踪URL模板')]
    public const schema_fields_TRACKING_URL_TEMPLATE = 'tracking_url_template';
    #[Col('varchar', 500, comment: '追踪API端点')]
    public const schema_fields_TRACKING_API_ENDPOINT = 'tracking_api_endpoint';
    #[Col('varchar', 10, nullable: false, default: 'GET', comment: 'API请求方法')]
    public const schema_fields_TRACKING_API_METHOD = 'tracking_api_method';
    #[Col('varchar', 20, nullable: false, default: 'supported', comment: '追踪支持状态')]
    public const schema_fields_TRACKING_SUPPORT_STATUS = 'tracking_support_status';
    #[Col('int', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('int', null, nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    // 快递公司类型常量
    public const TYPE_MANUAL = 'manual';
    public const TYPE_API = 'api';
    // 追踪支持状态常量
    public const TRACKING_SUPPORTED = 'supported';
    public const TRACKING_NOT_SUPPORTED = 'not_supported';
    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['carrier_id', 'carrier_code'];
    /**
     * 初始化模型
     */
    public function _init(): void
    {
    }

    /**
     * 保存前验证
     * 强制要求tracking_url_template必填
     */
    public function beforeSave(): void
    {
        $trackingUrlTemplate = $this->getData(self::schema_fields_TRACKING_URL_TEMPLATE);
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
        $template = $this->getData(self::schema_fields_TRACKING_URL_TEMPLATE);
        return str_replace('{tracking_number}', urlencode($trackingNumber), $template);
    }
    /**
     * 获取API配置
     * 
     * @return array
     */
    public function getApiConfig(): array
    {
        $config = $this->getData(self::schema_fields_API_CONFIG);
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
        $this->setData(self::schema_fields_API_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }
}
