# Weline Default Theme

Weline 默认主题 - 现代简约风格，响应式设计，支持 `system / light / dark`。

## 全局颜色契约

此主题只提供品牌 Token 覆盖：前台蓝色位于 `frontend/colors/_light.css` 与 `_dark.css`，后台紫蓝色位于 `backend/colors/_light.css` 与 `_dark.css`。Bootstrap/Weline 组件 CSS、主题运行时和切换逻辑均由 `Weline_Theme` 固定资源提供，因此业务页面、Card 和后台组件无需也不得添加页面级暗色补丁。

兼容别名 `--theme-*`、`--admin-*` 仍可使用，但必须指向语义 Token；不可在此主题新增同路径 `assets/css/theme.css` 或 `assets/js/theme.js`。

## 目录结构

```
app/design/Weline/default/
├── register.php          # 主题注册文件
├── theme.xml             # 主题配置文件
├── README.md             # 说明文档
├── assets/               # 公共资源
│   └── images/
│       └── logo.png      # Logo 图片（透明背景）
├── frontend/             # 前端品牌 Token
│   └── colors/
│       ├── _light.css
│       └── _dark.css
└── backend/              # 后端主题
    └── colors/
        ├── _light.css
        └── _dark.css
```

## 功能特性

### 前端主题
- 🎨 现代简约设计风格
- 📱 完全响应式布局
- 🌙 支持亮色/暗色模式自动切换
- 🚀 轻量级 CSS 变量系统
- ✨ 平滑动画效果
- 📦 按钮、卡片、表单、提示等通用组件能力由 `Weline_Theme` 全局 adapter 提供；本主题仅提供品牌 Token

### 后端主题
- 🎯 专业后台管理界面
- 📊 仪表盘布局优化
- 🗂️ 可折叠侧边栏
- 🔔 Toast、确认框和通用组件能力由 `Weline_Theme` 固定资源提供；本主题不复制实现
- 💬 确认对话框
- 📋 表格样式优化

## CSS 变量

### 前端变量
```css
:root {
    --theme-primary: #3b82f6;
    --theme-secondary: #64748b;
    --theme-success: #22c55e;
    --theme-warning: #f59e0b;
    --theme-danger: #ef4444;
    /* ... */
}
```

### 后端变量
```css
:root {
    --admin-primary: #6366f1;
    --admin-secondary: #64748b;
    --admin-sidebar-width: 260px;
    --admin-header-height: 60px;
    /* ... */
}
```

## JavaScript API

### 前端 Toast
```javascript
Weline.Toast.success('操作成功');
Weline.Toast.error('操作失败');
Weline.Toast.warning('警告信息');
Weline.Toast.info('提示信息');
```

兼容旧模块的 `window.Toast` 也映射到同一 `ThemeNotice` 实现；已有有效实现不会被覆盖。

### 后端 Toast
```javascript
BackendToast.success('保存成功');
BackendToast.error('删除失败');
```

兼容旧模块的 `window.AdminToast` 映射到 `BackendToast`；已有有效实现不会被覆盖。

### 后端确认框
```javascript
const confirmed = await AdminConfirm.show('确定要删除吗？', {
    title: '删除确认',
    confirmText: '删除',
    cancelText: '取消'
});
if (confirmed) {
    // 执行删除
}
```

## 主题切换

主题支持三态模式切换，使用以下声明式控件即可：

```html
<!-- 前端 -->
<select data-weline-theme-mode aria-label="颜色模式">
    <option value="system">跟随系统</option>
    <option value="light">亮色</option>
    <option value="dark">暗色</option>
</select>

<!-- 后端 -->
<button type="button" data-weline-backend-theme-mode="system" data-weline-theme-mode="system">跟随系统</button>
```

## 自定义扩展

1. 覆盖 CSS 变量：
```css
:root {
    --color-primary: #your-color;
}
```

2. 覆盖语义 Token：
在 `frontend/colors/` 或 `backend/colors/` 下创建 `_light.css`/`_dark.css`，不可覆盖核心组件文件。

## 版本历史

- **1.0.1** - 仅保留前后台品牌 Token，接入全局三态颜色契约。
- **1.0.0** - 初始版本。

## 作者

Weline Team - [aiweline.com](https://aiweline.com)

## 许可

MIT License
