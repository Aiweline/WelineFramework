# Blog 博客模板

## 模板概述

这是一个专为博客系统设计的模板，支持博客文章详情页、博客分类页和博客列表页。

SUPPORTED_TYPES: blog_post, blog_category, blog_list

### 适用场景

- 博客文章详情展示
- 博客分类文章列表
- 博客文章列表/归档页
- 新闻/资讯类内容展示

### 核心特性

1. **响应式设计** - 完美适配移动端、平板和桌面
2. **SEO优化** - 针对博客内容优化的结构化数据
3. **文章卡片布局** - 美观的文章列表展示
4. **分类导航** - 支持分类筛选和面包屑导航
5. **社交分享** - 支持社交媒体分享功能
6. **阅读体验** - 优化的文章阅读排版

## 模板结构

```
blog/
├── header.phtml   - 头部区域（导航 + 面包屑）
├── content.phtml  - 内容区域（文章列表/详情）
├── footer.phtml   - 页脚区域（版权 + 链接）
└── readme.md      - 本文档
```

## 配置项说明

### 布局配置 (Layout)

- **layout.max_width**: 内容最大宽度
- **layout.sidebar**: 是否显示侧边栏 (yes/no)
- **layout.posts_per_page**: 每页显示文章数

### 文章列表配置 (Posts)

- **posts.style**: 列表样式 (grid/list)
- **posts.columns**: 网格列数 (2/3/4)
- **posts.show_excerpt**: 是否显示摘要
- **posts.show_date**: 是否显示日期
- **posts.show_author**: 是否显示作者
- **posts.show_category**: 是否显示分类

### 文章详情配置 (Article)

- **article.show_cover**: 是否显示封面图
- **article.show_author**: 是否显示作者信息
- **article.show_date**: 是否显示发布日期
- **article.show_tags**: 是否显示标签
- **article.show_share**: 是否显示分享按钮
- **article.show_related**: 是否显示相关文章

### 侧边栏配置 (Sidebar)

- **sidebar.show_categories**: 是否显示分类列表
- **sidebar.show_recent**: 是否显示最近文章
- **sidebar.show_tags**: 是否显示标签云

### 样式配置 (Style)

- **style.primary_color**: 主色调
- **style.text_color**: 文字颜色
- **style.link_color**: 链接颜色
- **style.bg_color**: 背景颜色

## 使用说明

### 1. 创建博客列表页

1. 进入后台：**内容管理 > 页面构建器**
2. 点击"新建页面"
3. 选择页面类型：**博客列表**
4. 选择样式模板：**blog**
5. 配置样式选项

### 2. 创建博客分类页

1. 选择页面类型：**博客分类**
2. 选择样式模板：**blog**
3. 在样式配置中设置关联的分类

### 3. 博客数据集成

模板会自动从 `GuoLaiRen_Blog` 模块获取博客数据：
- 文章列表 (`$blog_posts`)
- 分类列表 (`$blog_categories`)
- 当前分类 (`$current_category`)
- 当前文章 (`$current_post`)

## 模板变量

### 列表页可用变量

- `$blog_posts` - 文章列表
- `$blog_categories` - 分类列表
- `$current_category` - 当前分类（分类页时）
- `$pagination` - 分页信息

### 详情页可用变量

- `$current_post` - 当前文章
- `$related_posts` - 相关文章
- `$prev_post` - 上一篇文章
- `$next_post` - 下一篇文章

## 版本信息

- **模板路径**: `GuoLaiRen_PageBuilder::style/blog`
- **创建日期**: 2025-01-22
- **版本**: 1.0.0
