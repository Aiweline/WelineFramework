# deps.md · policy-accessibility-seo-completeness（frozen）

- slug: `policy-accessibility-seo-completeness`
- 状态: **frozen**（测试主持对齐冻结会 2026-09-22；纪要 `meetings/align-freeze.md`）
- 配套: `surfaces.md` + `contracts.md`（frozen）+ SESSION plan 表
- 修订席: 测试（冻结）· 架构师（草案）· 2026-09-22

> 唤醒规则：上游 ready/closed 才 resume 下游。D2 已闭；下列为施工期依赖。

---

## 建议施工顺序（冻结）

```text
1. 对齐冻结：UC + contracts + deps 钉死（测试主持）← DONE
2. 并行轨 A：Seo HeadRenderer content-category 别名（G3）+ UT          ← UC-1
3. 并行轨 B：Seo inspector 别名 + /policy URL 启发式（G2）+ 契约测      ← UC-2
4. 并行轨 C：Theme StorefrontStaticSitemapUrlProvider 增补 policy/*（G1）+ UT ← UC-3
5. Sitemap 同步证据（后台或 cli 同步 → 表/XML 抽检 accessibility）
6. 测试真通路：/policy/accessibility head+inspector；禁止 phtml SEO 扫（UC-4）
   ※ ui_in_scope=false → 跳过 UI/原型门；直接测试执行波
7. 专席复审（若需）→ PM 汇审
```

**要点：** contracts 已冻，**后端可并行**改 Seo（G2+G3）与 Theme Provider（G1）；无互相阻塞的代码依赖。

---

## 节点定义

| id | 席位 / 轨 | 交付摘要 | 可先开干？ | UC |
|----|-----------|----------|------------|-----|
| D0 | 需求分析 | spec EARS + 主路径 UC | **closed** | UC-1..4 规格 |
| D1 | 架构师 | surfaces / contracts 草案 / deps | **closed** | — |
| D2 | 测试主持 | 对齐冻结会：UC+contracts+deps frozen | **closed**（本会） | UC-1..4 钉死 |
| D3a | 后端 · Seo | `HeadRenderer::defaultContentCategory` 别名→legal（G3）+ UT | wait D2 → **可开** | UC-1 |
| D3b | 后端 · Seo | inspector aliases + `inferSeoTypeFromUrlPath` `/policy`（G2） | wait D2；可与 D3a **并行** | UC-2 |
| D3c | 后端 · Theme | `StorefrontStaticSitemapUrlProvider` 增补 ROUTES（G1）+ UT | wait D2；可与 D3a/D3b **并行** | UC-3 |
| D4 | 后端 / 测试辅助 | Sitemap Provider 同步 → 抽检 `policy/accessibility` 行 | wait D3c | UC-3 |
| D5 | 测试 | UT/契约测绿；Browser/inspector 真通路（**跳过** UI/原型门） | wait D3a ∧ D3b；Sitemap 证据 wait D4 | UC-1..4 |
| D6 | 项目经理 | 汇审 + SESSION 收口 | wait D5；未完成清单清空 | — |

---

## 依赖边

```text
D0 spec-uc ──┐
             ├──→ D2 align-freeze(CLOSED) ──┬──→ D3a HeadRenderer (G3) ──┐
D1 surfaces ─┘                             ├──→ D3b inspector (G2) ─────┼──→ D5 test-exec ──→ D6 huishen
                                           └──→ D3c Theme sitemap (G1) ─→ D4 sync证据 ─┘
```

### 产品句式

| 边 | 含义 |
|----|------|
| **contracts 冻 → 后端并行** | G1 与 G2/G3 无代码互等；可同波并行合入 |
| **Provider 改 → 同步证据** | 仅改 ROUTES 不足以宣称 G1 closed；须同步后表/XML 可见 |
| **禁止 phtml SEO** | 任意轨不得在 policy 布局写 SEO；测试 F1/UC-4 可独立扫 |
| **跳过 UI/原型门** | `ui_in_scope=false`；D5 不等 `acceptance-ui` / `acceptance-prototype` |

---

## 不上的席位

| 席位 | 原因 |
|------|------|
| 性能检查工程师 | Sitemap 非店面热路径；不改 HotCache/FPC 键（见 surfaces §5） |
| 部件 / 主题 Token 专席 | 无 Widget/Token 变更 |
| 翻译工程师 | 本需求无新增用户可见文案（既有 i18n 标题） |
| 电商顾问 | 顾问约束 N/A（无政策正文变更） |

若施工触及 FPC Extra / HotCache key → escalate PM 拉性能席，并改本 deps。
