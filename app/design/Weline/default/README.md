# Weline Default Theme

Weline 默认主题 - 现代简约风格，响应式设计，支持亮色/暗色模式。

## 目录结构

```
app/design/Weline/default/
├── register.php          # 主题注册文件
├── theme.xml             # 主题配置文件
├── README.md             # 说明文档
├── assets/               # 公共资源
│   └── images/
│       └── logo.png      # Logo 图片（透明背景）
├── frontend/             # 前端主题
│   └── assets/
│       ├── css/
│       │   └── theme.css # 前端样式
│       └── js/
│           └── theme.js  # 前端脚本
└── backend/              # 后端主题
    └── assets/
        ├── css/
        │   └── theme.css # 后端样式
        └── js/
            └── theme.js  # 后端脚本
```

## 功能特性

### 前端主题
- 🎨 现代简约设计风格
- 📱 完全响应式布局
- 🌙 支持亮色/暗色模式自动切换
- 🚀 轻量级 CSS 变量系统
- ✨ 平滑动画效果
- 📦 常用组件样式（按钮、卡片、表单、提示等）

### 后端主题
- 🎯 专业后台管理界面
- 📊 仪表盘布局优化
- 🗂️ 可折叠侧边栏
- 🔔 Toast 通知系统
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
Toast.success('操作成功');
Toast.error('操作失败');
Toast.warning('警告信息');
Toast.info('提示信息');
```

### 后端 Toast
```javascript
AdminToast.success('保存成功');
AdminToast.error('删除失败');
```

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

主题支持亮色/暗色模式切换，添加以下按钮即可：

```html
<!-- 前端 -->
<button data-theme-toggle>切换主题</button>

<!-- 后端 -->
<button data-theme-toggle>
    <i class="mdi mdi-weather-night"></i>
</button>
```

## 自定义扩展

1. 覆盖 CSS 变量：
```css
:root {
    --theme-primary: #your-color;
}
```

2. 添加自定义样式：
在 `frontend/assets/css/` 或 `backend/assets/css/` 下创建新的 CSS 文件。

## 版本历史

- **1.0.0** - 初始版本
  - 前端默认主题
  - 后端默认主题
  - 响应式布局
  - 亮色/暗色模式

## 作者

Weline Team - [aiweline.com](https://aiweline.com)

## 许可

MIT License

