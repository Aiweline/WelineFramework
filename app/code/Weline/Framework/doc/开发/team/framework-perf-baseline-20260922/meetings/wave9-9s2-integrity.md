# wave9-9s2 — 内容完整性门 + 固化 skip-fill 禁交白卷（主题）

- date: 2026-09-22 ~20:20+08
- seat: Team:主题开发工程师:
- channel: `framework-unreasonable-audit.md` **msg-109**（re msg-108/110 · 用户硬纠偏）
- work_mode: `theme_module_runtime`
- Theme: **2.2.586**
- claim_sla: **false** · 禁自 reload · **禁** 8c\* · 禁回退 9s head 收益

## 丢件根因

8s5 固化后 `+skip_fill_solidified`；2.2.582 安全网**只认** `slot-placeholder` / filters `data-placeholder`。  
壳缺 header/nav/footer 扩展、空 required 槽、或无 `weline-header` 信号时仍 skip→strip → 间歇交白卷（本机 `/` 缺 header-nav/客户服务；空槽同类）。

## 落地

| 优先级 | 内容 |
|--------|------|
| **P0 完整性** | 扩展 `shellNeedsRuntimeSafetyNetFill`（空关键 chrome/required 槽 + 缺 header 信号）；`healPublishedPlaceholderShell` 增 `spliceChromeSlotsFromBake` + nested chrome 盘投影；**禁** injectChrome / storefront_chrome Policy 拼布局 |
| **P1 再生** | 固化 `CTX_USE_REACTIVE=false` 时 header/footer 旁路 `storefrontChromePolicy`；保留 9s head website + assets |
| skip-fill | **完整壳仍可** skip；缺件必须 heal |

## UT

- `PublishedStorefrontForcedZeroFillContractTest`：占位/缺件必 heal；完整可 skip；marker=0
- `PublishedStorefrontSolidifiedShellContractTest`：spliceChrome / 禁 injectChrome
- `PartialsChromeCachePolicyTest`：solidified bypass header/footer Policy

## 如何验收「不丢东西」

1. 本机 curl `/` + `/products`（panel Cookie 可选）：须含 `weline-header` 或 `header-nav`；不得仅空 `theme-published-slot` 于 header-nav-extensions / list-filters。
2. 响应 `data-wslot=` =0（固化 marker）。
3. 性能 9v2：完整壳路径下 `theme.storefront_chrome` builder 应 absent / 近零；**不得**因种袋 absent 排 8c\*。
4. 长期缺件 → rebake（publish / 注入收集），运行时 heal 仅安全网。

## escalate

@项目经理：可开 **9v2**（完整性抽检 + 固化主门；A 轴/head 辅）。禁本席自 reload。
