# 后台请求流证据索引（flow-visualizer · 2026-10-05）

覆盖 `https://host/<backendKey>/[currency]/[language]/module/controller/action` 这条链。路径前缀 `app/code/Weline/`。
图：`flow-backend.dot` / `flow-backend.svg`（viewer 第 4 个标签页）。

| 流步骤 | sourceRefs | 置信 |
|---|---|---|
| 原始 URI 保留到 server 变量供后续二次校验 | `Framework/Http/WlsRequest.php:777`（`WELINE_ORIGIN_REQUEST_URI`） | high |
| 区域识别只查配置前缀，不猜字面量 | `Framework/Http/Url.php:1975`(`Env::getAreaByRoutePrefix`), `:2005-2013`(backend 分支), `:2020-2026`(注释：不再用 URL 里的 admin/backend 判区域，避免安全漏洞) | high |
| backend 分支去掉首段 backendKey、`WELINE_AREA_ROUTE=''` | `Framework/Http/Url.php:2005-2013`；实测 `/U0Ma…/USD/zh_Hans_CN/admin/login` → REQUEST_URI `/USD/zh_Hans_CN/admin/login` | high |
| 货币/语言段最长倒序匹配、顺序可换最多两段 | `Framework/Http/Url.php:2038-2060`, `Framework/App/State.php:407-447`(`resolveLocalizationFromPathSegments`) | high |
| `is_backend` 判定与「禁止 empty() 短路」注释 | `Framework/Router/Core.php:159-167` | high |
| 后台显式跳过统一路由缓存/generated 快路径/路由结果缓存 | `Core.php:585-611`(processUrl 后台分支), `:308`(`shouldUseDirectGeneratedRouteFastPath` 遇 is_backend 返回 false), `:419`(`$canUseRouteCache`) | high |
| `process_uri_before` 事件写回 rule | `Core.php:594-610`, `Framework/etc/event.xml`（该事件 observer） | high |
| 按 `request_area` 分派 Pc()/Api()，default 才走 StaticFile | `Core.php:449-462` | high |
| 后台路由表文件 = `generated/routers/backend_pc.php` | `Core.php:1317-1336`, `Framework/App/Env.php:74`(`path_BACKEND_PC_ROUTER_FILE`)；实测该文件 57,111 行 | high |
| 路由键格式 `path::METHOD`、`'*'` 通配优先、HEAD 回退 GET | `Core.php:1221-1253`(`matchGeneratedRouterEntry`), `:1255-1280`(建索引) | high |
| 空路径默认路由：后台 `admin`、前台 `index/index` | `Core.php:1338` | high |
| 命中后 `router['type']='pc'`，但后台不写 `_router_cache_key` | `Core.php:1345-1348` | high |
| 后台未命中直接 404 + 六项诊断日志 | `Core.php:1356-1368` | high |
| route() 前置：模块状态、header_xss、request_method 不符→noRouter | `Core.php:1659-1664`, `:1668`, `:1752-1762` | high |
| FPC 整段被 `!is_backend` 门控跳过 | `Core.php:1618-1623`, `:1681-1687` | high |
| 后台 getController 不读控制器缓存 | `Core.php:1612-1616` | high |
| Attribute 链 execute、返回非空即短路 | `Core.php:1840-1852`, `:2093-2110`(反射取属性元数据+进程内缓存) | high |
| `#[Acl]` 是 PHP Attribute 类且实现 `RouterAttributeInterface` | `Framework/Acl/Acl.php:22`, `Framework/Attribute/RouterAttributeInterface.php:15`；用例 `Admin/Controller/Backend/User.php:21,32` | high |
| `Acl::execute()` 装载 type/module/router/class/route 后派发 `Weline_Framework_Acl::dispatch` | `Framework/Acl/Acl.php:221-236` | high |
| BackendController::__init 门禁顺序（areaKey→归一化→before→session start→no-store→after→loginCheck） | `Framework/App/Controller/BackendController.php:45-70` | high |
| assertBackendAreaKey 对原始 URI 首段 `hash_equals` 配置的 backendKey | `BackendController.php:72-106` | high |
| 后台运行时上下文归一化（area/is_backend/WELINE_*） | `BackendController.php:108-127` | high |
| 未登录白名单来自事件回填并缓存 | `BackendController.php:143-153`；observer 绑定 `Admin/etc/event.xml:5-7`(`BackendWhitelistUrl`) | high |
| 未登录出口：SSE→401 JSON、页面→redirect(带 returnUrl) 或 noRouter | `BackendController.php:157-183`, `:214-235`(`withBackendLoginReturnUrl`) | high |
| **ACL 权限校验由观察者挂在 `route_before`，不在 Framework 基类里** | `Acl/etc/event.xml:11-13` → `Acl/Observer/RouteBefore.php:162`(`isBackend‖isApiBackend`→`validateBackendAccess`) | high |
| ACL 拒绝分级：not_logged_in / user_disabled / 无角色 / 无 scope → 401 或 403(DEV)/404 | `Acl/Observer/RouteBefore.php:486-511`, `:540`, `:569`, `:595-597` | high |
| 拒绝前先派发 `Weline_Acl::no_access_redirect_before` | `RouteBefore.php:490,540,569,595`；observer `Admin/etc/event.xml:24-26`(`NoAccessRedirectBefore`) | high |
| HEAD 请求跳过权限检查与重定向 | `RouteBefore.php:155-160` | high |
| CSRF：仅写方法校验，token 依次 post/X-CSRF-TOKEN/form_key/t | `Framework/Controller/PcController.php:315-346` | high |
| action 执行 + FiberOutputBuffer 包裹 | `Core.php:1885`, `:1893` | high |
| SSE 分支丢弃缓冲直接回流，不经发布 | `Core.php:1896-1901` | high |
| 后台布局默认 `default.default`，子类可覆盖/禁用 | `BackendController.php:38-42` | high |
| 后台响应 `canPublishResponse` 恒 false | `Core.php:1986-1996` | high |
| `noRouter(code)` 先 flush session 再终止 | `Framework/Http/Response.php:214-226` | high |
| remember-me 在 `backend_controller_init_after` 恢复 | `BackendController.php:63-67`；observer `Admin/etc/event.xml:32-34` → `Admin/Observer/BackendControllerInitAfter.php:33-50` | high |

## 结构性发现（值得留意，不是缺陷断言）

1. **后台鉴权是三段式、跨两个模块装配的**：`Framework` 负责「是不是后台」（area 前缀 + backendKey hash_equals）和「登没登录」（`loginCheck` + 白名单事件），真正的**权限（ACL）判定完全在 `Weline_Acl` 模块**，通过 `route_before` 事件挂进来（`Acl/etc/event.xml:11-13`）。把 Acl 模块停用 ⇒ 后台仍可越过权限边界，这符合框架的 optional-capability 设计，但意味着 Acl 在场性是后台安全的隐含前提。
2. **`Admin/Observer/AclController.php` 是空实现**（`execute()` 只有 `// TODO`），而 `Admin/etc/event.xml:12-14` 确实把 `Weline_Framework_Acl::dispatch` 绑到了它。实际生效的是 `Weline_Acl::Observer\RouteBefore`。也就是说这条事件绑定目前是占位而非执行路径 —— 读代码时容易误判成「双重校验」。
3. **后台刻意不走任何路由/控制器/FPC 缓存**（⑤⑨⑪三处 `is_backend` 短路），代价是每请求 include 一份 5.7 万行的 `backend_pc.php`；收益是改路由立即生效、且不存在后台响应被缓存复用的风险。
4. **防御性重复校验**：`Url::parser` 已经按配置前缀定过区域，`BackendController::assertBackendAreaKey` 又对客户端原始 URI 的 `WELINE_ORIGIN_REQUEST_URI` 再做一次 `hash_equals`。两处独立，注释明确说明目的是防止前端上下文被"提升"为后台。

## 边界与未画内容

- **后台 REST API**（`BackendRestController` → `Api()`，`Core.php:763-816`，路由表 `backend_rest_api.php`）与 PC 后台共用前半段与 route()，差异只在路由表与 api_app_actor token 分支（`RouteBefore.php:507+`），本图未单独展开。
- **维护模式 observer**（`Admin/etc/event.xml:40`）挂在 pre_route_gate，属前台图已画的门禁。
- 图中红色「拒绝出口」节点是**逻辑聚合图例**，不是流程节点：各分支实际都在自己所在位置调用 `noRouter()` 立即终止（见上表最后一行），没有真实汇聚边。
- `Weline_Acl\Service\AclService` 内部的角色/授权源查询细节（DB 还是缓存优先）本次未逐行核验，标 medium。
