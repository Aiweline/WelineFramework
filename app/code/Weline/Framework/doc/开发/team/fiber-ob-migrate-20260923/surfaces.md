# surfaces — fiber-ob-migrate-20260923（架构冻结）

**status**: frozen · architect_joint=true · 2026-09-23  
**选型**：不新建跨模块 Event；一律走 `Weline\Framework\Runtime\FiberOutputBuffer`（`beginCapture` / `endCapture` / `discardCapture`；按需 `hasActiveCapture` / `resetCurrent`）。  
**缓存**：不借机改 FPC HIT/MISS；禁假 HIT、禁关 FPC、禁拆 Theme chrome 壳、禁私自 reload。  
**先例**：开发日志 2.5.101（`<w:form>`/Taglib children）+ 2.5.102（ErrorPage / BinaryOutputGuard / ClassGenerator）。

## 迁移模式（唯一合法 HTML/请求期捕获）

```php
\Weline\Framework\Runtime\FiberOutputBuffer::beginCapture();
try {
    // include / echo / 渲染
    $out = \Weline\Framework\Runtime\FiberOutputBuffer::endCapture();
} catch (\Throwable $e) {
    \Weline\Framework\Runtime\FiberOutputBuffer::discardCapture();
    throw $e;
}
```

- Taglib **编译产物**字符串：与 Form 同构，生成 `FiberOutputBuffer::beginCapture()` / `endCapture()` / `discardCapture()`，**禁止**再生成进程级 `ob_start()` / `ob_get_clean()`。
- 已有包装（如 `BinaryOutputGuard`、`Template::ob_file`/`fetch` 内捕获）优先复用，勿平行再造一套。
- **禁止**再引入「为抓模板/部件 HTML」的进程级 `ob_*`。
- Non-persistent（FPM）下 `FiberOutputBuffer` 已降级为 baseline native；业务侧仍应调 Fiber API，勿手写裸 `ob_start`。

## 分类冻结矩阵

| 类 | 含义 | 处置 |
|----|------|------|
| **A** | WLS/店面/Taglib/请求期 HTML（及会拆 Fiber handler 的请求期整栈 drain） | **硬·必须迁** |
| **B** | GD/`imagepng` 等二进制字节捕获 | **迁 Fiber API**（本波不批书面例外；CLI 脚本见 C） |
| **C** | SSE / FPM Emitter / CLI Setup / vendored / 脚本 / 已硬化分支 | 分类处理（见下） |
| **D** | `FiberOutputBuffer` 内部 `ob_*` | **保留** |
| **E** | 测试 | 断言禁裸 ob（生产路径契约）/ 允许 Fiber API |

### A — 硬·必须迁（并行席：后端 ∥ 主题）

| # | 路径 | 席 | 备注 |
|---|------|----|------|
| A1 | `Theme/Taglib/Slot.php`（编译 `ob_start`/`ob_get_clean`） | 主题 | 对齐 Form 2.5.101；契约 UT 改断言 Fiber |
| A2 | `Theme/.../ThemeLayoutEntityChrome.php` `includeChromePhtml` | 主题 | include 捕获 |
| A3 | `Theme/.../ThemeLayoutEntitySlotFiller.php` `includeEntityPhtml` | 主题 | include 捕获 |
| A4 | `Search/Taglib/Search.php` | 后端 | 面板 HTML 内联捕获 |
| A5 | `I18n/Taglib/LanguageSelect.php` | 后端 | include markup 捕获 |
| A6 | `DeveloperWorkspace/.../DevToolPanelObserver.php` | 后端 | panel include 捕获 |
| A7 | `Maintenance/.../MaintenanceStaticGenerator.php` | 后端 | maintenance.phtml include |
| A8 | `Theme/view/.../widgets/data/form/default.phtml` | 主题 | 部件 HTML 片段 `ob_start` |
| A9 | `Theme/view/.../widgets/header/mini-cart-icon/default.phtml` | 主题 | 同上 |
| A10 | `Backend/view/blocks/header/base.phtml` | 后端 | 请求期静音 `getThemeConfig`；改 Fiber 或去掉缓冲 |
| A11 | `I18n/Controller/Backend/Dictionary.php`（`getLocaleName` 静音 + JSON 动作 `while(ob_get_level) drain`） | 后端 | **禁** WLS 下整栈 `ob_end_clean`；JSON 走 `BinaryOutputGuard`/`resetCurrent` 模式 |
| A12 | `MediaManager/Observer/RouterRunBefore.php` | 后端 | 请求期 `ob_start`+`ob_end_clean` 静音；persistent 不得装裸层 |

**漏网核对**：`Widget/Ui/ParamType/AbstractParamType.php` — **无** `ob_*`（本波 N/A，无需迁）。

### B — GD/二进制捕获（必须迁 Fiber；不批「保留裸 ob」例外）

| # | 路径 | 席 | 备注 |
|---|------|----|------|
| B1 | `Admin/Controller/Login.php`（`imagepng` + 事后 `while ob_end_clean`） | 后端 | 捕获→Fiber；drain→persistent 禁整栈拆 handler |
| B2 | `Captcha/Service/LocalChallengeImage.php` | 后端 | `imagepng` 字节捕获 |
| B3 | `MediaManager/Service/AiDrawService.php` mock GD | 后端 | 同上 |
| B4 | `MediaManager/Controller/Backend/AiDraw.php` 整栈 clean | 后端 | 与 B3 同 harden |

优先复用/对齐 `Http/BinaryOutputGuard` 语义；纯字节捕获直接 `beginCapture`/`endCapture` 即可。

### C — 分类处理

| # | 路径 | 处置 | 理由 |
|---|------|------|------|
| C1 | `Http/Sse/SseContext.php` / `SseWriter.php` | **书面例外·保留 flush** | 流式推送需要 flush；**非** HTML 捕获。硬约束：persistent 下不得 `while(ob_get_level){ob_end_flush/clean}` 拆掉 Fiber 已安装 handler；仅 flush 顶层或走 Runtime 既有流式通道 |
| C2 | `Http/FpmResponseEmitter.php` | **例外·FPM-only 保留** | 非 WLS 路径清空缓冲发响应 |
| C3 | `Controller/PcController.php` `fetchJson` FPM `ob_clean` 分支 | **例外·保留** | persistent 分支已 `FiberOutputBuffer::resetCurrent`（2.5.102）；禁止回退 |
| C4 | `Http/BinaryOutputGuard.php` FPM 预清 + Fiber 捕获 | **已达标·保留** | persistent 已 Fiber；勿再改回裸栈 drain |
| C5 | `Setup/Console/Setup/Upgrade.php` / `Database/.../SetupUpgradeObserver.php` / `Event/Event.php` `@ob_flush` | **例外·CLI 进度** | 非请求 HTML 捕获 |
| C6 | `ElFinderFileManager/view/statics/php/connector.maximal.php-dist` | **例外·vendored dist** | 不改 upstream dist；若运行时包装器自有代码有裸 ob 另开票 |
| C7 | `Product/scripts/remediate-product-image-hd.php` | **例外·CLI 脚本** | 非 WLS 请求路径；可选对齐 Fiber API，不挡 A/B 汇审 |
| C8 | 文档样例（`Frontend/doc/...`、`DataTable/Test/README.md`） | **文档后续** | 非运行时；汇审后改示例为 Fiber |

### D — FiberOutputBuffer 内部

`Framework/Runtime/FiberOutputBuffer.php` 内 `ob_start(handler)` / nested native / `ob_flush` / baseline `ob_get_clean` — **保留**，禁止「为洁癖」拆除。

### E — 测试

| 处置 | 示例 |
|------|------|
| **契约 UT 改断言** | Theme Slot 契约现断言含 `ob_start` → 改为含 `FiberOutputBuffer::beginCapture` 且 **禁** 编译产物裸 `ob_start()`（对齐 `FormFiberCaptureContractTest`） |
| **允许 Fiber API** | 新/改契约可 `beginCapture`/`endCapture` |
| **PHPUnit 隔离用裸 ob** | Server/Gateway/CLI 单测捕获命令输出：允许保留，但不得复制到生产 Taglib/Theme 路径；优先逐步换 Fiber |
| **模板 include 测试助手** | Order/Checkout/Product/Theme widget 测试里 `ob_start`+include：本波改为 Fiber API（与生产一致） |

## 硬禁（全席）

1. 假 HIT / 关 FPC / 拆 chrome 壳 / 无卸载记录卸 required 注入  
2. 私自 `server:reload` / 重启 WLS「为了让缓冲生效」  
3. 借机大重构业务功能；只迁缓冲边界  
4. 新增进程级「抓模板」`ob_start`  

## 验收面（契约）

- `UC-no-process-ob-html`：A 类生产路径 rg 清零裸 HTML 捕获  
- `UC-fiber-api`：捕获点可见 Fiber API  
- `UC-fpc-unchanged`：探针 HIT/MISS 语义不变  
- `UC-storefront-smoke`：`/` `/products` `/guide/returns` 200（及 Search/LanguageSelect 相关头栏不空）
