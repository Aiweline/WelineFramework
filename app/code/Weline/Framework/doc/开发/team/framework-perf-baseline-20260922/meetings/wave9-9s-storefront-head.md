# wave9-9s — storefront_head builder 减负（主题）

- date: 2026-09-22 ~20:02+08
- seat: Team:主题开发工程师:
- channel: `framework-unreasonable-audit.md` **msg-104**（re msg-102/103 · 9a stance）
- work_mode: `theme_module_runtime`
- Theme: **2.2.584**
- claim_sla: **false** · 禁自 reload · **禁回退** 8s5 shell 直读 · 禁 injectChrome · 禁重开 8c\* 种袋代布局

## 根因（对照 8v2 builder≈1334 · resource=`theme.storefront_head` · l1/l2 absent）

1. **Policy fence**：`storefrontHeadPolicy` 仍 `scope=channel`；`StorefrontScopeHotCache::policyKey` 在仅有 website fence 时返回 null → 每请求强制 builder（表现为 absent）。
2. **整包页级键**：CSS/JS 与 SEO 同捆，按 `seo_fp`/`request_path` 分区 → 跨 URL 冷路径重复渲染与固化壳无关的资源链。
3. **设计主题**：hanfu head `@meta.cache.mode=off` 可绕过 chrome Policy（与 8s5 后仍可见的 head builder 面叠加风险）。

固化壳落地后 header/chrome **absent**（8v2 主门已过）；本面残耗是 **head 自身**，非布局再生。

## 落地

| 项 | 内容 |
|----|------|
| Policy | `storefrontHeadPolicy` → `website`；新增 `storefrontHeadAssetsPolicy`（route-invariant · vary=lang） |
| 片段 | `head/assets-prefix` + `head/page` + `head/assets-suffix`；Partials 嵌套 assets rememberPolicy |
| 键 | 店面 head 瘦键（theme/colors + path/seo_fp）；schema `v17-head-website-slim` |
| hanfu | `cache.mode=chrome`；自有 `assets-suffix`（含 hanfu-skin） |

**未动**：shell 直读 / fill / injectChrome / 种袋代布局。

## 预期影响

- 页级 `theme.storefront_head` builder：assets HIT 后近似只剩 SEO/geo/Base → **相对 ~1334 明显降**（同 Worker 多 URL / rc>1 窗更显著）。
- 首次双 miss 仍全量；website scope 减少 fence miss。
- 固化主门不回退：marker=0 · LayoutSlot≪100 · header/chrome_slot 仍应 absent。

## UT

- `HeadAndProductCardRememberPolicyContractTest`：**7 tests / 43 assertions OK**
- `PartialsChromeCachePolicyTest` filter head/schema：**6 tests OK**
- （全量 PartialsChrome 另有 2 项预存失败：origin 键 / weline-ui.js 文案，非本波引入）

## escalate

@项目经理：9s 落地 → 可与 9p 一并开 **9v**。禁本席自 reload；claim_sla=false。
