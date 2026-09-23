# channel — fiber-ob-migrate · backend-pm

## msg-1 | from:项目经理 | kind:brief | ts:2026-09-23T09:06+08:00

架构冻结 closed。请按 `surfaces.md` 施工：

**范围**：A4–A7、A10–A12、B1–B4；C1/C4 harden（勿拆 Fiber handler）。

**模式**：
```php
FiberOutputBuffer::beginCapture();
try { …; $out = FiberOutputBuffer::endCapture(); }
catch (\Throwable $e) { FiberOutputBuffer::discardCapture(); throw $e; }
```

硬禁：假 HIT / 关 FPC / 拆壳 / 私自 reload / 擦脏。

完成后 channel stance；notify_pm；末行 `@项目经理：本席已交付/上报，请检查并更新 SESSION。`

## msg-2 | from:后端 | kind:stance | ts:2026-09-23T09:20+08:00

**stance**: delivered  
**result**: closed  
**notify_pm**: true  
**wave**: fiber-ob-migrate-20260923  

### 施工摘要（仅缓冲边界，业务语义未改）

| # | 路径 | 处置 |
|---|------|------|
| A4 | `Search/Taglib/Search.php` | 面板 HTML → Fiber begin/end/discard |
| A5 | `I18n/Taglib/LanguageSelect.php` | include markup → Fiber |
| A6 | `DeveloperWorkspace/.../DevToolPanelObserver.php` | panel include → Fiber |
| A7 | `Maintenance/.../MaintenanceStaticGenerator.php` | maintenance.phtml → Fiber |
| A10 | `Backend/view/blocks/header/base.phtml` | getThemeConfig 静音 → Fiber discard |
| A11 | `I18n/Controller/Backend/Dictionary.php` | 静音 Fiber；JSON `while` drain → persistent `resetCurrent` / FPM-only drain；`var_export(..., true)` 去嵌套裸 ob |
| A12 | `MediaManager/Observer/RouterRunBefore.php` | 请求期静音 → Fiber + finally discard |
| B1 | `Admin/Controller/Login.php` | imagepng → Fiber；事后 drain → persistent resetCurrent / FPM drain |
| B2 | `Captcha/Service/LocalChallengeImage.php` | imagepng → Fiber |
| B3 | `MediaManager/Service/AiDrawService.php` | mock GD → Fiber |
| B4 | `MediaManager/Controller/Backend/AiDraw.php` | 整栈 clean harden 同 B1 |
| C1 | `Http/Sse/SseWriter.php` / `SseContext.php` | **已安全·未改**：`while ob_end_flush` 仅 FPM 分支；WLS 走 socket/callback；Context 仅 `ob_flush` 顶层 |
| C4 | `BinaryOutputGuard` | **未改**（已达标） |

### 自检

- A/B 目标文件 **零** 裸 `ob_start`（FPM-only `while ob_end_clean` 保留在 Login/AiDraw/Dictionary else 分支，不拆 Fiber handler）
- UT：`BinaryOutputGuardContractTest` + `FiberOutputBufferTest` → **OK 13 tests / 46 assertions**
- 未触 FPC / chrome / reload / 擦脏

@项目经理：本席已交付/上报，请检查并更新 SESSION。
