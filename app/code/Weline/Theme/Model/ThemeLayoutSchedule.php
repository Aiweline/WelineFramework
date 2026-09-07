<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Timed layout_option activation for product / category_product_default targets.
 * Schedules never rewrite permanent layout selections — resolve-time only.
 */
#[Table(comment: 'Theme 产品布局定时计划')]
#[Index(name: 'idx_theme_layout_schedule_target', columns: ['target_type', 'target_id', 'layout_type', 'status'], type: 'KEY')]
#[Index(name: 'idx_theme_layout_schedule_window', columns: ['starts_at', 'ends_at', 'priority'], type: 'KEY')]
class ThemeLayoutSchedule extends Model
{
    public const schema_table = 'theme_layout_schedule';
    public const schema_primary_key = 'schedule_id';

    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    public array $_unit_primary_keys = [self::schema_fields_ID];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '计划ID')]
    public const schema_fields_ID = 'schedule_id';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '计划名称')]
    public const schema_fields_NAME = 'name';

    #[Col(type: 'varchar', length: 64, nullable: false, default: 'product', comment: '布局类型')]
    public const schema_fields_LAYOUT_TYPE = 'layout_type';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: '生效 layout_option')]
    public const schema_fields_LAYOUT_OPTION = 'layout_option';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: '目标类型')]
    public const schema_fields_TARGET_TYPE = 'target_type';

    #[Col(type: 'int', nullable: false, default: 0, comment: '目标ID')]
    public const schema_fields_TARGET_ID = 'target_id';

    #[Col(type: 'varchar', length: 400, nullable: false, default: 'default.default.default', comment: 'scope')]
    public const schema_fields_SCOPE = 'scope';

    #[Col(type: 'varchar', length: 64, nullable: false, default: 'UTC', comment: '时区')]
    public const schema_fields_TIMEZONE = 'timezone';

    #[Col(type: 'datetime', nullable: false, comment: '开始时间')]
    public const schema_fields_STARTS_AT = 'starts_at';

    #[Col(type: 'datetime', nullable: false, comment: '结束时间')]
    public const schema_fields_ENDS_AT = 'ends_at';

    #[Col(type: 'int', nullable: false, default: 0, comment: '优先级，越大越优先')]
    public const schema_fields_PRIORITY = 'priority';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::STATUS_ENABLED, comment: '状态')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col(type: 'int', nullable: true, comment: '创建人')]
    public const schema_fields_CREATED_BY = 'created_by';

    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';

    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';
}
