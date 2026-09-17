# Fiber 协作批处理（框架核心）

## 用哪个类

| 类 | 职责 |
|----|------|
| `Weline\Framework\Php\FiberTaskRunner` | 底层 Fiber 池 |
| `Weline\Framework\Php\FiberTaskBatch` | `settle` / `mapModules` + 主线程进度 |

## 内存：边完成边卸

```php
$batch->mapModules($modules, $collectOne, function ($phase, $ctx) {
    if ($phase === 'task' && ($ctx['ok'] ?? false)) {
        mergeIntoShared($ctx['result']); // 立刻消费
    }
}, ['keep_results' => false, 'fail_fast' => true]);
```

- `keep_results=false`：返回值不囤 `results`，避免 Fiber 结果袋与业务缓冲双份。
- `unset` + `gc_collect_cycles` **不会立刻把进程 RSS 打回**，但降低峰值；CLI 会打「当前/峰值 MB」。
- 路由回滚备份改为临时文件拷贝，不再把整表路由 `require` 进 `originalRouteData`。
- ACL defer 队列超过 `DEFER_ACL_FLUSH_CHUNK`（600）会中途分块派发并卸掉事件包。

扫描期仍会有一份必要缓冲：`batchRouters`（全量路由）在 `route_update` commit 落盘前必须常驻，这是峰值主因之一。
