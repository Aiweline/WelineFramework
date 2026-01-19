# WeShop Default Theme - Frontend

## 目录结构

本主题继承自 `Weline_Default` 主题，可以覆盖父主题的任何文件。

### 文件覆盖机制

1. **同名文件以激活主题为准**：如果子主题和父主题有同名文件，使用子主题的文件
2. **文件查找顺序**：
   - 当前主题（WeShop/default）
   - 父主题（Weline/default）
   - 基础模块（Weline_Theme）

### 目录说明

- `layouts/` - 布局文件（页面结构）
- `partials/` - 片段文件（可复用的页面片段）
- `components/` - 组件文件（UI组件）
- `assets/` - 静态资源（CSS、JS、图片）
- `colors/` - 色系文件（主题色系配置）
- `variables/` - CSS变量文件
- `config/` - 配置文件

### 当前文件

- `assets/js/search.js` - 搜索模块（覆盖父主题的搜索功能）

### 开发指南

1. **覆盖文件**：在对应目录创建同名文件即可覆盖父主题文件
2. **新增文件**：在对应目录创建新文件，会自动加载
3. **JS模块**：同名JS文件会覆盖父主题的版本
