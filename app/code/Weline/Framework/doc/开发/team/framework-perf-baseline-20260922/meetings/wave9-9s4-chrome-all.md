# wave9-9s4 — 匿名 `/` + `/products` chrome

**席**：Team:主题开发工程师: · `work_mode=theme_module_runtime`  
**版本**：Theme `2.2.587` → `2.2.588`  
**claim_sla**：false · **禁自 reload** · **禁 8c\***

## 证据（reload 前）

| 面 | chrome |
|----|--------|
| 公网匿名 `/` MISS | 无（仅 homepage-*；`weline-header`=0） |
| `/products` MISS | 无（仅 list-* / products-bottom） |
| BP panel `/`（9v2） | 有（对照） |

## 根因

1. Template bag 漏带 `showHeader=false` → merge 盖布局默认 → Taglib 不渲 Partials。
2. published shell 常为 page-only → heal `chrome_by_slot=[]` → graft 空转。

## 修复

- `stripLeakedStorefrontChromeFlags` + ensure（auth/embed 例外）
- chrome.phtml 盘 bake 回落（禁 Policy）
- products/homepage PHP 默认 chrome on

## 验收（PM reload 后）

匿名无 Cookie：`/` 与 `/products` 均有 `weline-header` 或 `header-nav`，且 footer/delivery 槽非空。
