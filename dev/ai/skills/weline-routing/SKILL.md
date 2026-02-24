---
name: weline-routing
description: Weline Framework routing standards and URL generation. Use when creating controllers, defining routes, generating URLs, calling backend APIs, or fixing 404/405 routing errors. Covers HTTP method prefixes (get/post/put/delete), URL generation ($this->getUrl), and RESTful conventions.
globs:
  - "**/Controller/**/*.php"
alwaysApply: false
---

# Weline Framework 路由规范技能 (Routing Standards Skill)

## 何时使用此技能

**必须参考此技能的场景：**
- ✅ 创建新的控制器方法
- ✅ 设计 RESTful API 端点
- ✅ 使用 `$this->getUrl()` 生成 URL
- ✅ 前端调用后端 API（fetch/ajax）
- ✅ 修复路由解析错误（404/405）
- ✅ 重构现有控制器方法

**相互参照：**
- 创建控制器 → 参考 `module-development` + `weline-routing`
- 生成URL → 参考 `weline-routing`
- 用户提示 → 参考 `friendly-notifications`

## 目的
确保控制器方法命名和路由生成遵循 Weline Framework 的路由约定，避免 HTTP 方法冲突和路由解析错误。

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

## 参考资料

- Weline Framework 路由文档
- RESTful API 设计规范
- HTTP 方法语义
