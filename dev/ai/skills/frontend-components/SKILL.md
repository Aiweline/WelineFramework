---
name: frontend-components
description: Block、Taglib、Widget、DataTable。Block 继承+$_template+__init；TaglibInterface+taglib:collect；widget.php+<w:widget>；<w:d-table> 列表。
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

## 禁止

- Block 不调 parent::__init()；Taglib 不 taglib:collect；Widget 不注册就用；敏感字段不过滤
