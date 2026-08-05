# Weline.Api 使用指南

## 一、边界

浏览器业务接口只能通过已发布的 QueryProvider、Graph 或 Stream 契约访问：

- 普通读取/写入：`api.resource(provider).operation(params)`。
- 多资源读取：`api.graph(graph)`。
- SSE 订阅：`api.createStream(channel, params)` 或 `api.stream(channel, params)`。

业务页面不得手写 URL，也不得使用原生 `fetch`、`XMLHttpRequest`、axios、`$.ajax` 或 `EventSource`。`Weline.Api.request()`、`get()`、`post()` 不是新业务代码的接口；不要用它们绕过 QueryProvider。

QueryProvider/Graph/Stream 的实际传输由 API Worker 处理，前端代码不应依赖或拼接 `query-bin` 路径。

### 页面 bootstrap 与 Dedicated Worker

页面响应由服务端写入唯一的 opaque bootstrap meta，`weline-api.js` 加载后立即预热 Dedicated Worker。业务代码不得读取后再复制、缓存或自行设置 bootstrap ID；页面缺少、重复或格式错误时必须停止请求并刷新页面。

- Storefront bootstrap 只携带 43 字符 opaque ID，Scope Token 留在 `Secure + HttpOnly + SameSite=Lax` Cookie。
- Backend bootstrap 同样只在页面暴露 opaque ID，证明值留在 `HttpOnly + SameSite=Strict` Cookie。
- Scope Token、后台 Session ID、完整指纹、binding digest、Worker Session token 与 signing secret 均不得进入页面 JavaScript、日志或业务参数。
- bootstrap 只能消费一次。绑定过期、页面身份变化、登出/Session 轮换或 Dedicated Worker 重建后，不得静默降级或重新用旧 ID；必须刷新页面取得新证明。
- `scope_token_expired` 也不能在既有 Worker Session 内原地续签；刷新页面后，服务端必须重新按可信 Host/URI 解析 Scope 才能生成新证明。`scope_token_invalid`、未知 kid、错误 Host/audience/context version 不得触发同一失败请求的自动重签。
- `allowlist/on` 下缺少 bootstrap 的握手会在创建 Session 前返回 `scope_binding_required`；客户端不得通过省略 meta 降级为未绑定 Session。
- QueryBin 可在服务端完整复核后把 API 路由的 Host 默认 Store 细化为同 Website 的页面 Store/Channel；页面不得自行声明或覆盖该 Scope。跨 Website、rollout 撤权或 binding 不一致会返回 409，客户端只能刷新页面取得新的可信 bootstrap。
- Worker 状态后端故障必须返回 503，浏览器不得把它当作未登录、重新消费 bootstrap，或自动回落到另一套本地 Session。当前 Redis snapshot-CAS 仅用于 dev/test 验证，不是多节点生产门禁。

## 二、加载 API 模块

Theme 的延迟加载代理不等同于完整 API 实例。需要同步调用 `resource()`、`createStream()` 时，先加载模块：

```javascript
const api = await Weline.load('api');
```

官方完整模块必须在导出对象上声明 `__full: true`；例如 `WelineAccountModule`
不得只导出方法而缺失该标记。加载器会拒绝未声明完整契约的全局对象，
防止 fallback/半成品模块被误用；官方页面不得因模块标记缺失产生预加载 warning。

## 三、普通业务操作

后端先发布一个有权限、参数定义和返回定义的 QueryProvider operation。页面按 provider 与 operation 调用，而不是调用 Controller URL：

```javascript
const api = await Weline.load('api');
const websites = api.resource('websites');

try {
    const result = await websites.site_builder_set_stage({
        session_id: sessionId,
        stage: 'generate'
    });
    BackendToast.success(result.message || __('已保存'));
} catch (error) {
    // Weline.Api 已统一处理维护模式、HTTP 与业务错误；此处只恢复局部 UI。
    restoreStageControls();
}
```

需要多个只读资源时使用已发布的 Graph，而不是并行手写 HTTP 请求：

```javascript
const api = await Weline.load('api');
const result = await api.graph({
    firstRead: { provider: 'your_provider', operation: 'published_read', params: {} },
    secondRead: { provider: 'another_provider', operation: 'published_read', params: {} }
});
```

Graph 最多 10 个节点、总 cost 最多 20。alias 必须唯一且符合契约；所有节点的 descriptor、mode、权限、字段和参数会在任何 provider 执行前完成预检，非法 graph 返回 422/403，不会产生部分执行结果。

上传仍通过 `api.resource(provider).operation(formData)`；API 会走该 operation 对应的受控上传 ticket。

## 四、可恢复后台任务与 SSE

长时间业务工作绝不能在 SSE HTTP 连接内执行。页面先启动服务端注册的后台任务，再订阅它的持久事件：

```javascript
const api = await Weline.load('api');
const task = await api.resource('runtime_task').start({
    type_code: 'ai.chat_generation',
    input: {
        message,
        request_id: requestId
    }
});

const stream = api.createStream(task.stream_channel, {
    task_id: task.task_id,
    lease_id: task.lease_id
});

stream.addEventListener('chunk', renderChunk);
stream.addEventListener('completed', renderCompleted);
stream.addEventListener('failed', renderFailure);
await stream.start();
```

`createStream()` 允许页面在第一条重放事件到达前注册监听器。`StreamHandle` 会保存任务、页面租约与最后连续持久事件 ID 到 `sessionStorage`；每次重连重新申请一次性 ticket，并用最后游标重放缺失事件。

stream ticket 继承服务端构造的 execution area、Scope 和 owner/principal，页面不能覆盖。`auth=backend` stream 及后台上下文的 `runtime_task.events` 当前明确禁用并返回 `backend_stream_disabled`；后台长任务可以启动、查询、续租和取消，但实时流开放前不得在页面假设它可用。

```javascript
stream.close();                         // 仅当前实例退订并停止续租
await stream.cancel('user_requested');  // 唯一的显式、幂等取消入口
```

浏览器 `offline`、网络抖动、SSE 断开、页面隐藏和 `close()` 都不是取消。任务会在任一有效页面租约存在时继续运行；所有租约到期后才由服务端 Watchdog 请求协作停止。

Runtime task 的 SSE `id:` 是持久整数 sequence。`runtime_reset` 是无游标控制事件；其后的 `runtime_snapshot` 和业务事件才带持久 ID。观察型流（例如日志尾随）也用 `createStream()`，但可传稳定的不透明 cursor（如 `file-identity:byte-offset`）；它最多 128 个可打印 ASCII 字符，不能作为 Runtime task sequence 使用。

## 五、错误处理与交互

### 5.1 默认契约：reject + formatApiError

浏览器业务写操作统一返回：

```text
{ success: bool, message: string, data?: mixed, errors?: string[], retryable?: bool }
```

- **默认**：`success:false` 由 `Weline.Api` reject；页面只 `catch`，用共享 helper 展开 `errors`。
- **例外**：表格就地编辑需同时渲染失败体时，显式 `{ keepBusinessResult: true }`，并在调用处本地判断 `success`。
- 共享 helper：
  - 后台：`/static/Weline/Backend/js/weline-api-business.js` → `Weline.ApiBusiness.formatApiError(error)`
  - 前台：`/static/Weline/Frontend/js/weline-api-business.js`（同名薄封装）

```javascript
const api = await Weline.load('api');
try {
  const result = await api.resource(provider)[operation](params);
} catch (error) {
  BackendToast.error(Weline.ApiBusiness.formatApiError(error));
}
```

- 禁止：`Weline.Api.request/get/post(业务ControllerUrl)`；原生 `fetch` / `EventSource` 打业务口。
- 后台长任务：`runtime_task.start`（或模块等价写 op）拿到 `task_id` 后，用 `resource(...).getStatus({ task_id })` **轮询**；禁止后台 `createStream` / `EventSource`。
- 让 `Weline.Api` 统一处理维护模式、错误结构与默认提示；调用处仅在需要时恢复局部 loading/disabled 状态。
- 用户可见的成功或失败提示使用 `BackendToast` / `FrontendToast`，禁止 `alert`、`confirm`、`prompt`。
- 不要把 transport error、`pagehide` 或 EventSource `error` 映射为 `stream.cancel()`。
- 监听 `weline:scope-bootstrap-failed` / `weline:backend-bootstrap-failed` 时只恢复页面或提示刷新，禁止把 ID、Cookie 或错误上下文复制到业务请求；失败后不得继续未绑定调用。
- 查询操作必须先通过 `php bin/w query:help <provider> <operation>` 验证已发布契约；不得猜测 operation 或参数。

## 六、迁移检查表

- [ ] 页面没有 Controller URL 常量、`fetch`、XHR、axios、`$.ajax` 或原生 `EventSource`。
- [ ] 普通业务调用均使用 `resource()` 或 `graph()`。
- [ ] 执行型长任务：前台可用 `runtime_task.start` + `createStream()`；**后台只轮询** `getStatus`，不使用 `createStream` / `EventSource`。
- [ ] `close()` 与显式 `cancel()` 分离；没有网络断开自动取消逻辑。
- [ ] 事件处理从持久 ID 恢复，终态后停止重连与续租。
- [ ] 未手工构造、复制或持久化 bootstrap ID；身份/Worker 失效时要求刷新页面。
- [ ] 后台页面未使用当前禁用的 `auth=backend` stream。
- [ ] 失败提示使用 `Weline.ApiBusiness.formatApiError`，能展开 `errors`。
- [ ] `php dev/ai/scripts/check-browser-business-requests.php`（可加 `--module=`）`true_violations=0`。
