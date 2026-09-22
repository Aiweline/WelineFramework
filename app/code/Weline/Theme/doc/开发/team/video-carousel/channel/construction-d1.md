# channel: construction-d1

## msg-1 | 2026-09-21T22:22:00+08:00 | from:后端·Widget | to:* | thread:construction-d1 | kind:handoff
agent_id: backend-widget-d1
body:
D1 closed。ArrayType 项内 `product_picker` 已接通。

交付：
1. `app/code/Weline/Widget/Ui/ParamType/ArrayType.php` — `renderItemField` 增 `case 'product_picker'`，委托 `ProductPickerType::getHtml`；类缺失/异常降级文本 input（带 `data-field`）；sync hidden 去 name、打 `data-field="{fieldKey}"`；值=逗号分隔 product id。
2. 主题编辑器数组 fallback 同步认 `product_picker`（避免退化为裸文本）：
   - `Theme/view/statics/js/theme-editor.js`
   - `Theme/view/statics/ui/pages/weline-theme-editor.js`
3. `Widget/view/statics/js/widget-param-types.js` — 数组新增项时 `initProductAdminPickers(div)`。
4. 合同测：`Widget/test/Unit/Ui/ParamType/ArrayTypeProductPickerContractTest.php` — OK (2 tests, 20 assertions)。

未改 Theme video 部件 / ParamSchema / layout（留给其它席）。

下游：D3 ParamSchema 项字段可配完整选品体验。
result=closed
---
