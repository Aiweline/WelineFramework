# Callbreak Sunlit Durbar 静态资源说明

## 目录结构

```text
asset/
├── css/
│   └── home.css
├── js/
│   ├── common.js
│   ├── service-worker.js
│   └── zepto.min.js
└── img/
    ├── durbar-logo-icon.svg
    ├── durbar-hero-stage.svg
    ├── durbar-floating-badge.svg
    ├── durbar-floating-download-tab.svg
    ├── floating-download-orbit.svg
    ├── banner-2.jpg
    ├── banner-02.jpg
    ├── banner-03.webp
    ├── download-btn-m.webp
    ├── levitate-download.avif
    ├── logo-icon-128.avif
    ├── logo-icon.png
    ├── icon-20.png
    ├── v_dot.png
    ├── our-advantages/
    ├── game/
    └── user/
```

## 资源用途

- `css/home.css`
  主题级基础样式文件，主要承载旧版兼容样式和一些公共布局能力。
- `js/common.js`
  主题通用交互脚本。
- `js/service-worker.js`
  静态资源缓存与离线能力脚本。
- `img/durbar-*.svg`
  当前主题专属的导航、Hero、页脚浮动视觉资源。
- `img/banner-*`
  备用横幅图资源。
- `img/game/*`
  Games 模块默认插图。
- `img/user/*`
  Testimonials 模块默认头像。

## 在模板中的用法

```php
$logoUrl = $this->fetchTemplateStatic(
    'GuoLaiRen_PageBuilder::style/callbreak-sunlit-durbar/asset/img/durbar-logo-icon.svg'
);

$heroStageUrl = $this->fetchTemplateStatic(
    'GuoLaiRen_PageBuilder::style/callbreak-sunlit-durbar/asset/img/durbar-hero-stage.svg'
);

$cssUrl = $this->fetchTemplateStatic(
    'GuoLaiRen_PageBuilder::style/callbreak-sunlit-durbar/asset/css/home.css'
);
```

## 维护约定

1. 源文件以当前主题资源为准，不要在说明文档里保留其他主题站点来源或旧主题命名。
2. `durbar-*` 资源优先用于当前主题正式模块；`prism-*` 这类历史遗留资源若后续确认未使用，可再单独清理。
3. 新增静态资源时，命名应保持 `callbreak-sunlit-durbar` 主题语义，避免再次出现跨主题污染。
