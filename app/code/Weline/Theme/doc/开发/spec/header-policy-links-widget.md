---
status: ready-for-plan
work_kind: feature
feature_slug: header-policy-links-widget
module: Weline_Theme
updated: 2026-09-17
---

# Header 政策/关于链接部件

## 澄清记录

| # | 问题 | 用户答复 |
|---|------|----------|
| Q1 | 放置位置 | **B** 紧挨 `category-menu` 后新建独立槽 `header-policy-links`（multiple+Hook else，对齐 footer-above；不破坏分类 exclusive） |
| Q2 | 默认链接集合 | 同意假设：`/about` + Theme 全部 policy（除 default）+ `/terms` |
| Q3 | UI 形态 | **A** 与分类条同排横向文字链 |
| Q4 | 「可关闭」粒度 | **A** 每条默认链接 `enabled` |
| Q5 | 自定义链接 | 同意：同列表混排可排序；`label` i18n |

## 目标 / 非目标

- **目标**：Header 左侧分类菜单后可展示可配置的「关于我们 / 购物政策」等链接；默认展示 Theme 支持的政策页；可逐条关闭；可新增自定义链接；链接名可翻译。
- **非目标**：不改页脚 `footer-container` 法律行；不在 Theme 内嵌业务模块链接；不把政策正文塞进部件。

## 角色

- 店面访客：点击链接进入对应壳页。
- 主题编辑者：在可视化编辑器配置显示/关闭/新增/排序与多语名称。

## 用户故事

1. 作为访客，我希望在顶栏分类旁看到关于我们与购物政策入口，以便快速了解购物规则。
2. 作为编辑者，我希望默认已有 Theme 政策链接，并能关掉不需要的项，以免顶栏过挤。
3. 作为编辑者，我希望能新增自定义链接且名称可多语翻译，以便品牌扩展入口。

## EARS（验收）

1. WHEN 部件以默认配置渲染，系统 SHALL 展示关于我们、使用条件与 Theme `layouts/policy/*`（除 `default`）全部政策链接。
2. WHEN 某链接 `enabled=false`，系统 SHALL 不渲染该链接。
3. WHEN 配置含自定义链接（label+url），系统 SHALL 按排序渲染，且 label 经 WidgetI18n / ParamSchema `i18n=true` 可翻译。
4. WHEN 站内相对路径链接渲染，系统 SHALL 使用 `@url` / Taglib URL，禁止手拼语言前缀。
5. IF 有效链接列表为空，THEN 部件根节点 SHALL 不占可见布局（empty 隐藏或无输出）。

## 用例

### UC1 默认展示（主成功）

1. 打开任意店面页（含首页）。
2. Header 左侧分类菜单后出现政策/关于链接条。
3. 点击「关于我们」→ `/about`；点击政策项 → 对应 `/policy/...` 或 `/terms`。

### UC2 关闭单项

1. 编辑器将「Cookie 政策」`enabled=false` 并保存。
2. 店面刷新后该链接消失，其余仍在。

### UC3 新增自定义链接

1. 编辑器添加自定义 `{label,url,enabled:true}`。
2. 配置 `label` 英译后，`/en_US/` 显示英文名。

### UC4 空列表

1. 关闭全部默认项且无自定义项。
2. 顶栏不出现空占位条。

## 方案要点

- 新槽 `header-policy-links`（multiple + Hook else，对齐 footer-above）紧挨 `category-menu` 后；`category-menu` 保持 exclusive。
- 部件 `header-policy-links` + `HeaderPolicyLinksHelper`（扫描 policy 布局派生默认项）+ ParamSchema `header_policy_links`。
- 站内 URL 复用 `FooterDefaultLinksHelper::splitUrlForTaglib`。
- 窄屏（≤768）隐藏整条政策链（政策仍在页脚），避免左簇过挤。

## 实现状态

- status: implemented（Theme `2.2.421`）
- 验收：UT + Browser + e2e `header-policy-links-plan-suite` PASS
