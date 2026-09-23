# 对齐冻结会纪要 · policy-accessibility-seo-completeness

- time: 2026-09-22T13:55:00+08:00
- chair: 测试（Team:测试:）
- agent_id: `7d8ce88c-1211-4432-b162-7b456636a340`
- wave: `align_freeze` → **frozen**
- result: closed（仅冻结；本波不执行 UT/RT/WB/e2e）

---

## 1. 参会席与 stance

| 席位 | agent_id / 来源 | stance | 结论 |
|------|-----------------|--------|------|
| 测试（主持） | `7d8ce88c-1211-4432-b162-7b456636a340` | **freeze** | 钉 UC-1..4 ↔ contracts/deps；本波禁写生产码、禁提前验收 |
| 项目经理 | parent / msg-004 | **ask→freeze** | DoD：spec-uc + arch-surfaces closed；请测试主持冻结 |
| 需求分析 | `a5c55227-6bdf-4cb0-8759-8bf9cc5f44c2` | **agree**（文档） | UC-1..4 + EARS×6；`ui_in_scope=false`；顾问 N/A |
| 架构师 | `a5499328-01b1-447e-9891-315ce8ea28e0` | **agree**（机制） | G1 Theme Provider 扩 ROUTES；G2/G3 Seo 别名；禁 AccessibilityPage；不上性能席 |
| 后端 | standby | **wait** | 等本会 frozen 后由 PM 派施工 |

**无反对票。** 规格与 surfaces 无冲突；顾问席本波 N/A（无政策正文变更）。

---

## 2. 冻结的 UC 列表（可执行）

| UC | 名称 | 主断言（冻结） | 映射契约 | 验收意图（施工后） |
|----|------|----------------|----------|-------------------|
| **UC-1** | 无障碍页 head 经 Seo 管线输出 legal 类别 | 打开 `/policy/accessibility` → head 含非空 title、可索引 robots、`meta[name="content-category"]="legal"`；无布局内联 JSON-LD/手写 canonical | contracts §1（G3） | UT-HeadRenderer-policy-alias；WB-OP-policy-a11y-head |
| **UC-2** | inspector 将无障碍页归为 legal | 同页 inspector → `seoType=legal`（alias + `/policy/` URL 启发式）；命中 legal 规则集 | contracts §2（G2） | UT/契约-inspector-policy-to-legal；WB-OP-inspector-legal |
| **UC-3** | 同步后 sitemap 含无障碍声明 URL | 同步 Theme `storefront_static` Provider 后，表/XML 出现 `/policy/accessibility`（及整组 `policy/*`）；稳定 `url_key=theme-static:policy/...` | contracts §3（G1） | UT-StorefrontStaticSitemap-policy-routes；同步后抽检 |
| **UC-4** | 政策 phtml 不引入页内 SEO | diff + 源码：`layouts/policy/*.phtml` 无新增 meta/JSON-LD/canonical/robots；SEO 仅经 Seo 管线 | contracts §4（F1–F4） | 代码审查 + 源码抽检；汇审项 |

规格 EARS 1–6 作为 UC 的可观察细则，一并冻结意图；施工席不得私改已冻 UC 意图。

---

## 3. 机制勾选（对齐 surfaces §7）

- [x] **G1** → Theme `StorefrontStaticSitemapUrlProvider` 增补 ROUTES（**不**新建 Provider；**不**在 Seo 核心硬编码 Theme 法律 URL）
- [x] **G2** → inspector 别名 + `/policy` URL 启发式 → `legal`
- [x] **G3** → HeadRenderer `content-category` 别名 → `legal`；**page_type 事实保持 `policy`**；JSON-LD 壳 = **WebPage**（禁 `AccessibilityPage`）
- [x] **禁止**页内 SEO（policy phtml）；**禁止** AccessibilityPage
- [x] **不上**性能检查工程师（除非施工误改 FPC/HotCache 键 → escalate PM）

类型边界冻结（摘要）：Theme 发布 `page_type=policy`；Seo 归一别名 → content-category/inspector=`legal`；JSON-LD=`WebPage`。

---

## 4. UI / 原型门（硬判定）

| 项 | 值 |
|----|-----|
| `ui_in_scope` | **false**（规格 + SESSION） |
| UI/原型门 | **跳过**（`ui_prototype_gate_before_test` 不适用本 feature） |
| 施工后测试波 | **直接**进入 UT / 契约测 / 真通路 WB-OP（head / inspector / sitemap）；**不**等待 `acceptance-ui.md` / `acceptance-prototype.md` |

提醒（本波不执行，施工后测试执行波遵守）：

- `tester_tests_must_be_real`：禁止自造假数据再断言 pass；须真实业务通路证据（`acceptance_real_business_pathway`）。
- 正式 Playwright runner；禁止壳层冒烟收口；禁止向用户甩测或要账号（本机 admin/admin）。
- `browser_strip_automation_flags` + 禁缓存；验收 pass 的 closed 回报须填 `related_web_urls`。
- 测试 pass 后交 **项目经理汇审**；禁止本席直接向用户宣称 feature 完成。

本波：`related_web_urls` = N/A（仅冻结，未开 Browser / 未跑验收）。

---

## 5. contracts / deps 冻结决议

| 文档 | 决议 |
|------|------|
| `contracts.md` | **draft → frozen**；§1↔UC-1、§2↔UC-2、§3↔UC-3、§4↔UC-4 已回填 |
| `deps.md` | **draft → frozen**；并行轨 A/B/C（G3/G2/G1）在 D2 后可并行 |
| `surfaces.md` | 机制勾选与本纪要一致（会后可同步勾选 §7） |

---

## 6. 下一步施工轨（交 PM 派工）

```text
D2 align-freeze（本会）CLOSED
  ├── D3a 后端 · Seo HeadRenderer 别名（G3）+ UT     ─┐
  ├── D3b 后端 · Seo inspector 别名+/policy（G2）     ─┼─→ D5 测试执行波（跳过 UI/原型门）
  └── D3c 后端 · Theme StorefrontStatic ROUTES（G1） ─→ D4 同步证据 ─┘
                                                              ↓
                                                         D6 PM 汇审
```

计划项指针：`build-seo-pipeline`（§1+§2）、`build-theme-sitemap`（§3）、`test-exec`（真通路）、`huishen`。

**本席本波禁止：** 改生产 PHP/JS/phtml；提前跑验收 e2e/WB。

---

## 7. 技能引用

- `get_skill(webui_browser_closeout)`：MCP 需 repository；本波改用宿主 Read `local-browser-urls` 技能镜（禁缓存 / 抹自动化标志 / 交付后关 Browser）。本波未开 Browser → 收口 N/A。
- 正式验收波再强制 Playwright + 真通路；本纪要仅钉门禁，不执行。

---

## 8. notify_pm

@项目经理：本席已交付对齐冻结（UC+contracts+deps **frozen**），请检查并更新 SESSION：`plan_id=align-freeze` → closed；`wave` → 施工派工；`status` 保持 open 直至汇审。
