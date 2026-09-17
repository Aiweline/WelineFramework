# 规格：网站管理树形（Website / Store / Channel）

| 字段 | 值 |
|------|-----|
| slug | `website-scope-tree-management` |
| module | `Weline_Websites` |
| status | `clarified` |
| work_kind | `feature` |
| ui_skill_decision | `participate`（树形 + 右侧编辑壳，对齐分类管理布局） |
| created | 2026-09-15 |
| clarified | 2026-09-16（异步右栏） |

## 1. 背景与目标

在**网站管理**做成 **Website → Store → Channel** 左树右编（交互对齐 Catalog 分类管理：点左树节点、同页右侧出编辑）。

硬约束：

- 树内写目标起点只能是 Website，**禁止 Global**。
- Global 仍只走系统配置中心。
- 首版 **整页 SSR**（`?node=` 链接），不做 AJAX panel 主路径。
+ 左树点选 **异步加载右栏**（`index?panel=1&node=` JSON）；首屏仍可用 `?node=` SSR；URL 用 `history.pushState` 同步。

## 2. EARS

- WHEN 管理员打开网站管理，THE SYSTEM SHALL 以 Website 为根列出 Store → Channel。
- WHEN 选中 Website / Store / Channel，THE SYSTEM SHALL 同页右侧打开对应编辑面，写目标分别为 Website / Store / Channel Scope。
- WHEN 无 `node`，THE SYSTEM SHALL 选中目录默认站（仍非 Global）。
- WHEN 菜单「商店管理 / 渠道管理」，THE SYSTEM SHALL 进入树并带 `focus=stores|channels`。
- WHEN 在树上新建 Store/Channel，THE SYSTEM SHALL 归属当前 Website/Store，创建后回树并选中新节点。
- IF 节点无权限，THEN THE SYSTEM SHALL 只读占位并提示。

## 3. 用例

### UC-1 点树编辑

1. 打开网站管理  
2. 左树点选默认站 → **异步**加载右侧网站表单 + sections-after（联系信息等），不整页刷新  
3. 点下属店/渠 → 右侧对应表单异步切换；地址栏 `node=` 同步  

### UC-2 树上新建

1. Website 节点「新建商店」  
2. Store 节点「新建渠道」  
3. 保存后 `node=store:|channel:` 选中新节点  

## 4. 决策锁定

1. 菜单 1A：商店/渠道进树（`focus=`），旧 ScopeManagement 列表/编辑 302。  
2. 右侧完整实体表单 + sections-after。  
3. 树根：全部 Website 并列。  
4. 禁 Global。  
5. 写成功 `return_to=tree` 回树，解耦 OffCanvas。

## 5. 非目标

- 不嵌配置中心整页；不改域名池/ACL 授权主流程；不做分类式拖拽。
