# TPMST 模板静态资源

## 目录结构

```
asset/
├── css/          # CSS样式文件
│   └── home.css  # 主页样式（从 tpmst.com 下载）
├── js/           # JavaScript文件
│   ├── zepto.min.js  # Zepto库（轻量级jQuery替代）
│   └── common.js     # 通用JS脚本
├── img/          # 图片资源
│   ├── logo-icon-128.avif  # Logo图标（默认Logo）
│   ├── logo-icon.png       # Logo图标（PNG格式，用于SEO）
│   ├── icon-20.png         # Favicon图标
│   ├── banner-2.jpg        # Banner图片（默认Banner，移动端）
│   ├── banner-02.jpg       # Banner图片（移动端备用）
│   ├── banner-03.webp       # Banner图片（PC端）
│   ├── download-btn-m.webp # 下载按钮图片
│   ├── levitate-download.avif # 浮动下载按钮图片
│   ├── v_dot.png           # 占位符图片（懒加载用）
│   ├── our-advantages/     # 优势区域图标
│   │   ├── icon01.webp     # Teen Patti Speed图标
│   │   ├── icon02.webp     # Rummy Master图标
│   │   └── icon03.webp     # Slots Meta图标
│   ├── game/               # 游戏介绍图片
│   │   ├── game-i1.webp    # Teen Patti master（桌面端）
│   │   ├── game-i1-m.webp  # Teen Patti master（移动端）
│   │   ├── game-i2.webp    # Rummy
│   │   └── game-i3.webp    # Slots
│   └── user/               # 用户头像（评价区域）
│       ├── Rajesh-Kumar-1.avif
│       ├── Priya-Sharma-2.avif
│       └── Amit-Patel-3.avif
└── fonts/        # 字体文件（如有需要）
```

## 资源说明

### CSS文件
- **home.css**: 从 https://www.tpmst.com/assets/css/home.css 下载
- 包含网站的主要样式定义、响应式布局、颜色变量等

### JavaScript文件
- **zepto.min.js**: Zepto库，轻量级JavaScript库
- **common.js**: 通用JavaScript脚本，包含网站交互逻辑
- **service-worker.js**: Service Worker脚本，用于离线支持和缓存管理

### 图片资源

#### 基础图片
- **logo-icon-128.avif**: Logo图标文件（128x128），默认Logo
- **logo-icon.png**: Logo图标文件（PNG格式），用于SEO和社交媒体分享
- **icon-20.png**: Favicon图标（20x20）
- **v_dot.png**: 占位符图片，用于懒加载

#### Banner图片
- **banner-2.jpg**: Banner图片（移动端默认）
- **banner-02.jpg**: Banner图片（移动端备用）
- **banner-03.webp**: Banner图片（PC端）

#### 按钮图片
- **download-btn-m.webp**: 下载按钮图片（移动端和PC端通用）
- **levitate-download.avif**: 浮动下载按钮图片（桌面端固定位置）

#### 优势区域图标（our-advantages/）
- **icon01.webp**: Teen Patti Speed图标
- **icon02.webp**: Rummy Master图标
- **icon03.webp**: Slots Meta图标

#### 游戏介绍图片（game/）
- **game-i1.webp**: Teen Patti master（桌面端）
- **game-i1-m.webp**: Teen Patti master（移动端）
- **game-i2.webp**: Rummy游戏图片
- **game-i3.webp**: Slots游戏图片

#### 用户头像（user/）
- **Rajesh-Kumar-1.avif**: 用户评价头像1
- **Priya-Sharma-2.avif**: 用户评价头像2
- **Amit-Patel-3.avif**: 用户评价头像3

## 字体说明

网站主要使用系统字体，未使用自定义字体文件：
- Arial
- system-ui
- -apple-system
- "Segoe UI"
- Roboto
- "Helvetica Neue"
- "Noto Sans"
- "Liberation Sans"
- sans-serif

## 使用方法

在模板中使用 `fetchTemplateStatic` 方法获取本地资源路径：

```php
// CSS
$cssUrl = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/css/home.css');

// JS
$jsUrl = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/js/common.js');

// 图片
$logoUrl = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/img/logo-icon-128.avif');
$bannerUrl = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/img/banner-2.jpg');
$bannerDesktopUrl = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/img/banner-03.webp');
$downloadBtnUrl = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/img/download-btn-m.webp');
$levitateUrl = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/img/levitate-download.avif');
$vDotUrl = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/img/v_dot.png');
$icon20Url = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/img/icon-20.png');

// 优势区域图标
$icon01Url = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/img/our-advantages/icon01.webp');
$icon02Url = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/img/our-advantages/icon02.webp');
$icon03Url = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/img/our-advantages/icon03.webp');

// 游戏图片
$gameI1Url = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/img/game/game-i1.webp');
$gameI1MUrl = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/img/game/game-i1-m.webp');
$gameI2Url = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/img/game/game-i2.webp');
$gameI3Url = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/img/game/game-i3.webp');

// 用户头像
$user1Url = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/img/user/Rajesh-Kumar-1.avif');
$user2Url = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/img/user/Priya-Sharma-2.avif');
$user3Url = $this->fetchTemplateStatic('GuoLaiRen_PageBuilder::style/three-patti-mela-pop/asset/img/user/Amit-Patel-3.avif');
```

## 更新日期

- CSS和JS资源下载日期：2025-01-18
- Logo下载日期：2025-01-18
- Banner和下载按钮图片下载日期：2025-01-18
- 所有静态资源完整下载日期：2025-01-18

## 注意事项

1. 所有资源已本地化，不依赖外部CDN
2. 如需更新资源，请重新下载并替换对应文件
3. 保持文件版本号或时间戳以支持缓存控制
