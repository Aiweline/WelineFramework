# Theme.js 使用指南

## 概述

`Theme.js` 是 Weline Framework 的统一前端 JavaScript 入口文件，提供了轻量级的模块加载系统、主题管理、国际化、URL 解析等核心功能。它采用按需加载机制，确保页面加载速度，同时提供完整的开发体验。

## 文件位置

- **前端版本**: `app/code/Weline/Theme/view/theme/frontend/assets/js/theme.js`
- **后端版本**: `app/code/Weline/Theme/view/theme/backend/assets/js/theme.js`

## 核心特性

### 1. 轻量级设计
- 核心代码体积小，不阻塞页面加载
- 按需加载功能模块，减少初始加载时间
- 异步加载机制，不阻塞 JavaScript 单线程

### 2. 模块化架构
- 支持模块声明和按需加载
- 自动解析模块配置
- 支持自定义模块路径
- 模块代理机制，自动处理未加载模块的调用
- **延迟立即加载**：需要「最后再加载」的立即模块，可在 script 标签上加 `data-load-order="last"` 或传 `options.loadOrder: 'last'`（详见 [延迟立即加载](#延迟立即加载data-load-order--optionsloadorder)）

### 3. 配置管理
- 从 PHP 模板中注入配置（`window.__WelineThemeConfig`）
- 支持运行时配置更新
- 配置合并和深度合并机制

### 4. 主题管理
- **动态主题扫描**：自动扫描 `colors/` 目录下的主题文件（`_*.css`），动态获取可用主题列表
- 多主题支持（默认：light、dark、amazon，实际由扫描结果决定）
- 主题切换和持久化
- 自动检测系统主题偏好
- 主题切换事件通知

## 快速开始

### 1. 引入 Theme.js

在模板的 `<head>` 部分引入：

```php
<!-- 主题JS -->
<theme:js>Weline_Theme::theme/frontend/assets/js/theme.js</theme:js>
```

### 2. 配置初始化

在引入 Theme.js 之前，需要设置配置：

```php
<script>
    window.__WelineThemeConfig = {
        env: {
            WELINE_ENV: '<?= defined('DEV') && DEV ? 'DEV' : 'PROD' ?>',
            DEV: <?= defined('DEV') && DEV ? 'true' : 'false' ?>,
            PROD: <?= defined('DEV') && DEV ? 'false' : 'true' ?>
        },
        baseUrl: '<?= $baseUrl ?>',
        currentLang: '<?= $currentLang ?>',
        currentCurrency: '<?= $currentCurrency ?>',
        // ... 其他配置
    };
</script>
```

### 3. 主题初始化

Theme.js 会自动初始化主题，也可以手动初始化：

```javascript
// 自动初始化（推荐）
// Theme.js 会在 DOM 加载完成后自动调用 Weline.Theme.init()

// 手动初始化
if (window.Weline && window.Weline.Theme) {
    window.Weline.Theme.init();
}
```

## API 参考

### Weline 主对象

#### `Weline.config`
获取当前运行时配置对象。

```javascript
const config = Weline.config;
console.log(config.baseUrl);
console.log(config.currentLang);
```

#### `Weline.getConfig()`
获取配置对象的函数形式。

```javascript
const config = Weline.getConfig();
```

#### `Weline.applyConfig(config)`
应用新的配置（深度合并）。

**注意**：Account 模块的配置（如 `apiLoginUrl`、`apiLogoutUrl` 等）由 Account 模块内部自动处理，无需在此处配置。Account 模块会自动从全局配置中读取所需配置。

```javascript
Weline.applyConfig({
    debug: true
    // Account 模块配置由 Account 模块内部自动处理，无需手动配置
});
```

### 模块管理

#### `Weline.declare(moduleNames, loadImmediately, customPath, callback, options)`
声明模块（必须使用，方便 PHP 解析翻译词）。

**参数**:
- `moduleNames` (string|string[]): 模块名称或模块名称数组
- `loadImmediately` (boolean, 可选): 是否立即加载，默认 `false`
- `customPath` (string|string[], 可选): 自定义模块路径
- `callback` (Function, 可选): 加载完成后的回调函数
- `options` (Object, 可选): 配置选项，如 `{ loadOrder: 'last' }` 表示延迟到 DOMContentLoaded 后加载（显式参数优先于 script 标签属性）

**示例**:
```javascript
// 声明模块，按需加载
Weline.declare('api');

// 声明并立即加载
Weline.declare('account', true);

// 声明多个模块
Weline.declare(['api', 'account']);

// 使用自定义路径
Weline.declare('customModule', false, '/custom/path/module.js');

// 带回调函数
Weline.declare('api', true, null, function() {
    console.log('API 模块加载完成');
});

// 延迟立即加载（使用显式参数）
Weline.declare('search', true, 'WeShop_Search::js/search.js', null, { loadOrder: 'last' });
```

#### 延迟立即加载（data-load-order / options.loadOrder）

当 `loadImmediately === true` 时，可通过以下方式将**实际拉取脚本**推迟到 DOMContentLoaded 之后执行，避免与其它 head 脚本并行导致的栈溢出（如 `Maximum call stack size exceeded`）：

**方式一：script 标签属性**

在包含 `Weline.declare(..., true, ...)` 的 `<script>` 标签上添加 `data-load-order="last"`（或 `"defer"`）：

```html
<script data-load-order="last">
    Weline.declare('search', true, 'WeShop_Search::js/search.js');
</script>
```

**方式二：显式参数（推荐用于异步/回调场景）**

传入 `options.loadOrder: 'last'`，适用于在 setTimeout、事件回调等异步上下文中调用：

```javascript
// 异步场景中使用显式参数指定延迟加载
setTimeout(() => {
    Weline.declare('myModule', true, 'My_Module::js/module.js', null, { loadOrder: 'last' });
}, 1000);
```

**优先级**：显式参数 `options.loadOrder` 优先于 script 标签的 `data-load-order` 属性。

**注意**：`document.currentScript` 仅在同步内联 script 执行时有效；在异步场景（setTimeout/事件回调）中调用 declare 时，需使用显式参数 `options.loadOrder` 指定延迟加载。

#### `Weline.load(moduleNames, customPath, callback)`
立即加载模块。

**参数**:
- `moduleNames` (string|string[]): 模块名称或模块名称数组
- `customPath` (string|string[], 可选): 自定义模块路径
- `callback` (Function, 可选): 加载完成后的回调函数

**返回值**: Promise

**示例**:
```javascript
// 加载单个模块
await Weline.load('api');

// 加载多个模块
await Weline.load(['api', 'account']);

// 使用自定义路径
await Weline.load('customModule', '/custom/path/module.js');

// 带回调函数
Weline.load('api', null, function() {
    console.log('API 模块加载完成');
});
```

#### `Weline.use(moduleName)`
使用模块（确保已加载，如果未加载则立即加载）。

**参数**:
- `moduleName` (string): 模块名称

**返回值**: Promise

**示例**:
```javascript
const apiModule = await Weline.use('api');
apiModule.request('/api/data');
```

### 主题管理

#### `Weline.Theme.init()`
初始化主题系统。会自动检测保存的主题或系统主题偏好。

```javascript
Weline.Theme.init();
```

#### `Weline.Theme.switch(theme)`
切换主题。

**参数**:
- `theme` (string): 主题名称（从 `colors/` 目录动态扫描获取，如 'light'、'dark'、'amazon'）

**说明**:
- 主题列表会从 `Weline_Theme::theme/{area}/colors/` 目录下的 `_*.css` 文件中动态扫描获取
- 前端区域扫描 `frontend/colors/`，后端区域扫描 `backend/colors/`
- 如果主题不存在，会在开发模式下显示警告信息

**示例**:
```javascript
// 切换到暗色主题
Weline.Theme.switch('dark');

// 切换到亮色主题
Weline.Theme.switch('light');

// 获取可用主题列表
const availableThemes = Weline.Theme.themes;
console.log('可用主题:', availableThemes);
```

#### `Weline.Theme.getCurrent()`
获取当前主题。

**返回值**: string

**示例**:
```javascript
const currentTheme = Weline.Theme.getCurrent();
console.log('当前主题:', currentTheme); // 'light' | 'dark' | 'amazon'
```

#### `Weline.Theme.isDark()`
检查当前是否为暗色主题。

**返回值**: boolean

**示例**:
```javascript
if (Weline.Theme.isDark()) {
    console.log('当前是暗色主题');
}
```

#### `Weline.Theme.applyConfig(config)`
应用主题配置。

**参数**:
- `config` (object): 配置对象

**示例**:
```javascript
Weline.Theme.applyConfig({
    theme: {
        current: 'dark',
        available: ['light', 'dark', 'amazon']
    }
});
```

### URL 管理

#### `Weline.Url.resolve(path, options)`
解析 URL 路径。

**参数**:
- `path` (string): 路径
- `options` (object, 可选): 选项
  - `type` (string): 类型（'frontendApi'、'backendApi'、'frontend'、'backend'）
  - `currency` (string): 货币代码
  - `locale` (string): 语言代码

**返回值**: string

**示例**:
```javascript
// 解析前端 API URL
const apiUrl = Weline.Url.resolve('/api/data', { type: 'frontendApi' });

// 解析后端 API URL
const backendUrl = Weline.Url.resolve('/api/admin/data', { type: 'backendApi' });
```

#### `Weline.Url.getCurrentCurrency()`
获取当前货币。

**返回值**: string

**示例**:
```javascript
const currency = Weline.Url.getCurrentCurrency();
console.log('当前货币:', currency); // 'CNY'
```

#### `Weline.Url.getCurrentLocale()`
获取当前语言。

**返回值**: string

**示例**:
```javascript
const locale = Weline.Url.getCurrentLocale();
console.log('当前语言:', locale); // 'zh_Hans_CN'
```

### 国际化 (i18n)

#### `Weline.i18n.translate(key, params)`
翻译文本。

**参数**:
- `key` (string): 翻译键
- `params` (object, 可选): 参数对象

**返回值**: string

**示例**:
```javascript
const text = Weline.i18n.translate('Hello, %{name}!', { name: 'World' });
console.log(text); // 'Hello, World!'
```

#### `Weline.i18n.switchLang(lang)`
切换语言。

**参数**:
- `lang` (string): 语言代码

**示例**:
```javascript
Weline.i18n.switchLang('en_US');
```

### 本地化 (Locale)

#### `Weline.Locale.switchCurrency(currency)`
切换货币。

**参数**:
- `currency` (string): 货币代码（如 'CNY'、'USD'）

**示例**:
```javascript
Weline.Locale.switchCurrency('USD');
```

#### `Weline.Locale.switchLang(lang)`
切换语言。

**参数**:
- `lang` (string): 语言代码（如 'zh_Hans_CN'、'en_US'）

**示例**:
```javascript
Weline.Locale.switchLang('en_US');
```

### 主题工具函数

#### `Weline.ThemeUtils.getVariable(varName)`
获取 CSS 变量值。

**参数**:
- `varName` (string): CSS 变量名（如 '--color-primary'）

**返回值**: string

**示例**:
```javascript
const primaryColor = Weline.ThemeUtils.getVariable('--color-primary');
console.log('主色调:', primaryColor);
```

#### `Weline.ThemeUtils.setVariable(varName, value)`
设置 CSS 变量值。

**参数**:
- `varName` (string): CSS 变量名
- `value` (string): 变量值

**示例**:
```javascript
Weline.ThemeUtils.setVariable('--color-primary', '#ff0000');
```

### 静态资源解析器

#### `Weline.staticResourceResolver.resolve(path)`
解析静态资源路径。

**参数**:
- `path` (string): 资源路径

**返回值**: string

**示例**:
```javascript
const imageUrl = Weline.staticResourceResolver.resolve('Weline_Theme::theme/frontend/assets/images/logo.png');
```

## 使用示例

### 示例 1: 基本使用

```javascript
// 等待 Weline 初始化
if (window.Weline) {
    // 初始化主题
    Weline.Theme.init();
    
    // 切换主题
    document.getElementById('theme-toggle').addEventListener('click', function() {
        const currentTheme = Weline.Theme.getCurrent();
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        Weline.Theme.switch(newTheme);
    });
}
```

### 示例 2: 模块加载

```javascript
// 声明并加载 API 模块
Weline.declare('api', true, null, function() {
    console.log('API 模块已加载');
    
    // 使用 API 模块
    Weline.Api.request('/api/data', {
        method: 'GET'
    }).then(function(response) {
        console.log('数据:', response);
    });
});
```

### 示例 3: 主题切换监听

```javascript
// 监听主题切换事件
document.addEventListener('themechange', function(event) {
    console.log('主题已切换为:', event.detail.theme);
    
    // 根据主题更新 UI
    if (event.detail.theme === 'dark') {
        // 暗色主题逻辑
    } else {
        // 亮色主题逻辑
    }
});
```

### 示例 4: 使用 data-weline-load 属性

在 HTML 中使用 `data-weline-load` 属性自动加载模块：

```html
<div data-weline-load="api,account">
    <!-- 这个元素会在模块加载完成后触发 weline-modules-loaded 事件 -->
</div>

<script>
document.querySelector('[data-weline-load]').addEventListener('weline-modules-loaded', function(event) {
    console.log('模块已加载:', event.detail.modules);
    // 使用加载的模块
    Weline.Api.request('/api/data');
});
</script>
```

### 示例 5: 配置更新

```javascript
// 更新配置
Weline.applyConfig({
    debug: true
});

// 注意：Account 模块的配置（如 apiLoginUrl 等）由 Account 模块内部自动处理
// 无需在外部手动配置，Account 模块会自动从全局配置中读取
```

### 示例 6: URL 解析

```javascript
// 解析前端 API URL（会自动添加货币和语言）
const apiUrl = Weline.Url.resolve('/api/products', { type: 'frontendApi' });
console.log('API URL:', apiUrl); // /api/CNY/zh_Hans_CN/products

// 解析后端 API URL
const backendUrl = Weline.Url.resolve('/api/admin/users', { type: 'backendApi' });
console.log('后端 API URL:', backendUrl);
```

### 示例 7: 国际化使用

```javascript
// 翻译文本
const greeting = Weline.i18n.translate('Hello, %{name}!', { name: 'World' });
console.log(greeting); // 'Hello, World!'

// 切换语言
Weline.i18n.switchLang('en_US');
```

### 示例 8: CSS 变量操作

```javascript
// 获取 CSS 变量
const primaryColor = Weline.ThemeUtils.getVariable('--color-primary');
console.log('主色调:', primaryColor);

// 设置 CSS 变量
Weline.ThemeUtils.setVariable('--color-primary', '#ff0000');

// 动态更新主题色
function updateThemeColor(color) {
    Weline.ThemeUtils.setVariable('--color-primary', color);
}
```

## 事件系统

### themechange 事件

当主题切换时触发。

**事件对象**:
```javascript
{
    detail: {
        theme: 'dark' // 新主题名称
    }
}
```

**示例**:
```javascript
document.addEventListener('themechange', function(event) {
    console.log('主题已切换为:', event.detail.theme);
});
```

### weline-modules-loaded 事件

当通过 `data-weline-load` 属性加载的模块完成加载时触发。

**事件对象**:
```javascript
{
    detail: {
        modules: ['api', 'account'], // 已加载的模块列表
        element: HTMLElement // 触发事件的元素
    }
}
```

**示例**:
```javascript
document.querySelector('[data-weline-load]').addEventListener('weline-modules-loaded', function(event) {
    console.log('模块已加载:', event.detail.modules);
});
```

### weline-modules-error 事件

当模块加载失败时触发。

**事件对象**:
```javascript
{
    detail: {
        modules: ['api'], // 失败的模块列表
        error: Error, // 错误对象
        element: HTMLElement // 触发事件的元素
    }
}
```

## 配置说明

### 完整配置结构

```javascript
window.__WelineThemeConfig = {
    // 环境配置
    env: {
        WELINE_ENV: 'DEV' | 'PROD',
        DEV: true | false,
        PROD: true | false
    },
    
    // 基础配置
    baseUrl: 'http://example.com',
    currentLang: 'zh_Hans_CN',
    currentCurrency: 'CNY',
    debug: true | false,
    
    // 模块配置
    modulesBaseUrl: '/Weline/Frontend/view/statics/js/weline-api',
    modulesConfigUrl: '/Weline/Frontend/view/statics/base/weline.modules.js',
    
    // API 配置
    api: {
        workerUrl: '/Weline/Frontend/view/statics/js/weline-api-worker.js',
        cartCountCookieKey: 'weline_cart_item_count',
        tokenRefreshPeriod: 300,
        tokenRefreshBeforeExpire: 60
    },
    
    // 账户配置（由 Account 模块内部自动处理，无需手动配置）
    // Account 模块会自动从全局配置中读取这些配置项
    // 如需自定义，请参考 Account 模块文档
    account: {
        // 这些配置由 Account 模块内部管理，详见 Account 模块文档
    },
    
    // URL 配置
    url: {
        origin: 'http://example.com',
        apiArea: 'api',
        apiAdminArea: 'api_admin',
        defaultCurrency: 'CNY',
        defaultLocale: 'zh_Hans_CN'
    },
    
    // 国际化配置
    i18n: {
        currentLang: 'zh_Hans_CN',
        dictionary: {
            // 翻译字典
        },
        apiUrl: '/i18n/frontend/word/get-translations'
    },
    
    // 主题配置（available 数组由 PHP 动态扫描 colors 目录生成）
    theme: {
        current: 'light',
        available: ['light', 'dark', 'amazon'] // 动态扫描 colors/ 目录下的 _*.css 文件
    },
    
    // 站点配置
    site: {
        name: 'Weline Framework',
        title: '欢迎使用WelineFramework框架！',
        description: 'Weline Framework - 现代化的PHP框架',
        keywords: 'Weline, Framework, PHP'
    }
};
```

## 最佳实践

### 1. 模块声明
- **必须使用 `Weline.declare()` 声明模块**，这样 PHP 才能正确解析翻译词
- 优先使用按需加载，只在必要时立即加载模块

### 2. 主题管理
- 让 Theme.js 自动初始化主题，不要手动调用 `Weline.Theme.init()` 除非必要
- 使用 `themechange` 事件监听主题切换，而不是轮询检查
- **主题列表会自动从 `colors/` 目录扫描**，无需手动配置
- 添加新主题时，只需在 `colors/` 目录下添加 `_{themeName}.css` 文件即可

### 3. 配置管理
- 在 PHP 模板中设置 `window.__WelineThemeConfig`，而不是在 JavaScript 中硬编码
- 使用 `Weline.applyConfig()` 更新配置，而不是直接修改 `Weline.config`
- 主题列表由 PHP 动态扫描生成，无需手动维护

### 4. 错误处理
- 使用 Promise 的 `.catch()` 处理模块加载错误
- 监听 `weline-modules-error` 事件处理自动加载失败的情况

### 5. 性能优化
- 使用 `data-weline-load` 属性延迟加载非关键模块
- 避免在页面加载时立即加载所有模块

### 6. 主题开发
- 主题文件命名规范：`colors/_{themeName}.css`（下划线前缀 + 主题名 + .css 后缀）
- 前端主题放在 `view/theme/frontend/colors/` 目录
- 后端主题放在 `view/theme/backend/colors/` 目录（如果支持）
- 主题列表会自动扫描，无需修改代码

## 常见问题

### Q: 为什么必须使用 `Weline.declare()` 声明模块？

A: `Weline.declare()` 不仅声明模块，还帮助 PHP 解析模板中的翻译词。PHP 在编译模板时会扫描 `Weline.declare()` 调用，提取需要翻译的文本。

### Q: 模块加载失败怎么办？

A: 检查以下几点：
1. 模块路径是否正确
2. 模块文件是否存在
3. 模块是否正确导出了全局变量
4. 检查浏览器控制台的错误信息

### Q: 如何自定义模块路径？

A: 在 `Weline.declare()` 或 `Weline.load()` 中传入 `customPath` 参数：

```javascript
Weline.declare('customModule', false, '/custom/path/module.js');
```

### Q: 主题切换后如何更新页面内容？

A: 监听 `themechange` 事件：

```javascript
document.addEventListener('themechange', function(event) {
    // 根据新主题更新页面内容
    updatePageContent(event.detail.theme);
});
```

### Q: 如何添加新的主题？

A: 只需在对应的 `colors/` 目录下添加主题文件即可：

1. **前端主题**：在 `app/code/Weline/Theme/view/theme/frontend/colors/` 目录下添加 `_{themeName}.css` 文件
2. **后端主题**：在 `app/code/Weline/Theme/view/theme/backend/colors/` 目录下添加 `_{themeName}.css` 文件（如果支持）

例如，添加一个名为 `blue` 的主题：
- 创建文件：`app/code/Weline/Theme/view/theme/frontend/colors/_blue.css`
- 主题会自动出现在可用主题列表中，无需修改代码

### Q: 主题列表是如何获取的？

A: 主题列表由 PHP 在模板渲染时动态扫描 `colors/` 目录生成：
- 扫描 `view/theme/{area}/colors/_*.css` 文件
- 从文件名中提取主题名称（去掉 `_` 前缀和 `.css` 后缀）
- 将主题列表传递给 JavaScript 配置
- Theme.js 从配置中读取可用主题列表

### Q: 如何获取当前可用的主题列表？

A: 使用 `Weline.Theme.themes` 属性：

```javascript
const availableThemes = Weline.Theme.themes;
console.log('可用主题:', availableThemes); // ['light', 'dark', 'amazon', ...]
```

### Q: 如何获取当前配置？

A: 使用 `Weline.config` 或 `Weline.getConfig()`：

```javascript
const config = Weline.config;
// 或
const config = Weline.getConfig();
```

## 相关文档

- [Theme 模块 README](../README.md)
- [Partials 配置系统使用指南](./Partials配置系统使用指南.md)
- [Frontend 主题设计文档](../../Weline/Frontend/doc/主题设计/README.md)

## 更新日志

### v1.0.0
- 初始版本
- 支持模块声明和按需加载
- 支持主题管理
- 支持国际化
- 支持 URL 解析

