# Spec: storefront chrome locale HotCache prime（guest-neutral）

status: ready-for-plan  
covers_use_cases: UC-chrome-1a, UC-chrome-1b, UC-chrome-2

## 背景

投影袋预热后，新语种 search tip 曾独占 `theme.storefront_chrome` ~856ms。根因不是「缺 tip q 预热」，而是 header 共享袋按搜索 facade（`q`/type/category）分键且把请求态烤进 HTML，与 lang-only + 货币岛架构不一致。

## 方案

| mechanism | owning_module | not_to_do |
|-----------|---------------|-----------|
| Header chrome **guest-neutral 键**（schema `chrome-partial-v21-search-facade-island`）；袋写入 strip `header-search` 岛；serve `hydrateChromeSearchFacadeIslands` | `Weline_Theme` | 禁 tip `q=hanfu` / tip 语种白名单旁路；禁拆壳；禁空烧假 HIT |
| `StorefrontChromeLocaleCacheWarmer`：启用 locales 逐种 render header/footer；暖窗 `512M`；预算诚实 remaining | `Weline_Theme` | 禁 Runtime 硬编码语种列表 |
| Clear `warmUp('weline_theme_storefront_chrome')` | `Weline_Framework` | 禁 Theme FQCN soft-pull |

## 细节

- 语种源：`WebsiteLanguage::getWebsiteLanguageCodes(Website::ID_DEFAULT)`；基线优先 `zh_Hans_CN`、`en_US`。
- 池：`weline_theme_storefront_chrome`；Policy `vary=['lang']`。
- 搜索框请求态只在 hydrate 路径注入；袋内 `query=>''`。
- 验收 soft-clear 后新语种 search hit1：exclusive chrome ≪ 修前 ~856ms 或 wall &lt; 2.5s；搜索框 value 仍为 tip 词（hydrate）。

## UC

- **UC-chrome-1a** WHEN cache:clear 后 warmer 跑完 THEN warmed≥1 且 message 含 locales 计数。
- **UC-chrome-1b** WHEN soft-clear 后首击启用语 search THEN exclusive `theme.storefront_chrome` 不主导 ≥800ms（或 wall&lt;2.5s），且 input[name=q] 为请求词。
- **UC-chrome-2** WHEN 启用语很多 THEN 超时预算诚实报告 remaining。
