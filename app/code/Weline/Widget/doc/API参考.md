# Widget 模块 API 参考

## 📖 目录

1. [w:widget 标签](#wwidget-标签)
2. [WidgetRegistry 公共契约](#widgetregistry-公共契约)
3. [WidgetScanner 内部服务](#widgetscanner-内部服务)
4. [Page 模型](#page-模型)
5. [控制器API](#控制器api)
6. [JavaScript API](#javascript-api)

## 🏷️ w:widget 标签

### 标签语法

```html
<w:widget type="部件类型" name="部件名称" params='{"参数名":"参数值"}' />
```

### 属性说明

| 属性 | 类型 | 必需 | 说明 |
|------|------|------|------|
| `type` | string | 是 | 部件类型（如 header, content, footer） |
| `name` | string | 是 | 部件名称（对应目录名） |
| `params` | JSON | 否 | 部件参数，JSON格式字符串 |

### 使用示例

#### 基础用法

```html
<w:widget type="header" name="default" />
```

#### 带参数

```html
<w:widget type="header" name="default" params='{"title":"我的网站","logo_url":"/static/logo.png","show_search":true}' />
```

#### 多个部件

```html
<w:widget type="header" name="default" params='{"title":"我的网站"}' />
<w:widget type="content" name="text" params='{"text":"欢迎访问"}' />
<w:widget type="footer" name="default" />
```

### 参数格式

参数必须是有效的JSON格式字符串：

```json
{
  "title": "我的网站",
  "logo_url": "/static/logo.png",
  "show_search": true,
  "count": 10
}
```

**注意**：JSON字符串中的引号需要转义，或使用单引号包裹整个JSON字符串。

### 错误处理

标签渲染失败时会返回HTML注释：

```html
<!-- Widget 错误: 未找到部件 header/default -->
```

## 🧩 WidgetRegistry 公共契约

跨模块需要在 PHP 热路径读取已经编译的 Widget 注册表时，使用只读公共契约：

```php
use Weline\Framework\Manager\ObjectManager;
use Weline\Widget\Api\WidgetRegistryInterface;

/** @var WidgetRegistryInterface $registry */
$registry = ObjectManager::getInstance(WidgetRegistryInterface::class);
$widgetsByType = $registry->getRegistry();
```

调用模块必须在自己的 `etc/module.php` 中声明 `Weline_Widget` 为 `requires`。禁止跨模块引用 `Weline\Widget\Service\WidgetRegistry`；刷新、扫描和持久化仍属于 Widget 模块内部职责。普通跨模块数据查询优先使用 `w_query('widget', ...)`，本契约只用于必须复用进程内注册表缓存的服务端热路径。

### getRegistry()

```php
public function getRegistry(bool $forceReload = false): array;
```

- 默认命中 Widget 进程内注册表缓存，不增加适配层复制或数组转换。
- 返回结构为 `type => code => widget metadata`。
- `$forceReload=true` 会重新加载编译注册表，只应在明确的控制面刷新流程中使用。

契约由 `Weline\Widget\Service\WidgetRegistry` 实现，并通过模块 `provides` 与同名 Factory 解析；调用方只依赖 `Weline\Widget\Api\WidgetRegistryInterface`。

## 🔍 WidgetScanner 内部服务

`WidgetScanner` 是 Widget 模块内部扫描能力，不是跨模块公共 API。其他模块需要读取部件数据时使用上面的 `WidgetRegistryInterface` 或 `w_query('widget', ...)`。

### 类名

```php
Weline\Widget\Service\WidgetScanner
```

### 主要方法

#### scanAllWidgets()

扫描所有模块中的部件定义。

```php
use Weline\Framework\Manager\ObjectManager;
use Weline\Widget\Service\WidgetScanner;

/** @var WidgetScanner $scanner */
$scanner = ObjectManager::getInstance(WidgetScanner::class);
$widgets = $scanner->scanAllWidgets();

// 返回数组格式：
// [
//     [
//         'type' => 'header',
//         'widget_name' => 'default',
//         'module' => 'Weline_Widget',
//         'name' => '默认头部',
//         'description' => '...',
//         'template' => 'Weline_Widget::widgets/header/default.phtml',
//         'params' => [...],
//         ...
//     ],
//     ...
// ]
```

#### getWidgetConfig()

获取指定部件的配置信息。

```php
$config = $scanner->getWidgetConfig('header', 'default');

// 返回数组格式：
// [
//     'name' => '默认头部',
//     'description' => '...',
//     'type' => 'header',
//     'widget_name' => 'default',
//     'module' => 'Weline_Widget',
//     'template' => 'Weline_Widget::widgets/header/default.phtml',
//     'params' => [...],
//     ...
// ]
```

**参数**：
- `$type` (string) - 部件类型
- `$name` (string) - 部件名称

**返回值**：部件配置数组，未找到返回 `null`

## 📄 Page 模型

### 类名

```php
Weline\Widget\Model\Page
```

### 数据表结构

| 字段 | 类型 | 说明 |
|------|------|------|
| `page_id` | int | 页面ID（主键） |
| `title` | varchar(255) | 页面标题 |
| `handle` | varchar(255) | 页面标识（唯一） |
| `content` | text | 页面内容（w:widget标签） |
| `metadata` | text | 元数据（JSON格式） |
| `status` | varchar(50) | 页面状态（draft, published） |
| `created_at` | datetime | 创建时间 |
| `updated_at` | datetime | 更新时间 |

### 常用方法

#### 保存页面

```php
use Weline\Widget\Model\Page;

$page = new Page();
$page->setData('title', '首页');
$page->setData('handle', 'homepage');
$page->setData('content', '<w:widget type="header" name="default" />');
$page->setData('status', 'published');
$page->save();
```

#### 加载页面

```php
$page = new Page();
$page->load(1);  // 按ID加载

// 或按标识加载
$page->load('homepage', 'handle');
```

#### 查询页面

```php
$page = new Page();
$pages = $page->select()
    ->where('status', 'published')
    ->fetch();
```

## 🎮 控制器API

### Editor 控制器

#### index() - 编辑器首页

**路由**：`widget/backend/editor` （等价于 `widget/backend/editor/index`）

**参数**：
- `page_id` (int, 可选) - 页面ID，编辑已有页面时使用

**返回**：编辑器HTML页面

#### postSave() - 保存页面

**路由**：`widget/backend/editor/save` (POST)

**参数**：
- `page_id` (int, 可选) - 页面ID，更新时使用
- `title` (string, 必需) - 页面标题
- `handle` (string, 必需) - 页面标识
- `content` (string, 必需) - 页面内容（w:widget标签）
- `status` (string, 可选) - 页面状态，默认 'draft'

**返回**：JSON格式

```json
{
  "success": true,
  "message": "保存成功",
  "page_id": 1
}
```

#### postLoad() - 加载页面

**路由**：`widget/backend/editor/load` (POST)

**参数**：
- `page_id` (int, 必需) - 页面ID

**返回**：JSON格式

```json
{
  "success": true,
  "data": {
    "page_id": 1,
    "title": "首页",
    "handle": "homepage",
    "content": "<w:widget type=\"header\" name=\"default\" />",
    "status": "published"
  }
}
```

### Preview 控制器

#### postRender() - 渲染部件

**路由**：`widget/backend/preview/render` (POST)

**参数**：
- `type` (string, 必需) - 部件类型
- `name` (string, 必需) - 部件名称
- `params` (array, 可选) - 部件参数

**返回**：JSON格式

```json
{
  "success": true,
  "html": "<header>...</header>"
}
```

#### postPage() - 预览页面

**路由**：`widget/backend/preview/page` (POST)

**参数**：
- `content` (string, 必需) - 页面内容（w:widget标签）

**返回**：JSON格式

```json
{
  "success": true,
  "html": "<header>...</header><div>...</div>"
}
```

### Widget 控制器

#### index() - 部件列表

**路由**：`widget/backend/widget` （等价于 `widget/backend/widget/index`）

**返回**：部件管理页面

#### postDetail() - 获取部件详情

**路由**：`widget/backend/widget/detail` (POST)

**参数**：
- `type` (string, 必需) - 部件类型
- `name` (string, 必需) - 部件名称

**返回**：JSON格式

```json
{
  "success": true,
  "data": {
    "name": "默认头部",
    "type": "header",
    "widget_name": "default",
    "module": "Weline_Widget",
    "description": "...",
    "params": {...}
  }
}
```

## 💻 JavaScript API

### WidgetEditor 类

编辑器主类，位于 `editor.js`。

#### 初始化

```javascript
const editor = new WidgetEditor({
    pageId: 0,
    pageName: '新页面',
    initialContent: '',
    widgets: [...],  // 所有可用部件
    previewUrl: '/widget/backend/preview/render',
    saveUrl: '/widget/backend/editor/save'
});
```

#### 主要方法

##### addWidget(widget)

添加部件到画布。

```javascript
editor.addWidget({
    type: 'header',
    name: 'default',
    params: {
        title: '我的网站'
    }
});
```

##### removeWidget(index)

从画布中删除部件。

```javascript
editor.removeWidget(0);  // 删除第一个部件
```

##### selectWidget(widget)

选择部件。

```javascript
editor.selectWidget(editor.widgetsData[0]);
```

##### updateWidgetParams()

更新选中部件的参数。

```javascript
editor.updateWidgetParams();
```

##### savePage()

保存页面。

```javascript
await editor.savePage();
```

##### generatePageContent()

生成页面内容（w:widget标签字符串）。

```javascript
const content = editor.generatePageContent();
// 返回: '<w:widget type="header" name="default" params=\'{"title":"..."}\' />'
```

### 事件

编辑器触发以下事件（通过DOM事件）：

- `widget:added` - 部件添加时
- `widget:removed` - 部件删除时
- `widget:selected` - 部件选择时
- `widget:updated` - 部件更新时
- `page:saved` - 页面保存时

### 使用示例

```javascript
// 监听部件添加事件
document.addEventListener('widget:added', function(e) {
    console.log('部件已添加:', e.detail);
});

// 监听页面保存事件
document.addEventListener('page:saved', function(e) {
    console.log('页面已保存:', e.detail.pageId);
});
```

## 🔗 相关文档

- [快速开始指南](./快速开始.md)
- [开发指南](./开发指南.md)
- [使用手册](./使用手册.md)
