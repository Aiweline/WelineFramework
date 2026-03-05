<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫科技 编写，所有解释权归 weline 所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** 订单状态模型 - 管理订单状态定义和翻译 */
#[Table(comment: '订单状态表')]
#[Index(name: 'idx_code', columns: ['code'], type: 'UNIQUE')]
#[Index(name: 'idx_is_active', columns: ['is_active'])]
#[Index(name: 'idx_sort_order', columns: ['sort_order'])]
class OrderStatus extends AbstractModel
{

    public const schema_table = 'weline_order_status';
    public const schema_primary_key = 'status_id';
    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '状态ID')]
    public const schema_fields_ID = 'status_id';
    #[Col('varchar', 50, nullable: false, unique: true, comment: '状态代号')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 100, nullable: false, comment: '状态名称')]
    public const schema_fields_NAME = 'name';
    #[Col('text', comment: '状态描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('varchar', 20, nullable: false, default: 'secondary', comment: '状态颜色')]
    public const schema_fields_COLOR = 'color';
    #[Col('varchar', 50, comment: '状态图标')]
    public const schema_fields_ICON = 'icon';
    #[Col('int', 1, nullable: false, default: 0, comment: '是否系统状态')]
    public const schema_fields_IS_SYSTEM = 'is_system';
    #[Col('int', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('int', null, nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['status_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['status_id', 'code', 'is_active', 'sort_order'];

    /**
     * 是否系统状态
     *
     * @return bool
     */
    public function isSystem(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_SYSTEM);
    }

    /**
     * 是否启用
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_ACTIVE);
    }

    /**
     * 获取状态翻译名称
     *
     * 状态名称存储在 name 字段，通过翻译系统进行翻译
     * 翻译 key 格式：order_status_{code}
     *
     * @return string
     */
    public function getTranslatedName(): string
    {
        $code = $this->getData(self::schema_fields_CODE);
        $name = $this->getData(self::schema_fields_NAME);

        // 使用翻译系统，翻译 key 为 order_status_{code}
        $translationKey = 'order_status_' . $code;
        $translated = __($translationKey);

        // 如果翻译不存在，返回默认名称
        if ($translated === $translationKey) {
            return $name;
        }

        return $translated;
    }
}
