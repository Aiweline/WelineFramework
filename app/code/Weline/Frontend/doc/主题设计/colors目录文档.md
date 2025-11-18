# colors/ 目录文档

## 目录概述

`colors/` 目录定义不同颜色主题的变量覆盖值。当用户切换主题（如亮色/暗色）时，这些文件会覆盖 `variables/_colors.css` 中定义的默认颜色值。

## 目录结构

```
colors/
├── _light.css             # 亮色主题颜色覆盖
├── _dark.css              # 暗色主题颜色覆盖
└── _amazon.css            # Amazon风格主题颜色覆盖
```

## 工作原理

### 1. 变量覆盖机制

```css
/* 第一步：variables/_colors.css 定义默认值 */
:root {
    --color-text-primary: #111;  /* 默认值（亮色） */
    --color-bg-primary: #fff;    /* 默认值（亮色） */
}

/* 第二步：colors/_dark.css 覆盖值 */
[data-theme="dark"] {
    --color-text-primary: #e5e5e5;  /* 覆盖为暗色值 */
    --color-bg-primary: #1a1a1a;     /* 覆盖为暗色值 */
}
```

### 2. 选择器优先级

- 使用 `[data-theme="xxx"]` 属性选择器
- 优先级高于 `:root`，可以覆盖默认值
- 只覆盖需要改变的值，未覆盖的变量使用默认值

---

## 文件说明

### 1. `_light.css` - 亮色主题颜色覆盖

**作用**：定义亮色主题的颜色变量值

**内容结构**：
```css
/**
 * 亮色主题颜色覆盖
 * 通常与 variables/_colors.css 中的默认值一致
 * 可以省略，或明确指定以便理解
 */

[data-theme="light"],
:root {
    /* 文本色 */
    --color-text-primary: #111;
    --color-text-secondary: #767676;
    --color-text-tertiary: #a6a6a6;
    --color-text-disabled: #c7c7c7;
    
    /* 背景色 */
    --color-bg-primary: #fff;
    --color-bg-secondary: #f8f9fa;
    --color-bg-tertiary: #e7e7e7;
    --color-bg-dark: #232f3e;
    
    /* 边框色 */
    --color-border-default: #ddd;
    --color-border-emphasis: #a6a6a6;
    --color-border-focus: #e77600;
    
    /* 品牌色通常不变 */
    /* --color-primary: #f0c14b; */  /* 使用默认值 */
}
```

**特点**：
- 通常与默认值一致，可以省略
- 明确指定有助于理解主题结构
- 作为其他主题的参考基准

**使用场景**：
- 用户切换到亮色主题时
- 作为默认主题（如果未指定主题）

---

### 2. `_dark.css` - 暗色主题颜色覆盖

**作用**：定义暗色主题的颜色变量值

**内容结构**：
```css
/**
 * 暗色主题颜色覆盖
 * 覆盖需要改变的颜色变量
 */

[data-theme="dark"] {
    /* 文本色 - 需要变亮 */
    --color-text-primary: #e5e5e5;
    --color-text-secondary: #a6a6a6;
    --color-text-tertiary: #767676;
    --color-text-disabled: #4a4a4a;
    
    /* 背景色 - 需要变暗 */
    --color-bg-primary: #1a1a1a;
    --color-bg-secondary: #2d2d2d;
    --color-bg-tertiary: #3a3a3a;
    --color-bg-dark: #0f1419;
    
    /* 边框色 - 需要调整 */
    --color-border-default: #4a4a4a;
    --color-border-emphasis: #6a6a6a;
    --color-border-focus: #ff9900;  /* 暗色下更亮的焦点色 */
    
    /* 功能色 - 可能需要调整 */
    --color-success: #34ce57;  /* 暗色下更亮的成功色 */
    --color-error: #f44336;    /* 暗色下更亮的错误色 */
    
    /* 品牌色通常保持不变 */
    /* --color-primary: #f0c14b; */  /* 使用默认值 */
}
```

**设计原则**：
- **对比度**：确保文本与背景的对比度符合WCAG AA标准（至少4.5:1）
- **可读性**：暗色背景使用较亮的文本色
- **一致性**：保持与亮色主题相同的视觉层次
- **选择性覆盖**：只覆盖需要改变的值

**颜色调整指南**：
- 文本色：从深色变为浅色
- 背景色：从浅色变为深色
- 边框色：适当调整以适应暗色背景
- 功能色：可能需要更亮的颜色以提高可见性

---

### 3. `_amazon.css` - Amazon风格主题颜色覆盖

**作用**：定义Amazon风格主题的颜色变量值

**内容结构**：
```css
/**
 * Amazon风格主题颜色覆盖
 * 参考Amazon的设计风格
 */

[data-theme="amazon"] {
    /* Amazon风格的颜色调整 */
    --color-primary: #ff9900;  /* Amazon橙色 */
    --color-primary-light: #ffb84d;
    --color-primary-dark: #e68900;
    --color-primary-border: #cc7700;
    
    /* 文本色 - Amazon风格 */
    --color-text-primary: #111;
    --color-text-secondary: #565959;
    
    /* 背景色 - Amazon风格 */
    --color-bg-primary: #fff;
    --color-bg-secondary: #f3f3f3;
    
    /* 链接色 - Amazon风格 */
    --color-link: #007185;
    --color-link-hover: #c45500;
}
```

**特点**：
- 参考Amazon的设计风格
- 使用Amazon的品牌色（橙色）
- 保持Amazon的视觉特征

**使用场景**：
- 需要Amazon风格的界面时
- 作为品牌主题的参考

---

## 使用方式

### 1. 在 theme.css 中导入

```css
/**
 * theme/assets/css/theme.css
 */

/* 先导入变量定义 */
@import '../variables/_colors.css';

/* 再导入主题覆盖 */
@import '../colors/_light.css';   /* 亮色主题（默认） */
@import '../colors/_dark.css';    /* 暗色主题 */
@import '../colors/_amazon.css';  /* Amazon风格主题 */
```

### 2. 在HTML中切换主题

```html
<!-- 亮色主题（默认） -->
<html data-theme="light">

<!-- 暗色主题 -->
<html data-theme="dark">

<!-- Amazon风格主题 -->
<html data-theme="amazon">
```

### 3. 在JavaScript中切换主题

```javascript
// 切换主题
function switchTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('weline-theme', theme);
}

// 使用
switchTheme('dark');  // 切换到暗色主题
switchTheme('light'); // 切换到亮色主题
```

---

## 主题切换机制

### 1. 自动检测系统主题

```javascript
// 检测系统主题偏好
if (window.matchMedia) {
    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
    
    // 初始设置
    if (mediaQuery.matches) {
        switchTheme('dark');
    }
    
    // 监听系统主题变化
    mediaQuery.addEventListener('change', (e) => {
        switchTheme(e.matches ? 'dark' : 'light');
    });
}
```

### 2. 用户偏好保存

```javascript
// 保存用户选择
function switchTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('weline-theme', theme);
}

// 恢复用户偏好
function initTheme() {
    const savedTheme = localStorage.getItem('weline-theme');
    const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    const theme = savedTheme || systemTheme;
    switchTheme(theme);
}

// 页面加载时初始化
initTheme();
```

---

## 创建新主题

### 步骤1：创建主题文件

```css
/**
 * colors/_your-theme.css
 * 你的自定义主题
 */

[data-theme="your-theme"] {
    /* 覆盖需要改变的颜色变量 */
    --color-primary: #your-color;
    --color-text-primary: #your-text-color;
    --color-bg-primary: #your-bg-color;
    /* ... */
}
```

### 步骤2：在 theme.css 中导入

```css
@import '../colors/_your-theme.css';
```

### 步骤3：更新主题切换逻辑

```javascript
// 在 theme.js 中添加新主题选项
const availableThemes = ['light', 'dark', 'amazon', 'your-theme'];
```

---

## 最佳实践

### 1. 颜色选择

- **对比度**：确保文本与背景对比度 ≥ 4.5:1（WCAG AA标准）
- **可读性**：暗色主题使用较亮的文本色
- **一致性**：保持与亮色主题相同的视觉层次
- **品牌色**：品牌色通常保持不变

### 2. 变量覆盖

- **选择性覆盖**：只覆盖需要改变的值
- **保持默认**：不需要改变的值可以省略
- **语义化**：使用语义化的变量名

### 3. 主题测试

- **对比度测试**：使用工具检查对比度
- **可读性测试**：在不同设备上测试
- **一致性测试**：确保所有组件正确应用主题

---

## 颜色对比度参考

### WCAG标准

- **AA标准**：文本与背景对比度 ≥ 4.5:1（正常文本）
- **AAA标准**：文本与背景对比度 ≥ 7:1（正常文本）
- **大文本**：大文本（18px+）可以降低到 3:1（AA）或 4.5:1（AAA）

### 常用颜色对比度

| 文本色 | 背景色 | 对比度 | 等级 |
|--------|--------|--------|------|
| #111 | #fff | 16.6:1 | AAA |
| #767676 | #fff | 4.6:1 | AA |
| #e5e5e5 | #1a1a1a | 13.2:1 | AAA |
| #a6a6a6 | #2d2d2d | 4.8:1 | AA |

---

## 相关文档

- [配色系统设计规范](./配色.md)
- [variables/ 目录文档](./variables目录文档.md)
- [变量与颜色主题区别说明](./变量与颜色主题区别说明.md)
- [主题切换实现](./主题切换实现.md)（待创建）

