---
name: theme-development
description: Weline Theme 主题开发。用于 `view/theme` 下的 frontend/backend 主题目录、layouts/partials/widgets/components、变量与色盘、主题 CSS/JS、`@meta`/`@param`/`@widget` 注释、`<theme:css>`/`<theme:js>`、`Weline\\Theme\\Block\\Partials`、主题 README 和主题规范更新。模板优先静态标签，CSS 优先主题变量，不要再引入过时的 `theme.json` 认知。
globs:
  - "**/view/**/*.phtml"
  - "**/view/**/*.js"
  - "**/view/**/*.css"
  - "**/view/theme/**/*.md"
  - "**/view/theme/**/modules.json"
  - "**/theme/**/*"
  - "**/statics/**/*.css"
  - "**/statics/**/*.js"
alwaysApply: false
---

# theme-development

## 快速路由

- 先重读 `dev/ai/global-constraints.md`
- 目录结构、命名、scope、配置来源、扫描与回退规则：读 `references/theme-structure.md`
- `@meta` / `@param` / `@widget` 注释、模板可用变量、标签写法：读 `references/theme-metadata.md`
- 如果任务还涉及 Block / Taglib / Widget 的 PHP 实现或注册，再补读 `dev/ai/skills/frontend-components/SKILL.md`

## 必做

- 先分清 area：`frontend` 和 `backend` 目录各自独立，不要把后台变量、前台 partial 或后台 widget 混写
- `layouts/` 遵循 `layouts/{layoutType}/{option}.phtml`
  - 控制器可以只传 `layoutType`
  - 也可以传 `layoutType.option`
  - option 还可能被主题配置和预览 scope 覆盖
- `partials/` 遵循 `partials/{type}/{option}.phtml`
  - 优先通过 `Weline\Theme\Block\Partials` 加载
  - 不要在布局里手写 ObjectManager 拼 partial 路径
- `widgets/` 遵循 `widgets/{type}/{code}/default.phtml`
  - 路径、`@widget.type`、`@widget.code`、目录名必须一致
  - 涉及 widget 清单时同步检查 `extends/module/Weline_Widget/Weline_Theme/widget.php`
- `variables/_*.css` 放 token，`colors/_*.css` 放整套色盘覆盖
  - 两类文件都要补 `@meta.name` 和 `@meta.description`
  - CSS 优先使用主题变量；新增颜色先落变量或色盘，不要直接散落到组件
- `assets/css/theme.css` 和 `assets/js/theme.js` 只放 area 级公共资源
  - 强绑定单个 layout / partial / widget、且需要被布局资源收集器提取的 `<style>` / `<script>` 可以保留内联
  - 同一段样式或脚本如果会跨文件复用，就上提到 `assets/` 或公共 partial
- `config/modules.json` 只负责 JS 模块路径和别名
  - 主题布局、色盘、partials、variables、scope 配置走 `ThemeConfigManager` / `ConfigLoader` / `ThemeData`
  - 不要再新增或继续文档化 `theme.json`
- 用户可见文案用 `__()`、`<lang>`、`@lang`；弹窗/确认用 BackendToast / BackendConfirm
- CSS 禁止硬编码颜色；JS 保持 IIFE 闭包，避免全局污染
- 组件或 widget 的 CSS 选择器要有明确作用域前缀，避免 `.card`、`.header` 这种裸类名污染
- **【硬性】自定义标签（非 HTML）属性禁止 PHP**
  - 范围：`<w:...>`、`<w:module:...>` 等 Taglib / 自定义标签
  - 用 `@lang`、`@var`、`{{}}`、`@static`、`@url`、`@backend-url` 等静态标签表达
  - 普通 HTML 是否允许 `<?=` 以模板规范为准；自定义标签属性一律按本条执行

## 模板与元数据

- layout / partial / component / variable / color 文件：
  - 至少补 `@meta.name`
  - 至少补 `@meta.description`
  - 有运行时参数就补 `@param.xxx`
- widget 文件：
  - 至少补 `@widget.code`
  - 至少补 `@widget.name`
  - 至少补 `@widget.description`
  - 至少补 `@widget.type`
  - 至少补 `@widget.area`
  - 有参数就补 `@param.xxx`
- `.phtml` 展示层能不用 `<?php` / `<?=` 就不用
- 优先使用：
  - `{{ ... }}`
  - `@lang{...}` / `@lang(...)`
  - `@static(...)`
  - `@url(...)`
  - `@backend-url(...)`
  - `@var(...)`
  - `<theme:css>...</theme:css>`
  - `<theme:js>...</theme:js>`
- 布局和片段里优先复用 `<w:block class="Weline\Theme\Block\Partials" .../>`
- 可编辑内容和容器优先用 `<w:slot>`、`<w:widget>` 表达，不要把 slot 能力写死在普通 HTML 结构里

## 检查清单

- 目录命名是否符合扫描器预期
- `@meta.*` / `@param.*` / `@widget.*` 是否完整
- `frontend` / `backend` area 是否写对
- `modules.json` 是否只承担模块配置职责
- 样式是否走变量或色盘，而不是新增裸色
- 布局、片段、部件是否保留合理的 hook / slot / partial 复用
- README 是否与真实目录和现有实现一致，而不是复述旧结构

## 禁止

- 把 `modules.json` 当成主题样式配置文件
- 新写 `theme.json`，或继续在文档里声称 `view/theme/**/config/theme.json` 是当前配置入口
- 在多个 layout / partial / widget 里复制同一份基础 CSS / JS
- 在任意自定义标签属性里写 `<?=` / `<?php`
- 能用静态标签却堆一整段 `<?php` 做字符串拼接
- 沿用旧 README 里的 layout 名称、partial 清单或目录结构覆盖真实实现
