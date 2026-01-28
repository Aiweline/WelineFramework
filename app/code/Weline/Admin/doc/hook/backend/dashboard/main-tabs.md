# 后台首页标签页导航（Weline_Admin::backend::layouts::dashboard::main-tabs）

**位置**：后台首页（`Weline_Admin::system_dashboard`）标签页导航区域。  
**Hook 名称**：`Weline_Admin::backend::layouts::dashboard::main-tabs`

## 作用

- 在后台首页标签页导航区域动态追加标签页按钮。  
- 像素统计模块（`Weline_Visitor`）可以在这里注册"像素统计"标签页按钮。  
- 其他模块也可以注册自己的标签页，实现功能模块化展示。

## 使用建议

- 每个实现文件应输出一个完整的 `<button>` 标签页按钮元素。  
- 按钮应使用 Bootstrap 的 `tab` 相关类名和属性（`data-bs-toggle='tab'`、`data-bs-target` 等）。  
- 按钮 ID 和 `data-bs-target` 应与对应的标签页内容面板 ID 匹配。  
- 建议按钮样式与 Admin 主题保持一致（使用 `btn dashboard-tab` 等样式类）。

## 示例

```html
<button class='btn dashboard-tab' 
        type='button'
        id='dashboard-pixel-statistics-tab'
        data-bs-toggle='tab'
        data-bs-target='#dashboard-pixel-statistics'
        role='tab'
        aria-controls='dashboard-pixel-statistics'
        aria-selected='false'>
    <span class='d-none d-sm-inline-block'>
        <lang>像素</lang>
    </span>
</button>
```
