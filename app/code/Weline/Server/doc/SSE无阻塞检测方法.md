# SSE 连接时无阻塞检测方法

用于验证 WLS 在保持一条 SSE 长连接的同时，其他请求仍能快速响应（Fiber 调度 + 请求级上下文隔离生效）。

## 前提

- WLS 已启动（`php bin/w server:start` 或通过 Dispatcher 访问 Worker）
- 使用带 Fiber 的 worker（默认 worker.php 已接入 Fiber + WlsFiberContext）

---

## 方法一：浏览器双标签（最直观）

1. **标签 A**：打开 SSE 测试页并保持流式连接  
   - 后台：`/admin/<backendKey>/<lang>/server/sse-test/index`  
   - 前台：`/server/sse-test/index`  
   - 点击「开始测试」，保持 SSE 连接（约 5 秒内会持续收事件，不要点停止）

2. **标签 B**：在 SSE 连接存在期间发普通请求  
   - 打开同一后台/前台的任意页面（如仪表盘、系统配置等）  
   - 或直接访问一个轻量接口，例如：  
     - 后台：`/admin/.../server/backend/status`（若有）  
     - 或任意返回 JSON 的 API

3. **判断**  
   - **无阻塞**：标签 B 的页面/接口在 1 秒内完成加载或返回。  
   - **有阻塞**：标签 B 要等标签 A 的 SSE 流结束（约 5 秒）才有响应。

---

## 方法二：curl 双终端

**终端 1**：保持 SSE 长连接（会持续输出事件，不要关）

```bash
curl -N -H "Accept: text/event-stream" "http://127.0.0.1:端口/admin/你的后台路径/server/sse-test/stream"
```

若走 Dispatcher，把 `http://127.0.0.1:端口` 换成实际前端访问的域名和端口。

**终端 2**：在 SSE 连接存在期间，多次请求普通接口并看耗时

```bash
curl -o /dev/null -s -w "耗时: %{time_total}s\n" "http://127.0.0.1:端口/admin/你的后台路径/"
```

多执行几次（例如 3～5 次）。

**判断**  
- **无阻塞**：终端 2 的「耗时」通常在 0.1～0.5s 左右，且不会随 SSE 是否在跑而明显变长。  
- **有阻塞**：终端 2 的耗时接近 SSE 单次推送间隔（如 0.5s）或整段流时长（如 5s）。

---

## 方法三：单页「SSE + 并发请求」小工具

在浏览器控制台执行下面脚本（或在测试页里加一个「测并发」按钮调用相同逻辑）：

```javascript
(function () {
  var streamUrl = '你的 SSE stream 地址';  // 如: /admin/xx/zh_cn/server/sse-test/stream
  var apiUrl = '你的普通接口地址';          // 如: /admin/xx/zh_cn/ 或任意 API

  var es = new EventSource(streamUrl);
  es.onopen = function () {
    console.log('SSE 已连接，开始测并发...');
    var t0 = performance.now();
    fetch(apiUrl).then(function () {
      var elapsed = (performance.now() - t0).toFixed(0);
      console.log('SSE 连接期间，普通请求耗时: ' + elapsed + ' ms');
      if (elapsed < 2000) console.log('结论: 无阻塞');
      else console.log('结论: 可能阻塞');
    });
  };
})();
```

把 `streamUrl` 和 `apiUrl` 换成当前环境实际地址（同源或支持 CORS）。  
**无阻塞**：控制台里「普通请求耗时」一般为几百 ms 内；**有阻塞**：会接近或超过 2s。

---

## 方法四：看 WLS 日志（辅助）

Worker 日志中若在 SSE 连接期间出现类似：

- `请求进入 Fiber 异步模式 (connId: xxx)`

说明该请求在 `SchedulerSystem::usleep()` 时被挂起并加入 `activeFibers`，主循环仍在处理其他连接。再结合方法一或二，若其他请求仍快速返回，即可确认无阻塞。

---

## 小结

| 方法     | 做法简述                         | 无阻塞时现象           |
|----------|----------------------------------|------------------------|
| 浏览器   | 一标签开 SSE，另一标签打开其他页 | 其他页 1 秒内加载完成   |
| curl     | 一终端拉 SSE，另一终端 curl 普通 URL | 普通请求耗时 < 1s   |
| 控制台   | SSE 连接后 fetch 普通接口        | fetch 耗时几百 ms 内   |
| 日志     | 看是否出现 Fiber 异步模式        | 有该日志且其他请求不卡 |

任选其一即可验证「SSE 连接时无阻塞」；推荐方法一或方法二，便于反复对比开关 SSE 时的差异。
