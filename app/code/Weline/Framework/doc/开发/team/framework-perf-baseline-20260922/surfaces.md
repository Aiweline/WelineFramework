# surfaces（wave1 · 机制面最小版）

team: `framework-perf-baseline-20260922`  
updated: 2026-09-22 · from:架构师 · **wave8-8a2 固化模板口径叠加**

选型权威：`Framework/doc/3-开发/扩展点选型.md`。本波**不新建**跨模块 Event/Query；禁止跨模块直调 Service/Model。

| 面 | 机制（选型表） | Owner / 落点 | 本波用途 | 禁止 |
|----|----------------|--------------|----------|------|
| R1 批量缓存传输 | **Interface**（Framework 内） | `BatchCacheAdapterInterface` ← `WlsMemoryAdapter`；调用方 `CachePool` | Pool 优先批量；非批量 Adapter 逐键 fallback | 业务旁路直调 Memory facade；伪批量 RPC；无 epoch 进程袋 |
| R2 店面冷首击 / FPC | **Interface** + Runtime 编排 | `FpcWarmupProviderInterface`（路径贡献）；`WlsRuntime` deferred warmup；`FullPageCacheCoordinator` | 保留 homepage fail-open；deferred 必跑且 `/` 首槽；公网 Host；真实 HIT | 默认强制严格 READY prime；假 HIT；回环 Host 预热错 key |
| R3 已发布布局/Slot（并行辅轨 → **8a2 降级**） | **固化 bake 直读**（主）+ HotCache（辅） | Theme materialize 发布产物；店面 include 固化 phtml | **店面 = 直接加载 published bake**；header/chrome 已 bake 进壳 | 店面 runtime SlotFiller/`injectChrome` 补槽；用种袋/header·chrome_slot Policy **代替**固化直读；草稿进共享池；平行 static |
| R4（本波不做） | — | Search / ACL / Fiber | 下一波 | 本波施工 |

## wave8-8a2 · 固化模板禁区（8s5 / 后端）

| 席 | 允许 | **禁止** |
|----|------|----------|
| **主题 8s5** | editor/preview 重路径；`materialize`/`BakeCoordinator` 在 **publish** 与 **注入收集** 时重生 bake；店面 **include** 已含 header/chrome 的固化 `layout.phtml`（或等价整壳） | 店面 `SlotFiller::fill` / `injectChromeSlots` / safety-net 拼槽；请求期 `renderPublishedSolidifiedFragments`+`publishedInner` 当主交付；把 `chrome_slot_projection` / Partials header HotCache 当布局再生 |
| **后端 8c\*** | FPC / fail-open / no-store；种袋**辅**（业务词/导航等非布局壳） | 用 deferred 种袋代替「固化模板直读」；为补槽再开 header/chrome 大袋主链；假 HIT；拆 fail-open/no-store |

再生窗口（硬）：① 主题可视化编辑 **发布**；② **注入收集**（default_injection / 有部件必入）触发。其它访问不得再生成布局。

跨模块读/写若后续波次触及业务列表聚合：仍走 **QueryProvider / Interface**，不得用直调换性能。

## wave9-9a · A 轴 total（固化主门已关 · 另案）

updated: 2026-09-22 · from:架构师 · channel **msg-103** · `meetings/wave9-9a-a-axis.md`

目标：压近冷 total（辅证锚 ≤2152 · claim_sla=false）；**不回退** 8s5 整壳直读；**禁**重开 8c\* 种袋代布局。

| 面 | 机制 | Owner | 本波用途 | 禁止 |
|----|------|-------|----------|------|
| `theme.storefront_head` builder | CachePolicy / 片段复用 / 减负 | **9s 主题** | 压 ~1334ms miss/重算 | injectChrome；fill；用种袋代布局；回退 shell |
| `product.card.render` | FragmentCache / 批渲染 / 非首屏延迟 | **9p Product** | 压 ~1289ms 卡 SSR | 删功能语义；平行 static；动壳 |
| dict_prefetch / `partials.fetch.head` / `category_nav` | 批量词 / 既有 Policy（辅） | 9d 或并入 9s（后排） | 压次要 wall | 代布局；无 Policy 私袋 |

硬继承 8a2/8s5：店面 = published `shell.phtml`；再生仅 publish + 注入收集。
