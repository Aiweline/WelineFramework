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

## 布局映射

Dashboard 视图映射到 `theme_layout`：

```text
page_type     = dashboard
layout_option = default
target_type   = website
target_id     = website_id
scope         = dashboard_view:{view_id}
```

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

默认 Dashboard 部件只在模块安装/升级导致对应 `default_injections` 首次被收集时自动写入一次。用户在 ThemeEditor 中删除、移动、保存或保存空布局后，后续普通注册刷新、打开页面、预览渲染、保存布局都不会自动补回默认部件；缺失项会保留在部件库“应用”tab 中，用户点击“应用”才会恢复建议的默认部件位置。

## 默认注入回归用例

默认 Dashboard 统计部件的回归用例固定在模块目录：

```bash
php bin/w e2e:run --module=Weline_Dashboard --case-id=DASHBOARD-DEFAULT-WIDGETS-001 --project=chromium --workers=1 --headless
```

完整页面渲染回归用例：

```bash
php bin/w e2e:run --module=Weline_Dashboard --case-id=DASHBOARD-DEFAULT-WIDGETS-002 --project=chromium --workers=1 --headed
```

这些用例验证：

- 普通 `widget_refresh_command` 收集不会写入默认 Dashboard 部件。
- 模块安装/升级来源触发 `Weline_Widget::registry_refresh_after` 后，Theme 统一把 `default_injections` 写入对应 Dashboard 布局身份。
- 用户清空布局后，再次安装/升级来源不会强制补回；缺失项仍通过 ThemeEditor 的“应用”tab 提示。
- 后台 Dashboard 页面能真实渲染默认注入的 8 个统计/图表/列表部件，并放在声明的 slot 上。

后台“应用”tab 的浏览器交互由 `Weline_Theme` 的 `THEME-DEFAULT-INJECTION-001` 覆盖；Dashboard 不单独维护 ThemeEditor 交互用例。

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
