# FinTech Hub (fintech-hub) 模板

## 模板概述

金融科技落地页模板，采用皇家蓝 + 金色暗色主题，适合金融平台、投资服务、支付系统推广。品牌名称 VaultPay，盾牌图标代表安全可信。

### 适用场景

- 金融科技产品官网
- 支付平台落地页
- 投资服务推广页
- 数字银行展示页
- 企业级金融解决方案

### 核心特性

1. **完全可配置** - 所有文本、颜色、尺寸都可以通过后台配置
2. **皇家蓝金暗色主题** - 专业、可信赖的金融科技风格
3. **响应式设计** - 完美适配移动端、平板和桌面
4. **组件化架构** - 支持可视化拖拽组装
5. **交互式FAQ** - 可展开/收起的FAQ区域
6. **三大区域** - 头部、内容、底部独立管理

## 模板结构

### 文件组成

```
fintech-hub/
├── header.phtml       - 头部区域（导航 + 像素统计）
├── content.phtml      - 内容区域渲染器
├── footer.phtml       - 页脚区域（4列链接）
├── layout.phtml       - 主布局文件
├── readme.md          - 本文档
├── colors/            - 色系配置目录
│   └── default.phtml  - 默认色系（皇家蓝金暗色）
├── components/        - 可视化组件目录
│   ├── component.json - 组件配置清单
│   ├── header/
│   │   └── nav.phtml  - 导航栏组件
│   ├── content/
│   │   ├── hero.phtml          - 首屏英雄区
│   │   ├── features.phtml      - 核心优势（6卡片）
│   │   ├── stats.phtml         - 平台数据（4指标）
│   │   ├── services.phtml      - 服务方案（3列）
│   │   ├── testimonials.phtml  - 客户评价（3列）
│   │   └── faq.phtml           - 常见问题（手风琴）
│   └── footer/
│       └── links.phtml         - 页脚链接（4列）
└── layouts/           - 布局配置目录
    └── default/
        ├── home_page.json   - 首页布局
        └── custom_page.json - 自定义页面布局
```

## 组件系统

### 可用组件

| 组件 | 文件 | 说明 |
|------|------|------|
| 导航栏 | header/nav.phtml | Logo（盾牌）、导航链接、CTA按钮 |
| 首屏英雄区 | content/hero.phtml | 标题、描述、CTA、统计数据 |
| 核心优势 | content/features.phtml | 6张卡片展示平台优势 |
| 平台数据 | content/stats.phtml | 4个大型指标卡片 |
| 服务方案 | content/services.phtml | 个人/商务/企业三列方案 |
| 客户评价 | content/testimonials.phtml | 3条金融专业人士评价 |
| FAQ | content/faq.phtml | 6个常见问题手风琴 |
| 页脚链接 | footer/links.phtml | 品牌信息 + 4列链接 |

## 配色方案

主题采用皇家蓝 + 金色暗色配色：

- **主背景**: #080b16 (深海蓝黑)
- **主强调色**: #2563eb (皇家蓝)
- **次强调色**: #f59e0b (金色/琥珀)
- **文字主色**: #f8fafc (近白)
- **文字次色**: #94a3b8 (蓝灰)
- **卡片背景**: rgba(255,255,255,0.03)
- **徽章背景**: rgba(37,99,235,0.15)

## 响应式设计

模板采用响应式设计，支持以下断点：

- **移动端**: < 576px
- **平板**: 576px - 992px
- **桌面**: > 992px

## CSS 前缀

所有 CSS 类名使用 `fh-` 前缀：

- `fh-container` - 容器
- `fh-section` - 区域
- `fh-card` - 卡片
- `fh-btn` - 按钮
- `fh-grid` - 网格

## 辅助函数

- `fh_color($colors, $key, $default)` - 获取颜色值
- `fh_getConfig($settings, $key, $default)` - 获取配置值

---

**模板路径**: `GuoLaiRen_PageBuilder::style/fintech-hub`

**创建日期**: 2026-02-06

**版本**: 1.0.0
