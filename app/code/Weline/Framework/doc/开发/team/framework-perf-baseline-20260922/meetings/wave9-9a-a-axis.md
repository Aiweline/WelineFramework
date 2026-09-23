# wave9-9a — A 轴 surfaces / 禁区冻结（架构师 · 不写大码）

- date: 2026-09-22 ~19:53+08
- seat: Team:架构师:
- channel: `framework-unreasonable-audit.md` **msg-103**（re msg-102 / msg-101 / msg-100）
- claim_sla: **false** · 禁自 reload · 禁重开 8c\* 种袋 · **禁回退 shell 直读**
- 对照：8v2 top（rc=300 非处女 · 数字仅归因）· msg-70 total=2152 辅证锚

## result

**stance_frozen**（A 轴压 total · 固化主门保持关账）

## 冻结 surfaces（A 轴）

| 优先级 | 面 / phase | Owner 席 | 本波用途 | 机制边界 |
|--------|-------------|----------|----------|----------|
| **P0** | `storefront.cache.builder` · resource=`theme.storefront_head` | **9s 主题** | 压 ~1334ms 冷 builder miss/重算；对齐整壳后 head 减负 | CachePolicy / 片段复用 / 非布局种袋辅；**禁** injectChrome / fill 拼布局 |
| **P0** | `product.card.render` | **9p Product/后端** | 压 ~1289ms 列表卡 SSR | 既有 `StorefrontProductCardFragmentCache` / 批渲染 / 非首屏延迟输出；**禁**平行 static |
| **P1** | `view.hook.dictionary_prefetch` | 9d（可选 · 9a 后再排） | 压 ~435ms | 真批量 prefetchWords；禁 DB/RPC N+1 换皮 |
| **P1** | `theme.partials.fetch.head` | 9s（可并入 head 面） | 压 ~164ms | 与 storefront_head 同禁区；禁当布局再生 |
| **P1** | `theme.header.category_nav` | 9s 或目录归属（后排） | 压 ~140ms | 导航事实袋辅；**禁**代布局壳 |

选型：不新建跨模块 Event；跨模块事实聚合仍走 QueryProvider / Interface。本波**不回** R3 固化主路径改造。

## 禁区（硬）

| ID | 禁 | 来源 |
|----|----|------|
| Z1 | **禁回退** 8s5 `shell.phtml` 整壳直读；店面须 continue include published bake | msg-95/97/101 |
| Z2 | **禁** published `fill` / `injectChrome` / `chrome_slot_projection` / `theme.partials.fetch.header` 作布局再生主路径 | 8a2/8s5/8v2 |
| Z3 | **禁**重开 8c\* **种袋代布局**主波；种袋仅业务词/导航等非壳辅 | msg-101/102 |
| Z4 | card/head：**允许** Policy / 片段缓存 / 批渲染 / 非首屏延迟；**禁删**功能语义；**禁**平行 static / 无 Policy 进程袋 | msg-102 · 历史 A 轴纪律 |
| Z5 | claim_sla=false；禁自 reload；禁公网暖 HIT / 高 rc 冒充近冷 total 关账 | 全程 |

## 9p / 9s 分工与验收探针

| 席 | 一句分工 | 一句验收探针 |
|----|----------|--------------|
| **9p** | 只收 `product.card.render`：复用/加厚 `StorefrontProductCardFragmentCache` 与列表批渲染，禁平行 static、禁动壳。 | 同 Host 冷 MISS 对照：`product.card.render` 绝对 ms 相对 8v2(~1289) **明显降**，且 HTML 卡语义/可点购未退化。 |
| **9s** | 只收 `theme.storefront_head`（及可并的 `partials.fetch.head`）：查清 miss/重算根因并减负，**禁** injectChrome / fill / 回退 shell。 | 同窗 timeline：`theme.storefront_head` builder 绝对 ms 相对 8v2(~1334) **明显降**，且 `data-wslot=`=0 · LayoutSlot≪100 · header/chrome_slot **仍 absent**（固化主门不回退）。 |

**9d**（dict / category_nav）：9a 冻结后由 PM 另排；本 stance 不阻塞 9p∥9s。

## 关账口径（wave9 辅 · 非固化主门）

- 辅证锚：近冷 total **求降且不劣于** msg-70 **2152**（能过更好；claim_sla 仍 false）。
- **不得**因种袋 absent 要求重开 8c\*。
- 性能 9v：施工落地后由 PM 唤醒；本席不自排、不自 reload。

## escalate

@项目经理：A 轴 surfaces/禁区已冻结 → **可并行保持/唤醒 9p+9s**（roster 已 running 则令其按本 stance 施工）。证据：本文件 · channel msg-103 · `surfaces.md` wave9 节。
