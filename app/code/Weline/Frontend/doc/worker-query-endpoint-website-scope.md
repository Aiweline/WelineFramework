# Worker Query 入口与 Website 挂载范围

前台页面的 `runtime.api.endpoint` 与 `queryBinUrl` 必须由 `Weline\Framework\Http\Url::getFrontendApiUrl('framework/query-bin', [], false)` 生成。该公共服务保留当前请求的 Website 挂载路径、REST area 和本地化路由，不继承当前页面无关 query 参数。

`Env::getFrontendQueryBinPath()` 只返回 area 根路径，适合没有 Website 挂载要求的路径声明，不能直接作为多 Website 店面的最终 Worker URL。例如 `/daocharms/` 页面若把 Worker 发到根 `/api/framework/query-bin`，页面的 bootstrap 绑定属于 daocharms，而 API 导航会匹配 Host 默认 Website。可信绑定恢复拒绝跨 Website 替换，购买请求会返回 Scope 冲突。

原生 header `view/blocks/header/base.phtml` 共用一次服务器生成的 `$queryBinUrl`。静态 worker 脚本 URL 独立于业务 Website 路径，不需要搬到 `/daocharms`。客户端不得手写 Website ID、Scope 或 Token，也不得绕过 QueryBin Worker 传输。`RequestContext::replaceScopeIdentityForTrustedWorker` 的同 Website 检查保持不变。

## 最小复现与实际验收

|入口|预期|观测方式|
|---|---|---|
|Host 根默认店面 `/`|API URL使用默认Website base；bootstrap与QueryBin导航是同Website|读runtime.api.endpoint，并执行现有Search调用|
|挂载店面 `/daocharms/`，HTTPS scope rollout on|API URL包含 `/daocharms/api/`；原生opaque bootstrap仅一个；Search结果为该店面发布商品|页面搜索真实商品，检查结果与当前SSR目录ID|
|同店面有效Theme preview Token|与正式店面同一业务Scope；主题预览不改变Worker所属Website|镇内搜索与原卡片Choose options打开真实规格面板|
|非默认locale/currency的mounted店面|官方URL服务生成本地化API路由；商品价格币种及文案与页面一致|原购买面板观察币种和商品ID|
|刻意使用另一Website绑定|继续拒绝跨Website Scope替换|已有Framework ScopeIdentity契约测试，不为主题放宽guard|

本地开发 prerequisite：Websites原生Scope Kernel rollout及预置keyring按其权威文档配置。`off` 模式按设计不发bootstrap；开启 `on` 必须使用HTTPS及正常keyring。配置启用与修复入口是两件事，不以临时模拟商品或内存购物车验收。

本次开发证据：原错误调用购买409；修复前mounted API POST正常路由到Framework QueryBin（无合法协议body时400）；模板php-lint与diff-check通过。真实完整搜索/购买恢复须在正常模板刷新与Worker重载后验收，不以lint代替。
