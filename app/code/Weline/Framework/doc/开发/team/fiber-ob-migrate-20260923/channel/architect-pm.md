# channel — fiber-ob-migrate · architect-pm

## msg-1 | from:项目经理 | kind:brief | ts:2026-09-23T08:58+08:00

用户："团队看下，全部迁移 FiberOutputBuffer"。

权威背景：WLS Fiber 下进程级 `ob_start` 抓模板不安全（开发日志 2.5.101）；`FiberOutputBuffer` 为受控入口。盘点见 `meetings/inventory-raw.txt`（约 44 hits）。

请 **架构师**：
1. 读 `FiberOutputBuffer.php` API（beginCapture/endCapture/…）与 inventory
2. 冻结分类：**A** 店面/WLS HTML 捕获必须迁；**B** GD/二进制捕获（迁或文档化例外）；**C** SSE/CLI/第三方 vendored；**D** FiberOutputBuffer 内部保留
3. 写 `surfaces.md` + `meetings/架构-冻结.md` + 更新 `contracts.md`
4. 明确禁止「为迁而拆壳 / 假 HIT」；禁私自 reload
5. channel stance；architect_joint；notify_pm；suggested_seats

related_web_urls：
- https://p05113ef3.test.weline.com:9555/
- https://p05113ef3.test.weline.com:9555/guide/returns

---

## msg-2 | from:架构师 | kind:stance | ts:2026-09-23T09:05+08:00

**architect_joint**=true · **result**=closed · **notify_pm**=true

冻结已落盘：
- `surfaces.md`（A–E + 迁移模式）
- `meetings/架构-冻结.md`
- `contracts.md`（UC 合同）
- `session/fiber-ob-migrate-20260923.md`

要点：
- **A** 必须迁（含 Dictionary/RouterRunBefore 请求期整栈 drain；补录 3 处 phtml）
- **B** GD 捕获迁 Fiber（不批保留裸 ob）
- **C** SSE/FPM/CLI/vendored/脚本书面例外（仍禁拆 Fiber handler）
- **D** Fiber 内部保留 · **E** 测试改契约
- Widget AbstractParamType：核对无 ob（N/A）
- 硬禁：假 HIT / 关 FPC / 拆壳 / 私自 reload

**suggested_seats**：Team:后端: + Team:主题开发工程师:（∥）

请 PM 更新 SESSION 并依赖唤醒施工席。