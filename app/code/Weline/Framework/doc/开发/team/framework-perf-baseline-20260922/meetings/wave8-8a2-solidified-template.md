# wave8-8a2 — 固化模板 stance（架构）

- date: 2026-09-22 ~19:31+08
- seat: Team:架构师:
- channel: `framework-unreasonable-audit.md` **msg-95**（re msg-94 用户硬口径）
- claim_sla: **false** · 不写大码 · 禁自 reload / 禁自排施工

## stance（冻结）

1. **店面访问** = **直接加载固化模板**（published bake：`layout.phtml` / 等价整壳；**header/chrome 已 bake 进壳**）。
2. **重新生成布局**仅当：
   - 后台主题可视化编辑器改布局并 **发布**；或
   - **注入收集**（`default_injection` / 有部件必入）触发须重生 bake。
3. 其它路径（含普通冷/暖 SSR、deferred 种袋、HotCache fill）**不得**运行时拼槽 / 再生成布局。
4. 店面 **禁止** runtime `SlotFiller::fill` / `injectChromeSlots` 补槽；editor/preview 重路径保留。

相对 8a「尽量零运行时补槽」：**升级为硬禁止**——零 marker 早退不够；壳内仍不得靠请求期 chrome/header 投影袋代替固化直读。

## 对照现状（违规表）

| 组件 | 现状 | vs 本口径 |
|------|------|-----------|
| `LayoutSlotRenderer` 早退 / zero-fill | msg-89：`data-wslot=`=0 · LayoutSlot **无 span / ≪100ms** | **合规（补槽面）**；非最终态 |
| `PublishedSlotHost` | 请求期 `prime`→`loadFragments`→`renderPublishedSolidifiedFragments`（include 页 bake + **`rememberPublishedChromeSlotProjection`**）再经 Taglib `publishedInner` 拼壳 | **违规（半）**：非「整壳直读」；chrome 仍请求期投影 |
| `SlotFiller::fill` / `injectChromeSlots` | 安全网占位仍可走 fill；editor 保留 | 店面安全网 **违规**；editor **允许** |
| HotCache `theme.partials.fetch.header` | msg-89 残 B **header ~846ms** 主导 | **硬违规**：header 未 bake 进固化壳、仍运行时 Partials |
| HotCache `chrome_slot_projection` | 种袋/remember 服务 injectChrome；msg-89 仍曾 absent | **违规**：用袋代替固化 chrome |
| 后端 deferred 种袋 | 8c\* 主盯 header/chrome 真种 | **降级为辅**：不得代替固化直读 |

## 8s5 / 后端禁区摘要

见 `surfaces.md`「wave8-8a2 · 固化模板禁区」；一句话：

- **8s5**：店面 include 已含 header/chrome 的固化 phtml；禁 runtime injectChrome/fill；再生仅 publish + 注入收集。
- **后端**：种袋辅；禁用种袋代替固化模板直读。

## 建议 8s5（一句）

把已发布店面主链改为 **include 含 header/chrome 的固化整壳**；短路请求期 `injectChrome` / Partials header / `chrome_slot_projection` 拼布局；再生只留 **editor publish + 注入收集**。

## escalate

@项目经理：8a2 **stance_frozen** → 可唤醒主题 **8s5**（禁本席自 reload；claim_sla=false）。
