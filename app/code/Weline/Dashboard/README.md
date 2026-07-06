# Weline_Dashboard

`Weline_Dashboard` 是后台 Theme 的特殊 Dashboard 布局能力，不单独实现一套布局引擎。

核心边界：

- 站点是最大数据观察范围。
- Dashboard 视图按站点保存。
- `system` 视图是系统默认共享视图。
- `private` 视图仅创建者可见。
- `public` 视图同站点后台用户可见，默认只有创建者可编辑，其他用户可复制。
- Dashboard Widget 只允许后台使用。
- 后台侧栏只保留仪表盘入口；需要进入后台面板的信息，优先注册 Dashboard 统计/图表/表格部件。
- `website_id = 0` 是 `Weline_Websites` 在框架安装时自动创建的系统默认站点，Dashboard 必须把它当作合法站点，而不是“未选择站点”“无站点”或无效 ID。

## 布局映射

Dashboard 视图映射到 `theme_layout`：

```text
page_type     = dashboard
layout_option = default
target_type   = website
target_id     = website_id（默认站点为 0）
scope         = dashboard_view:{view_id}
```

当安装或升级时站点表为空，`Weline_Websites` 会先自动补齐 `website_id=0/code=default` 的零号默认站点，Dashboard 再为该站点创建 `system/default` 默认视图。因此后台 Dashboard 不应因站点列表为空显示“当前没有可用站点，无法初始化 Dashboard”。Dashboard 相关查询、布局身份、Theme target 和 Widget 注入都必须把 `target_id=0` 视为系统默认站点，而不是空值。

Dashboard 不提供自由画布，而是固定几个后台报表区域 slot：

```html
<w:slot id="dashboard-summary"  accept="dashboard-slot-summary,dashboard-stat,dashboard-kpi"   position="dashboard-summary" />
<w:slot id="dashboard-analysis" accept="dashboard-slot-analysis,dashboard-chart,dashboard-trend" position="dashboard-analysis" />
<w:slot id="dashboard-side"     accept="dashboard-slot-side,dashboard-status,dashboard-list"    position="dashboard-side" />
<w:slot id="dashboard-detail"   accept="dashboard-slot-detail,dashboard-table,dashboard-list"   position="dashboard-detail" />
```

v1 使用固定区域 + 区域内排序，不做自由定位。Widget 的区域内展示配置可放在 `theme_layout.config`：

```json
{
  "dashboard_layout": {
    "colSpan": 3,
    "rowSpan": 1,
    "sortOrder": 10
  }
}
```

默认 Dashboard 部件只在普通文件 Widget 第一次写入 `widget_registry_entry` 且声明 `default_injections` 时自动写入一次。首次自动写入按 Dashboard 布局级别执行，同一主题、同一 `dashboard/default` 布局下已经存在的所有 Dashboard view 身份都会写入。用户在 ThemeEditor 中删除、移动、保存或保存空布局后，后续刷新注册表、打开页面、预览渲染、保存布局都不会自动补回默认部件；缺失项会保留在部件库“应用”tab 中，用户可选择“应用当前身份”或“应用全部身份”恢复建议的默认部件位置。

后台新建 Dashboard 视图会创建新的 `dashboard_view:{view_id}` 布局身份，并复制同站点默认视图当前的 draft/published 布局作为初始内容；这不是默认注入事件重放，也不会覆盖用户后续对该视图身份的独立布局配置。

## 模块创建 Dashboard 页面身份

业务模块如果需要一个同 Dashboard 布局结构、但独立保存配置的页面身份，不要直接写 `dashboard_view` 或 `theme_layout`。派发 `Weline_Dashboard::layout_page_ensure`，由 Dashboard 统一创建或复用视图，并初始化 Theme 布局身份：

```php
$eventsManager->dispatch('Weline_Dashboard::layout_page_ensure', [
    'module' => 'Vendor_Module',
    'code' => 'vendor_module_statistics',
    'name' => __('统计页面'),
    'page_type' => 'dashboard',
    'layout_type' => 'dashboard',
    'layout_option' => 'default',
    'target_type' => 'website',
    'target_id' => '*',
    'visibility' => 'system',
    'sort_order' => 20,
    'copy_default_layout' => false,
    'replace_layout' => false,
    'layout' => [
        'dashboard-summary' => [
            [
                'module' => 'Vendor_Module',
                'type' => 'stats',
                'code' => 'summary',
                'sort_order' => 10,
            ],
        ],
    ],
]);
```

事件约定：

- `target_id = '*'` 表示为全部站点创建同 code 的页面身份；也可以传单个 `website_id`。
- `code` 是模块页面身份的稳定业务码，同一站点下重复派发会复用已有视图。
- `layout` 只在页面首次创建或布局为空时写入；`replace_layout = false` 时不会覆盖运营已经保存过的布局。
- `copy_default_layout = true` 可让新页面从同站点默认 Dashboard 视图复制布局；模块提供专属页面时通常设置为 `false` 并传入自己的初始部件。
- 这是“创建布局页面身份”的公共能力，不是 Widget 默认注入；不会重放 `Weline_Widget::widget_install_after`。

`Weline_Visitor` 的事件统计页就是这个模式：模块派发事件创建 `weline_visitor_event_statistics`，页面名称为“事件统计”，并把 Visitor 的 4 个统计部件写入 `dashboard-summary`、`dashboard-analysis`、`dashboard-side`、`dashboard-detail`。

## 默认注入回归用例

默认 Dashboard 统计部件的回归用例固定在模块目录：

```bash
php bin/w e2e:run --module=Weline_Dashboard --case-id=DASHBOARD-DEFAULT-WIDGETS-001 --project=chromium --workers=1 --headless
```

完整页面渲染回归用例：

```bash
php bin/w e2e:run --module=Weline_Dashboard --case-id=DASHBOARD-DEFAULT-WIDGETS-002 --project=chromium --workers=1 --headed
```

后台页面新建视图回归用例：

```bash
php bin/w e2e:run --module=Weline_Dashboard --case-id=DASHBOARD-DEFAULT-WIDGETS-004 --project=chromium --workers=1 --headless
```

模块创建独立 Dashboard 页面身份回归用例：

```bash
php bin/w e2e:run --module=Weline_Dashboard --case-id=DASHBOARD-DEFAULT-WIDGETS-005 --project=chromium --workers=1 --headless
```

这些用例验证：

- 清理 `widget_registry_entry` 后触发真实 Widget 收集，首次入库派发 `Weline_Widget::widget_install_after`。
- Theme 按事件里的精确 Widget identity 把 `default_injections` 写入同一 Dashboard 布局下已经存在的全部身份。
- 用户清空布局后，再次刷新 Widget 不会强制补回；缺失项仍通过 ThemeEditor 的“应用”tab 提示，并提供当前身份/全部身份两个恢复范围。
- 后台 Dashboard 页面能真实渲染默认注入的 8 个统计/图表/列表部件，并放在声明的 slot 上。
- 后台 Dashboard 页面通过 `w_query('dashboard', 'createView', ...)` 新建视图时不会被 frontend worker API 拦截，新视图会继承默认视图的默认统计部件布局。
- 模块通过 `Weline_Dashboard::layout_page_ensure` 创建独立 Dashboard 页面身份时，会初始化自己的 Theme 布局配置；Visitor 的“事件统计”页会渲染 4 个 Visitor 部件，并与默认概览的布局身份隔离。

后台“应用”tab 的浏览器交互由 `Weline_Theme` 的 `THEME-DEFAULT-INJECTION-001` 和 `THEME-DEFAULT-INJECTION-002` 覆盖；Dashboard 不单独维护 ThemeEditor 交互用例。

## 注册后台 Dashboard Widget

业务模块只需要在自己的 `extends/module/Weline_Widget/{Module}/widget.php` 中注册后台 Widget：

```php
<?php

declare(strict_types=1);

return [
    'orders_today' => [
        'name' => '今日订单',
        'code' => 'orders_today',
        'type' => 'stats',
        'area' => 'backend',
        'template' => 'Vendor_Module::templates/dashboard/widgets/orders-today.phtml',
        'page_layouts' => ['dashboard'],
        'slot' => 'dashboard-summary',
        'position' => ['dashboard-summary'],
        'supports' => ['dashboard-widget', 'dashboard-slot-summary', 'dashboard-stat', 'dashboard-kpi'],
    ],
];
```

约束：

- 必须设置 `'area' => 'backend'`。
- 必须设置 `'page_layouts' => ['dashboard']`。
- 必须包含 `dashboard-widget`，并至少包含一个目标 slot 的协议码，如 `dashboard-slot-summary`、`dashboard-slot-analysis`、`dashboard-slot-side`、`dashboard-slot-detail`。
- 统计面板入口使用 `type => 'stats'`，并包含 `dashboard-stat`；不要再通过后台左侧菜单新增统计入口。
- `slot` 和 `position` 要匹配目标区域，避免统计、图表、表格、列表混放。
- 业务模块负责自己的数据查询，Dashboard 只负责视图、布局和可见性。
