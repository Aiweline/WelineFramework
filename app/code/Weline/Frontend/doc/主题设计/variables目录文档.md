> 警告：本文是历史主题设计资料，仅用于理解早期设计思路，不是当前开发规范。当前主题开发先读 `app/code/Weline/Theme/doc/AI-INDEX.md`、`app/code/Weline/Theme/doc/theme-inheritance-and-file-conventions.md`、`app/code/Weline/Theme/doc/开发/Theme开发总指南.md`；浏览器业务请求只使用 `app/code/Weline/Frontend/doc/Weline.Api使用指南.md`。

# variables/ 目录文档

## 目录概述

`variables/` 目录定义所有设计系统的核心CSS变量，包括颜色、间距、字体、阴影、边框等。这些变量是设计系统的基础，所有组件和样式都应使用这些变量，而不是硬编码值。

## 目录结构

```
variables/
├── _colors.css          # 颜色变量定义
├── _spacing.css         # 间距变量定义
├── _typography.css      # 字体变量定义
├── _shadows.css         # 阴影变量定义
└── _borders.css         # 边框变量定义
```

## 文件说明

### 1. `_colors.css` - 颜色变量定义

**作用**：定义所有颜色相关的CSS变量和默认值

**内容结构**：
```css
:root {
    /* ========== 品牌色 ========== */
    --color-primary: #f0c14b;
    --color-primary-light: #f4d078;
    --color-primary-dark: #e7b92e;
    --color-primary-border: #a88734;
    
    /* ========== 文本色 ========== */
    --color-text-primary: #111;
    --color-text-secondary: #767676;
    --color-text-tertiary: #a6a6a6;
    --color-text-disabled: #c7c7c7;
    
    /* ========== 背景色 ========== */
    --color-bg-primary: #fff;
    --color-bg-secondary: #f8f9fa;
    --color-bg-tertiary: #e7e7e7;
    --color-bg-dark: #232f3e;
    
    /* ========== 边框色 ========== */
    --color-border-default: #ddd;
    --color-border-emphasis: #a6a6a6;
    --color-border-focus: #e77600;
    
    /* ========== 功能色 ========== */
    --color-success: #28a745;
    --color-warning: #ffc107;
    --color-error: #dc3545;
    --color-info: #17a2b8;
    
    /* ========== 链接色 ========== */
    --color-link: #0066c0;
    --color-link-hover: #c45500;
    --color-link-visited: #551a8b;
}
```

**变量分类**：
- **品牌色**：主品牌色及其变体
- **文本色**：不同层级的文本颜色
- **背景色**：不同层级的背景颜色
- **边框色**：不同状态的边框颜色
- **功能色**：成功、警告、错误、信息等状态色
- **链接色**：链接的不同状态颜色

**使用示例**：
```css
.button {
    background-color: var(--color-primary);
    color: var(--color-text-primary);
    border-color: var(--color-primary-border);
}
```

**注意事项**：
- 所有颜色变量必须在此文件中定义
- 变量值使用默认值（通常是亮色主题的值）
- 变量名使用语义化命名，而非具体颜色值

---

### 2. `_spacing.css` - 间距变量定义

**作用**：定义统一的间距系统

**内容结构**：
```css
:root {
    /* ========== 基础间距 ========== */
    --spacing-xs: 0.25rem;    /* 4px */
    --spacing-sm: 0.5rem;     /* 8px */
    --spacing-md: 1rem;       /* 16px */
    --spacing-lg: 1.5rem;     /* 24px */
    --spacing-xl: 2rem;       /* 32px */
    --spacing-2xl: 3rem;      /* 48px */
    --spacing-3xl: 4rem;      /* 64px */
    
    /* ========== 组件间距 ========== */
    --spacing-component-padding: var(--spacing-md);
    --spacing-component-gap: var(--spacing-sm);
    --spacing-section-margin: var(--spacing-xl);
    
    /* ========== 布局间距 ========== */
    --spacing-container-padding: var(--spacing-md);
    --spacing-container-max-width: 1200px;
    --spacing-grid-gap: var(--spacing-md);
}
```

**间距系统**：
- 使用 `rem` 单位，基于根字体大小
- 遵循 4px 基础网格系统
- 提供语义化的组件和布局间距变量

**使用示例**：
```css
.card {
    padding: var(--spacing-md);
    margin-bottom: var(--spacing-lg);
}

.form-group {
    margin-bottom: var(--spacing-md);
    gap: var(--spacing-sm);
}
```

**注意事项**：
- 统一使用间距变量，避免硬编码
- 组件间距使用语义化变量
- 保持间距的一致性

---

### 3. `_typography.css` - 字体变量定义

**作用**：定义字体系统相关的变量

**内容结构**：
```css
:root {
    /* ========== 字体族 ========== */
    --font-family-base: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif;
    --font-family-mono: 'Courier New', Courier, monospace;
    --font-family-serif: Georgia, 'Times New Roman', serif;
    
    /* ========== 字体大小 ========== */
    --font-size-xs: 0.75rem;    /* 12px */
    --font-size-sm: 0.875rem;   /* 14px */
    --font-size-base: 1rem;     /* 16px */
    --font-size-lg: 1.125rem;   /* 18px */
    --font-size-xl: 1.25rem;    /* 20px */
    --font-size-2xl: 1.5rem;    /* 24px */
    --font-size-3xl: 1.75rem;   /* 28px */
    --font-size-4xl: 2rem;      /* 32px */
    
    /* ========== 字体粗细 ========== */
    --font-weight-light: 300;
    --font-weight-normal: 400;
    --font-weight-medium: 500;
    --font-weight-semibold: 600;
    --font-weight-bold: 700;
    
    /* ========== 行高 ========== */
    --line-height-tight: 1.25;
    --line-height-normal: 1.5;
    --line-height-relaxed: 1.75;
    --line-height-loose: 2;
    
    /* ========== 字间距 ========== */
    --letter-spacing-tight: -0.025em;
    --letter-spacing-normal: 0;
    --letter-spacing-wide: 0.025em;
}
```

**字体系统**：
- 使用系统字体栈，确保跨平台一致性
- 字体大小使用 `rem` 单位
- 提供完整的字体粗细和行高选项

**使用示例**：
```css
.heading {
    font-family: var(--font-family-base);
    font-size: var(--font-size-2xl);
    font-weight: var(--font-weight-bold);
    line-height: var(--line-height-tight);
}

.body-text {
    font-size: var(--font-size-base);
    line-height: var(--line-height-normal);
}
```

**注意事项**：
- 优先使用系统字体，确保性能
- 保持字体大小的层次结构
- 行高与字体大小匹配

---

### 4. `_shadows.css` - 阴影变量定义

**作用**：定义统一的阴影系统

**内容结构**：
```css
:root {
    /* ========== 基础阴影 ========== */
    --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
    --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
    --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);
    --shadow-xl: 0 20px 25px rgba(0, 0, 0, 0.15);
    --shadow-2xl: 0 25px 50px rgba(0, 0, 0, 0.25);
    
    /* ========== 特殊阴影 ========== */
    --shadow-focus: 0 0 3px 2px rgba(228, 121, 17, 0.5);
    --shadow-inset: inset 0 2px 4px rgba(0, 0, 0, 0.06);
    --shadow-none: none;
    
    /* ========== 组件阴影 ========== */
    --shadow-card: var(--shadow-md);
    --shadow-button: var(--shadow-sm);
    --shadow-modal: var(--shadow-xl);
    --shadow-dropdown: var(--shadow-lg);
}
```

**阴影系统**：
- 提供不同强度的阴影
- 包含特殊用途的阴影（焦点、内阴影等）
- 提供组件级别的语义化阴影变量

**使用示例**：
```css
.card {
    box-shadow: var(--shadow-card);
}

.button:focus {
    box-shadow: var(--shadow-focus);
}

.modal {
    box-shadow: var(--shadow-modal);
}
```

**注意事项**：
- 阴影强度应与元素层级匹配
- 焦点阴影使用品牌色
- 保持阴影的一致性

---

### 5. `_borders.css` - 边框变量定义

**作用**：定义统一的边框系统

**内容结构**：
```css
:root {
    /* ========== 边框宽度 ========== */
    --border-width-none: 0;
    --border-width-thin: 1px;
    --border-width-medium: 2px;
    --border-width-thick: 3px;
    
    /* ========== 边框圆角 ========== */
    --border-radius-none: 0;
    --border-radius-sm: 3px;
    --border-radius-md: 4px;
    --border-radius-lg: 8px;
    --border-radius-xl: 12px;
    --border-radius-2xl: 16px;
    --border-radius-full: 9999px;
    
    /* ========== 边框样式 ========== */
    --border-style-solid: solid;
    --border-style-dashed: dashed;
    --border-style-dotted: dotted;
    
    /* ========== 组件边框 ========== */
    --border-input: var(--border-width-thin) var(--border-style-solid) var(--color-border-default);
    --border-button: var(--border-width-thin) var(--border-style-solid) var(--color-primary-border);
    --border-card: var(--border-width-thin) var(--border-style-solid) var(--color-border-default);
}
```

**边框系统**：
- 提供不同宽度的边框
- 提供不同大小的圆角
- 提供组件级别的语义化边框变量

**使用示例**：
```css
.input {
    border: var(--border-input);
    border-radius: var(--border-radius-md);
}

.button {
    border: var(--border-button);
    border-radius: var(--border-radius-md);
}

.card {
    border: var(--border-card);
    border-radius: var(--border-radius-lg);
}
```

**注意事项**：
- 统一使用边框变量
- 圆角大小应与元素类型匹配
- 保持边框的一致性

---

## 使用方式

### 1. 在 theme.css 中导入

```css
/**
 * theme/assets/css/theme.css
 */

/* 导入所有变量文件 */
@import '../variables/_colors.css';
@import '../variables/_spacing.css';
@import '../variables/_typography.css';
@import '../variables/_shadows.css';
@import '../variables/_borders.css';
```

### 2. 在组件中使用

```css
/* 使用变量 */
.component {
    color: var(--color-text-primary);
    padding: var(--spacing-md);
    font-size: var(--font-size-base);
    box-shadow: var(--shadow-md);
    border: var(--border-input);
    border-radius: var(--border-radius-md);
}
```

### 3. 在其他模块中引用

```css
/* 在其他模块的CSS中 */
@import url('/static/Weline_Frontend/theme/assets/css/theme.css');

.my-module-component {
    background-color: var(--color-bg-primary);
    padding: var(--spacing-lg);
}
```

---

## 变量命名规范

### 1. 命名规则

- 使用 `--` 前缀（CSS自定义属性标准）
- 使用小写字母和连字符
- 语义化命名，而非具体值
- 分组前缀：`--color-*`, `--spacing-*`, `--font-*` 等

### 2. 命名示例

```css
/* ✅ 正确 */
--color-primary
--spacing-md
--font-size-base
--shadow-md
--border-radius-md

/* ❌ 错误 */
--yellow
--16px
--font-16
--shadow-1
--radius-4
```

---

## 最佳实践

### 1. 变量定义

- 所有变量必须在此目录中定义
- 使用语义化命名
- 提供合理的默认值
- 添加注释说明用途

### 2. 变量使用

- 优先使用变量，避免硬编码
- 使用语义化变量，而非具体值
- 保持变量使用的一致性

### 3. 变量扩展

- 新增变量时，更新相关文档
- 保持命名规范的一致性
- 考虑向后兼容性

---

## 相关文档

- [配色系统设计规范](./配色.md)
- [colors/ 目录文档](./colors目录文档.md)
- [变量与颜色主题区别说明](./变量与颜色主题区别说明.md)

