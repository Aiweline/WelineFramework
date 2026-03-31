<?php

declare(strict_types=1);

/*
 * WeShop CMS Module
 * 页面模型 - 完全参照PageBuilder结构
 */

namespace WeShop\Cms\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Page extends Model
{
    public const schema_table = 'weline_cms_page';
    public const schema_primary_key = 'page_id';
    public string $indexer = 'cms_page_indexer';
    
    // 字段定义
    public const schema_fields_ID = 'page_id';
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['page_id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['page_id', 'handle', 'type', 'status'];
    public const schema_fields_HANDLE = 'handle';
    public const schema_fields_TYPE = 'type';
    public const schema_fields_NAME = 'name';
    public const schema_fields_TITLE = 'title';
    public const schema_fields_CONTENT = 'content';
    public const schema_fields_PARENT_ID = 'parent_id';
    public const schema_fields_GA4_ID = 'ga4_id';
    public const schema_fields_GTM_ID = 'gtm_id';
    public const schema_fields_FB_PIXEL_ID = 'fb_pixel_id';
    public const schema_fields_LOGO = 'logo';
    public const schema_fields_ICON = 'icon';
    public const schema_fields_LOCALES = 'locales';
    public const schema_fields_DEFAULT_LOCALE = 'default_locale';
    public const schema_fields_STYLE = 'style';
    public const schema_fields_STYLE_SETTING = 'style_setting';
    public const schema_fields_META_TITLE = 'meta_title';
    public const schema_fields_META_DESCRIPTION = 'meta_description';
    public const schema_fields_META_KEYWORDS = 'meta_keywords';
    public const schema_fields_REDIRECT_URL = 'redirect_url';
    public const schema_fields_STATUS = 'status';
    public const schema_fields_CREATE_TIME = 'create_time';
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
        if (empty($locales) || !is_string($locales)) {
            return [];
        }
        $decoded = json_decode($locales, true);
        return is_array($decoded) ? $decoded : [];
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
        if (empty($setting) || !is_string($setting)) {
            return [];
        }
        $decoded = json_decode($setting, true);
        return is_array($decoded) ? $decoded : [];
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
    
    /**
     * 加载本地化描述（用于多语言支持）
     * 重写基类方法以支持PageBuilder的用法
     */
    public function loadLocalDescription(string $local_code = '', string|\Weline\I18n\LocalModel $model = ''): static
    {
        // 调用基类方法
        return parent::loadLocalDescription($local_code, $model);
    }

    public function install(ModelSetup $setup, Context $context): void {}
    public function upgrade(ModelSetup $setup, Context $context): void {}
    public function setup(ModelSetup $setup, Context $context): void {}
}
