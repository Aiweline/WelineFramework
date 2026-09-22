# deps · newsletter-subscribe（依赖边 · 对齐冻结）

- slug: `newsletter-subscribe`
- 冻结时间: 2026-09-22T09:40:00+08:00
- 主持席: 测试
- 状态: **frozen**（测试 + 架构师 + 扩展点；`valid_days=A`）
- 配套: `contracts.md` + `meetings/align-freeze.md`（UC-1…UC-4）

> 唤醒规则：仅当依赖边已满足才开工；上游 `closed` 才 resume 下游。禁止全员空等终点。  
> 本波只写文档；下列为施工期依赖（生产改码在后续波）。

---

## 建议施工顺序（权威）

```text
1. Setup + 模块骨架 + ACL（可先）
2. 主题：footer-container 开 footer-newsletter 槽          ← 硬阻塞
3. Newsletter：Model/台账 + SubscribeService + Controller/BinQuery
4. Marketing：活动 upsert 同步 + issue 门禁（终身一次）
5. Smtp：MailChannelProvider + default_templates
6. Widget 迁入 Newsletter + required default_injections
7. 同发布单元：Theme 删壳 + FooterPartialComposer 停 newsletter
8. T1 applyCoupon；T2 邮箱匹配（Event/Interface）
9. 后台名单 / 有奖配置 UI
10. 合同测增量变绿 → e2e/WB-OP plan-suite
```

---

## 节点定义

| id | 席位 / 轨 | 交付摘要 | 可先开干？ |
|----|-----------|----------|------------|
| D1 | Setup | register、depends、表 `weline_newsletter_subscriber`、route、upgrade | **是** |
| D2 | ACL | 后台名单 / 有奖配置资源 | **是**（可与 D1 并行） |
| D3 | 主题 | `footer-container` 声明槽 `footer-newsletter` + `w:slot` | **是**（硬阻塞下游注入验收） |
| D4 | 后端 | SubscribeService（校验/upsert/偏好）+ Frontend Controller 壳 | wait D1 |
| D5 | 查询 | `NewsletterQueryProvider::subscribe` | wait D4 |
| D6 | 后端 + 扩展点 + Marketing | `SubscribeGiftCampaignSyncService` → Marketing upsert；**SPI `valid_days` 选项 A**（context int，缺省 30） | wait D1；Marketing `valid_days` 合入与 upsert 同波或先于 D7；未合入则 **D7 发券阻塞**（纯订阅 D4 可并行） |
| D7 | 后端 | `SubscribeGiftIssuer`（终身一次 + issue + 台账写 `coupon_code`） | wait D4 ∧ D6 |
| D8 | 后端 / Smtp | `MailChannelProvider` + welcome/gift 模板 | wait D1；发信编排 wait D7（无券路径可先 welcome） |
| D9 | 后端 + 主题/前端 | Newsletter 注册 Widget（同 code）+ 模板迁入 + `default_injections` | wait D3（footer 注入）；弹窗可与 D3 尾部并行 |
| D10 | 主题 | Theme **删壳**（widget.php 去 newsletter + Composer 停硬编码） | wait D9 已注册且可渲染（**同发布单元**，防双注册窗口） |
| D11 | 后端 | T1：`MarketingCheckoutCouponSession::applyCoupon`（发券后） | wait D7 |
| D12 | 后端 + 事件/扩展点 | T2：结账邮箱匹配未用券 → apply | wait D7；事件名 wait 扩展点 |
| D13 | 后端 | 后台 DataTable 名单 + 有奖配置页 | wait D2 ∧ D6 |
| D14 | 前端 | 部件 JS（BinQuery、成功态、弹窗 cookie 14） | wait D5 ∧ D9 |
| D15 | 原型/UI/主题 Token | 汉服信笺视觉 | wait D9 DOM；可与 D14 尾部并行 |
| D16 | i18n | 中英 CSV + collect | wait 文案入模板（D9/D13） |
| D17 | 测试 · 合同测 | Owner/Slot/Service/Issuer/Mail/Sync/AutoApply | 骨架：冻结后立即；执行随 Di closed |
| D18 | 测试 · e2e + Browser | `newsletter-subscribe-plan-suite` UC-1…UC-4 | wait D10 ∧ D11 ∧ D14（UC-2 另 wait D12）；造数 wait D6/D7 |

---

## 依赖边（谁 wait 谁）

```text
D3 主题开槽 ──────────────────────────→ D9 Newsletter 注入（footer）
D9 注入/迁入 Widget ──────────────────→ D10 Theme 删壳（同发布）
D6 Marketing upsert ──────────────────→ D7 发券（issue）
D7 发券 ────┬─────────────────────────→ D8 gift 邮件
            ├─────────────────────────→ D11 T1 applyCoupon
            └─────────────────────────→ D12 T2 邮箱匹配
D4 Service ← D1 Setup
D5 BinQuery ← D4
D14 前端 ← D5 ∧ D9
D13 后台 ← D2 ∧ D6
D18 e2e ← D10 ∧ D11 ∧ D14（∧ D12 for UC-2 T2）
```

### 产品句式（任务要求）

| 边 | 含义 |
|----|------|
| **主题开槽 → 注入** | 无 `footer-newsletter` 槽则不得宣称 required injection 完成 / UC-1 前置不满足 |
| **Marketing upsert → 发券** | 无有效规则（或 inactive）则只订阅不发券（UC-1 A2 / UC-4） |
| **部件迁移 → Theme 删壳** | Newsletter 注册同 code 后必须退役 Theme，否则双渲；UC-1 可加「部件计数=1」 |

---

## 并行波建议（项目经理唤醒用）

| 波 | 可同时拉起 | 仍 wait |
|----|------------|---------|
| 施工-1 | D1、D2、D3、扩展点 `valid_days`/T2 事件名协商 | — |
| 施工-2 | D4、D6（D1 后）、D8 渠道骨架 | D7 issue |
| 施工-3 | D5、D7（D4∧D6）、D9（D3 后） | D10 |
| 施工-4 | D10（D9 后同发布）、D11、D13、D14 | D12 若事件未钉 |
| 施工-5 | D12、D15、D16 | — |
| 测试执行 | D17 增量绿；D18 plan-suite + WB-OP | 上游未 closed 禁止改已冻 UC |

---

## 禁止反流

- 下游不得私改上游已冻接口签名 / 选择器 / UC 证据字段而不回对齐冻结会。
- 技术方案会可细化 how（类名、事件名字面），**不得**把 UC-2「结账自动用券可观测」降成仅订阅成功。
- **禁止**为对齐依赖而 `git restore/clean/stash` 擦脏工作区。
- Hook 主路径 skip：不得用 Hook 再渲页脚订阅造成双份 DOM。
