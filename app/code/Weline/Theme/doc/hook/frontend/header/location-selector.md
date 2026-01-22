# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`header-location-selector`
- **显示名称**：页头配送地址选择器
- **Hook 类型**：简单格式 Hook（向后兼容）
- **功能说明**：在页头 Logo 右侧显示配送地址选择器，允许其他模块实现配送区域/仓库/站点等位置的选择功能。如果未实现该 Hook，将显示默认的“配送至 中国大陆”文案。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/header-location-selector.phtml`

> 简单格式 Hook 约定：文件名与 Hook 名称完全一致，加上 `.phtml` 后缀，无子目录。

## 使用场景

- 为不同国家/地区站点提供配送地址选择（国家、城市、区域等）
- 根据选择的配送地址切换价格、库存、可售商品等
- 结合多仓发货、门店自提等业务，展示“送至某仓/某门店”
- 在移动端和 PC 端统一展示配送位置状态

## 默认结构参考

在主题默认模板 `header/default.phtml` 中，未实现 Hook 时的结构大致如下（供参考）：

```html
<div class="header-location">
    <a href="#" class="location-link" title="<lang>选择配送地址</lang>">
        <i class="fas fa-map-marker-alt"></i>
        <div class="location-text">
            <span class="location-line-1"><lang>配送至</lang></span>
            <span class="location-line-2"><lang>中国大陆</lang></span>
        </div>
    </a>
</div>
```

## 示例代码

```php
<!-- 在模块的 view/hooks/header-location-selector.phtml 文件中 -->
<?php
use Weline\Framework\Manager\ObjectManager;
use YourVendor\YourModule\Service\LocationService;

/** @var \Weline\Framework\View\Template $this */

$service = ObjectManager::getInstance(LocationService::class);
$currentLocation = $service->getCurrentLocation();

$line1 = (string)__('配送至');
$line2 = $currentLocation ? $currentLocation->getDisplayName() : (string)__('请选择地址');
?>
<a href="/location/select" class="location-link" title="<?= __('选择配送地址') ?>">
    <i class="fas fa-map-marker-alt" aria-hidden="true"></i>
    <div class="location-text">
        <span class="location-line-1"><?= $line1 ?></span>
        <span class="location-line-2"><?= htmlspecialchars($line2) ?></span>
    </div>
</a>
```

## CSS 类说明

- `.header-location`：配送地址选择器容器（由主题模板提供）
- `.location-link`：点击区域链接
- `.location-text`：文案包裹元素
- `.location-line-1`：第一行文案（如“配送至”）
- `.location-line-2`：第二行文案（如“上海 浦东新区”）

## 注意事项

- 建议链接跳转到专门的地址/仓库选择页面，或弹出侧边栏/弹窗进行选择。
- 输出内容会直接渲染在现有 `.header-location` 容器内部，注意保持结构与主题样式兼容。
- 如果 Hook 模板未输出任何内容，则会回退到默认的“配送至 中国大陆”文案。

