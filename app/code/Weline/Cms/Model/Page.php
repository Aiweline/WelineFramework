<?php

declare(strict_types=1);

/*
 * Weline Cms Module
 * CMS内容管理系统页面模型
 */

namespace Weline\Cms\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

#[Table(comment: 'CMS页面表')]
#[Index(name: 'UNQ_WEBSITE_HANDLE', columns: ['website_id', 'handle'], type: 'UNIQUE')]
#[Index(name: 'idx_handle', columns: ['handle'])]
#[Index(name: 'idx_website_id', columns: ['website_id'])]
#[Index(name: 'idx_type', columns: ['type'])]
#[Index(name: 'idx_parent_id', columns: ['parent_id'])]
#[Index(name: 'idx_status', columns: ['status'])]
class Page extends Model
{
    public const schema_table = 'weline_cms_page';

    // 字段定义
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '页面ID')]
    public const schema_fields_ID = 'page_id';
    #[Col('varchar', 100, nullable: false, comment: '页面句柄')]
    public const schema_fields_HANDLE = 'handle';
    #[Col('int', 11, nullable: false, default: 0, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 50, nullable: false, comment: '页面类型')]
    public const schema_fields_TYPE = 'type';
    #[Col('varchar', 255, nullable: false, comment: '页面名称')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 255, nullable: false, comment: '页面标题')]
    public const schema_fields_TITLE = 'title';
    #[Col('text', comment: '页面内容')]
    public const schema_fields_CONTENT = 'content';
    #[Col('int', default: 0, comment: '父页面ID')]
    public const schema_fields_PARENT_ID = 'parent_id';
    #[Col('varchar', 100, comment: 'GA4 ID')]
    public const schema_fields_GA4_ID = 'ga4_id';
    #[Col('varchar', 100, comment: 'GTM ID')]
    public const schema_fields_GTM_ID = 'gtm_id';
    #[Col('varchar', 100, comment: 'Facebook Pixel ID')]
    public const schema_fields_FB_PIXEL_ID = 'fb_pixel_id';
    #[Col('varchar', 100, comment: 'CTA转化事件名称')]
    public const schema_fields_CTA_EVENT_NAME = 'cta_event_name';
    #[Col('varchar', 255, comment: 'Logo路径')]
    public const schema_fields_LOGO = 'logo';
    #[Col('varchar', 255, comment: 'Icon路径')]
    public const schema_fields_ICON = 'icon';
    #[Col('text', comment: '选中的语言列表(JSON)')]
    public const schema_fields_LOCALES = 'locales';
    #[Col('varchar', 10, comment: '默认语言代码')]
    public const schema_fields_DEFAULT_LOCALE = 'default_locale';
    #[Col('varchar', 100, comment: '页面样式模板名称')]
    public const schema_fields_STYLE = 'style';
    #[Col('text', comment: '页面样式配置(JSON)')]
    public const schema_fields_STYLE_SETTING = 'style_setting';
    #[Col('varchar', 255, comment: 'SEO标题')]
    public const schema_fields_META_TITLE = 'meta_title';
    #[Col('text', comment: 'SEO描述')]
    public const schema_fields_META_DESCRIPTION = 'meta_description';
    #[Col('varchar', 255, comment: 'SEO关键词')]
    public const schema_fields_META_KEYWORDS = 'meta_keywords';
    #[Col('varchar', 500, comment: '表单提交后跳转URL')]
    public const schema_fields_REDIRECT_URL = 'redirect_url';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';
    
    // 页面类型常量
    public const TYPE_HOME = 'home_page';
    public const TYPE_ABOUT = 'about_page';
    public const TYPE_CONTACT = 'contact_page';
    public const TYPE_PRIVACY_POLICY = 'privacy_policy';
    public const TYPE_TERMS_OF_SERVICE = 'terms_of_service';
    public const TYPE_REFUND_POLICY = 'refund_policy';
    public const TYPE_SHIPPING_POLICY = 'shipping_policy';
    public const TYPE_CUSTOM = 'custom_page';
    
    // 状态常量
    public const STATUS_DRAFT = 0;
    public const STATUS_PUBLISHED = 1;
    
    /**
     * 获取所有页面类型
     */
    public static function getPageTypes(): array
    {
        return [
            self::TYPE_HOME => __('首页'),
            self::TYPE_ABOUT => __('关于我们'),
            self::TYPE_CONTACT => __('联系我们'),
            self::TYPE_PRIVACY_POLICY => __('隐私政策'),
            self::TYPE_TERMS_OF_SERVICE => __('服务条款'),
            self::TYPE_REFUND_POLICY => __('退款政策'),
            self::TYPE_SHIPPING_POLICY => __('配送政策'),
            self::TYPE_CUSTOM => __('自定义页面'),
        ];
    }
    
    /**
     * 获取页面类型名称
     */
    public function getTypeName(): string
    {
        $types = self::getPageTypes();
        return $types[$this->getData(self::schema_fields_TYPE)] ?? $this->getData(self::schema_fields_TYPE);
    }
    
    /**
     * 获取状态名称
     */
    public function getStatusName(): string
    {
        return $this->getData(self::schema_fields_STATUS) == self::STATUS_PUBLISHED ? __('已发布') : __('草稿');
    }
    
    /**
     * 获取选中的语言列表
     */
    public function getSelectedLocales(): array
    {
        $locales = $this->getData(self::schema_fields_LOCALES);
        if (empty($locales)) {
            return [];
        }
        return json_decode($locales ?? '', true) ?: [];
    }
    
    /**
     * 设置选中的语言列表
     */
    public function setSelectedLocales(array $locales): self
    {
        return $this->setData(self::schema_fields_LOCALES, json_encode($locales));
    }
    
    /**
     * 获取父页面
     */
    public function getParentPage(): ?Page
    {
        $parentId = $this->getData(self::schema_fields_PARENT_ID);
        if (!$parentId) {
            return null;
        }
        
        $parent = clone $this;
        $parent->clear()->load($parentId);
        return $parent->getId() ? $parent : null;
    }
    
    /**
     * 获取子页面列表
     */
    public function getChildPages(): array
    {
        $children = clone $this;
        return $children->clear()
            ->where(self::schema_fields_PARENT_ID, $this->getId())
            ->select()
            ->fetch()
            ->getItems();
    }
    
    /**
     * 获取样式配置
     */
    public function getStyleSetting(): array
    {
        $setting = $this->getData(self::schema_fields_STYLE_SETTING);
        if (empty($setting)) {
            return [];
        }
        return json_decode($setting ?? '', true) ?: [];
    }
    
    /**
     * 设置样式配置
     */
    public function setStyleSetting(array $setting): self
    {
        return $this->setData(self::schema_fields_STYLE_SETTING, json_encode($setting));
    }
    
    /**
     * 获取样式配置的某个值
     */
    public function getStyleSettingValue(string $key, $default = null)
    {
        $settings = $this->getStyleSetting();
        return $settings[$key] ?? $default;
    }

}

