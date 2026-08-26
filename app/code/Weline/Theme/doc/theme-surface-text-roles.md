# 主题 Surface / Text 语义抽象

> **强约束**：深色顶栏、页脚等反色区域禁止依赖裸 `span`/`a` 继承页面默认黑字。必须用 **Surface 语境** 或 **Text 角色类**，颜色只来自主题 Token。

## 问题

页头 `header-belt` 背景为深色时，若文案仍走页面默认 `--weline-theme-text`（近黑），或 Theme Editor 把 `textLight` 配成空/黑色，就会出现**黑底黑字**。

## 标准

### 1. Surface（容器语境）

在容器上声明表面角色，子孙默认继承前景色：

| 标记 | 含义 | 前景 |
|---|---|---|
| `data-surface="inverse"` / `.w-surface-inverse` | 深色条（顶栏/页脚） | `--weline-theme-text-on-dark` |
| `data-surface="body"` / `.w-surface-body` | 深色 chrome 内的浅色浮层 / 页面主体 | `--weline-theme-body-text` |
| `data-surface="default"` / `.w-surface` | 普通浅色面板 | `--weline-theme-text` |
| `data-surface="muted"` / `.w-surface-muted` | 弱背景区 | `--weline-theme-text` + muted 表面 |

Surface 会设置 `--w-surface-fg` / `--w-surface-fg-muted`，供子级 Text 类读取。

深色顶栏示例：

```html
<div class="header-belt w-surface-inverse" data-surface="inverse">...</div>
```

浮层若挂在 inverse 内，必须**重置**为 body 或 default，避免白底浅字 / 黑底白字错位：

```html
<div class="delivery-panel w-surface" data-surface="default">...</div>
<div class="header-category-panel w-surface-body" data-surface="body">...</div>
```

### Body 文字 Token（与 chrome 顶栏/页脚区分）

| Token | 用途 |
|---|---|
| `--weline-chrome-body-text` / `--weline-theme-body-text` | 浮层/主体主文案 |
| `--weline-chrome-body-text-heading` / `--weline-theme-body-text-heading` | 列标题、分组标题 |
| `--weline-chrome-body-text-muted` / `--weline-theme-body-text-muted` | 次文案 |
| `--weline-chrome-body-link-hover` / `--weline-theme-body-link-hover` | 链接 hover（如分类入口） |
| `--weline-chrome-menu-panel-text` / `--weline-theme-menu-panel-text` | 顶栏 hover 下拉 / Mega Menu 主文案（白底黑字） |
| `--weline-chrome-menu-panel-text-heading` | 下拉列标题 |
| `--weline-chrome-menu-panel-text-hover` | 下拉项 hover 高亮色 |

定义于 `variables/_colors.css`，由 `theme.css` 桥接为 `--weline-theme-body-*`。

### 2. Text（文案角色类）

不要给裸 `span` 写死 `#000`。用语义类 + CSS 名改样式：

| 类名 | 用途 |
|---|---|
| `.w-text` | 主文案（跟当前 Surface） |
| `.w-text-muted` | 次文案 / 说明行 |
| `.w-text-subtle` | 更弱说明 |
| `.w-text-inverse` | 强制浅色字（不依赖 Surface） |
| `.w-text-inverse-muted` | 强制浅色次文案 |
| `.w-text-primary` 等 | 品牌/状态色（与 Surface 无关） |

页头配送入口示例：

```html
<button class="w-header-delivery-entry location-link delivery-entry">
  <span class="w-text-muted location-line-1">配送至</span>
  <span class="w-text location-line-2">中国澳门特别行政区</span>
</button>
```

### 3. 组件入口类

页头配送/定位入口统一：

- `.w-header-delivery-entry`
- `.location-link`
- `.delivery-entry`

页头语言/货币触发器（`quiet` 按钮）统一：

- `.w-language-switcher__trigger`
- `.w-currency-switcher__trigger`

颜色走 `--w-surface-fg` / `--weline-theme-header-text` / `--weline-theme-text-on-dark`；foundation 的 `.w-button[data-tone="quiet"]` 亦读取 `--w-surface-fg`。下拉面板须自带 `default`/`body` surface，保持深色正文。

样式定义在 `view/theme/frontend/assets/css/theme.css`（Theme UI 默认），业务模块只挂类名，不私写裸按钮黑字样式。

## 验收

1. 深色 `header-belt` 内主/次文案对比度可读（浅色字），含语言「English」与货币「CNY ¥」触发器。
2. 打开配送面板后，面板内文字为深色（default surface），不是浅色发白。
3. 打开语言/货币下拉后，选项文字为深色正文，不是浅色发白。
4. 新增页头控件优先复用 Surface + Text 类，禁止再注入 `$colors['textLight']` 字面量。
