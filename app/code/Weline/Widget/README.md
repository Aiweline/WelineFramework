# Weline_Widget 可视化编辑器模块

## 📋 模块概述

Weline_Widget 是一个基于 Weline Framework 的后端可视化编辑器模块，允许用户通过拖放方式组织页面，使用 `w:widget` 标签存储和渲染部件。模块采用简化架构，复用框架现有的 Block、Meta、Extends 等机制，实现轻量级、易维护的部件系统。

### 核心特性

- ✅ **部件扩展机制**：基于 Extends 系统，支持模块级和主题级部件扩展
- ✅ **可视化编辑**：拖放式编辑器，实时预览
- ✅ **w:widget 标签**：统一的部件标签，支持参数配置
- ✅ **部件管理**：自动扫描、分类管理、来源追踪
- ✅ **参数配置**：基于 widget.php 自动生成配置表单
- ✅ **AJAX 预览**：实时预览部件效果

### 技术栈

- **框架**：Weline Framework
- **部件系统**：基于 Block 和模板
- **数据存储**：Meta 模块（MetaData）
- **扩展机制**：Extends 模块
- **前端**：Bootstrap 5 + SortableJS + Vanilla JS

## 🚀 快速开始

### 1. 安装模块

```bash
# 注册模块并创建数据库表
php bin/w command:upgrade

# 清理缓存
php bin/w cache:clear -f

# 启动服务器
php bin/w s:sta
```

### 2. 访问后台

菜单位置：**内容管理 > 可视化编辑器**

### 3. 创建第一个部件

在您的模块中创建部件：

```
app/code/YourModule/extends/Weline_Widget/Weline_Widget/
└── header/
    └── default/
        ├── widget.php
        └── template.phtml
```

## 📁 模块结构

```
Weline_Widget/
├── extends.php                    # 扩展规约文件
├── extends.md                     # 扩展文档
├── register.php                   # 模块注册
├── README.md                      # 本文件
├── Service/
│   └── WidgetScanner.php          # 部件扫描服务
├── Taglib/
│   └── Widget.php                 # w:widget 标签实现
├── Model/
│   └── Page.php                   # 页面模型
├── Controller/
│   └── Backend/
│       ├── Editor.php             # 可视化编辑器控制器
│       ├── Widget.php             # 部件管理控制器
│       └── Preview.php            # AJAX 预览控制器
├── view/
│   ├── templates/Backend/
│   │   ├── Editor/
│   │   │   └── index.phtml        # 编辑器主界面
│   │   └── Widget/
│   │       └── index.phtml        # 部件管理界面
│   └── statics/
│       ├── js/
│       │   └── editor.js          # 编辑器核心 JS
│       └── css/
│           └── editor.css         # 编辑器样式
└── doc/
    ├── 快速开始.md                 # 快速入门指南
    ├── 开发指南.md                 # 开发文档
    └── API文档.md                  # API 参考
```

## 🎯 核心功能

### 1. 部件扩展机制

#### 扩展点定义

在 `extends.php` 中定义扩展点：

```php
<?php
return [
    'type' => 'module',
    'documentation' => 'extends.md',
    'extends' => [
        'Widget' => [
            'path' => 'extends/Weline_Widget/Weline_Widget/{type}/{name}',
            'type' => ['module', 'theme'],
            'description' => 'Widget 部件扩展点，用于定义可复用的页面部件',
            'required' => false,
            'multiple' => true
        ]
    ]
];
```

#### 创建部件

在您的模块中创建部件目录：

```
app/code/YourModule/extends/Weline_Widget/Weline_Widget/
└── header/                          # 部件类型
    └── default/                     # 部件名称
        ├── widget.php              # 部件规约（必需）
        ├── template.phtml          # 部件模板（必需）
        ├── Block.php               # Block 类（可选）
        └── doc.md                  # 部件文档（可选）
```

#### widget.php 规约文件

```php
<?php
declare(strict_types=1);

return [
    'name' => '默认头部',
    'description' => '标准网站头部部件，包含 Logo 和导航菜单',
    'type' => 'header',
    'version' => '1.0.0',
    'author' => 'Your Name',
    'template' => 'YourModule::widgets/header/default.phtml',
    'params' => [
        'title' => [
            'type' => 'string',
            'label' => '标题',
            'default' => '网站标题',
            'required' => true,
            'description' => '网站主标题'
        ],
        'logo' => [
            'type' => 'image',
            'label' => 'Logo',
            'default' => '',
            'required' => false,
            'description' => '网站 Logo 图片 URL'
        ]
    ]
];
```

### 2. w:widget 标签

#### 基本用法

```phtml
<!-- 基础用法 -->
<w:widget type="header" name="default" />

<!-- 带参数 -->
<w:widget type="header" name="default" params='{"title":"我的网站","logo":"/logo.png"}' />
```

#### 标签属性

| 属性 | 类型 | 必需 | 说明 |
|------|------|------|------|
| `type` | string | 是 | 部件类型（如 header, footer, content） |
| `name` | string | 是 | 部件名称（如 default, minimal） |
| `params` | JSON | 否 | 部件参数，JSON 格式字符串 |
| `id` | string | 否 | 部件实例 ID，用于编辑模式 |

### 3. 可视化编辑器

#### 编辑器界面

编辑器采用三栏布局：

- **左侧面板**：部件选择器
  - 按类型分组显示所有可用部件
  - 显示部件名称、描述、来源模块
  - 支持搜索和筛选
  
- **中间画布**：编辑区域
  - 显示当前页面的部件列表
  - 支持拖放排序
  - 每个部件显示容器（编辑模式）
  - 支持点击编辑、删除操作
  
- **右侧面板**：属性配置
  - 显示选中部件的参数配置表单
  - 根据 widget.php 自动生成表单
  - 实时预览更新

#### 使用编辑器

1. **创建新页面**
   - 点击"新建页面"
   - 填写页面标题和标识
   - 进入编辑器

2. **添加部件**
   - 在左侧面板选择部件类型
   - 点击部件添加到画布
   - 或直接拖放到画布

3. **配置部件**
   - 点击画布中的部件
   - 在右侧面板配置参数
   - 实时预览效果

4. **调整顺序**
   - 拖放部件改变顺序
   - 或使用上下箭头按钮

5. **保存页面**
   - 点击"保存"按钮
   - 页面内容以 w:widget 标签形式存储

### 4. 部件管理

#### 部件列表

访问：**内容管理 > 可视化编辑器 > 部件管理**

功能：
- 显示所有已扫描的部件
- 按模块、类型筛选
- 搜索部件
- 查看部件详情
- 查看部件文档

## 📚 API 文档

### WidgetScanner

#### scanAllWidgets()

扫描所有模块的部件。

```php
use Weline\Widget\Service\WidgetScanner;

$scanner = ObjectManager::getInstance(WidgetScanner::class);
$widgets = $scanner->scanAllWidgets();
```

#### scanWidget(string $type, string $name)

扫描指定部件。

```php
$widget = $scanner->scanWidget('header', 'default');
```

### w:widget 标签

#### 在模板中使用

```phtml
<w:widget type="header" name="default" params='{"title":"我的网站"}' />
```

### Page 模型

#### 创建页面

```php
use Weline\Widget\Model\Page;

$page = ObjectManager::getInstance(Page::class);
$page->setData('title', '首页')
     ->setData('handle', 'homepage')
     ->setData('content', '<w:widget type="header" name="default" />')
     ->save();
```

## 🔧 开发指南

### 创建自定义部件

#### 步骤 1：创建目录结构

```
app/code/YourModule/extends/Weline_Widget/Weline_Widget/
└── your_type/
    └── your_name/
        ├── widget.php
        └── template.phtml
```

#### 步骤 2：编写 widget.php

参考上面的 widget.php 示例。

#### 步骤 3：编写模板

参考上面的模板示例。

#### 步骤 4：测试部件

1. 运行 `php bin/w cache:clear -f` 清理缓存
2. 在编辑器中查看部件是否出现
3. 添加部件到页面测试

## 📖 更多文档

- [快速开始指南](extends.md)
- [扩展文档](extends.md)

## 🔗 相关模块

- **Weline_Framework**：核心框架
- **Weline_Extends**：扩展机制
- **Weline_Meta**：元数据管理
- **Weline_Taglib**：标签系统
- **Weline_Theme**：主题系统（可参考 Partials 实现）

## 📝 版本信息

- **当前版本**：1.0.0
- **最低框架版本**：1.0.0
- **PHP 版本要求**：8.1+

## 👥 贡献

欢迎提交 Issue 和 Pull Request！

## 📄 许可证

遵循 Weline Framework 许可证。

---

**提示**：首次使用前请确保已安装并配置好 Weline Framework 和相关依赖模块。

