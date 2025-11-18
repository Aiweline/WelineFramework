# Weline Switcher 组件使用指南

## 概述

`weline-switcher` 是一个 SEO 友好的 Web Component，用于实现语言、货币、地区等切换功能。

## SEO 友好性设计

### 核心原则

1. **内容在 Light DOM 中**：不使用 Shadow DOM，确保搜索引擎可以索引所有内容
2. **渐进增强**：基础功能是链接，JavaScript 只负责交互增强
3. **语义化 HTML**：使用合适的 HTML 标签和 ARIA 属性
4. **服务端渲染**：关键内容由 PHP 在服务端渲染

### SEO 优势

- ✅ 所有选项链接都在初始 HTML 中，搜索引擎可以抓取
- ✅ 支持无 JavaScript 降级，基础功能仍然可用
- ✅ 使用语义化标签（`<a>`、`<ul>`、`<li>`）
- ✅ 支持 `aria-*` 属性，提升可访问性
- ✅ 隐藏的 SEO 内容区域保留原始链接结构

## 使用方法

### 1. 在 PHP 模板中使用

```php
<!-- 语言切换器 -->
<w:template>Weline_Frontend::components/switcher.phtml</w:template>
<?php
$this->setData('type', 'language');
$this->setData('current', 'zh_Hans_CN');
$this->setData('options', [
    ['value' => 'zh_Hans_CN', 'label' => '中文', 'href' => '/zh_Hans_CN/page'],
    ['value' => 'en_US', 'label' => 'English', 'href' => '/en_US/page'],
]);
?>

<!-- 货币切换器 -->
<?php
$this->setData('type', 'currency');
$this->setData('current', 'CNY');
$this->setData('options', [
    ['value' => 'CNY', 'label' => '¥ 人民币', 'href' => '/?currency=CNY'],
    ['value' => 'USD', 'label' => '$ 美元', 'href' => '/?currency=USD'],
]);
?>
<w:template>Weline_Frontend::components/switcher.phtml</w:template>
```

### 2. 直接在 HTML 中使用

```html
<!-- 语言切换器 -->
<weline-switcher type="language" current="zh_Hans_CN">
    <a href="/zh_Hans_CN/page" data-value="zh_Hans_CN">中文</a>
    <a href="/en_US/page" data-value="en_US">English</a>
    <a href="/ja_JP/page" data-value="ja_JP">日本語</a>
</weline-switcher>

<!-- 货币切换器 -->
<weline-switcher type="currency" current="CNY">
    <a href="/?currency=CNY" data-value="CNY">¥ 人民币</a>
    <a href="/?currency=USD" data-value="USD">$ 美元</a>
    <a href="/?currency=EUR" data-value="EUR">€ 欧元</a>
</weline-switcher>

<!-- 地区切换器 -->
<weline-switcher type="region" current="CN">
    <a href="/?region=CN" data-value="CN">中国</a>
    <a href="/?region=US" data-value="US">美国</a>
    <a href="/?region=JP" data-value="JP">日本</a>
</weline-switcher>
```

### 3. 在 Header 中使用

```php
<!-- 在 header 模板中 -->
<div class="header-action-item">
    <w:template>Weline_Frontend::components/switcher.phtml</w:template>
    <?php
    $this->setData('type', 'language');
    $this->setData('current', $_SERVER['WELINE_USER_LANG'] ?? 'zh_Hans_CN');
    // options 会自动从配置中获取
    ?>
</div>
```

## 属性说明

### type 属性

- `language`: 语言切换
- `currency`: 货币切换
- `region`: 地区切换
- `locale`: 区域设置切换

### current 属性

当前选中的值，例如：`zh_Hans_CN`、`CNY`、`CN`

## JavaScript API

### 事件监听

```javascript
// 监听切换事件
document.querySelector('weline-switcher').addEventListener('weline:switcher:change', (event) => {
    const { type, value, option } = event.detail;
    console.log(`切换到 ${type}: ${value}`, option);
});
```

### 编程式控制

```javascript
const switcher = document.querySelector('weline-switcher');

// 获取当前值
const current = switcher.currentValue;

// 设置当前值
switcher.currentValue = 'en_US';

// 打开下拉菜单
switcher.open();

// 关闭下拉菜单
switcher.close();

// 切换下拉菜单
switcher.toggle();
```

## 样式定制

组件使用 CSS 变量，可以轻松定制样式：

```css
weline-switcher {
    --border-color: #e0e0e0;
    --hover-bg: #f5f5f5;
    --active-bg: #e8f5f3;
    --active-color: #0bb197;
    --focus-color: #0bb197;
    --dropdown-bg: #ffffff;
    --text-color: #333333;
}
```

## 可访问性

- ✅ 支持键盘导航（方向键、Enter、Escape）
- ✅ 支持屏幕阅读器（ARIA 属性）
- ✅ 支持焦点管理
- ✅ 支持无鼠标操作

## 浏览器兼容性

- Chrome/Edge: ✅ 完全支持
- Firefox: ✅ 完全支持
- Safari: ✅ 完全支持
- IE11: ❌ 不支持（需要 polyfill）

## 最佳实践

1. **始终提供 href**：确保链接有效，支持无 JS 降级
2. **使用语义化标签**：使用 `<a>` 标签而不是 `<button>`
3. **提供清晰的标签**：使用有意义的文本标签
4. **保持选项数量合理**：建议不超过 10 个选项
5. **测试无 JS 场景**：确保基础功能在禁用 JS 时仍然可用

## 与现有系统集成

组件会自动与 `Weline.Locale` 对象集成：

- `type="language"` 会调用 `Weline.Locale.switchLang()`
- `type="currency"` 会调用 `Weline.Locale.switchCurrency()`
- 如果 `Weline` 对象不存在，会使用链接跳转作为降级方案

## 示例：完整的 Header 集成

```php
<!-- header.phtml -->
<div class="header-actions">
    <!-- 语言切换 -->
    <div class="header-action-item">
        <w:template>Weline_Frontend::components/switcher.phtml</w:template>
        <?php
        $this->setData('type', 'language');
        $this->setData('current', $_SERVER['WELINE_USER_LANG'] ?? 'zh_Hans_CN');
        $this->setData('availableLangs', [
            ['code' => 'zh_Hans_CN', 'name' => '中文', 'native' => '中文'],
            ['code' => 'en_US', 'name' => 'English', 'native' => 'English'],
        ]);
        ?>
    </div>

    <!-- 货币切换 -->
    <div class="header-action-item">
        <w:template>Weline_Frontend::components/switcher.phtml</w:template>
        <?php
        $this->setData('type', 'currency');
        $this->setData('current', $_SERVER['WELINE_USER_CURRENCY'] ?? 'CNY');
        $this->setData('availableCurrencies', [
            ['code' => 'CNY', 'name' => '人民币', 'symbol' => '¥'],
            ['code' => 'USD', 'name' => '美元', 'symbol' => '$'],
        ]);
        ?>
    </div>
</div>
```

