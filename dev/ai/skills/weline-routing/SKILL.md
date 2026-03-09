---
name: weline-routing
description: |
  Weline Framework routing standards, URL structure, and URL generation.
  
  MUST use when:
  - Creating controllers, defining routes, generating URLs
  - Fixing 404/405 routing errors or language/currency parsing issues
  - Working with URL structure: /<backendKey>/<currency>/<language>/<module>/<controller>/<action>
  - Understanding WELINE_USER_LANG, WELINE_USER_CURRENCY, $_SERVER parsing
  - WLS state management for URL-related variables
  
  Keywords: URL, 路由, 路由解析, URL结构, 语言, 货币, currency, language, locale,
  WELINE_USER_LANG, WELINE_USER_CURRENCY, Url::parser, getUrl, getBackendUrl,
  404, 405, 路由错误, backend, frontend, rest_api, area, 区域,
  router, backend_router, env.php, menu, action, 模块路由, weline_order
globs:
  - "**/Controller/**/*.php"
  - "**/Http/Url.php"
  - "**/Runtime/WlsRuntime.php"
alwaysApply: false
---

# Weline Framework 路由与 URL 解析技能

## 何时使用此技能

**必须参考此技能的场景：**
- ✅ 创建新的控制器方法
- ✅ 设计 RESTful API 端点
- ✅ 使用 `$this->getUrl()` 生成 URL
- ✅ 前端调用后端 API（fetch/ajax）
- ✅ 修复路由解析错误（404/405）
- ✅ 重构现有控制器方法
- ✅ **URL 语言/货币不稳定或解析错误**
- ✅ **WLS 模式下 WELINE_* 变量问题**
- ✅ **理解 URL 结构和区域判断**

**相互参照：**
- 创建控制器 → 参考 `module-development` + `weline-routing`
- 生成URL → 参考 `weline-routing`
- 模板中 URL → 使用 `@backend-url`、`@url` 等静态 @ 标签（见下方「模板 URL 标签」）
- 用户提示 → 参考 `friendly-notifications`
- WLS 状态问题 → 参考 `weline-server`（状态管理章节）

## 目的
确保控制器方法命名和路由生成遵循 Weline Framework 的路由约定，避免 HTTP 方法冲突和路由解析错误。

---

## URL 结构（重要！）

### Weline URL 完整结构

```
https://domain.com/<backendKey>/<currency>/<language>/<module>/<area>/<controller>/<action>
```

**示例：**
```
https://127.0.0.1:9981/f7LYPUzS4UD9UL1kqkf0hzzPxyxmvT8c/USD/zh_Hans_CN/media/backend/manager
                      │                                  │   │          │     │       │
                      │                                  │   │          │     │       └─ 控制器: Manager
                      │                                  │   │          │     └─ 区域: backend
                      │                                  │   │          └─ 模块: media (Weline_MediaManager)
                      │                                  │   └─ 语言: zh_Hans_CN
                      │                                  └─ 货币: USD
                      └─ 后台访问密钥 (env.php 配置)
```

### URL 段解析规则

| 段位置 | 内容 | 识别规则 | 设置到 |
|--------|------|----------|--------|
| 第1段 | 后台密钥 | 匹配 `env.php` 中的 `backend_router` | `WELINE_AREA=backend` |
| 第2段 | 货币 | 3 位大写字母（USD, CNY, EUR...） | `WELINE_USER_CURRENCY` |
| 第3段 | 语言 | 格式 `xx_Xxxx_XX`（如 `zh_Hans_CN`） | `WELINE_USER_LANG` |
| 第4段 | 模块 | 模块路由标识 | `REQUEST_URI` |
| 第5段 | 区域 | `backend` / `frontend` | `WELINE_AREA` 确认 |
| 第6段+ | 控制器/动作 | 路由解析 | `REQUEST_URI` |

### 货币识别规则

```php
// 货币代码必须满足:
strlen($code) === 3 && ctype_upper($code)
// 示例: USD, CNY, EUR, JPY, GBP
```

### 语言识别规则

```php
// 语言代码必须满足:
strlen($code) > 3 && strlen($code) <= 10 
    && ctype_lower(substr($code, 0, 2))  // 前两位小写
    && $code[2] === '_'                   // 第三位是下划线
// 示例: zh_Hans_CN, en_US, ja_JP
```

### 区域（Area）类型

| 区域值 | 说明 | 路由前缀 |
|--------|------|----------|
| `frontend` | 前台 PC 页面 | 无或网站前缀 |
| `backend` | 后台管理 | `/<backendKey>/` |
| `rest_frontend` | 前台 REST API | `/api/` |
| `rest_backend` | 后台 REST API | `/<backendKey>/api/` |

### 模块路由配置（router / backend_router）

- **配置位置**：各模块 `etc/env.php`，键 `router`（前端）、`backend_router`（后台）。
- **默认行为**：未配置时由 `Handle::getEnv()` 设置 `router = strtolower(module_name)`（如 `Weline_Order` → `weline_order`），`backend_router` 未配置时与 `router` 一致。
- **菜单 action 中的 `*`**：`MenuCollector::replaceModuleAction()` 在收集菜单（`s:up`）时，将 `*/backend/控制器/方法` 中的 `*` 替换为**当前菜单所属模块**的路由；替换时使用回退链：
  - 后台菜单：优先 `backend_router`，空则用 `router`，再空则 `strtolower(module_name)`；
  - 前台菜单：优先 `router`，空则 `strtolower(module_name)`。
- **Hook/模板中的跨模块 URL**：`getBackendUrl('*/backend/...')` 也会按当前模块上下文解析 `*`。若目标接口属于其他模块（如 `server/backend/...`），必须显式写目标模块前缀，避免在 PageBuilder/Theme 等上下文下误命中 404。
- **自定义路由**：在模块 `etc/env.php` 中显式配置 `router` / `backend_router` 时，以配置为准；配置后需执行 `php bin/w s:up` 与 `php bin/w setup:upgrade --route` 使菜单与路由生效。

---

## URL 解析流程（WLS 模式）

### 解析时序

```
1. WlsRuntime::handle()
   │
   ├─ RequestContext::init()          # 初始化请求上下文
   │     └─ syncFromServer()          # 从 $_SERVER 同步 WELINE_* 变量
   │
   ├─ GlobalsEmulator::emulate()      # 模拟 $_GET/$_POST/$_COOKIE/$_SERVER
   │
   ├─ Url::parser()                   # 核心 URL 解析
   │     ├─ 检测后台密钥 → WELINE_AREA
   │     ├─ 检测货币 → WELINE_USER_CURRENCY
   │     ├─ 检测语言 → WELINE_USER_LANG
   │     └─ 解析纯路由 → REQUEST_URI
   │
   └─ processUrlParse()               # 将解析结果写入 $_SERVER 和 RequestContext
```

### 关键变量来源

| 变量 | 来源优先级 |
|------|-----------|
| `WELINE_USER_LANG` | URL 路径 > Cookie > 网站默认 > `zh_Hans_CN` |
| `WELINE_USER_CURRENCY` | URL 路径 > Cookie > 网站默认 > `CNY` |
| `WELINE_AREA` | URL 路径解析（后台密钥/API前缀） |
| `WELINE_WEBSITE_ID` | URL 路径匹配网站配置 |

---

## WLS 状态管理（URL 相关）⚠️

### 必须重置的 URL 相关变量

在 WLS 模式下，以下 URL 相关变量**必须在请求结束后重置**，否则会导致跨请求污染：

| 类 | 变量 | 重置方式 | 说明 |
|----|------|----------|------|
| `Url` | `$parserServer` | `StateManager` | URL 解析结果缓存 |
| `Url` | `$parserCache` | `StateManager` | URL 缓存 |
| `Url` | `$parserLanguages` | `StateManager` | 语言代码缓存 |
| `Url` | `$parserCurrencies` | `StateManager` | 货币代码缓存 |
| `RequestContext` | `$_userLang` | `resetWelineVars()` | 当前用户语言 |
| `RequestContext` | `$_userCurrency` | `resetWelineVars()` | 当前用户货币 |

### 常见问题：语言/货币不稳定

**症状：** 刷新页面时语言或货币在不同值之间交替切换。

**根本原因：** `$_SERVER['WELINE_USER_LANG']` 被设为**空字符串**而非 `unset`，导致 `??` 运算符无法回退到默认值。

**错误代码：**
```php
// ❌ 错误：设为空字符串
$_SERVER['WELINE_USER_LANG'] = '';

// 后续代码无法正确回退
$lang = $_SERVER['WELINE_USER_LANG'] ?? 'zh_Hans_CN';  // 得到 ''，不是 'zh_Hans_CN'
```

**正确做法：**
```php
// ✅ 正确：unset 变量
unset($_SERVER['WELINE_USER_LANG']);

// 后续代码正确回退
$lang = $_SERVER['WELINE_USER_LANG'] ?? 'zh_Hans_CN';  // 得到 'zh_Hans_CN'
```

**修复位置：**
- `RequestContext::resetWelineVars()` - 使用 `unset()` 而非赋空字符串
- `GlobalsEmulator::buildServerArray()` - 不设置 `WELINE_USER_LANG`/`WELINE_USER_CURRENCY`
- `WlsRuntime::processUrlParse()` - 不设置空字符串默认值

---

## Weline Framework 路由规则

### 1. 控制器方法前缀与 HTTP 方法映射

控制器方法名的**前缀**决定了 HTTP 请求方法：

| 方法前缀 | HTTP 方法 | 示例方法名 | 路由示例 | 说明 |
|---------|----------|-----------|---------|------|
| `get` | GET | `getList()` | `/controller/list` | 查询操作 |
| `post` | POST | `postSave()` | `/controller/save` | 创建/提交操作 |
| `put` | PUT | `putUpdate()` | `/controller/update` | 更新操作 |
| `delete` | DELETE | `deleteRemove()` | `/controller/remove` | 删除操作 |
| `patch` | PATCH | `patchModify()` | `/controller/modify` | 部分更新 |
| 无前缀 | GET | `index()` | `/controller/index` | 默认GET |

### 2. 路由 URL 生成规则

控制器方法名转换为 URL 路径：

```
控制器方法名 → 去除HTTP前缀 → 驼峰转短横线 → 小写
```

**示例：**

| 控制器方法 | 去除前缀 | 路由路径 |
|-----------|---------|---------|
| `postSaveWidget()` | `SaveWidget` | `/controller/save-widget` |
| `getWidgetList()` | `WidgetList` | `/controller/widget-list` |
| `postUpdateConfig()` | `UpdateConfig` | `/controller/update-config` |
| `deleteWidget()` | `Widget` | `/controller/widget` |

### 3. 关键字冲突问题 ⚠️

**重要：避免在路由路径中使用 HTTP 方法关键字**

路由解析器可能将路径中的 HTTP 方法关键字（`get`, `post`, `put`, `delete`, `patch`）误识别为方法限定符。

**❌ 错误示例：**

```php
// 控制器方法
public function postDeleteOrphanWidgets() { }

// 生成的路由: /controller/delete-orphan-widgets
// 问题：路径包含 "delete"，可能被误识别为 DELETE 请求
// 实际：方法前缀是 post，应该接受 POST 请求
// 结果：POST 请求可能路由失败
```

**✅ 正确示例：**

```php
// 方案1：使用同义词
public function postRemoveOrphanWidgets() { }
// 路由: /controller/remove-orphan-widgets

// 方案2：使用业务术语
public function postCleanOrphanWidgets() { }
// 路由: /controller/clean-orphan-widgets

// 方案3：使用动作词
public function postPurgeOrphanWidgets() { }
// 路由: /controller/purge-orphan-widgets
```

### 4. 推荐命名模式

#### GET 请求（查询）
```php
public function getList()           // 获取列表
public function getDetail()         // 获取详情
public function getSearch()         // 搜索
public function getExport()         // 导出
public function getPreview()        // 预览
```

#### POST 请求（创建/提交）
```php
public function postSave()          // 保存（新建或更新）
public function postCreate()        // 创建
public function postSubmit()        // 提交
public function postImport()        // 导入
public function postUpload()        // 上传
```

**POST 方法中获取 JSON 数据的正确方式：**

```php
public function postSave()
{
    // 方式1：获取 JSON 请求体（推荐用于 Content-Type: application/json）
    $bodyParams = $this->request->getBodyParams();
    
    if (is_string($bodyParams)) {
        $decoded = json_decode($bodyParams, true);
        $data = ($decoded !== null && is_array($decoded)) ? $decoded : $this->request->getParams();
    } elseif (is_array($bodyParams) && !empty($bodyParams)) {
        $data = $bodyParams;
    } else {
        $data = $this->request->getParams();
    }
    
    // 方式2：获取单个参数（用于 form-data 或 URL 参数）
    $id = $this->request->getParam('id');
    $name = $this->request->getParam('name', 'default_value');
    
    // 方式3：获取所有参数
    $allParams = $this->request->getParams();
}
```

#### PUT 请求（完整更新）
```php
public function putUpdate()         // 完整更新
public function putReplace()        // 替换
```

#### DELETE 请求（删除）
```php
// ❌ 避免：deleteDelete(), deleteRemove()
// ✅ 推荐：
public function deleteById()        // 按ID删除: /controller/by-id (DELETE)
public function deleteItem()        // 删除项: /controller/item (DELETE)
public function deleteBatch()       // 批量删除: /controller/batch (DELETE)
```

#### 删除操作使用 POST（推荐）⭐
为避免冲突，删除操作建议使用 POST + 明确的动作词：

```php
// ✅ 推荐方式
public function postRemove()        // 移除: /controller/remove (POST)
public function postDestroy()       // 销毁: /controller/destroy (POST)
public function postPurge()         // 清除: /controller/purge (POST)
public function postClean()         // 清理: /controller/clean (POST)
public function postClear()         // 清空: /controller/clear (POST)
```

### 5. 路由 URL 生成方法

#### 5.1 在 Controller 中生成 URL

在模板或控制器中生成路由 URL：

```php
// 基础路由
$this->getUrl('module/controller/action')

// 带参数
$this->getUrl('theme/backend/theme-editor/save-widget', ['theme_id' => 5])

// 示例
$saveUrl = $this->getUrl('theme/backend/theme-editor/save-widget');
// 输出: /backend/theme-editor/save-widget

$previewUrl = $this->getUrl('theme/backend/theme-editor/layout-preview', [
    'theme_id' => $themeId,
    'layout_type' => 'homepage',
    'editor_mode' => '1'
]);
// 输出: /backend/theme-editor/layout-preview?theme_id=5&layout_type=homepage&editor_mode=1
```

#### 5.2 在非 Controller 类中生成 URL ⚠️

**Observer / Service / Helper 等类中生成 URL 时，必须注入 `Weline\Framework\Http\Url` 服务！**

**❌ 错误做法：硬编码 URL**
```php
// Observer 中 - 错误示例
private function injectScript(string $html): string
{
    $js = <<<JS
fetch('/backend/theme-editor/remove-widget', { method: 'POST' });
JS;
    return $html . "<script>{$js}</script>";
}
```

**✅ 正确做法：注入 Url 服务**
```php
namespace Weline\Theme\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Url;  // 导入 Url 服务

class MyObserver implements ObserverInterface
{
    private Url $url;  // 声明属性
    
    // 在构造函数中注入
    public function __construct(Url $url)
    {
        $this->url = $url;
    }
    
    public function execute(Event &$event): void
    {
        // 使用 getBackendUrl() 生成后台URL
        $removeUrl = $this->url->getBackendUrl('theme/backend/theme-editor/remove-widget');
        // 输出: /backend/theme-editor/remove-widget
        
        // 或使用 getUrl() 生成前台URL
        $frontUrl = $this->url->getUrl('catalog/product/view', ['id' => 123]);
        
        // 在 HTML/JS 中使用
        $js = <<<JS
fetch('{$removeUrl}', { method: 'POST' });
JS;
    }
}
```

**URL 生成方法对比：**

| 方法 | 适用场景 | 示例 |
|------|---------|------|
| `$this->url->getUrl()` | 前台路由 | `catalog/product/view` |
| `$this->url->getBackendUrl()` | 后台路由 | `theme/backend/theme-editor/save` |
| `$this->url->getBackendApiUrl()` | 后台API路由 | `api/products` |

**注意事项：**
- ❌ **禁止硬编码** `/backend/...` 或 `/frontend/...`
- ✅ **始终使用** `Url` 服务生成完整URL
- ✅ **路径格式** 始终为 `module/controller/action`
- ✅ **后台路由** 必须使用 `getBackendUrl()`

#### 5.3 模板中的 URL 生成（@ 静态标签）

模板中（尤其是 Taglib 属性、`<script>` 内）应使用 **@ 静态标签** 而非 PHP 输出，因 Taglib 属性值可能不解析 PHP、导致 URL 原样输出为 `<?= ... ?>` 字面量：

| 标签 | 用途 |
|------|------|
| `@url('path')` | 前台 URL |
| `@frontend-url('path')` | 前台 URL |
| `@backend-url('path')` | 后台 URL |
| `@api('path')` | API URL |
| `@backend-api('path')` | 后台 API URL |

```html
<!-- ❌ Taglib 属性中 PHP 可能不解析 -->
<w:theme:sse-terminal url="<?= $this->getBackendUrl('blog/backend/post/sse') ?>"/>

<!-- ✅ 使用 @backend-url 在编译期展开 -->
<w:theme:sse-terminal url="@backend-url('blog/backend/post/sse')"/>

<!-- href、action、JS 变量同理 -->
<a href="@backend-url('admin/dashboard')">仪表盘</a>
<script>var url = '@backend-api('api/data')';</script>
```

### 6. 前端调用规范

#### JavaScript Fetch API（JSON 数据）

```javascript
// POST 请求 - JSON 格式（推荐）
fetch('/backend/theme-editor/save-widget', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',  // 必须设置
    },
    body: JSON.stringify({
        theme_id: 5,
        widget_code: 'banner'
    })
})
.then(response => response.json())
.then(data => console.log(data));

// GET 请求（可省略 method）
fetch('/backend/theme-editor/widget-list?theme_id=5')
    .then(response => response.json())
    .then(data => console.log(data));

// DELETE 请求
fetch('/backend/theme-editor/widget/123', {
    method: 'DELETE'
})
.then(response => response.json())
.then(data => console.log(data));

// FormData 格式（用于文件上传）
const formData = new FormData();
formData.append('file', fileInput.files[0]);
formData.append('name', 'example');

fetch('/backend/upload', {
    method: 'POST',
    // 不要设置 Content-Type，让浏览器自动设置为 multipart/form-data
    body: formData
})
.then(response => response.json())
.then(data => console.log(data));
```

## 常见问题

### Q1: 如何在控制器中获取 POST JSON 数据？

**推荐方式：**

```php
public function postSave()
{
    // 1. 获取 JSON 请求体
    $bodyParams = $this->request->getBodyParams();
    
    // 2. 处理不同格式
    if (is_string($bodyParams)) {
        $decoded = json_decode($bodyParams, true);
        $data = ($decoded !== null && is_array($decoded)) ? $decoded : $this->request->getParams();
    } elseif (is_array($bodyParams) && !empty($bodyParams)) {
        $data = $bodyParams;
    } else {
        $data = $this->request->getParams();
    }
    
    // 3. 获取具体参数（带默认值）
    $themeId = (int)($data['theme_id'] ?? $this->request->getParam('theme_id', 0));
    $slotIds = $data['slot_ids'] ?? $this->request->getParam('slot_ids', []);
    
    // 4. 验证参数
    if (!$themeId || empty($slotIds)) {
        return $this->fetchJson([
            'success' => false,
            'message' => __('参数不完整')
        ]);
    }
    
    // 5. 处理业务逻辑
    // ...
}
```

**错误做法：**

```php
// ❌ 错误：直接使用 file_get_contents('php://input')
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

// ❌ 错误：使用不存在的 getContent() 方法
$rawBody = $this->request->getContent(); // 方法不存在
$data = json_decode($rawBody, true);
```

### Q2: 为什么 `json_decode()` 收到 null？

**原因：**
1. 使用了错误的方法获取请求体（如 `getContent()`，该方法不存在）
2. 前端没有设置正确的 `Content-Type: application/json`
3. `php://input` 只能读取一次，可能已被框架读取

**典型错误示例：**
```php
// ❌ 错误 1：使用不存在的方法
$rawBody = $this->request->getContent();  // 方法不存在
$data = json_decode($rawBody, true);      // $rawBody 为 null

// ❌ 错误 2：直接读取 php://input（可能已被读取）
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);
```

**解决：**
- ✅ 使用 `$this->request->getBodyParams()`（框架推荐方法）
- ✅ 前端设置 `Content-Type: application/json`
- ✅ 使用框架提供的方法，不要直接读取 `php://input`

**实际案例**（2026-01-29）：
```php
// 问题代码
public function postRemoveOrphanWidgets()
{
    $rawBody = $this->request->getContent();  // getContent() 不存在
    $data = json_decode($rawBody, true);      // TypeError: Argument #1 must be string, null given
}

// 修复后
public function postRemoveOrphanWidgets()
{
    $bodyParams = $this->request->getBodyParams();
    
    if (is_string($bodyParams)) {
        $decoded = json_decode($bodyParams, true);
        $data = ($decoded !== null && is_array($decoded)) ? $decoded : $this->request->getParams();
    } elseif (is_array($bodyParams) && !empty($bodyParams)) {
        $data = $bodyParams;
    } else {
        $data = $this->request->getParams();
    }
}
```

### Q3: 控制器中使用模型报 "Undefined property" 错误？

**错误信息：**
```
Warning: Undefined property: Controller::$modelName
Fatal error: Call to a member function reset() on null
```

**原因：**
模型未在控制器构造函数中注入。

**错误代码：**
```php
class ThemeEditor extends BackendController
{
    // ❌ 缺少属性声明
    // private ThemeLayout $themeLayout;
    
    public function __construct(
        WelineTheme $welineTheme,
        ThemeLayoutService $layoutService
        // ❌ 缺少 ThemeLayout 注入
    ) {
        $this->welineTheme = $welineTheme;
        $this->layoutService = $layoutService;
    }
    
    public function postRemoveOrphanWidgets()
    {
        // ❌ $this->themeLayout 未定义
        $widgets = $this->themeLayout->reset();  // 致命错误
    }
}
```

**解决方案：**

Weline 框架依赖注入三步骤：

```php
class ThemeEditor extends BackendController
{
    // ✅ 1. 声明私有属性
    private ThemeLayout $themeLayout;
    
    // ✅ 2. 构造函数参数注入
    public function __construct(
        WelineTheme $welineTheme,
        ThemeLayoutService $layoutService,
        ThemeLayout $themeLayout
    ) {
        $this->welineTheme = $welineTheme;
        $this->layoutService = $layoutService;
        // ✅ 3. 赋值给属性
        $this->themeLayout = $themeLayout;
    }
    
    public function postRemoveOrphanWidgets()
    {
        // ✅ 现在可以正常使用
        $widgets = $this->themeLayout->reset();
    }
}
```

**预防措施：**
- 使用模型/服务前，必须先在构造函数注入
- 利用 IDE 的类型提示和自动补全功能
- 参考同一控制器中其他依赖的注入方式

### Q4: 为什么 `postDeleteWidget()` 的路由找不到？

**原因：** 路由路径 `/controller/delete-widget` 包含 `delete` 关键字，可能被路由器误识别为 DELETE 请求方法限定。

**解决：** 改用同义词

```php
// 之前
public function postDeleteWidget() { }

// 之后
public function postRemoveWidget() { }
```

### Q2: 如何同时支持 GET 和 POST？

在同一个控制器中创建两个方法：

```php
// GET 请求 - 显示表单
public function getForm() {
    return $this->fetch('form.phtml');
}

// POST 请求 - 处理提交
public function postForm() {
    // 处理表单数据
    return $this->fetchJson(['success' => true]);
}
```

### Q3: RESTful 风格路由如何实现？

```php
// GET /api/widgets - 列表
public function getList() { }

// GET /api/widgets/123 - 详情
public function getDetail() {
    $id = $this->request->getParam('id');
}

// POST /api/widgets - 创建
public function postCreate() { }

// PUT /api/widgets/123 - 更新
public function putUpdate() {
    $id = $this->request->getParam('id');
}

// DELETE /api/widgets/123 - 删除
public function deleteItem() {
    $id = $this->request->getParam('id');
}
```

## 检查清单

在创建或修改控制器方法时：

- [ ] 方法名前缀与 HTTP 方法匹配
- [ ] 路由路径不包含 HTTP 方法关键字（get/post/put/delete/patch）
- [ ] 删除操作使用 `post` + 明确动作词（remove/destroy/purge等）
- [ ] 驼峰方法名正确转换为短横线URL路径
- [ ] 前端调用使用正确的 HTTP 方法
- [ ] URL 生成使用 `getUrl()` 方法

## 迁移示例

### 场景：删除孤儿部件功能

#### 之前（❌ 错误）

```php
// 控制器
public function postDeleteOrphanWidgets() { }

// 前端
fetch('/backend/theme-editor/delete-orphan-widgets', {
    method: 'POST',
    // ...
})
// 可能失败：路径包含 'delete' 关键字
```

#### 之后（✅ 正确）

```php
// 控制器
public function postRemoveOrphanWidgets() { }

// 前端
fetch('/backend/theme-editor/remove-orphan-widgets', {
    method: 'POST',
    // ...
})
```

## 相关技能 (Related Skills)

- `error-learning` - **自动学习**：遇到路由相关错误时自动调用
- `module-development` - 模块开发工作流，创建控制器时必须参考本技能
- `theme-development` - **主题开发必读**：JS模块加载、URL生成、API请求
- `friendly-notifications` - 友好通知UI，替代 alert/confirm/prompt
- `code-generation-standards` - 代码生成标准，与路由命名保持一致
- `error-tracking` - 错误跟踪和记录

## 相关文件

- 主题编辑器控制器: `app/code/Weline/Theme/Controller/Backend/ThemeEditor.php`
- 前端JS: `app/code/Weline/Theme/view/statics/js/theme-editor.js`
- 模板: `app/code/Weline/Theme/view/templates/Backend/ThemeEditor/index.phtml`

### Q5: 语言/货币在请求间不稳定（WLS 模式）？

**症状：**
```
刷新页面：中文 → 英文 → 中文 → 英文...
URL 明确包含语言: /backendKey/USD/zh_Hans_CN/...
```

**根本原因：**
WLS 模式下 `$_SERVER['WELINE_USER_LANG']` 被重置为空字符串 `''` 而非 `unset`，
导致 `??` 运算符无法回退到默认值。

**检查清单：**
1. `RequestContext::resetWelineVars()` 中是否使用 `unset($_SERVER['WELINE_USER_LANG'])`？
2. `GlobalsEmulator::buildServerArray()` 中是否**不**设置 `WELINE_USER_LANG`？
3. `WlsRuntime::processUrlParse()` 中是否移除了空字符串默认值逻辑？

**修复方法：**
```php
// RequestContext::resetWelineVars()
// ❌ 错误
$_SERVER['WELINE_USER_LANG'] = '';

// ✅ 正确
unset($_SERVER['WELINE_USER_LANG']);
```

**历史案例（2026-02-25）：**
语言在 `zh_Hans_CN` 和 `en_US` 之间交替。原因是多处代码将 `WELINE_USER_LANG` 设为空字符串。
修复：在 `RequestContext`、`GlobalsEmulator`、`WlsRuntime` 中使用 `unset` 而非赋空字符串。

### Q6: 如何正确获取当前语言/货币？

**在控制器/视图中：**
```php
// 推荐：使用 State 类
$lang = \Weline\Framework\App\State::getLang();
$currency = \Weline\Framework\App\State::getCurrency();

// 或使用 RequestContext
$lang = \Weline\Framework\Runtime\RequestContext::locale();
$currency = \Weline\Framework\Runtime\RequestContext::currency();
```

**State::getLang() 内部优先级：**
1. `$_SERVER['WELINE_USER_LANG']`（URL 路径解析设置）
2. `$_COOKIE['WELINE_USER_LANG']`（Cookie 存储）
3. `$_COOKIE['WELINE-WEBSITE-LANG']`（网站默认）
4. `'zh_Hans_CN'`（硬编码默认值）

### Q7: URL 中的语言是如何被解析的？

**URL 示例：**
```
/f7LYPUzS4UD9UL1kqkf0hzzPxyxmvT8c/USD/zh_Hans_CN/media/backend/manager
```

**解析流程（Url::parser）：**
1. 检测后台密钥 `f7LY...` → `WELINE_AREA = 'backend'`
2. 检测 `USD`（3位大写）→ `detectCurrency()` → `WELINE_USER_CURRENCY = 'USD'`
3. 检测 `zh_Hans_CN`（格式匹配）→ `detectLanguage()` → `WELINE_USER_LANG = 'zh_Hans_CN'`
4. 剩余路径 `/media/backend/manager` → `REQUEST_URI`

**detectLanguage() 检测逻辑：**
```php
// 格式检查
strlen($code) > 3 && strlen($code) <= 10 
    && ctype_lower(substr($code, 0, 2))  // zh
    && $code[2] === '_'                   // _

// 数据库/缓存验证
$languageCache->checkLanguage($code);  // 查询语言是否存在
```

### Q8: 后台 URL 明明像对的，为什么仍然 404？

**典型场景：**
```text
/backend/framework/env-manager  -> 404
```

**常见根因：**
1. 把路由段写成了 `backend/{module}/...`，但框架实际顺序是 `/{backend_router}/backend/{controller}`。  
2. 使用了错误的模块路由前缀（`router/backend_router`），例如 `Weline_Framework` 实际是 `weline_framework`。  
3. 菜单 action 和模板/JS URL 的写法不一致，导致页面入口与 AJAX 分别命中不同路径。

**快速排查：**
```bash
# 1) 先确认模块 backend_router
ReadFile app/etc/modules.php

# 2) 再确认路由是否注册
php bin/w route:list | Select-String "env-manager"
```

**修复建议：**
- 菜单优先使用 `*/backend/...`（由菜单收集器按模块自动替换），或显式写 `{backend_router}/backend/...`。  
- 模板/服务中统一使用 `getBackendUrl('{backend_router}/backend/...')`，不要手写 `backend/...`。  
- 修改控制器/菜单后执行：`php bin/w setup:upgrade --route`。

---

## 参考资料

- Weline Framework 路由文档
- RESTful API 设计规范
- HTTP 方法语义

---

## 更新日志

| 日期 | 更新内容 |
|------|----------|
| 2026-02-25 | 添加 URL 结构、语言/货币解析、WLS 状态管理章节 |
| 2026-01-29 | 添加 JSON 数据获取、依赖注入相关 Q&A |
