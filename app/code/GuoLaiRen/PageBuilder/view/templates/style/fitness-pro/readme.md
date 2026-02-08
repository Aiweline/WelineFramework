# Fitness Pro 模板

健身运动落地页模板，活力橙色主题，适合健身房、运动品牌、健康应用推广。

## 色彩方案

- **主色**: `#f97316` (活力橙)
- **辅色**: `#ec4899` (粉色)
- **背景**: `#0f0a07` (深色)
- **文字**: `#fef3c7` / `#d6b87a`
- **CSS 前缀**: `fp-`

## 组件列表

| 组件 | 文件 | 说明 |
|------|------|------|
| header-nav | `components/header/nav.phtml` | 顶部导航栏 |
| hero | `components/content/hero.phtml` | 首屏英雄区 |
| programs | `components/content/programs.phtml` | 训练项目展示 |
| stats | `components/content/stats.phtml` | 数据成就统计 |
| testimonials | `components/content/testimonials.phtml` | 用户评价 |
| cta-banner | `components/content/cta-banner.phtml` | 行动号召横幅 |
| faq | `components/content/faq.phtml` | 常见问题手风琴 |
| footer-links | `components/footer/links.phtml` | 页脚链接 |

## 文件结构

```
fitness-pro/
├── colors/
│   └── default.phtml          # 默认色彩配置
├── components/
│   ├── component.json         # 组件注册表
│   ├── header/
│   │   └── nav.phtml          # 导航栏组件
│   ├── content/
│   │   ├── hero.phtml         # 首屏英雄区
│   │   ├── programs.phtml     # 训练项目
│   │   ├── stats.phtml        # 数据成就
│   │   ├── testimonials.phtml # 用户评价
│   │   ├── cta-banner.phtml   # 行动号召
│   │   └── faq.phtml          # 常见问题
│   └── footer/
│       └── links.phtml        # 页脚链接
├── layouts/
│   └── default/
│       ├── home_page.json     # 首页布局配置
│       └── custom_page.json   # 自定义页面布局配置
├── layout.phtml               # 主布局文件
├── header.phtml               # Header 区域渲染器
├── content.phtml              # Content 区域渲染器
├── footer.phtml               # Footer 区域渲染器
└── readme.md                  # 本文档
```

## 技术特性

- 所有组件使用 `uniqid()` 生成唯一实例 ID，CSS 通过 `#instanceId` 选择器实现样式隔离
- 支持 `@fields_start / @fields_end` 注解，可视化编辑器自动识别可配置字段
- 所有用户输出使用 `htmlspecialchars()` 转义
- 响应式设计：992px / 768px / 576px 三个断点
- 支持颜色主题系统，通过 `colors/` 目录配置
- 组件通过 `$component_config` 接收配置，通过 `$colors` 接收主题色
