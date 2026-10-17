# Spec: storefront chrome locale HotCache prime（guest-neutral）

status: delivered（guest-neutral 基线）· 续波 path=A 键对齐见下  
covers_use_cases: UC-chrome-1a, UC-chrome-1b, UC-chrome-2  
related_wave: storefront-xscope-hotcache-wave（A-xscope-chrome-hit）

## 背景

投影袋预热后，新语种 search tip 曾独占 `theme.storefront_chrome` ~856ms。根因不是「缺 tip q 预热」，而是 header 共享袋按搜索 facade（`q`/type/category）分键且把请求态烤进 HTML，与 lang-only + 货币岛架构不一致。

**续波对齐（path=A · schema v22）**：非 home（search/plp）与 homepage warmer 曾因 `layout_type` + 整页 snapshot 源指纹分叉致空袋——已改为 **layout-agnostic shell**（frontend header/footer `layout_type=shared` / `layout_option=default`；schema `chrome-partial-v22-layout-agnostic-shell`；header/footer **path-local** 源 digest）。Warmer 仍 homepage 种袋，同键覆盖非 home；禁拆壳。验收：`A-xscope-chrome-hit`（含 fr_FR/EUR）安静窗 `chrome_n=0`。

## 方案

| mechanism | owning_module | not_to_do |
|-----------|---------------|-----------|
| Header chrome **guest-neutral 键**（schema 演进：`v21-search-facade-island` → **`chrome-partial-v22-layout-agnostic-shell`**）；袋写入 strip `header-search` 岛；serve `hydrateChromeSearchFacadeIslands`；hashPayload layout 中性 | `Weline_Theme` | 禁 tip `q=hanfu` / tip 语种白名单旁路；禁拆壳；禁空烧假 HIT；禁只验 home tip |
| `StorefrontChromeLocaleCacheWarmer`：启用 locales 逐种 render header/footer；暖窗 `512M`；预算诚实 remaining | `Weline_Theme` | 禁 Runtime 硬编码语种列表；勿先扩 currency vary |
| Clear 经 Registry chrome 池预热（子进程 reseed；无 Theme FQCN soft-pull） | `Weline_Framework` | 禁 Theme FQCN soft-pull |

## 细节

- 语种源：`WebsiteLanguage::getWebsiteLanguageCodes(Website::ID_DEFAULT)`；基线优先 `zh_Hans_CN`、`en_US`。
- 池：`weline_theme_storefront_chrome`；Policy `vary=['lang','currency']`（语言敏感共享部件防毒；warmer 按门禁币×locale 预热）。
- 搜索框请求态只在 hydrate 路径注入；袋内 `query=>''`。
- 验收 soft-clear 后新语种 search hit1：exclusive chrome ≪ 修前 ~856ms 或 wall &lt; 2.5s；搜索框 value 仍为 tip 词（hydrate）。

## UC

- **UC-chrome-1a** WHEN cache:clear 后 warmer 跑完 THEN warmed≥1 且 message 含 locales 计数。
- **UC-chrome-1b** WHEN soft-clear 后首击启用语 search THEN exclusive `theme.storefront_chrome` 不主导 ≥800ms（或 wall&lt;2.5s），且 input[name=q] 为请求词。
- **UC-chrome-2** WHEN 启用语很多 THEN 超时预算诚实报告 remaining。
