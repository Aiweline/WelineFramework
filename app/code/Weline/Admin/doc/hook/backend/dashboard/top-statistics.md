# 后台首页顶部统计卡片（Weline_Admin::backend::layouts::dashboard::top-statistics）

**位置**：后台首页（`Weline_Admin::system_dashboard`）顶部第一行统计卡片区域。  
**Hook 名称**：`Weline_Admin::backend::layouts::dashboard::top-statistics`

## 作用

- 在后台首页顶部渲染一组统计卡片，用于展示系统关键指标。  
- 像素统计模块（`Weline_Visitor`）可以在这里输出“真实访客像素统计”的概览数据。  
- 如果没有任何模块实现该 Hook，将使用后台内置的示例卡片作为占位内容。

## 使用建议

- 单个实现文件即可覆盖整个区域，按栅格系统输出多张卡片。  
- 建议卡片风格与 Admin 主题保持一致（使用 `card` / `dashboard-card` 等样式）。  
- 适合展示：
  - 总像素事件数 / 当天事件数
  - 已处理 / 待处理像素记录
  - 站点数量、转化相关指标等

