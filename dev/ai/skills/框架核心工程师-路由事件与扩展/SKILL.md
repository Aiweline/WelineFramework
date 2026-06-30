---
name: 框架核心工程师-路由事件与扩展
description: >-
  路由、事件、Hook、扩展点。含模块 Controller/Router.php（ModuleRouter）自定义 URL 匹配：
  随机路径、别名、前缀短路，勿与 etc/env.php 静态路由混淆。
version: 1.2.0
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
- 后台 URL 用 `getBackendUrl()`，前台用框架 URL 助手，禁止硬编码域名

---

## 事件 / Hook / extends

- 通知用事件；跨模块读用 QueryProvider
- 账户中心优先 Hook 壳（`account.sidebar`），勿随意跳独立模块页
- 扩展点须文档化命名与载荷

# Workflow

1. 判断：固定路径 → 控制器 + env；自定义/随机公网 URL → `Controller/Router.php`
2. Router 方案：定特征前缀 → 实现 `process()` → 改写内部 `$path` → 配置缓存与失效
3. 控制器方案：改 Controller + `etc/env.php` → `setup:upgrade --route`
4. HTTP / `http:request` 验证内外路径

# Validation

- 静态路由变更：`php bin/w setup:upgrade --route`
- Module Router：`curl` 公网路径 + 确认内部 Controller 被命中
- 配置型随机路径：变更后确认 `ProcessUrlBefore::clearCache()` 已执行

# Constraints

- 禁止 `routes.xml`
- 禁止用 Observer 替代 `Router.php` 做 URL 别名（应走 ModuleRouter 约定文件）
- 禁止无特征前缀的全路径遍历匹配

# Shared Collaboration Contract

遵循 `通用工程师-开发规范与代码质量`；跨域问题通知 `@Weline-技术主管`。
