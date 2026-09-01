<?php

declare(strict_types=1);

namespace Weline\Promotion\Model;

use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\Api\Localization\LocalModel;

/** 活动主题多语言（走 I18n LocalModel + AI 翻译） @package Weline_Promotion */
#[Table(comment: '前台活动主题多语言')]
class PromotionActivityThemeLocal extends LocalModel
{
    public const schema_table = 'weline_promotion_activity_theme_local';
    public const indexer = 'promotion_activity_theme_local';

    #[Col(type: 'int', nullable: false, primaryKey: true, comment: '主题 ID')]
    public const schema_fields_ID = PromotionActivityTheme::schema_fields_ID;

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = self::schema_fields_local_code;

    #[Col(type: 'varchar', length: 64, nullable: true, comment: '导航标签')]
    public const schema_fields_NAV_LABEL = 'nav_label';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '页面标题')]
    public const schema_fields_PAGE_TITLE = 'page_title';
    #[Col(type: 'text', nullable: true, comment: 'Hero 说明')]
    public const schema_fields_HERO_LEDE = 'hero_lede';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '入口卡片标题')]
    public const schema_fields_ENTRY_TITLE = 'entry_title';
    #[Col(type: 'text', nullable: true, comment: '入口卡片说明')]
    public const schema_fields_ENTRY_SUBTITLE = 'entry_subtitle';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '入口按钮文案')]
    public const schema_fields_ENTRY_ACTION_LABEL = 'entry_action_label';
}
