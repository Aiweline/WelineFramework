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
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Service\AddressFormatter;
#[Table(comment: '发货地址表')]
#[Index(name: 'idx_is_default', columns: ['is_default'])]
#[Index(name: 'idx_is_enabled', columns: ['is_enabled'])]
class ShippingAddress extends AbstractModel
{
    public const schema_table = 'w_shipping_addresses';
    public const schema_primary_key = 'shipping_address_id';
    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '发货地址ID')]
    public const schema_fields_ID = 'shipping_address_id';
    #[Col('varchar', 100, nullable: false, comment: '地址名称')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 100, nullable: false, comment: '联系人姓名')]
    public const schema_fields_CONTACT_NAME = 'contact_name';
    #[Col('varchar', 20, nullable: false, comment: '联系电话')]
    public const schema_fields_CONTACT_PHONE = 'contact_phone';
    #[Col('varchar', 50, nullable: false, default: '中国', comment: '国家')]
    public const schema_fields_COUNTRY = 'country';
    #[Col('varchar', 2, nullable: true, comment: 'ISO country code')]
    public const schema_fields_COUNTRY_CODE = 'country_code';
    #[Col('varchar', 50, nullable: false, comment: '省份')]
    public const schema_fields_PROVINCE = 'province';
    #[Col('varchar', 32, nullable: true, comment: 'Province/state code')]
    public const schema_fields_PROVINCE_CODE = 'province_code';
    #[Col('int', null, nullable: true, comment: 'Province/state region id')]
    public const schema_fields_PROVINCE_REGION_ID = 'province_region_id';
    #[Col('varchar', 50, nullable: false, comment: '城市')]
    public const schema_fields_CITY = 'city';
    #[Col('varchar', 32, nullable: true, comment: 'City code')]
    public const schema_fields_CITY_CODE = 'city_code';
    #[Col('int', null, nullable: true, comment: 'City region id')]
    public const schema_fields_CITY_REGION_ID = 'city_region_id';
    #[Col('varchar', 50, comment: '区县')]
    public const schema_fields_DISTRICT = 'district';
    #[Col('varchar', 32, nullable: true, comment: 'District code')]
    public const schema_fields_DISTRICT_CODE = 'district_code';
    #[Col('int', null, nullable: true, comment: 'District region id')]
    public const schema_fields_DISTRICT_REGION_ID = 'district_region_id';
    #[Col('varchar', 200, nullable: false, comment: '街道地址')]
    public const schema_fields_STREET = 'street';
    #[Col('varchar', 20, comment: '邮政编码')]
    public const schema_fields_POSTAL_CODE = 'postal_code';
    #[Col('int', 1, nullable: false, default: 0, comment: '是否默认地址')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col('int', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ENABLED = 'is_enabled';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['shipping_address_id'];
    /**
     * 索引排序键（用于提升查询效率）
     */
    public array $_index_sort_keys = ['shipping_address_id', 'is_default', 'is_enabled'];
    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_primary_key = self::schema_fields_ID;
    }
/**
     * 获取完整地址
     */
    public function getFullAddress(): string
    {
        return ObjectManager::getInstance(AddressFormatter::class)->formatSingleLine($this->getData());
    }
    /**
     * 是否默认地址
     */
    public function isDefault(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_DEFAULT);
    }
    /**
     * 是否启用
     */
    public function isEnabled(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_ENABLED);
    }
}
