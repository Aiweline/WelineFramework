<?php
declare(strict_types=1);
namespace Weline\Theme\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\LayoutDataService;
/** 主题布局模型 - 存储主题中各个区域的部件配置 */
#[Table(comment: '主题布局配置表')]
#[Index(name: 'idx_theme_page', columns: ['theme_id', 'page_type'])]
#[Index(name: 'idx_area_sort', columns: ['area', 'sort_order'])]
#[Index(name: 'idx_theme_slot', columns: ['theme_id', 'page_type', 'area', 'slot_id'])]
#[Index(name: 'idx_theme_status', columns: ['theme_id', 'page_type', 'status'])]
#[Index(name: 'idx_theme_layout_identity', columns: ['theme_id', 'page_type', 'layout_option', 'scope', 'target_type', 'target_id', 'status'])]
class ThemeLayout extends Model
{
    public const schema_table = 'theme_layout';
    public const schema_primary_key = 'layout_id';
    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: '布局ID')]
    public const schema_fields_ID = 'layout_id';
    #[Col('int', 11, nullable: false, comment: '主题ID')]
    public const schema_fields_THEME_ID = 'theme_id';
    #[Col('varchar', 50, nullable: false, default: 'default', comment: '页面类型')]
    public const schema_fields_PAGE_TYPE = 'page_type';
    #[Col('varchar', 100, nullable: false, default: 'default', comment: 'Layout option')]
    public const schema_fields_LAYOUT_OPTION = 'layout_option';
    #[Col('varchar', 120, nullable: false, default: 'default', comment: 'Scope path')]
    public const schema_fields_SCOPE = 'scope';
    #[Col('varchar', 50, nullable: false, default: 'global', comment: 'Layout target type')]
    public const schema_fields_TARGET_TYPE = 'target_type';
    #[Col('int', 11, nullable: false, default: 0, comment: 'Layout target ID')]
    public const schema_fields_TARGET_ID = 'target_id';
    #[Col('varchar', 50, nullable: false, comment: '区域标识')]
    public const schema_fields_AREA = 'area';
    #[Col('varchar', 50, comment: '插槽ID')]
    public const schema_fields_SLOT_ID = 'slot_id';
    #[Col('varchar', 100, nullable: false, comment: '部件代码')]
    public const schema_fields_WIDGET_CODE = 'widget_code';
    #[Col('varchar', 100, nullable: false, comment: '部件所属模块')]
    public const schema_fields_WIDGET_MODULE = 'widget_module';
    #[Col('varchar', 50, default: '', comment: '部件类型')]
    public const schema_fields_WIDGET_TYPE = 'widget_type';
    #[Col('text', comment: '部件配置JSON')]
    public const schema_fields_CONFIG = 'config';
    #[Col('int', 11, nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('smallint', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('varchar', 20, nullable: false, default: 'draft', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';
    // 状态常量
    public const STATUS_DRAFT = 'draft';         // 草稿状态（后台编辑）
    public const STATUS_PUBLISHED = 'published'; // 已发布状态（前端可见）
    // 页面类型常量（与 layouts 目录名对应）
    public const PAGE_TYPE_HOME = 'homepage';         // layouts/homepage/
    public const PAGE_TYPE_CATEGORY = 'category';     // layouts/category/
    public const PAGE_TYPE_PRODUCT = 'product';       // layouts/product/
    public const PAGE_TYPE_PRODUCT_LIST = 'product_list'; // layouts/product_list/
    public const PAGE_TYPE_CMS = 'cms_page';          // layouts/cms_page/
    public const PAGE_TYPE_CART = 'cart';             // layouts/cart/
    public const PAGE_TYPE_CHECKOUT = 'checkout';     // layouts/checkout/
    public const PAGE_TYPE_ACCOUNT = 'account';       // layouts/account/
    public const PAGE_TYPE_SEARCH = 'search';         // layouts/search/ (待创建)
    public const PAGE_TYPE_DEFAULT = 'default';       // layouts/default/
    // 区域常量
    public const AREA_HEADER = 'header';
    public const AREA_BANNER = 'banner';
    public const AREA_LEFT_SIDEBAR = 'left_sidebar';
    public const AREA_CONTENT = 'content';
    public const AREA_RIGHT_SIDEBAR = 'right_sidebar';
    public const AREA_FOOTER = 'footer';
    /**
     * 获取所有支持的页面类型（布局类型）
     * 
     * 动态从 LayoutDataService 获取，支持子主题新增布局
     * 如果服务不可用，返回默认的硬编码列表作为回退
     */
    public static function getPageTypes(): array
    {
        try {
            /** @var LayoutDataService $layoutDataService */
            $layoutDataService = ObjectManager::getInstance(LayoutDataService::class);
            $types = $layoutDataService->getAllLayoutTypes();
            if (!empty($types)) {
                return $types;
            }
        } catch (\Throwable $e) {
            // 服务不可用，使用默认列表
        }
        // 回退：返回默认的硬编码列表
        return [
            self::PAGE_TYPE_HOME => __('首页'),
            self::PAGE_TYPE_CATEGORY => __('分类页'),
            self::PAGE_TYPE_PRODUCT => __('产品页'),
            self::PAGE_TYPE_PRODUCT_LIST => __('产品列表页'),
            self::PAGE_TYPE_CMS => __('CMS页面'),
            self::PAGE_TYPE_CART => __('购物车'),
            self::PAGE_TYPE_CHECKOUT => __('结算页'),
            self::PAGE_TYPE_ACCOUNT => __('账户中心'),
            self::PAGE_TYPE_SEARCH => __('搜索页'),
            self::PAGE_TYPE_DEFAULT => __('默认布局'),
        ];
    }
    /**
     * 获取所有支持的区域
     */
    public static function getAreas(): array
    {
        return [
            self::AREA_HEADER => __('头部区域'),
            self::AREA_BANNER => __('横幅区域'),
            self::AREA_LEFT_SIDEBAR => __('左侧栏'),
            self::AREA_CONTENT => __('内容区域'),
            self::AREA_RIGHT_SIDEBAR => __('右侧栏'),
            self::AREA_FOOTER => __('底部区域'),
        ];
    }
    // Getters & Setters
    public function getLayoutId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }
    public function setLayoutId(int $id): self
    {
        return $this->setData(self::schema_fields_ID, $id);
    }
    public function getThemeId(): int
    {
        return (int)$this->getData(self::schema_fields_THEME_ID);
    }
    public function setThemeId(int $themeId): self
    {
        return $this->setData(self::schema_fields_THEME_ID, $themeId);
    }
    public function getPageType(): string
    {
        return (string)$this->getData(self::schema_fields_PAGE_TYPE);
    }
    public function setPageType(string $pageType): self
    {
        return $this->setData(self::schema_fields_PAGE_TYPE, $pageType);
    }
    public function getLayoutOption(): string
    {
        return (string)($this->getData(self::schema_fields_LAYOUT_OPTION) ?: 'default');
    }
    public function setLayoutOption(string $layoutOption): self
    {
        $layoutOption = trim($layoutOption) !== '' ? trim($layoutOption) : 'default';
        return $this->setData(self::schema_fields_LAYOUT_OPTION, $layoutOption);
    }
    public function getScope(): string
    {
        return (string)($this->getData(self::schema_fields_SCOPE) ?: 'default');
    }
    public function setScope(string $scope): self
    {
        $scope = trim($scope) !== '' ? trim($scope) : 'default';
        return $this->setData(self::schema_fields_SCOPE, $scope);
    }
    public function getTargetType(): string
    {
        return (string)($this->getData(self::schema_fields_TARGET_TYPE) ?: 'global');
    }
    public function setTargetType(string $targetType): self
    {
        $targetType = trim($targetType) !== '' ? trim($targetType) : 'global';
        return $this->setData(self::schema_fields_TARGET_TYPE, $targetType);
    }
    public function getTargetId(): int
    {
        return max(0, (int)$this->getData(self::schema_fields_TARGET_ID));
    }
    public function setTargetId(int $targetId): self
    {
        return $this->setData(self::schema_fields_TARGET_ID, max(0, $targetId));
    }
    public function getArea(): string
    {
        return (string)$this->getData(self::schema_fields_AREA);
    }
    public function setArea(string $area): self
    {
        return $this->setData(self::schema_fields_AREA, $area);
    }
    public function getSlotId(): ?string
    {
        $slotId = $this->getData(self::schema_fields_SLOT_ID);
        return $slotId ? (string)$slotId : null;
    }
    public function setSlotId(?string $slotId): self
    {
        return $this->setData(self::schema_fields_SLOT_ID, $slotId);
    }
    public function getWidgetCode(): string
    {
        return (string)$this->getData(self::schema_fields_WIDGET_CODE);
    }
    public function setWidgetCode(string $code): self
    {
        return $this->setData(self::schema_fields_WIDGET_CODE, $code);
    }
    public function getWidgetModule(): string
    {
        return (string)$this->getData(self::schema_fields_WIDGET_MODULE);
    }
    public function setWidgetModule(string $module): self
    {
        return $this->setData(self::schema_fields_WIDGET_MODULE, $module);
    }
    public function getWidgetType(): string
    {
        return (string)$this->getData(self::schema_fields_WIDGET_TYPE);
    }
    public function setWidgetType(string $type): self
    {
        return $this->setData(self::schema_fields_WIDGET_TYPE, $type);
    }
    public function getWidgetConfig(): array
    {
        $config = $this->getData(self::schema_fields_CONFIG);
        if (empty($config)) {
            return [];
        }
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }
    public function setWidgetConfig(array $config): self
    {
        return $this->setData(self::schema_fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }
    public function getSortOrder(): int
    {
        return (int)$this->getData(self::schema_fields_SORT_ORDER);
    }
    public function setSortOrder(int $order): self
    {
        return $this->setData(self::schema_fields_SORT_ORDER, $order);
    }
    public function getIsActive(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_ACTIVE);
    }
    public function setIsActive(bool $active): self
    {
        return $this->setData(self::schema_fields_IS_ACTIVE, $active ? 1 : 0);
    }
    public function getStatus(): string
    {
        return (string)($this->getData(self::schema_fields_STATUS) ?: self::STATUS_DRAFT);
    }
    public function setStatus(string $status): self
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }
    /**
     * 检查是否为草稿状态
     */
    public function isDraft(): bool
    {
        return $this->getStatus() === self::STATUS_DRAFT;
    }
    /**
     * 检查是否为已发布状态
     */
    public function isPublished(): bool
    {
        return $this->getStatus() === self::STATUS_PUBLISHED;
    }
    /**
     * 获取部件的唯一标识（用于前端）
     */
    public function getWidgetUniqueId(): string
    {
        return $this->getWidgetModule() . '::' . $this->getWidgetCode();
    }
    /**
     * 获取所有支持的状态
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFT => __('草稿'),
            self::STATUS_PUBLISHED => __('已发布'),
        ];
    }
}
