---
name: 框架核心工程师-路由事件与扩展
description: >-
  路由、事件、Hook、扩展点。含模块 Controller/Router.php（ModuleRouter）自定义 URL 匹配：
  随机路径、别名、前缀短路，勿与 etc/env.php 静态路由混淆；禁止用 URL/query/Router
  选择 Theme layout，公开页布局由 Controller 或业务上下文决定。
version: 1.2.2
---

# Role

负责 WelineFramework 的路由定义、事件契约、Hook 命名与 extends 扩展点。保持与 `setup:upgrade --route` 驱动的控制器路由注册一致，并正确使用 **ModuleRouter** 处理自定义公网 URL。

# When To Use

- 新建/修改控制器 URL、`getUrl` / `getBackendUrl` 行为
- 需要 **公网 URL 与内部路由不一致**（随机路径、短链、别名、SEO 友好路径）
- 事件、Observer、Hook、extends 设计
- 关键词：`route`、`Router.php`、`RouterInterface`、`ModuleRouter`、`process_uri_before`

# Source Material

- `AI-ENTRY.md`、`dev/ai/global-constraints.md`
- `app/code/Weline/Framework/doc/event/router/URI处理前.md`
- `app/code/Weline/ModuleRouter/Observer/ProcessUrlBefore.php`
- `app/code/Weline/ModuleRouter/Config/ModuleRouterReader.php`
- 参考实现：`Theme/Controller/Router.php`、`MediaManager/Controller/Router.php`、`Deploy/Controller/Router.php`

---

## 两种路由机制（必辨）

| 机制 | 入口 | 适用场景 |
|------|------|----------|
| **静态控制器路由** | `etc/env.php` 的 `router` + `Controller/*` + `setup:upgrade --route` | 固定、可预测的 `模块/控制器/方法` 路径 |
| **模块 Router 预处理** | `Controller/Router.php` 实现 `RouterInterface` | 自定义/随机/别名公网路径，再 **改写 `$path`** 到内部静态路由 |

**不要**用 `routes.xml`。  
**不要**为「仅改公网路径」再写 Nginx fastcgi 或独立 PHP 监听；优先用模块 `Router.php`。

---

## Theme 布局选择边界（强制）

- `Controller/Router.php` 只负责把公网 URL、短链、随机路径或 SEO 友好路径改写到内部静态 Controller 路由；它不负责选择 Theme layout。
- 禁止在普通公开页 Router 中设置或依赖 `layout_type`、`page_type`、`layout_option` 等 URL/query 参数来控制 Theme 布局。
- 禁止把正常 storefront 页面路由到 `theme/frontend/policy` 来借用 Theme layout。`theme/frontend/policy` 和 preview 参数属于 Theme 预览/编辑器场景，不是业务公开页的布局选择机制。
- 正确方式：公开页命中业务 Controller，由 Controller 通过框架布局设置选择布局，例如 `protected ?string $layoutType = 'homepage.default';`、`product_list.default`、`product.default`。
- 特殊布局或产品详情布局变体必须由 Controller、事件/Observer、配置或业务上下文决定；不要通过匹配 URL layout 参数实现。
- Router 可以传递业务上下文参数，如商品 handle、搜索关键字或 public route 标识，但不得传递 Theme layout identity。

---

## 模块 Router（`Controller/Router.php`）

### 是什么

每个模块可在 **`app/code/Weline/{Module}/Controller/Router.php`** 放置一个类，实现：

```php
use Weline\Framework\Router\RouterInterface;

class Router implements RouterInterface
{
    public static function process(string &$path, array &$rule): void
    {
        // 改写 $path 为框架内部路由，如 deploy/webhook/deploy
    }
}
```

### 何时触发

1. 框架 `Weline_Framework_Router::process_uri_before` 事件
2. `Weline_ModuleRouter\Observer\ProcessUrlBefore` 遍历各模块 `Controller/Router.php`
3. 仅当 `$rule['module']` 仍为空（尚未匹配到生成路由）时调用
4. `ModuleRouterReader` 扫描并缓存模块 Router 列表（`w_cache('module_router')`）

### 性能约定（强制）

1. **尽早 return**：`$rule['module']` 已设置则直接返回
2. **特征前缀短路**：用少见前缀（如 `~wh~`）`str_starts_with`，让 99% 请求零成本跳过
3. **避免重 IO**：配置用内存/缓存；动态路径变更后调用 `ProcessUrlBefore::clearCache()`
4. **精确匹配**：对随机段用 `hash_equals`，不要宽泛正则扫全站

### 标准写法模板

```php
public static function process(string &$path, array &$rule): void
{
    if (!empty($rule['module'])) {
        return;
    }

    $normalized = trim(str_replace('\\', '/', $path), '/');
    if (!str_starts_with($normalized, self::MARKER)) {
        return;
    }

    // 从配置/缓存读取期望路径，hash_equals 后改写
    if (hash_equals($expected, $normalized)) {
        $path = 'your_module/your_controller/your_action';
    }
}
```

改写后的 `$path` 由框架 **generated PC router** 继续匹配到真实 `Controller` 方法。

## 本地化 URL 前缀（强制）

- 公开路径可在可选 area 段后携带本地化前缀。货币与语言可单独出现，也可两者同时出现，并且顺序不固定：`/USD/products`、`/zh_Hans_CN/products`、`/USD/zh_Hans_CN/products`、`/zh_Hans_CN/USD/products` 都必须被视为合法形态。
- Router、WLS URL parser、`App` 请求预热、canonical redirect、登录回跳和 path strip observer 必须复用同一套路径本地化解析约定；禁止各处复制“先货币再语言”或“必须两个都出现”的局部实现。
- 路由匹配/路径剥离阶段不得调用 allowed currency/language 作为是否剥离前缀的前置条件。allowed 配置可能依赖本次请求上下文，过早调用会导致漏剥离、404 或递归初始化；使用 3 位大写货币码、locale 形状和 area 排除来识别前缀，再在业务层校验是否允许。
- 修改本地化路由时必须覆盖四类验证：currency-only、language-only、currency/language、language/currency。

### 与 Deploy Webhook 的范例

- 公网：`https://域名/~wh~<随机hex>`（`deploy:webhook:setup` 生成）
- `Deploy\Controller\Router.php`：`~wh~` 前缀匹配 → 内部 `deploy/webhook/deploy`
- 版本探测：同路径 + `/version` → `deploy/version`
- 配置服务：`DeployWebhookRouteService`；路径变更后 `clearCache()`

详见 `CI发布工程师-部署发布系统`。

### 缓存失效

动态路径（后台配置、CLI 生成）变更后须：

```php
use Weline\ModuleRouter\Observer\ProcessUrlBefore;

ProcessUrlBefore::clearCache();
// 并清理模块自管的 w_cache 键
```

### 何时仍需 `setup:upgrade --route`

- **新增/改名** `Controller` 或 `Api` 类及方法 → 必须刷路由
- **仅修改** `Controller/Router.php` 的匹配逻辑或改写目标 → 一般 **不需要** 刷路由（除非改了内部目标控制器）

---

## 静态控制器路由（常规）

- 模块前缀：`etc/env.php` → `'router' => 'module_name'`
- 新增控制器后：`php bin/w setup:upgrade --route`
- 后台 URL 必须使用运行时后台区域 key 作为第一段：读取 `Env::getAreaRoutePrefix('backend')`，形成 `/{backendKey}/{module}/{area}/{controller}/{action}`；不得硬编码 `/backend` 或 `/admin`，也不得允许无 key 的后台 URL 作为回退。
- `WELINE_AREA_ROUTE` 只表示当前请求已经解析出的区域上下文，不能作为生成后台 URL 的 key 来源；生成 URL 必须使用 `getBackendUrl()` 或统一 URL builder。
- 模块 `etc/env.php` 的 `backend_router`（例如 `admin`）是模块内部路由名，不是后台区域 key。后台入口 key 来自 `router.area_routes.backend.prefix`，生产环境可能是随机字符串。
- 例：若 `Env::getAreaRoutePrefix('backend')` 返回 `EXAMPLE_BACKEND_KEY`，i18n 国家页是 `/EXAMPLE_BACKEND_KEY/i18n/backend/countries`，而不是 `/i18n/backend/countries`；真实 key 必须在运行时读取，不能把示例值写死。
- 后台 URL 用 `getBackendUrl()`，前台用框架 URL 助手，禁止硬编码域名或后台入口 key。

---

## 事件 / Hook / extends

- 通知用事件；跨模块读用 QueryProvider
- 账户中心优先 Hook 壳（`account.sidebar`），勿随意跳独立模块页
- 扩展点须文档化命名与载荷

# Workflow

1. 判断：固定路径 → 控制器 + env；自定义/随机公网 URL → `Controller/Router.php`
2. 后台路径先读取 `Env::getAreaRoutePrefix('backend')`，确认 URL 第一段带真实 key；再拼接模块内部路由，区分 `backendKey` 与模块 `backend_router`
3. Router 方案：定特征前缀 → 实现 `process()` → 改写内部 `$path` → 配置缓存与失效
4. 若涉及 Theme 页面布局，先在目标 Controller 设置/检查 `$layoutType` 或业务布局选择逻辑；不要把 layout 塞进 Router 或 URL 参数
5. 若涉及货币/语言 URL 前缀，先确认是否已复用共享解析逻辑；不要新增只支持固定顺序或双段同时出现的解析代码
6. 控制器方案：改 Controller + `etc/env.php` → `setup:upgrade --route`
7. HTTP / `http:request` 验证带 key 的外部路径，并确认无 key 路径不会被当作后台入口

# Validation

- 静态路由变更：`php bin/w setup:upgrade --route`
- Module Router：`curl` 公网路径 + 确认内部 Controller 被命中
- 后台路由：从 `Env::getAreaRoutePrefix('backend')` 读取实际 key，验证 `/{backendKey}/...` 可访问；同时验证 `/...` 无 key 不会误判为后台路径
- Theme 页面：确认响应中没有依赖 `layout_type`、`page_type`、`layout_option` 或 `theme/frontend/policy` 的公开路由残留
- 本地化 URL：验证 `/USD/...`、`/zh_Hans_CN/...`、`/USD/zh_Hans_CN/...`、`/zh_Hans_CN/USD/...` 均能命中同一业务路由或预期跳转
- 配置型随机路径：变更后确认 `ProcessUrlBefore::clearCache()` 已执行

# Constraints

- 禁止 `routes.xml`
- 禁止用 Observer 替代 `Router.php` 做 URL 别名（应走 ModuleRouter 约定文件）
- 禁止无特征前缀的全路径遍历匹配
- 禁止用 Router、URL、query 参数选择 Theme layout；公开页布局必须由 Controller、事件/Observer、配置或业务上下文决定
- 禁止本地化 URL 新逻辑只兼容单一前缀顺序、只兼容双段同时出现，或在路由剥离阶段依赖 allowed currency/language 配置
- 后台 URL 禁止省略运行时 `backendKey`；禁止把模块 `backend_router`（如 `admin`）误当成后台区域 key；禁止让无 key 路径通过经验性的 `/admin` 或 `/backend` 判断进入后台

# Shared Collaboration Contract

遵循 `通用工程师-开发规范与代码质量`；跨域问题通知 `@Weline-技术主管`。
