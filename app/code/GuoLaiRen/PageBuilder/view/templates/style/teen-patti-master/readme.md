# Teen Patti Master Theme

## 🎴 主题概述

**Teen Patti Master** 是一个专为印度流行纸牌游戏设计的现代化、响应式主题模板。采用深紫-金色渐变配色方案，集成 Tailwind CSS 框架，提供完美的移动端体验。

### 🎯 核心特点

- ✅ **全新视觉风格** - 深紫金色渐变主题，区别于其他主题
- ✅ **Tailwind CSS集成** - 现代化CSS框架，快速响应式开发
- ✅ **移动端优先** - 完美支持手机、平板、桌面设备
- ✅ **3D动画效果** - 卡片悬浮、渐变动画、视差滚动
- ✅ **汉堡菜单** - 移动端侧滑菜单，用户体验优秀
- ✅ **组件化设计** - 独立组件，易于维护和扩展

## 🎨 视觉设计

### 配色方案

- **主色调**: 深紫色 (#2d1b69) → 深蓝紫 (#1a0f3d) → 深黑紫 (#0f0728)
- **强调色**: 金色渐变 (#fbbf24 → #f59e0b)
- **背景**: 多层渐变 + 径向光晕效果
- **文字**: 白色/浅灰色系

### 设计风格

- 现代扁平化设计
- 大胆的渐变使用
- 3D悬浮卡片效果
- 流畅的动画过渡
- 视差滚动效果

## 📁 文件结构

```
teen-patti-master/
├── components/
│   ├── header/
│   │   └── nav.phtml              # 响应式导航组件（汉堡菜单）
│   ├── content/
│   │   ├── hero-slider.phtml      # 全屏Hero区（视差效果）
│   │   └── games.phtml            # 游戏展示区（3D卡片）
│   └── footer/
│       └── links.phtml            # 多列Footer布局
├── asset/
│   ├── css/                       # 自定义CSS（可选）
│   ├── js/                        # 自定义JS（可选）
│   └── img/                       # 图片资源
├── colors/                        # 颜色配置（可选）
├── layouts/                       # 布局配置
│   └── default/
├── layout.phtml                   # 主布局文件
├── header.phtml                   # Header入口
├── content.phtml                  # Content入口
├── footer.phtml                   # Footer入口
└── readme.md                      # 本文档
```

## 🧩 组件说明

### 1. Header Navigation (nav.phtml)

**特点**:

- Logo居中或左对齐
- 桌面端：横向导航 + CTA按钮
- 移动端：汉堡菜单 + 侧滑抽屉
- 粘性定位，滚动时保持可见

**配置项**:

- `logo.display` - 显示/隐藏Logo
- `logo.text` - Logo文字
- `logo.url` - Logo图片URL
- `navigation.items` - 导航项配置
- `navigation.cta_text` - CTA按钮文字
- `navigation.cta_url` - CTA按钮链接

### 2. Hero Slider (hero-slider.phtml)

**特点**:

- 全屏视差滚动效果
- 动态渐变背景
- 浮动卡片图标动画
- 大标题 + 副标题 + CTA按钮

**配置项**:

- `hero.title` - 主标题
- `hero.subtitle` - 副标题
- `hero.cta_text` - CTA按钮文字
- `hero.cta_url` - CTA按钮链接
- `hero.bg_image` - 背景图片（可选）

### 3. Games Showcase (games.phtml)

**特点**:

- 响应式网格布局
- 3D卡片悬浮效果
- 悬停时显示"Play Now"按钮
- 图标动画效果

**配置项**:

- `section.title` - 区域标题
- `section.subtitle` - 副标题
- `games.items` - 游戏列表（格式：名称|描述|链接）

### 4. Footer Links (links.phtml)

**特点**:

- 多列响应式布局
- 社交媒体图标
- 版权信息 + 免责声明
- 链接悬停动画

**配置项**:

- `footer.copyright` - 版权信息
- `footer.links` - 链接配置（格式：文本|链接）

## 📱 响应式设计

### 断点设置

- **移动端**: < 768px
- **平板**: 768px - 1024px
- **桌面**: > 1024px

### 移动端优化

- 汉堡菜单导航
- 单列卡片布局
- 触摸友好的按钮尺寸
- 优化的字体大小
- 减少动画复杂度

## 🚀 使用方法

### 1. 创建页面

1. 进入后台：**内容管理 > 页面构建器**
2. 点击"新建页面"
3. 选择样式模板：**teen-patti-master**
4. 填写页面信息

### 2. 配置组件

在页面编辑界面，可以配置：

- Header导航和Logo
- Hero区域内容
- 游戏列表
- Footer链接

### 3. 预览和发布

- 点击"预览"查看效果
- 确认后发布页面

## 🎯 适用场景

- 印度纸牌游戏推广（Andar Bahar、Teen Patti、Rummy）
- 在线赌场游戏平台
- 移动游戏应用下载页
- 游戏社区网站
- 竞技游戏平台

## 🔧 技术栈

- **CSS框架**: Tailwind CSS 3.x (CDN)
- **JavaScript**: 原生JS（无依赖）
- **PHP**: 7.4+
- **响应式**: Mobile-first设计
- **浏览器支持**: 现代浏览器（Chrome, Firefox, Safari, Edge）

## 🎨 自定义建议

### 颜色定制

修改 Tailwind 配置中的颜色变量：

```javascript
tailwind.config = {
  theme: {
    extend: {
      colors: {
        "abpro-purple": "#2d1b69", // 主紫色
        "abpro-gold": "#fbbf24", // 主金色
        "abpro-dark": "#0f0728", // 深色背景
      },
    },
  },
};
```

### 添加新组件

1. 在 `components/content/` 创建新的 `.phtml` 文件
2. 添加组件元数据注释
3. 在 `content.phtml` 中引用

### 添加自定义CSS

在 `asset/css/` 目录创建CSS文件，在 `layout.phtml` 中引入

## ⚠️ 注意事项

1. **18+ 内容** - 赌博类游戏需要明确年龄限制
2. **法律合规** - 确保符合当地法律法规
3. **响应式测试** - 在多种设备上测试
4. **性能优化** - 图片使用 WebP 格式，启用懒加载
5. **SEO优化** - 填写完整的Meta信息

## 📊 与其他主题的区别

| 特性     | tpmst    | poker-arena | teen-patti-master |
| -------- | -------- | ----------- | --------------- |
| CSS框架  | 自定义   | 自定义      | Tailwind CSS    |
| 配色     | 蓝黄色   | 绿金色      | 深紫金色        |
| 导航     | Logo居中 | Logo左侧    | 汉堡菜单        |
| 卡片效果 | 2D       | 2D悬浮      | 3D悬浮          |
| 动画     | 基础     | 中等        | 丰富            |
| 移动端   | 响应式   | 响应式      | 移动端优先      |

## 🔄 更新日志

### v1.0.0 (2026-03-12)

- ✅ 初始版本发布
- ✅ 集成Tailwind CSS
- ✅ 实现响应式布局
- ✅ 添加3D动画效果
- ✅ 完成所有核心组件

## 📞 支持

如有问题或建议，请联系开发团队。

---

**主题路径**: `GuoLaiRen_PageBuilder::style/teen-patti-master`

**创建日期**: 2026-03-12

**版本**: 1.0.0

**作者**: Claude Sonnet 4.6 + 开发团队
