# TPMST 布局模板系统

本目录包含 Teen Patti Master (tpmst) 模板的布局文件，用于不同页面类型的渲染。

## 目录结构

```
layouts/
├── layouts.json          # 布局配置索引文件
├── README.md             # 本文档
├── default/              # 默认布局 JSON 配置
│   ├── home.json         # 首页默认布局
│   ├── blog-list.json    # 博客列表默认布局
│   ├── blog-category.json # 博客分类默认布局
│   ├── blog-post.json    # 博客文章默认布局
│   ├── about.json        # 关于页面默认布局
│   ├── contact.json      # 联系页面默认布局
│   ├── legal.json        # 法律文档默认布局
│   └── custom.json       # 自定义页面默认布局
├── home.phtml            # 首页布局模板
├── blog-list.phtml       # 博客列表布局模板
├── blog-category.phtml   # 博客分类布局模板
├── blog-post.phtml       # 博客文章布局模板
├── about.phtml           # 关于页面布局模板
├── contact.phtml         # 联系页面布局模板
├── legal.phtml           # 法律文档布局模板
└── custom.phtml          # 自定义页面布局模板
```

## 页面类型映射

| 页面类型 | 布局文件 | 默认配置 |
|---------|---------|----------|
| home_page | home.phtml | home.json |
| about_page | about.phtml | about.json |
| contact_page | contact.phtml | contact.json |
| privacy_policy | legal.phtml | legal.json |
| terms_of_service | legal.phtml | legal.json |
| refund_policy | legal.phtml | legal.json |
| shipping_policy | legal.phtml | legal.json |
| blog_list | blog-list.phtml | blog-list.json |
| blog_category | blog-category.phtml | blog-category.json |
| blog_post | blog-post.phtml | blog-post.json |
| custom_page | custom.phtml | custom.json |

## 布局继承规则

### Header/Footer 继承

所有非首页页面的 header 和 footer 均从首页 (home_page) 继承：

- 在 `default/*.json` 中，header 和 footer 数组为空表示继承首页配置
- 系统通过 `LayoutOwnerResolver` 自动处理继承逻辑
- 子页面修改 header/footer 时，实际修改的是首页的配置

### 内容区域

- 每种页面类型有自己的默认 content 组件配置
- 用户可以在可视化编辑器中自由添加/删除/重排组件
- 当 content 为空时，布局模板会渲染页面的默认内容

## 使用方式

### 1. 获取页面布局

```php
// 通过 LayoutOwnerResolver 获取完整布局配置
$layoutConfig = $layoutOwnerResolver->getFullLayoutConfig($page);
```

### 2. 渲染布局

```php
// PageRenderService 会根据页面类型自动选择对应的布局模板
$html = $pageRenderService->render($page, 'live', 'en');
```

### 3. 加载默认布局

```php
// 读取默认布局配置
$defaultLayoutPath = "layouts/default/{$pageType}.json";
$defaultLayout = json_decode(file_get_contents($defaultLayoutPath), true);
```

## 布局模板变量

每个布局模板可用的变量：

| 变量名 | 类型 | 描述 |
|--------|------|------|
| $page | Page | 页面模型对象 |
| $layout_config | array | 布局配置数组 |
| $style_setting | array | 样式配置数组 |
| $mode | string | 渲染模式 (visual/preview/live) |
| $lang | string | 当前语言代码 |

### 博客相关变量

| 变量名 | 适用布局 | 描述 |
|--------|---------|------|
| $blog_posts | blog-list, blog-category | 文章列表 |
| $blog_categories | 所有博客布局 | 分类列表 |
| $current_category | blog-category | 当前分类 |
| $current_post | blog-post | 当前文章 |
| $related_posts | blog-post | 相关文章 |
| $recent_posts | 所有博客布局 | 最新文章 |

## 解析后的 HTML

解析后的 HTML 文件存储在 `parsed/layouts/` 目录：

- 用于前端预览和参考
- 展示最终渲染的 HTML 结构
- 可用于静态化缓存

## 扩展新布局

1. 在 `layouts.json` 中添加新布局配置
2. 在 `default/` 中创建默认 JSON 配置
3. 创建 `.phtml` 布局模板文件
4. 在 `parsed/layouts/` 中添加解析后的 HTML 示例
5. 在 `Page` 模型中添加对应的页面类型常量
