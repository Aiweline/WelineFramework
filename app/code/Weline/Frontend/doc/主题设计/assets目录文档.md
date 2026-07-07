> 警告：本文是历史主题设计资料，仅用于理解早期设计思路，不是当前开发规范。当前主题开发先读 `app/code/Weline/Theme/doc/AI-INDEX.md`、`app/code/Weline/Theme/doc/theme-inheritance-and-file-conventions.md`、`app/code/Weline/Theme/doc/开发/Theme开发总指南.md`；浏览器业务请求只使用 `app/code/Weline/Frontend/doc/Weline.Api使用指南.md`。

# assets/ 目录文档

## 目录概述

`assets/` 目录包含主题相关的静态资源，包括CSS样式文件、JavaScript文件和图片资源。这些资源是主题系统的核心，定义了主题的视觉样式和交互行为。

## 目录结构

```
assets/
├── css/
│   ├── theme.css          # 主题主样式（导入所有变量）
│   ├── components.css     # 组件样式
│   └── utilities.css      # 工具类样式
├── js/
│   ├── theme.js          # Weline核心JS（模块加载、API、账户管理等）
│   └── theme.js           # 主题JS（主题切换、工具函数）
└── images/
    └── theme/              # 主题相关图片
        ├── logo.svg
        └── placeholder.png
```

---

## CSS文件说明

### 1. `css/theme.css` - 主题主样式

**作用**：主题的主样式文件，导入所有变量和基础样式

**内容结构**：
```css
/**
 * Weline Frontend - 主题主样式文件
 * 
 * 导入顺序：
 * 1. 变量文件
 * 2. 颜色主题
 * 3. 基础样式
 * 4. 组件样式
 * 5. 工具类
 */

/* ========== 1. 核心变量 ========== */
@import '../variables/_colors.css';
@import '../variables/_spacing.css';
@import '../variables/_typography.css';
@import '../variables/_shadows.css';
@import '../variables/_borders.css';

/* ========== 2. 颜色主题 ========== */
@import '../colors/_light.css';
@import '../colors/_dark.css';
@import '../colors/_amazon.css';

/* ========== 3. 基础样式 ========== */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: var(--font-family-base);
    font-size: var(--font-size-base);
    line-height: var(--line-height-normal);
    color: var(--color-text-primary);
    background-color: var(--color-bg-primary);
}

/* ========== 4. 组件样式 ========== */
@import './components.css';

/* ========== 5. 工具类 ========== */
@import './utilities.css';
```

**使用方式**：
```php
<!-- 在 head.phtml 中加载 -->
<link rel="stylesheet" href="@static(Weline_Frontend::theme/assets/css/theme.css)">
```

**特点**：
- 集中导入所有变量和样式
- 定义全局基础样式
- 作为主题的入口文件

---

### 2. `css/components.css` - 组件样式

**作用**：定义所有组件的样式

**内容结构**：
```css
/**
 * Weline Frontend - 组件样式
 * 
 * 所有组件样式使用统一的变量系统
 */

/* ========== 按钮组件 ========== */
.btn {
    background-color: var(--color-primary);
    color: var(--color-text-primary);
    padding: var(--spacing-sm) var(--spacing-md);
    border: var(--border-width-thin) solid var(--color-primary-border);
    border-radius: var(--border-radius-md);
    font-size: var(--font-size-base);
    font-weight: var(--font-weight-normal);
    box-shadow: var(--shadow-sm);
    transition: all 0.2s ease;
    cursor: pointer;
}

.btn:hover {
    background-color: var(--color-primary-light);
    box-shadow: var(--shadow-md);
}

.btn:focus {
    outline: 2px solid var(--color-border-focus);
    box-shadow: var(--shadow-focus);
}

.btn:active {
    background-color: var(--color-primary-dark);
}

/* 按钮类型 */
.btn-primary {
    background-color: var(--color-primary);
    border-color: var(--color-primary-border);
}

.btn-secondary {
    background-color: var(--color-bg-secondary);
    border-color: var(--color-border-default);
}

.btn-danger {
    background-color: var(--color-error);
    border-color: var(--color-error);
    color: white;
}

/* 按钮尺寸 */
.btn-sm {
    padding: var(--spacing-xs) var(--spacing-sm);
    font-size: var(--font-size-sm);
}

.btn-md {
    padding: var(--spacing-sm) var(--spacing-md);
    font-size: var(--font-size-base);
}

.btn-lg {
    padding: var(--spacing-md) var(--spacing-lg);
    font-size: var(--font-size-lg);
}

/* ========== 输入框组件 ========== */
.form-control {
    width: 100%;
    padding: var(--spacing-sm) var(--spacing-md);
    border: var(--border-width-thin) solid var(--color-border-default);
    border-radius: var(--border-radius-md);
    font-size: var(--font-size-base);
    background-color: var(--color-bg-primary);
    color: var(--color-text-primary);
    transition: border-color 0.2s ease;
}

.form-control:focus {
    outline: none;
    border-color: var(--color-border-focus);
    box-shadow: var(--shadow-focus);
}

.form-control.is-invalid {
    border-color: var(--color-error);
}

.form-control.is-valid {
    border-color: var(--color-success);
}

/* ========== 卡片组件 ========== */
.card {
    background-color: var(--color-bg-primary);
    border: var(--border-width-thin) solid var(--color-border-default);
    border-radius: var(--border-radius-lg);
    box-shadow: var(--shadow-card);
    overflow: hidden;
}

.card-header {
    padding: var(--spacing-md);
    border-bottom: var(--border-width-thin) solid var(--color-border-default);
    background-color: var(--color-bg-secondary);
}

.card-body {
    padding: var(--spacing-md);
}

.card-footer {
    padding: var(--spacing-md);
    border-top: var(--border-width-thin) solid var(--color-border-default);
    background-color: var(--color-bg-secondary);
}

/* ========== 模态框组件 ========== */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1000;
}

.modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background-color: var(--color-bg-primary);
    border-radius: var(--border-radius-lg);
    box-shadow: var(--shadow-modal);
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

/* ========== 提示框组件 ========== */
.alert {
    padding: var(--spacing-md);
    border-radius: var(--border-radius-md);
    margin-bottom: var(--spacing-md);
}

.alert-success {
    background-color: rgba(40, 167, 69, 0.1);
    border: var(--border-width-thin) solid var(--color-success);
    color: var(--color-success);
}

.alert-error {
    background-color: rgba(220, 53, 69, 0.1);
    border: var(--border-width-thin) solid var(--color-error);
    color: var(--color-error);
}

.alert-warning {
    background-color: rgba(255, 193, 7, 0.1);
    border: var(--border-width-thin) solid var(--color-warning);
    color: var(--color-warning);
}

.alert-info {
    background-color: rgba(23, 162, 184, 0.1);
    border: var(--border-width-thin) solid var(--color-info);
    color: var(--color-info);
}
```

**特点**：
- 所有组件使用CSS变量
- 支持主题切换
- 统一的交互效果

---

### 3. `css/utilities.css` - 工具类样式

**作用**：提供常用的工具类

**内容结构**：
```css
/**
 * Weline Frontend - 工具类样式
 * 
 * 提供常用的工具类，类似Tailwind CSS
 */

/* ========== 间距工具类 ========== */
.m-0 { margin: 0; }
.mt-0 { margin-top: 0; }
.mb-0 { margin-bottom: 0; }
.ml-0 { margin-left: 0; }
.mr-0 { margin-right: 0; }

.p-0 { padding: 0; }
.pt-0 { padding-top: 0; }
.pb-0 { padding-bottom: 0; }
.pl-0 { padding-left: 0; }
.pr-0 { padding-right: 0; }

/* ========== 文本工具类 ========== */
.text-primary { color: var(--color-text-primary); }
.text-secondary { color: var(--color-text-secondary); }
.text-success { color: var(--color-success); }
.text-error { color: var(--color-error); }

.text-left { text-align: left; }
.text-center { text-align: center; }
.text-right { text-align: right; }

.font-bold { font-weight: var(--font-weight-bold); }
.font-normal { font-weight: var(--font-weight-normal); }

/* ========== 背景工具类 ========== */
.bg-primary { background-color: var(--color-bg-primary); }
.bg-secondary { background-color: var(--color-bg-secondary); }

/* ========== 显示工具类 ========== */
.d-none { display: none; }
.d-block { display: block; }
.d-flex { display: flex; }
.d-inline { display: inline; }
.d-inline-block { display: inline-block; }

/* ========== 响应式工具类 ========== */
@media (max-width: 768px) {
    .d-md-none { display: none; }
    .d-md-block { display: block; }
}
```

**使用示例**：
```html
<div class="text-center bg-primary p-md">
    <h1 class="text-primary font-bold">标题</h1>
</div>
```

---

## JavaScript文件说明

### 1. `js/theme.js` - Weline核心JavaScript

**作用**：Weline Framework的统一前端JS入口，提供模块加载、API请求、账户管理、国际化等核心功能

**核心功能**：
- **模块加载器**：按需加载功能模块（api、account等）
- **API请求**：统一的HTTP请求接口
- **账户管理**：前端登录、API登录、后端API登录
- **国际化**：i18n翻译和语言切换
- **货币/语言切换**：Locale管理
- **自动预加载**：根据页面路径自动预加载相关模块

**主要API**：
```javascript
// API 请求（必须使用，禁止 fetch/$.ajax）— 详见 Weline_Frontend::doc/Weline.Api使用指南.md
Weline.Api.request(url, options)  // 维护/404 自动感知，统一错误提示

// 账户管理
Weline.Account.frontendLogin(username, password)
Weline.Account.checkFrontendLogin()
Weline.Account.getFrontendUser()

// 国际化
Weline.i18n.translate(key, params)
Weline.i18n.switchLang(lang)

// 货币/语言切换
Weline.Locale.switchCurrency(currency)
Weline.Locale.switchLang(lang)

// 模块预加载
Weline.preLoad('account')
Weline.preLoad(['api', 'account'])
```

**配置**：
```javascript
// 在 head.phtml 中配置
window.WelineConfig = {
    baseUrl: 'http://example.com',
    currentLang: 'zh_Hans_CN',
    currentCurrency: 'CNY',
    modulesBaseUrl: '/static/Weline_Frontend/js/weline-api',
    api: {
        workerUrl: '/static/Weline_Frontend/js/weline-api-worker.js',
        cartCountCookieKey: 'weline_cart_item_count'
    },
    account: {
        frontendLoginUrl: '/frontend/account/login',
        // ... 其他配置
    }
};
```

**使用方式**：
```php
<!-- 在 head.phtml 中加载 -->
<script>
    window.WelineConfig = {
        baseUrl: '<?= $baseUrl ?>',
        currentLang: '<?= $currentLang ?>',
        currentCurrency: '<?= $currentCurrency ?>'
    };
</script>
<js>Weline_Theme::theme/frontend/assets/js/theme.js</js>
```

**自动预加载机制**：
- 登录/注册页面：自动预加载 `account` 模块
- 用户中心页面：自动预加载 `api` 和 `account` 模块
- API相关页面：自动预加载 `api` 模块
- 购物车相关页面：自动预加载 `api` 模块

**Weline.Api 说明**（详见 `Weline_Frontend::doc/Weline.Api使用指南.md`）：
- **为什么用**：维护模式自动感知、404/5xx 友好提示、统一错误处理
- **响应结构**：`{ ok, status, data, headers }`；错误时 `catch(err)` 含 `err.response`、`err.status`、`err.maintenance`
- **错误提示**：使用 `BackendToast`/`FrontendToast`，禁止 `alert`/`confirm`/`prompt`
- **建议**：所有业务 Ajax 必须使用 `Weline.Api.request`，禁止直接 `fetch`/`$.ajax`

**特点**：
- 按需加载，减少初始加载时间
- 统一的API接口
- 支持开发模式调试信息
- 防止重复初始化

---

### 2. `js/theme.js` - 主题JavaScript

**作用**：提供主题切换、工具函数等JavaScript功能

**内容结构**：
```javascript
/**
 * Weline Frontend - 主题JavaScript
 */

(function() {
    'use strict';
    
    /**
     * 主题管理器
     */
    const ThemeManager = {
        /**
         * 当前主题
         */
        current: 'light',
        
        /**
         * 可用主题列表
         */
        themes: ['light', 'dark', 'amazon'],
        
        /**
         * 切换主题
         * @param {string} theme 主题名称
         */
        switch: function(theme) {
            if (!this.themes.includes(theme)) {
                console.warn(`[Theme] 未知主题: ${theme}`);
                return;
            }
            
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('weline-theme', theme);
            this.current = theme;
            
            // 触发主题切换事件
            const event = new CustomEvent('themechange', {
                detail: { theme: theme }
            });
            document.dispatchEvent(event);
        },
        
        /**
         * 初始化主题
         */
        init: function() {
            // 1. 检查用户保存的主题
            const savedTheme = localStorage.getItem('weline-theme');
            
            // 2. 检查系统主题偏好
            let systemTheme = 'light';
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                systemTheme = 'dark';
            }
            
            // 3. 使用保存的主题或系统主题
            const theme = savedTheme || systemTheme;
            this.switch(theme);
            
            // 4. 监听系统主题变化
            if (window.matchMedia) {
                window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                    if (!localStorage.getItem('weline-theme')) {
                        this.switch(e.matches ? 'dark' : 'light');
                    }
                });
            }
        },
        
        /**
         * 获取当前主题
         * @returns {string}
         */
        getCurrent: function() {
            return this.current;
        },
        
        /**
         * 检查是否为暗色主题
         * @returns {boolean}
         */
        isDark: function() {
            return this.current === 'dark';
        }
    };
    
    /**
     * 工具函数
     */
    const ThemeUtils = {
        /**
         * 获取CSS变量值
         * @param {string} varName 变量名
         * @returns {string}
         */
        getVariable: function(varName) {
            return getComputedStyle(document.documentElement)
                .getPropertyValue(varName).trim();
        },
        
        /**
         * 设置CSS变量值
         * @param {string} varName 变量名
         * @param {string} value 值
         */
        setVariable: function(varName, value) {
            document.documentElement.style.setProperty(varName, value);
        }
    };
    
    // 页面加载时初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            ThemeManager.init();
        });
    } else {
        ThemeManager.init();
    }
    
    // 挂载到全局
    if (typeof window.Weline !== 'undefined') {
        window.Weline.Theme = ThemeManager;
        window.Weline.ThemeUtils = ThemeUtils;
    } else {
        window.WelineTheme = ThemeManager;
        window.WelineThemeUtils = ThemeUtils;
    }
})();
```

**API说明**：
```javascript
// 切换主题
Weline.Theme.switch('dark');

// 获取当前主题
const currentTheme = Weline.Theme.getCurrent();

// 检查是否为暗色主题
if (Weline.Theme.isDark()) {
    // 暗色主题逻辑
}

// 获取CSS变量值
const primaryColor = Weline.ThemeUtils.getVariable('--color-primary');

// 设置CSS变量值
Weline.ThemeUtils.setVariable('--color-primary', '#ff0000');

// 监听主题切换事件
document.addEventListener('themechange', function(e) {
    console.log('主题已切换为:', e.detail.theme);
});
```

**使用方式**：
```php
<!-- 在 head.phtml 或 footer.phtml 中加载 -->
<script src="@static(Weline_Theme::theme/frontend/assets/js/theme.js)"></script>
```

---

## 图片资源说明

### `images/theme/` - 主题相关图片

**作用**：存放主题相关的图片资源

**文件列表**：
- `logo.svg` - Logo图片（SVG格式，支持主题色）
- `placeholder.png` - 占位图片

**使用方式**：
```php
<!-- 在模板中使用 -->
<img src="@static(Weline_Frontend::theme/assets/images/theme/logo.svg)" alt="Logo">
```

**图片规范**：
- 优先使用SVG格式（矢量图，支持缩放）
- 提供不同尺寸的版本（如需要）
- 优化图片大小，提高加载速度

---

## 文件加载顺序

### 1. CSS加载顺序

```php
<!-- 在 head.phtml 中 -->
<!-- 1. 先加载主题主样式（包含所有变量） -->
<link rel="stylesheet" href="@static(Weline_Frontend::theme/assets/css/theme.css)">

<!-- 2. 根据主题模式加载特定颜色主题（如果需要） -->
<?php
$themeMode = $themeConfig->getThemeModel() ?: 'light';
if ($themeMode && $themeMode !== 'light'): ?>
<link rel="stylesheet" href="@static(Weline_Frontend::theme/colors/_<?= $themeMode ?>.css)">
<?php endif; ?>
```

### 2. JavaScript加载顺序

```php
<!-- 在 head.phtml 中 -->
<!-- 1. 先加载jQuery（如果需要） -->
<js>Weline_Frontend::/libs/jquery/3.6.0/jquery.js</js>

<!-- 2. 配置 WelineConfig -->
<script>
    window.WelineConfig = {
        baseUrl: '<?= $baseUrl ?>',
        currentLang: '<?= $currentLang ?>',
        currentCurrency: '<?= $currentCurrency ?>'
    };
</script>

<!-- 3. 加载Weline核心JS（必须先加载） -->
<js>Weline_Theme::theme/frontend/assets/js/theme.js</js>

<!-- 4. 加载主题JS -->
<js>Weline_Theme::theme/frontend/assets/js/theme.js</js>
```

**加载顺序说明**：
1. **jQuery**：基础依赖（如果需要）
2. **WelineConfig**：配置对象，必须在 `theme.js` 之前定义
3. **theme.js**：核心功能，提供 `Weline` 全局对象
4. **theme.js**：主题功能，可以使用 `Weline` 对象

---

## 最佳实践

### 1. CSS组织

- 使用 `@import` 导入变量和组件样式
- 保持导入顺序：变量 → 主题 → 组件 → 工具类
- 避免循环依赖

### 2. JavaScript组织

- 使用IIFE避免全局污染
- 提供清晰的API
- 支持事件系统

### 3. 资源优化

- CSS文件可以合并压缩
- JavaScript文件可以压缩
- 图片资源优化大小

---

## 相关文档

- [variables/ 目录文档](./variables目录文档.md)
- [colors/ 目录文档](./colors目录文档.md)
- [components/ 目录文档](./components目录文档.md)

