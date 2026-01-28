# Satta King 786 (sattaking) 模板

## 📋 模板概述

这是一个深紫蓝渐变风格的现代信息平台主题，基于 https://www.insattaking786.com/ 网站内容创建。模板包含所有必要的元素，并且每个元素都可以通过可视化配置进行自定义。

### 🎯 适用场景

- 信息展示平台
- 数据查询类网站
- 应用下载落地页
- 内容聚合平台
- 企业/产品展示页

### ✨ 核心特性

1. **完全可配置** - 所有文本、颜色、尺寸都可以通过后台配置
2. **深紫蓝渐变风格** - 现代化的视觉设计
3. **响应式设计** - 完美适配移动端、平板和桌面
4. **组件化架构** - 支持可视化拖拽组装
5. **交互式FAQ** - 可展开/收起的FAQ区域
6. **多区块支持** - 头部、内容、底部三大区域

## 🏗️ 模板结构

### 文件组成

```
sattaking/
├── header.phtml       - 头部区域（导航）
├── content.phtml      - 内容区域（完整模板）
├── footer.phtml       - 页脚区域
├── layout.phtml       - 主布局文件
├── readme.md          - 本文档
├── colors/            - 色系配置目录
│   └── default.phtml  - 默认色系（深紫蓝渐变）
├── components/        - 可视化组件目录
│   ├── component.json - 组件配置清单
│   ├── header/
│   │   └── nav.phtml  - 导航栏组件
│   ├── content/
│   │   ├── hero.phtml       - 首屏英雄区
│   │   ├── features.phtml   - 特性展示
│   │   ├── app-download.phtml - 应用下载
│   │   ├── platform.phtml   - 平台概述
│   │   ├── games.phtml      - 游戏/市场展示
│   │   ├── benefits.phtml   - 用户优势
│   │   └── faq.phtml        - 常见问题
│   └── footer/
│       └── links.phtml      - 页脚链接
└── layouts/           - 布局配置目录
    └── default/
        ├── home_page.json   - 首页布局
        └── custom_page.json - 自定义页面布局
```

## 🧩 组件系统

### 可用组件

| 组件 | 文件 | 说明 |
|------|------|------|
| 导航栏 | header/nav.phtml | Logo、导航链接、CTA按钮 |
| 首屏英雄区 | content/hero.phtml | 标题、描述、CTA按钮、特性标签 |
| 特性展示 | content/features.phtml | 4列布局展示核心功能 |
| 应用下载 | content/app-download.phtml | 下载按钮、安装指南 |
| 平台概述 | content/platform.phtml | 数据结构和特性展示 |
| 游戏展示 | content/games.phtml | 热门游戏/市场卡片 |
| 用户优势 | content/benefits.phtml | 2列布局优势展示 |
| FAQ | content/faq.phtml | 手风琴式问答 |
| 页脚链接 | footer/links.phtml | 多列链接、品牌信息、版权 |

### 使用可视化构建器

1. 进入页面编辑界面
2. 点击"可视化配置"按钮
3. 从右侧组件库拖拽组件到页面
4. 点击组件可以编辑配置
5. 保存布局

## 🎨 配色方案

主题采用深紫蓝渐变配色：

- **主背景**: #0c0c1e (深蓝黑)
- **渐变背景**: #08081a → #1a1a3e
- **主强调色**: #7c3aed (紫色)
- **次强调色**: #3b82f6 (蓝色)
- **文字主色**: #ffffff
- **文字次色**: #a0a0c0

## 📱 响应式设计

模板采用响应式设计，支持以下断点：

- **移动端**: < 768px
- **平板**: 768px - 1024px
- **桌面**: > 1024px

## 🎯 使用说明

### 1. 创建页面

1. 进入后台：**内容管理 > 页面构建器**
2. 点击"新建页面"
3. 选择样式模板：**sattaking**
4. 填写页面基本信息

### 2. 配置组件

在可视化编辑器中：
- 拖拽组件到页面
- 点击组件打开配置面板
- 修改文本、颜色、显示设置
- 保存配置

### 3. 预览和发布

- 点击"预览"按钮查看效果
- 确认无误后，设置状态为"已发布"

## ✅ 支持的页面类型

- 首页 (home_page)
- 自定义页面 (custom_page)
- 关于我们 (about_page)
- 联系我们 (contact_page)
- 博客列表 (blog_list)
- 博客文章 (blog_post)
- 隐私政策 (privacy_policy)
- 服务条款 (terms_of_service)

---

**模板路径**: `GuoLaiRen_PageBuilder::style/sattaking`

**创建日期**: 2025-01-27

**版本**: 1.0.0
