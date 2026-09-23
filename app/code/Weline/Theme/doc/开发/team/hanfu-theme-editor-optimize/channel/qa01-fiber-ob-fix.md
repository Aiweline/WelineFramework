# QA-01 · FiberOutputBuffer OB display-handler E_ERROR 修复

日期：2026-09-23  
席位：Team:框架/Runtime  
状态：已修（UT 绿）

## 根因

进程级 OB 回调 `handleChunk` → `appendToFrame` 在真实 arena 已近 `memory_limit`（日志 ≈511MB）时仍用 `memory_get_usage(false)` 放行拼接。拼接/副作用路径再触发 `ob_*` 或错误渲染时，PHP 以 E_ERROR 杀 Worker：

`Cannot use output buffering in output buffering display handlers`

## 改动（契约对齐）

文件：`app/code/Weline/Framework/Runtime/FiberOutputBuffer.php`

1. **溢出先于拼接**：`buildAppendOverflowContext` 用 `max(emalloc, memory_get_usage(true))` + headroom；将满则标 `overflowed`、清空 buffer、短路拼接；`endCapture`/`finishFrame` 抛可控 `OverflowException`（不 E_ERROR）。
2. **OB 回调纯度**：`inHandlerDepth` 守卫；回调栈内禁止 `ob_flush`（`flushInstalledBufferIntoCurrentFrame` 直接 return false）；重入只标 overflow / 丢弃 chunk。
3. **异常短路**：`handleChunk` / `.=` 包 `Throwable` → 标 overflow，不向外冒致命。
4. **契约保留**：`beginCapture` / `endCapture` / `discardCapture` 对外语义不变。

## 测试

`app/code/Weline/Framework/Test/Unit/Runtime/FiberOutputBufferTest.php` 新增：

- `testRealMemoryHeadroomOverflowsBeforeAppend`
- `testReentrancyDuringHandlerMarksOverflowWithoutFatal`
- `testFlushSkippedWhileInsideOutputHandler`

命令：

```bash
php vendor/bin/phpunit app/code/Weline/Framework/Test/Unit/Runtime/FiberOutputBufferTest.php --no-configuration
```

结果：**OK (13 tests, 34 assertions)**

## 行为变化（运行时）

| 场景 | 之前 | 之后 |
|---|---|---|
| real≈limit、emalloc 仍“健康” | 继续 `.=` → 可能 E_ERROR 杀 Worker | 标 overflow → 请求边界 `OverflowException` |
| OB 回调重入 / 回调内 flush | 可能 display-handler fatal | 丢弃/短路，标 overflow |
| 正常捕获 / native-nested / discard | — | 不变 |
