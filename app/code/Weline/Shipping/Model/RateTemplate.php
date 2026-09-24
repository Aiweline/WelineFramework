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
#[Table(comment: '配送费用模板表')]
#[Index(name: 'uk_template_scope_code', columns: ['scope_type', 'scope_id', 'template_code'], type: 'UNIQUE')]
#[Index(name: 'idx_rate_template_scope', columns: ['scope_type', 'scope_id', 'is_active'])]
#[Index(name: 'idx_calculation_type', columns: ['calculation_type'])]
class RateTemplate extends AbstractModel
{
    public const schema_table = 'w_shipping_rate_templates';
    public const schema_primary_key = 'template_id';

    public const SCOPE_WEBSITE = 'website';
    public const SCOPE_STORE = 'store';
    public const SCOPE_CHANNEL = 'channel';

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '模板ID')]
    public const schema_fields_ID = 'template_id';
    #[Col('varchar', 16, nullable: false, default: 'website', comment: '作用范围类型 website|store|channel')]
    public const schema_fields_SCOPE_TYPE = 'scope_type';
    #[Col('int', null, nullable: false, default: 0, comment: '作用范围ID')]
    public const schema_fields_SCOPE_ID = 'scope_id';
    #[Col('varchar', 255, nullable: false, comment: '模板名称')]
    public const schema_fields_TEMPLATE_NAME = 'template_name';
    #[Col('varchar', 50, nullable: false, comment: '模板代码（同范围内唯一）')]
    public const schema_fields_TEMPLATE_CODE = 'template_code';
    #[Col('varchar', 20, nullable: false, comment: '计算类型')]
    public const schema_fields_CALCULATION_TYPE = 'calculation_type';
    #[Col('decimal', '10,2', nullable: false, default: 0.00, comment: '基础费用')]
    public const schema_fields_BASE_FEE = 'base_fee';
    #[Col('varchar', 10, nullable: false, default: 'kg', comment: '重量单位')]
    public const schema_fields_WEIGHT_UNIT = 'weight_unit';
    #[Col('decimal', '10,4', comment: '每单位重量费用')]
    public const schema_fields_WEIGHT_RATE = 'weight_rate';
    #[Col('varchar', 10, nullable: false, default: 'm3', comment: '体积单位')]
    public const schema_fields_VOLUME_UNIT = 'volume_unit';
    #[Col('decimal', '10,4', comment: '每单位体积费用')]
    public const schema_fields_VOLUME_RATE = 'volume_rate';
    #[Col('decimal', '10,2', comment: '每件费用')]
    public const schema_fields_QUANTITY_RATE = 'quantity_rate';
    #[Col('longtext', comment: '混合模式配置JSON')]
    public const schema_fields_MIXED_CONFIG = 'mixed_config';
    #[Col('text', comment: 'Shopify式阶梯 JSON [{min,max,price}]')]
    public const schema_fields_RATE_BRACKETS = 'rate_brackets';
    #[Col('decimal', '10,3', comment: '最大可寄计费重(kg)，空=不限')]
    public const schema_fields_MAX_WEIGHT_KG = 'max_weight_kg';
    #[Col('varchar', 3, nullable: false, default: 'USD', comment: '货币代码')]
    public const schema_fields_CURRENCY_CODE = 'currency_code';
    #[Col('int', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    // 计算类型常量
    public const CALC_TYPE_WEIGHT = 'weight';
    public const CALC_TYPE_VOLUME = 'volume';
    public const CALC_TYPE_QUANTITY = 'quantity';
    public const CALC_TYPE_FIXED = 'fixed';
    public const CALC_TYPE_MIXED = 'mixed';
    public const CALC_TYPE_WEIGHT_TABLE = 'weight_table';
    public const CALC_TYPE_PRICE_TABLE = 'price_table';

    /** 系统航线种子模板代码前缀（不可物理删除）。 */
    public const SEED_CODE_PREFIX = 'SEED_TPL_';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['template_id'];

    public function isSeed(): bool
    {
        return self::isSeedTemplateCode((string)$this->getData(self::schema_fields_TEMPLATE_CODE));
    }

    public static function isSeedTemplateCode(string $code): bool
    {
        return str_starts_with(strtoupper(trim($code)), self::SEED_CODE_PREFIX);
    }

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['template_id', 'scope_type', 'scope_id', 'template_code'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_table = self::schema_table;
        $this->_primary_key = self::schema_fields_ID;
    }
/**
     * 获取混合模式配置
     * 
     * @return array
     */
    public function getMixedConfig(): array
    {
        $config = $this->getData(self::schema_fields_MIXED_CONFIG);
        if (empty($config)) {
            return [];
        }
        return json_decode($config, true) ?: [];
    }

    /**
     * 设置混合模式配置
     * 
     * @param array $config
     * @return $this
     */
    public function setMixedConfig(array $config): self
    {
        $this->setData(self::schema_fields_MIXED_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * @return list<array{min:float|int|string|null,max:float|int|string|null,price:string|float|int}>
     */
    public function getRateBrackets(): array
    {
        $raw = $this->getData(self::schema_fields_RATE_BRACKETS);
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            return array_values($raw);
        }
        $decoded = json_decode((string)$raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param list<array<string,mixed>> $brackets
     */
    public function setRateBrackets(array $brackets): self
    {
        $this->setData(
            self::schema_fields_RATE_BRACKETS,
            json_encode(array_values($brackets), JSON_UNESCAPED_UNICODE),
        );

        return $this;
    }
}

