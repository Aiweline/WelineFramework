<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Theme widget default injection handled records')]
#[Index(name: 'uk_theme_widget_default_injection', columns: ['theme_id', 'component_area', 'page_type', 'layout_option', 'scope', 'target_type', 'target_id', 'injection_key'], type: 'UNIQUE')]
#[Index(name: 'idx_theme_widget_default_injection_widget', columns: ['widget_module', 'widget_type', 'widget_code'])]
class ThemeWidgetDefaultInjection extends Model
{
    public const schema_table = 'theme_widget_default_injection';
    public const schema_primary_key = 'record_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Record ID')]
    public const schema_fields_ID = 'record_id';
    #[Col('int', 11, nullable: false, comment: 'Theme ID')]
    public const schema_fields_THEME_ID = 'theme_id';
    #[Col('varchar', 32, nullable: false, default: 'frontend', comment: 'Component area')]
    public const schema_fields_COMPONENT_AREA = 'component_area';
    #[Col('varchar', 50, nullable: false, default: 'default', comment: 'Page type')]
    public const schema_fields_PAGE_TYPE = 'page_type';
    #[Col('varchar', 100, nullable: false, default: 'default', comment: 'Layout option')]
    public const schema_fields_LAYOUT_OPTION = 'layout_option';
    #[Col('varchar', 120, nullable: false, default: 'default', comment: 'Scope path')]
    public const schema_fields_SCOPE = 'scope';
    #[Col('varchar', 50, nullable: false, default: 'global', comment: 'Layout target type')]
    public const schema_fields_TARGET_TYPE = 'target_type';
    #[Col('int', 11, nullable: false, default: 0, comment: 'Layout target ID')]
    public const schema_fields_TARGET_ID = 'target_id';
    #[Col('varchar', 64, nullable: false, comment: 'Default injection key')]
    public const schema_fields_INJECTION_KEY = 'injection_key';
    #[Col('varchar', 100, nullable: false, comment: 'Widget module')]
    public const schema_fields_WIDGET_MODULE = 'widget_module';
    #[Col('varchar', 50, nullable: false, default: '', comment: 'Widget type')]
    public const schema_fields_WIDGET_TYPE = 'widget_type';
    #[Col('varchar', 100, nullable: false, comment: 'Widget code')]
    public const schema_fields_WIDGET_CODE = 'widget_code';
    #[Col('varchar', 50, nullable: true, comment: 'Default slot ID')]
    public const schema_fields_SLOT_ID = 'slot_id';
    #[Col('varchar', 50, nullable: false, default: 'content', comment: 'Default area')]
    public const schema_fields_AREA = 'area';
    #[Col('int', 11, nullable: false, default: 0, comment: 'Default sort order')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('varchar', 50, nullable: false, default: 'auto', comment: 'Handled source')]
    public const schema_fields_SOURCE = 'source';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        parent::save_before();
        $now = date('Y-m-d H:i:s');
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}
