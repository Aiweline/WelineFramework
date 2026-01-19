# Teen Patti Master (TPMST) 模板

## 📋 模板概述

这是一个专为 Teen Patti Master 游戏网站设计的完整落地页模板，基于 https://www.tpmst.com/ 网站内容创建。模板包含所有必要的元素，并且每个元素都可以通过可视化配置进行自定义。

### 🎯 适用场景

- 游戏应用推广页
- 在线卡牌游戏落地页
- 移动应用下载页
- 游戏功能展示页
- 用户评价和FAQ页面

### ✨ 核心特性

1. **完全可配置** - 所有文本、颜色、尺寸都可以通过后台配置
2. **响应式设计** - 完美适配移动端、平板和桌面
3. **SEO 优化** - 从页面数据自动生成 Meta 信息
4. **跟踪集成** - 支持 GA4、GTM、Facebook Pixel
5. **交互式FAQ** - 可展开/收起的FAQ区域
6. **用户评价展示** - 精美的用户评价卡片

## 🏗️ 模板结构

### 文件组成

```
tpmst/
├── header.phtml   - 头部区域（导航 + Hero区域 + 下载按钮）
├── content.phtml  - 内容区域（优势、游戏、应用信息、策略、评价、FAQ）
├── footer.phtml   - 页脚区域（链接 + 版权 + 免责声明）
└── readme.md      - 本文档
```

### 页面布局

```
┌─────────────────────────────────────┐
│        Navigation (导航栏)          │
│  ┌──────────────────────────────┐   │
│  │      Logo (可选)              │   │
│  │   导航链接 (Home/About等)    │   │
│  └──────────────────────────────┘   │
└─────────────────────────────────────┘
┌─────────────────────────────────────┐
│         Hero Section (Hero区域)       │
│  ┌──────────────────────────────┐   │
│  │      主标题 (可配置)           │   │
│  │      副标题 (可配置)           │   │
│  │    下载按钮 (可配置)           │   │
│  └──────────────────────────────┘   │
└─────────────────────────────────────┘
┌─────────────────────────────────────┐
│      Content Section (内容区域)      │
│  ┌──────────────────────────────┐   │
│  │   优势介绍 (3个卡片)           │   │
│  │   游戏介绍 (3个卡片)           │   │
│  │   应用信息表格                 │   │
│  │   策略说明                     │   │
│  │   用户评价 (3个评价)           │   │
│  │   FAQ (可展开)                 │   │
│  └──────────────────────────────┘   │
└─────────────────────────────────────┘
┌─────────────────────────────────────┐
│        Footer (页脚)                 │
│  ┌──────────────────────────────┐   │
│  │   页脚链接                    │   │
│  │   免责声明                    │   │
│  │   版权信息                    │   │
│  └──────────────────────────────┘   │
└─────────────────────────────────────┘
```

## 🎨 配置项说明

### Header 配置

#### Logo设置
- **logo.display**: 是否显示Logo（yes/no）
- **logo.url**: Logo图片地址
- **logo.width**: Logo宽度（响应式：移动端/PC端）

#### 导航设置
- **navigation.display**: 是否显示导航栏
- **navigation.bg_color**: 导航背景色
- **navigation.text_color**: 导航文字颜色
- **navigation.link_color**: 链接颜色
- **navigation.link_hover_color**: 链接悬停颜色

#### Hero区域设置
- **hero.title**: 主标题文本
- **hero.subtitle**: 副标题文本
- **hero.title_color**: 标题颜色
- **hero.subtitle_color**: 副标题颜色
- **hero.bg_color**: 背景颜色
- **hero.bg_image**: 背景图片（可选）
- **hero.padding_v**: 上下内边距（响应式）

#### 下载按钮设置
- **download_button.text**: 按钮文本
- **download_button.display**: 是否显示按钮
- **download_button.bg_color**: 背景颜色
- **hover_bg_color**: 悬停背景色
- **download_button.text_color**: 文字颜色
- **download_button.size**: 字体大小（响应式）
- **download_button.padding**: 内边距（响应式）
- **download_button.border_radius**: 圆角大小

### Content 配置

#### 优势区域设置
- **advantages.title**: 优势区域标题
- **advantages.display**: 是否显示优势区域
- **advantages.bg_color**: 背景颜色

#### 游戏介绍设置
- **games.title**: 游戏区域标题
- **games.display**: 是否显示游戏区域
- **games.bg_color**: 背景颜色

#### 应用信息设置
- **app_info.display**: 是否显示应用信息
- **app_info.app_name**: 应用名称
- **app_info.app_size**: 应用大小
- **app_info.app_version**: 版本号
- **app_info.app_updated**: 更新日期
- **app_info.app_requires**: 系统要求

#### 用户评价设置
- **testimonials.title**: 评价区域标题
- **testimonials.display**: 是否显示评价区域
- **testimonials.bg_color**: 背景颜色

#### FAQ设置
- **faq.title**: FAQ标题
- **faq.display**: 是否显示FAQ
- **faq.bg_color**: 背景颜色

#### 样式设置
- **styles.section_padding**: 区块上下内边距（响应式）
- **styles.text_color**: 文字颜色
- **styles.heading_color**: 标题颜色
- **styles.link_color**: 链接颜色

### Footer 配置

#### 样式配置
- **style.background_color**: 背景颜色
- **style.text_color**: 文字颜色
- **style.link_color**: 链接颜色
- **style.link_hover_color**: 链接悬停颜色
- **style.border_top**: 是否显示顶部边框
- **style.border_color**: 边框颜色

#### 布局设置
- **layout.max_width**: 最大宽度
- **layout.padding_horizontal**: 左右内边距（响应式）
- **layout.padding_vertical**: 上下内边距（响应式）

#### 内容配置
- **content.footer_links**: 页脚链接（格式：文本|URL,文本|URL）
- **content.copyright_text**: 版权信息
- **content.disclaimer_text**: 免责声明

#### 排版设置
- **typography.font_size**: 字体大小（响应式）
- **typography.line_height**: 行高

## 📱 响应式设计

模板采用响应式设计，支持以下断点：

- **移动端**: < 768px
- **平板**: 768px - 1024px
- **桌面**: > 1024px

所有尺寸配置都支持响应式格式：`移动端值/桌面值`，例如：`60/120` 表示移动端60px，桌面端120px。

## 🎯 使用说明

### 1. 创建页面

1. 进入后台：**内容管理 > 页面构建器**
2. 点击"新建页面"
3. 选择样式模板：**tpmst**
4. 填写页面基本信息（标题、句柄、SEO信息等）

### 2. 配置样式

在页面编辑界面，展开"样式配置"面板，可以配置：

- Header区域的Logo、导航、Hero、下载按钮
- Content区域的优势、游戏、应用信息、评价、FAQ
- Footer区域的样式、链接、版权信息

### 3. 预览和发布

- 点击"预览"按钮查看效果
- 确认无误后，设置状态为"已发布"
- 页面即可通过配置的句柄访问

## 🔧 技术实现

### 响应式CSS

使用 `clamp()` 函数实现流畅的响应式尺寸：

```css
font-size: clamp(28px, calc(28px + 28 * ((100vw - 375px) / 905)), 56px);
```

### FAQ交互

使用JavaScript实现FAQ项的展开/收起功能，点击问题即可展开答案。

### 卡片悬停效果

所有卡片都有平滑的悬停动画效果，提升用户体验。

## 📊 页面内容

模板包含以下预设内容：

1. **优势介绍**: Teen Patti Speed, Rummy Master, Slots Meta
2. **游戏介绍**: Teen Patti master, Rummy, Slots
3. **应用信息**: 应用名称、大小、版本、更新日期、系统要求
4. **策略说明**: 游戏策略和技巧
5. **用户评价**: 3个真实用户评价
6. **FAQ**: 5个常见问题

所有内容都可以通过后台配置进行修改。

## ✅ 验证结果

- ✅ 代码语法检查通过
- ✅ 所有功能正常实现
- ✅ 响应式设计完善
- ✅ SEO优化到位
- ✅ 交互功能正常

## 📝 注意事项

1. **图片资源**: Logo和背景图片需要上传到服务器或使用外部链接
2. **链接配置**: 页脚链接使用 `文本|URL` 格式，多个链接用逗号分隔
3. **免责声明**: 建议根据实际需求修改免责声明内容
4. **年龄限制**: 游戏类网站需要明确标注年龄限制（18+）

## 🚀 后续优化建议

1. 添加更多游戏截图展示
2. 集成应用商店下载链接
3. 添加实时在线人数显示
4. 集成客服聊天功能
5. 添加多语言支持

---

**模板路径**: `GuoLaiRen_PageBuilder::style/tpmst`

**创建日期**: 2025-01-18

**版本**: 1.0.0
