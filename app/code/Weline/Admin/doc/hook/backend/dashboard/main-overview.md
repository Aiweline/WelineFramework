# 后台首页主要概览（Weline_Admin::backend::layouts::dashboard::main-overview）

**位置**：后台首页（`Weline_Admin::system_dashboard`）中部主要概览区域。  
**Hook 名称**：`Weline_Admin::backend::layouts::dashboard::main-overview`

## 作用

- 在后台首页中部输出系统的核心业务概览内容。  
- 像素统计模块（`Weline_Visitor`）可以在这里展示：
  - 最近 N 天的趋势数据（事件数、价值等）
  - 热门事件 Top N
  - 站点粒度的统计表格或可视化图表
- 如果没有任何模块实现该 Hook，将使用后台内置的示例图表和列表作为占位内容。

## 使用建议

- 推荐使用两栏或三栏布局（如 `col-xl-8` + `col-xl-4`）组织趋势图和排行列表。  
- 前端脚本建议通过 `@lang` / `<lang>` 做国际化提示文案。  
- 数据计算逻辑尽量下沉到 Service 层，模板只负责渲染与简单处理。

