# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`header-nav-links`
- **显示名称**：页头导航链接
- **Hook 类型**：简单格式 Hook（向后兼容）
- **功能说明**：在页头主导航中间区域显示一组横向导航链接（如“今日特价、Prime Video、礼品心愿单”等），允许其他模块根据业务需要自定义导航项和跳转地址。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/header-nav-links.phtml`

## 使用场景

- 为首页提供一组高频入口：今日特价、视频、礼品卡、客户服务等
- 根据不同频道/主题站点，切换导航入口（如“数字商品、直播、课程”等）
- 为特定活动期动态配置导航标签（如“双11 会场”、“黑五 会场”等）

## 默认结构参考

在主题默认模板 `header/default.phtml` 中，未实现 Hook 时的结构大致如下：

```html
<nav class="header-nav-links">
    <ul class="nav-links-list" id="nav-links-list">
        <li><a href="/deals"><lang>今日特价</lang></a></li>
        <li><a href="/video"><lang>Prime Video</lang></a></li>
        <li><a href="/registry"><lang>礼品心愿单</lang></a></li>
        <li><a href="/gift-cards"><lang>礼品卡</lang></a></li>
        <li><a href="/help"><lang>客户服务</lang></a></li>
    </ul>
    <!-- “更多”按钮（由 JS 根据宽度控制显示/隐藏） -->
    <div class="nav-more-wrapper" id="nav-more-wrapper" style="display: none;">
        <button class="nav-more-btn" id="nav-more-btn" aria-label="更多" aria-expanded="false">
            <span><lang>更多</lang></span>
            <i class="fas fa-chevron-down"></i>
        </button>
        <ul class="nav-more-dropdown" id="nav-more-dropdown" role="menu" aria-hidden="true">
            <!-- 动态添加的菜单项 -->
        </ul>
    </div>
    </nav>
```

## 示例代码

```php
<!-- 在模块的 view/hooks/header-nav-links.phtml 文件中 -->
<?php
/** @var \Weline\Framework\View\Template $this */

$links = [
    ['url' => '/deals',       'label' => (string)__('今日特价')],
    ['url' => '/new-arrival', 'label' => (string)__('新品上架')],
    ['url' => '/bestseller',  'label' => (string)__('畅销排行')],
    ['url' => '/help',        'label' => (string)__('客户服务')],
];
?>
<nav class="header-nav-links">
    <ul class="nav-links-list" id="nav-links-list">
        <?php foreach ($links as $link): ?>
            <li>
                <a href="<?= htmlspecialchars($link['url'] ?? '#') ?>">
                    <?= htmlspecialchars($link['label'] ?? '') ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
```

> 如需支持“更多”下拉菜单，可复用主题默认结构中的 `.nav-more-wrapper`、`.nav-more-dropdown` 等元素和现有 JS 逻辑。

## CSS 类说明

- `.header-nav-links`：导航容器
- `.nav-links-list`：链接列表 `<ul>` 元素
- `.nav-more-wrapper` / `.nav-more-btn` / `.nav-more-dropdown`：收纳超出宽度的链接（由主题 JS 控制）

## 注意事项

- 建议尽量复用主题已有的 CSS 类，保持风格一致。
- 链接文案应通过 i18n 翻译（示例中使用 `__()` 返回字符串后再输出）。
- 如果 Hook 模板未输出任何内容，将回退到默认的导航链接列表。

