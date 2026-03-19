---
name: frontend-components
description: Block、Taglib、Widget、DataTable。模板优先 {{}}/@lang/@static/@url/@backend-url/@var；Block 继承+$_template+__init；Taglib+widget+<w:d-table>。
globs:
  - "**/Block/**/*.php"
  - "**/view/**/*.phtml"
alwaysApply: false
---

# frontend-components（极简版·Block+Taglib+Widget+DataTable）

## 何时使用

- Block、Taglib、Widget、DataTable、<w:d-table>、<w:widget>、自定义标签

## 1) Block

- 继承 Block；$_template 格式 `模块名::路径.phtml`；__init 必调 parent::__init()；assign/getData

## 2) Taglib

- 实现 TaglibInterface：name/tag/attr/callback；taglib:collect 收集；callback 返回渲染结果

## 3) Widget

- extends/module/Weline_Widget/Vendor_Module/ 下 widget.php（type/code/name/block/template）；可选 param_schema；模板 widgets/{type}/{code}.phtml；`<w:widget type="x" code="y"/>`

## 4) DataTable

- `<w:d-table model="ModelClass" scope="xxx">`；<w:t-header>、<w:t-filter>；<w:field belong="t-header" name="x" sortable="true">；分页用框架 pagination

## 模板（与 theme-development 一致）

- `.phtml` 少写 `<?php`/`<?=`：优先 `{{}}`、`@lang{}`、`@static()`、`@url()`、`@backend-url()`、`@var()`、`@api()`（项目若有）等静态标签；细则见 `dev/ai/skills/theme-development/SKILL.md`。
- **【硬性】自定义标签（非 HTML，含 `<w:...>` / Taglib 输出名）**：**属性上禁止使用 PHP**，只能静态标签或文档允许的纯变量名；见 `theme-development` 同条。

## 禁止

- Block 不调 parent::__init()；Taglib 不 taglib:collect；Widget 不注册就用；敏感字段不过滤
- **自定义（非 HTML）标签属性写 `<?=`/`<?php`** → 禁止；改用 `@lang`/`@var`/`{{}}`/URL 类静态标签，或 taglib 允许的变量名 + 标签前 `<?php` 赋值
