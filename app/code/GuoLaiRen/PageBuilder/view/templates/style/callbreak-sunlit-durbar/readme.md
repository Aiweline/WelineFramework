# Callbreak Sunlit Durbar 主题说明

## 概述

`callbreak-sunlit-durbar` 是一个面向 Callbreak / 棋牌类落地页的亮色主题。
主题强调：

- 更强的移动端视觉节奏
- 导航、Hero、内容模块之间的层次反差
- 适合下载转化的 CTA 表现
- About、Contact、Legal、Blog 等内页的统一主题语言

## 主题结构

```text
callbreak-sunlit-durbar/
├── header.phtml
├── content.phtml
├── footer.phtml
├── layout.phtml
├── readme.md
├── colors/
├── components/
│   ├── component.json
│   ├── header/
│   ├── content/
│   └── footer/
├── layouts/
└── asset/
```

## 首页默认模块

默认首页布局包含：

1. `header-nav`
2. `hero-slider`
3. `advantages`
4. `games`
5. `testimonials`
6. `faq`
7. `footer-links`

这些模块都支持可视化配置，并且默认带有响应式展示与滚动入场动画。

## 设计方向

主题采用亮色、高反差、暖色与薄荷色交错的视觉方向，重点不是简单换肤，而是通过：

- 异形导航与下载入口
- 更开放的 Hero 舞台构图
- 各模块不同的背景与装饰语汇
- 卡片、轨迹、标签、信号条等不同信息结构

来保持首页和内页的差异化表现。

## 可视化编辑说明

主题中的主要内容都应该通过字段驱动，包括但不限于：

- Logo、导航、下载按钮
- Hero 标题、副标题、图片、CTA
- 首页各内容模块标题、说明、卡片内容
- Contact 联系信息、表单文案、地图标题
- Legal 页面标题、更新时间文案、目录标题、正文内容
- Blog 列表、分类页、详情页相关文案

如果前端显示了某个固定文本，但在可视化编辑中无法修改，应视为需要继续修正的问题。

## 响应式目标

主题必须兼容：

- 手机
- 平板
- 桌面端

其中移动端不是简单压缩桌面布局，而是需要有单独的视觉节奏与交互层次。

## 资源与维护说明

- `parsed/` 目录为生成产物，不是源码。
- 主题源码以 `.phtml`、`component.json`、`layouts/default/*.json` 为准。
- 修改主题时优先保持字段命名、编辑器回填、解析安全和组件结构一致性。

## 当前维护重点

后续维护时优先关注：

1. 避免残留其他主题或旧模板文案
2. 保持字段命名与编辑器配置一致
3. 保持首页与内页的结构创新，不回退为普通套板
4. 保持移动端体验与桌面端一样有设计感
