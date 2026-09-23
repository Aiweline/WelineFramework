# channel — 全站迷你车 chrome 零价清扫（OPS COL 阻断）

日期：2026-09-23  
角色：父会话（跟进顾问终审 fail）  
权威：`sitewide-ops-acceptance-rereview.md`（COLLECTION fail · 迷你车 `$0.00`×4）

## 根因

源码 `mini-cart-icon` 与 widget 烘焙已禁空车 `$0.00`，但 **`partials/header` 编译壳**仍内嵌旧迷你车（`20260922-minicart-fs-progress` + 字面 `$0.00`）。HOME/PDP 已走新壳，COLLECTION 仍命中旧 header 壳；nginx edge 磁盘缓存放大残留。

## 处置

1. 删除 7 个含旧零价的 `view/tpl/**/partials/header/**/*.phtml`  
2. 清空 `var/server/nginx/cache`  
3. **仅**硬重启 nginx 边车（未叠 WLS server:start/restart）

## 父验（nocache）

| 面 | HTTP | `$0.00` | CSS bump |
|----|------|---------|----------|
| HOME | 200 | **0** | — |
| COLLECTION | 200 | **0** | `20260923-home-zero-minicart` |
| PDP / CART（对照） | 200 | **0** | 新 bump |

`$49` 未改。

`notify_pm: true`  
**@项目经理 / 电商顾问：请补签 COLLECTION → 目标 `ops_acceptance=pass`。**
