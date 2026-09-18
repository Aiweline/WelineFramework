# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`header-nav-links`
- **显示名称**：页头导航链接
- **Hook 类型**：简单格式 Hook（向后兼容）
- **功能说明**：在页头主导航中间区域显示一组横向导航链接（如“今日特价、客户服务”等），允许其他模块根据业务需要自定义导航项和跳转地址。不包含礼品心愿单/礼品卡（业务不做）。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/header-nav-links.phtml`

## 使用场景

- 为首页提供一组高频入口：今日特价、客户服务等
- 根据不同频道/主题站点，切换导航入口（如“数字商品、直播、课程”等）
- 为特定活动期动态配置导航标签（如“双11 会场”、“黑五 会场”等）

## 默认结构参考

在主题默认模板 `header/default.phtml` 中，未实现 Hook 时的结构大致如下：

```html
<nav class="header-nav-links">
    <ul class="nav-links-list" id="nav-links-list">
        <li><a href="/promotion/deals"><lang>今日特价</lang></a></li>
        <li><a href="/faq"><lang>客户服务</lang></a></li>
    </ul>
    <!-- “更多”：通用 Weline.UI menu（hover 展开），溢出项由 adjustNavLinks 写入 w-menu__item -->
    <div class="nav-more-wrapper"
         id="nav-more-wrapper"
         data-w-component="menu"
         data-w-open-on="hover"
         data-w-placement="bottom-end"
         style="display: none;">
        <button class="nav-more-btn w-button"
                id="nav-more-btn"
                type="button"
                data-w-menu-trigger
                data-tone="quiet"
                aria-haspopup="menu"
                aria-expanded="false"
                aria-label="更多">
            <span><lang>更多</lang></span>
            <i class="fas fa-chevron-down" aria-hidden="true"></i>
        </button>
        <div class="w-menu nav-more-menu w-surface-body"
             data-surface="body"
             id="nav-more-dropdown"
             data-w-menu-panel
             role="menu"
             data-state="closed"
             aria-hidden="true"
             hidden></div>
    </div>
</nav>
```

## 示例代码

```html
<!-- 在模块的 view/hooks/header-nav-links.phtml 文件中 -->
<!-- 固定导航项：优先静态 <lang>，编译期生成译文 -->
<nav class="header-nav-links">
    <ul class="nav-links-list" id="nav-links-list">
        <li><a href="/promotion/deals"><lang>今日特价</lang></a></li>
        <li><a href="/new-arrival"><lang>新品上架</lang></a></li>
        <li><a href="/bestseller"><lang>畅销排行</lang></a></li>
        <li><a href="/faq"><lang>客户服务</lang></a></li>
    </ul>
</nav>
```

若链接项由 PHP 数组/接口动态生成，可在 `<?php ?>` 块内对中文源串调用 `__()` 解析后再 `htmlspecialchars` 输出；不要在 HTML 里写 `<?= __('...') ?>`。

> 如需支持“更多”下拉：复用 `data-w-component="menu"` + `data-w-open-on="hover"` 与 `#nav-more-wrapper` / `#nav-more-dropdown`；溢出项必须是 `a.w-menu__item[role=menuitem]`，由主题 `adjustNavLinks` 写入并 `Weline.UI.mount`。禁止手写 `li>a` 列表或第二套溢出脚本覆盖面板。

## CSS 类说明

- `.header-nav-links`：导航容器
- `.nav-links-list`：链接列表 `<ul>` 元素
- `.nav-more-wrapper` / `.nav-more-btn` / `.nav-more-menu`（`#nav-more-dropdown`）：通用 menu 悬浮收纳超出宽度的链接
- `#header-nav-fill` / `.header-nav-fill-inner` 与父槽 `.header-nav-right-slot` / `.header-nav-links-slot`：桌面宽屏按条目内容自适应宽度（`flex: 0 1 auto`），不与左侧分类 `flex: 1` 平分中间空白；左侧吃满剩余空间后再折叠「更多」。**单行互让**：左预算预留右簇自然宽，右按「主栏−左实占」；`clustersOnSeparateRows` 仅 `is-nav-stacked` 或明显跨行（阈值 `max(24, 左簇高×0.75)`），禁止同行 offsetTop 抖动跳过互让。窄屏两行栈（`is-nav-stacked` / ≤768）时，左右已分行则各自按该行 **100% 主栏内容宽** 算 More，放得下则隐藏。

## 注意事项

- 建议尽量复用主题已有的 CSS 类，保持风格一致。
- 链接文案优先 `<lang>` / `@lang()`（编译期静态译文）；仅在 PHP 逻辑层必要时使用 `__()`。
- 如果 Hook 模板未输出任何内容，将回退到默认的导航链接列表。
- 禁止给右侧快捷槽恢复 `flex: 1 1 0` 抢占空白：会导致左侧分类过早出现「更多」，而中间仍大块空闲。

