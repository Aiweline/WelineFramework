# Jion Landing 模板

## 模板说明

这是一个面向股票市场学习社区的落地页模板，采用暗色主题设计，支持三端自适应（桌面端、iPad、移动端）。

## 文件结构

```
jion-landing/
├── header.phtml          # 顶部横条（包含按钮）
├── content.phtml         # 主要内容区域
├── footer.phtml          # 底部版权信息
├── colors/
│   └── default.phtml     # 默认色系颜色配置
└── readme.md             # 本文件
```

## 功能特性

### 1. 三端自适应设计
- **桌面端**：多列布局，卡片横向排列
- **iPad端**：优化的中等屏幕布局，banner 使用 `cover` 模式
- **移动端**：单列布局，卡片垂直堆叠，banner 使用 100% 宽度

### 2. 颜色配置系统
- 支持通过 `color_scheme` 配置字段选择不同的色系
- 默认使用 `default` 默认色系
- 颜色配置文件位于 `colors/` 目录
- 颜色配置协议：颜色配置文件定义 `$colors` 数组，包含所有颜色值

### 3. 内容区域
- **Banner区域**：背景图片、标题、描述文字、按钮
- **Why Jion区域**：三个特色卡片（Community、Daily Insights、Educational Discussions）
- **Community Preview区域**：三个预览卡片（带图片）
- **CTA区域**：两个行动号召卡片
- **Social Proof区域**：两个社交证明卡片

### 4. 页面内容支持
- 如果页面有 `content` 字段，会在内容区域顶部显示
- 可通过配置控制是否显示页面内容

## 配置说明

### 颜色方案配置
在模板的 `style_settings` 中设置：
```php
'color_scheme' => 'default'  // 可选值：default, light, dark（未来可扩展）
```

### 响应式字体大小
使用 CSS `clamp()` 函数实现动态字体大小：
- 格式：`clamp(最小值, 动态值, 最大值)`
- 断点：移动端 375px，桌面端 1280px

### Banner 图片配置
- **iPad端**：使用 `background-size: cover`，保持图片比例填充
- **移动端**：使用 `background-size: 100% auto`，宽度 100%，高度自适应

## 使用示例

### 在页面中使用
1. 在页面设置中选择 `style` 为 `jion-landing`
2. 在样式配置中设置各项参数
3. 设置 `color_scheme` 为 `default`（默认）

### 扩展颜色方案
1. 在 `colors/` 目录下创建新的颜色配置文件，如 `light.phtml`
2. 在文件中定义 `$colors` 数组
3. 在配置中设置 `color_scheme` 为新方案的名称

## 颜色配置协议

颜色配置文件必须定义 `$colors` 数组，包含以下键名：

```php
$colors = [
    'primary_bg' => '#0A1128',           // 主背景色
    'card_bg' => '#101830',              // 卡片背景色
    'text_primary' => '#FFFFFF',         // 主文字颜色
    'text_secondary' => '#E0E0E0',       // 次要文字颜色
    'text_muted' => '#999999',           // 弱化文字颜色
    'accent_blue' => '#2A64F6',          // 强调蓝色
    'button_primary_bg' => '#2A64F6',    // 按钮背景色
    'button_primary_text' => '#FFFFFF',  // 按钮文字颜色
    'button_primary_hover' => '#1E4FD6', // 按钮悬停色
    'card_border' => 'rgba(42, 100, 246, 0.2)', // 卡片边框色
];
```

## 注意事项

1. 所有文字和图片都可通过后台配置
2. 图片使用懒加载，提升性能
3. 使用 CSS 变量和动态属性实现响应式
4. 颜色配置系统会自动回退到默认 `default` 色系
