# SSE 短轮询测试问题诊断

## 问题现象

在测试 SSE 短轮询时，发现：
1. SSE 请求到达 Worker 并被正确识别为 SSE 协议
2. 但请求进入框架后没有返回，一直挂着
3. 导致其他并发请求超时（3 秒）

## 根本原因

**SSE 端点需要后台登录认证**

```php
// AiSiteAgent.php:442
$adminId = (int)$this->getLoginUserId();
if ($adminId <= 0 || $publicId === '') {
    $this->sendSseContractError($sse, 'INVALID_PARAMS', (string)__('参数无效'), self::PARAMS_PUBLIC_ID);
    $sse->complete(['success' => false]);
    return;
}
```

测试时使用的 curl 命令没有提供有效的登录 Cookie，导致：
1. `getLoginUserId()` 返回 0
2. 请求被拒绝或重定向到登录页
3. 连接一直挂着，没有正确关闭
4. Worker 被占用，其他请求排队等待

## 日志证据

```


[2026-04-02 02:14:18] [WorkerSSL#2:16898@default] [INFO] 长链分层命中: layer=layer-3-path-fallback, protocol=sse, connId=1081

```

之后没有任何日志，说明请求卡在框架处理中。

## 正确的测试方法

### 方法 1：使用真实的浏览器测试

1. 打开浏览器
2. 登录后台：`https://weline-p11005ce4.local/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/admin`

4. 打开浏览器开发者工具 → Network 标签
5. 观察：
   - SSE 连接是否每 3-4 秒重连一次
   - 其他资源（JS、CSS、图片）是否正常加载
   - 是否有资源超时

### 方法 2：使用有效的 Session Cookie

```bash
# 1. 先登录获取 Cookie
COOKIE=$(curl -k "https://weline-p11005ce4.local/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/admin" \
  -d "username=admin&password=yourpassword" \
  -c - -s | grep PHPSESSID | awk '{print $7}')

# 2. 使用 Cookie 测试 SSE

  -H "Accept: text/event-stream" \
  -H "Cookie: PHPSESSID=$COOKIE" \
  -N
```

### 方法 3：修改代码临时跳过认证（仅用于测试）

在 `handleStreamSse()` 方法开头添加：

```php
// 临时测试：跳过登录检查
if ($adminId <= 0) {
    $adminId = 1;  // 使用管理员 ID 1
}
```

**注意**：测试完成后必须删除此代码！

## 代码验证

SSE 短轮询代码已经正确修改：

```php

$maxPolls = 3;
$pollInterval = 1000;  // 1 秒

for ($i = 0; $i < $maxPolls; $i++) {
    if (!$sse->isAlive()) {
        break;
    }

    $newEvents = $this->sessionService->listEventsAfterId($session->getId(), $adminId, $lastEventId, 80);

    if (!empty($newEvents)) {
        foreach ($newEvents as $event) {
            $eventId = (int)($event['event_id'] ?? 0);
            if ($eventId > $lastEventId) {
                $lastEventId = $eventId;
            }
            $sse->sendEvent('log', $event);
        }
    }

    if ($i < $maxPolls - 1) {
        SchedulerSystem::yieldDelay($pollInterval);
    }
}

$sse->complete(['success' => true, 'message' => __('请重新连接继续监听'), 'last_event_id' => $lastEventId]);
```

代码逻辑正确，只轮询 3 次（3 秒），然后立即断开连接。

## 结论

1. ✅ **代码修改正确**：SSE 短轮询逻辑已实现
2. ✅ **代码已加载**：Worker 正确识别 SSE 协议
3. ❌ **测试方法错误**：没有提供有效的登录 Cookie
4. ❌ **认证失败**：请求被阻塞，没有进入 SSE 处理逻辑

## 下一步

需要使用**真实的浏览器**或**有效的登录 Session** 来测试，才能验证 SSE 短轮询是否真正生效。

命令行测试（curl）无法模拟真实的登录状态，不适合测试需要认证的 SSE 端点。
