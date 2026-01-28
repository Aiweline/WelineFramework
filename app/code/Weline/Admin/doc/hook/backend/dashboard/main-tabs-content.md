# 后台首页标签页内容（Weline_Admin::backend::layouts::dashboard::main-tabs-content）

**位置**：后台首页（`Weline_Admin::system_dashboard`）标签页内容区域。  
**Hook 名称**：`Weline_Admin::backend::layouts::dashboard::main-tabs-content`

## 作用

- 在后台首页标签页内容区域动态追加标签页内容面板。  
- 像素统计模块（`Weline_Visitor`）可以在这里注册"像素统计"标签页内容面板。  
- 其他模块也可以注册自己的标签页内容，实现功能模块化展示。

## 使用建议

- 每个实现文件应输出一个完整的 `<div class='tab-pane fade'>` 标签页内容面板。  
- 面板 ID 应与对应的标签页导航按钮的 `data-bs-target` 属性值匹配（去掉 `#` 前缀）。  
- 面板应使用 Bootstrap 的 `tab-pane` 相关类名和属性。  
- 内容组织建议使用栅格系统（`row` / `col-*`）和卡片组件（`card` / `card-body`）。  
- 数据计算逻辑尽量下沉到 Service 层，模板只负责渲染与简单处理。

## 示例

```html
<div class='tab-pane fade' id='dashboard-pixel-statistics' role='tabpanel' aria-labelledby='dashboard-pixel-statistics-tab'>
    <!-- 标签页内容 -->
    <div class='row'>
        <!-- 统计卡片、图表等内容 -->
    </div>
</div>
```
