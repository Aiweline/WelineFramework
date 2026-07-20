# Weline.Api 使用指南

## 一、边界

浏览器业务接口只能通过已发布的 QueryProvider、Graph 或 Stream 契约访问：

- 普通读取/写入：`api.resource(provider).operation(params)`。
- 多资源读取：`api.graph(graph)`。
- SSE 订阅：`api.createStream(channel, params)` 或 `api.stream(channel, params)`。

业务页面不得手写 URL，也不得使用原生 `fetch`、`XMLHttpRequest`、axios、`$.ajax` 或 `EventSource`。`Weline.Api.request()`、`get()`、`post()` 不是新业务代码的接口；不要用它们绕过 QueryProvider。

QueryProvider/Graph/Stream 的实际传输由 API Worker 处理，前端代码不应依赖或拼接 `query-bin` 路径。

## 二、加载 API 模块

Theme 的延迟加载代理不等同于完整 API 实例。需要同步调用 `resource()`、`createStream()` 时，先加载模块：

```javascript
const api = await Weline.load('api');
```

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

```javascript
stream.close();                         // 仅当前实例退订并停止续租
await stream.cancel('user_requested');  // 唯一的显式、幂等取消入口
```

浏览器 `offline`、网络抖动、SSE 断开、页面隐藏和 `close()` 都不是取消。任务会在任一有效页面租约存在时继续运行；所有租约到期后才由服务端 Watchdog 请求协作停止。

Runtime task 的 SSE `id:` 是持久整数 sequence。`runtime_reset` 是无游标控制事件；其后的 `runtime_snapshot` 和业务事件才带持久 ID。观察型流（例如日志尾随）也用 `createStream()`，但可传稳定的不透明 cursor（如 `file-identity:byte-offset`）；它最多 128 个可打印 ASCII 字符，不能作为 Runtime task sequence 使用。

## 五、错误处理与交互

- 让 `Weline.Api` 统一处理维护模式、错误结构与默认提示；调用处仅在需要时恢复局部 loading/disabled 状态。
- 用户可见的成功或失败提示使用 `BackendToast` / `FrontendToast`，禁止 `alert`、`confirm`、`prompt`。
- 不要把 transport error、`pagehide` 或 EventSource `error` 映射为 `stream.cancel()`。
- 查询操作必须先通过 `php bin/w query:help <provider> <operation>` 验证已发布契约；不得猜测 operation 或参数。

## 六、迁移检查表

- [ ] 页面没有 Controller URL 常量、`fetch`、XHR、axios、`$.ajax` 或原生 `EventSource`。
- [ ] 普通业务调用均使用 `resource()` 或 `graph()`。
- [ ] 执行型长任务使用 `runtime_task.start` + `createStream()`，不使用 SSE 执行业务。
- [ ] `close()` 与显式 `cancel()` 分离；没有网络断开自动取消逻辑。
- [ ] 事件处理从持久 ID 恢复，终态后停止重连与续租。
