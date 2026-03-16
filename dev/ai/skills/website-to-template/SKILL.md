---
name: website-to-template
description: 将外部网站转换为 PageBuilder 模板/主题。克隆设计、HTML 转组件、分析结构拆分。
globs: []
alwaysApply: false
---

# website-to-template（极简版）

## 何时使用

- 模仿/克隆现有网站设计
- 基于外部网站创建新主题
- 将 HTML 模板转为 PageBuilder 组件

## 必做

- 分析目标网站结构、配色、组件
- 按 PageBuilder 目录结构组织（components/、layouts/、colors/）
- 提取颜色到 colors/default.phtml
- 拆分 header、content、footer 等组件

## 最小示例

```
style/{theme_name}/
├── components/header/nav.phtml
├── components/content/hero.phtml
├── colors/default.phtml
└── layouts/default/home_page.json
```

## 禁止

- 直接复制整站 HTML 不拆分
- 硬编码颜色不提取到主题变量
