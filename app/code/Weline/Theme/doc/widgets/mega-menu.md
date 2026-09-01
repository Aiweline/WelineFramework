# Mega Menu（分栏导航浮层）

> 模块：`Weline_Theme` · 基座：`Weline.UI` · 组件名：`mega-menu`  
> 更新：2026-09-01

## 目的

为「左侧分类 tab + 右侧内容面板」的导航浮层提供可复用契约。开合/定位仍由父级 `popover` 负责；本组件只管理侧栏激活与默认展开卡片。

右侧面板内容规则：

1. **无子分类**：展示当前侧栏分类的 **Banner** 与 **描述**（有则显示；描述优先 `description`，否则 `summary`），不再显示「暂无子分类」。
2. **有子分类**：默认先展示 Banner/描述，再展示子分类网格。
3. **配置**：部件/Header 参数 `show_banner_with_children`（bool，默认 `true`）控制「有子分类时是否展示 Banner/描述」；无子分类时始终尝试展示。

## 声明式用法

```html
<li data-w-component="popover" data-w-open-on="hover" data-w-placement="bottom-start">
  <button type="button" data-w-popover-trigger>Clothing</button>
  <div class="w-popover w-mega-menu w-mega-menu--split"
       data-w-component="mega-menu"
       data-w-popover-panel
       data-w-mega-menu
       role="dialog"
       hidden>
    <div class="w-mega-menu__layout">
      <ul class="w-mega-menu__sidebar" role="tablist">…</ul>
      <div class="w-mega-menu__panels">…</div>
    </div>
  </div>
</li>
```

| 属性 / 类 | 含义 |
|-----------|------|
| `data-w-component="mega-menu"` | 挂载本组件 |
| `data-w-mega-menu` / `data-mega-menu` | 作用域根（兼容旧标记） |
| `data-w-mega-tab` / `data-mega-tab` | 侧栏 tab → panel id |
| `data-w-mega-panel` / `data-mega-panel` | 内容面板 id |
| `data-show-banner-with-children` | `1`/`0`：有子分类时是否渲染 Banner/描述 intro |
| `.w-mega-menu--split` | 零内边距分栏（侧栏 + 内容） |
| `.mega-menu-panel__intro` | Banner + 描述区块 |

兼容：`.mega-menu-*` / `.header-category-panel.is-megamenu` 选择器仍由组件 CSS 覆盖，便于 Header 渐进迁移。

## 资源

- JS：`view/ui/js/components/mega-menu.js` → `components/weline-mega-menu.js`
- CSS：`view/ui/css/components/mega-menu.css` → `components/weline-mega-menu.css`
- 懒加载：`Weline.UI` 在见到 `data-w-component~="mega-menu"` 时拉取

编译：`php bin/w resource:compile welineUi`
