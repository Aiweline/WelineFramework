# contracts — fiber-ob-migrate-20260923

**冻结**：2026-09-23 · 权威纪要 `meetings/架构-冻结.md` + `surfaces.md`。

| UC | 意图 | Given / When / Then | 证据 |
|----|------|---------------------|------|
| UC-no-process-ob-html | A 类生产路径无进程级裸 `ob_start` 抓模板/include HTML | Given WLS 店面/Taglib/Theme include；When 渲染 Slot/Search/LanguageSelect/Chrome/SlotFiller/DevTool/Maintenance/widget phtml；Then 源码仅为 Fiber API 或字符串拼装，rg 无裸 HTML 捕获 | rg 清单 + 契约 UT |
| UC-fiber-api | 捕获一律 `FiberOutputBuffer::beginCapture/endCapture`（或 `BinaryOutputGuard` 等已批准包装） | Given 任一 A/B 迁后点；When 读源码；Then 可见 Fiber API，异常路径 `discardCapture` | 源码 review |
| UC-no-drain-fiber-handler | 请求期禁止 `while (ob_get_level) ob_end_clean/flush` 拆掉 Fiber 已安装层 | Given persistent；When Dictionary JSON / Login 事后 clean / AiDraw / RouterRunBefore；Then 不整栈拆 handler；用 `resetCurrent` / Guard / 无-op | 源码 + WLS 冒烟 |
| UC-gd-via-fiber | B 类 GD 字节捕获走 Fiber（无裸 ob 例外） | Given Login/Captcha/AiDrawService；When `imagepng`；Then `beginCapture`/`endCapture` | 源码 |
| UC-c-exceptions-honored | C 书面例外保留且不回退已迁分支 | Given SSE/FpmEmitter/PcController FPM/`BinaryOutputGuard`/CLI flush/ElFinder dist/CLI script；When 施工；Then 不误删例外；不把 persistent `fetchJson` 改回 `ob_clean` | surfaces C 表 |
| UC-fiber-internal-kept | D 类内部 ob 保留 | Given `FiberOutputBuffer.php`；When 施工；Then 不「洁癖拆除」handler | diff 门禁 |
| UC-tests-contract | E：生产路径契约禁裸 ob、允许 Fiber | Given Slot/Form 类契约；When 断言；Then 含 Fiber API 且禁编译裸 `ob_start` | UT |
| UC-fpc-unchanged | 不改变 FPC HIT/MISS；禁假 HIT/关 FPC | Given 店面缓存探针；When 迁后；Then 语义不变 | 探针 |
| UC-no-chrome-teardown | 禁拆壳 / 禁卸无 user_deleted 的 required | Given Theme chrome；When 迁 Slot/Chrome；Then 只换缓冲 API | review |
| UC-no-private-reload | 禁私自 reload | Given 施工波；When 验活；Then 不擅自 `server:reload` | 流程 |
| UC-storefront-smoke | `/` `/products` `/guide/returns` 200；头栏 Search/语言不空壳 | Given 本机验收 Host；When GET；Then 200 + 关键 HTML 非空 | curl/Browser |

## 迁移模式（合同级）

唯一合法请求期捕获：`FiberOutputBuffer::beginCapture` → … → `endCapture`；失败 `discardCapture`。禁再引入进程级抓模板 ob。
