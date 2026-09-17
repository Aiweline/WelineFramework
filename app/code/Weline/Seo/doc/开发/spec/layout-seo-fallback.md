---
status: implemented
work_kind: feature
feature_slug: layout-seo-fallback
module: Weline_Seo
updated: 2026-09-15
---

# 布局 SEO 兜底桥（实体优先）

## 澄清记录

| # | 问题 | 结论 |
|---|------|------|
| 1 | SEO 标签是否重做？ | 否。`<w:seo>` / HeadRenderer 已独立。 |
| 2 | 布局信息与实体 SEO 谁优先？ | 控制器/实体 `assign('seo')` 与商品/CMS/博客实体字段优先；布局显式 SEO 仅兜底。 |
| 3 | `@meta.name` 能否当公开 title？ | 否。设计器显示名永不进公开 SEO。 |
| 4 | CMS/商品页还要布局 SEO 吗？ | 可保留字段，但仅兜底；有实体 SEO 时布局值不覆盖。 |
| 5 | 纯 Theme 建站怎么办？ | 布局显式 `meta_title` / `meta_description` 等经独立 fallback bag 进入 head。 |
| 6 | 本期是否新建 SEO 后台表？ | 否。用 Theme 布局 `@param` 作运营面。 |

## 目标 / 非目标

**目标**

- 运营在主题编辑器改布局显式 SEO 参数后，无实体 SEO 的页面 head 能输出对应 title/description 等。
- 有商品/CMS/博客等实体 SEO 时，实体值覆盖布局兜底。

**非目标**

- 不重做 `<w:seo>` / 不把 SEO 绑回 chrome header。
- 不把 `@meta.name` / `layout_description` 升成公开 SEO。
- 不新建全站 SEO 配置后台表。

## 角色

- 运营：Theme 编辑器改壳布局 SEO 参数并发布。
- 系统：独立 head 按优先级解析事实。

## 用户故事

As a 运营, I want Theme 壳布局上的 SEO 参数在无实体 SEO 时生效, so that 纯 Theme 建站也能改搜索标题与描述。

As a 运营, I want 商品/CMS 已填的 SEO 不被布局参数覆盖, so that 业务实体 SEO 仍是权威来源。

## 验收标准（EARS）

1. WHEN 请求无控制器/实体 SEO bag 且布局 fallback 含非空 `meta_title` THEN 系统 SHALL 在公开 `<title>` 使用该 `meta_title`。
2. WHEN 控制器 `assign('seo')` 已提供非空 `title`/`meta_title` THEN 系统 SHALL 忽略布局 fallback 中的同名字段。
3. IF 布局仅有 `@meta.name` / `layout_name` / `layout_description` 而无显式 SEO 字段 THEN 系统 SHALL NOT 将其用作公开 title/description。
4. WHEN 商品页实体提供 `meta_name`/`meta_description` 且布局同时有 SEO 参数 THEN 系统 SHALL 输出实体值。
5. WHILE 解析公开 description THEN 系统 SHALL 在实体与 bag 皆空时才采用布局 `meta_description`（或无 `meta_title` 时的布局 `title` 仅用于 title 链）。

## 隐形需求摘要

- 复用既有 `SeoPageProfileBag` / `PageSeoContextResolver` / `<w:seo slot="head"/>`，不新增 Taglib。
- Theme `ControllerFetchFileBefore` 已组装布局 `meta`；在此桥接 fallback，避免布局 `setData(meta_*)` 进不了独立 head。
- 账号类布局继续 noindex，不强调运营 SEO 字段。

## 用例

### UC-1 纯 Theme 首页布局 SEO 生效

| 字段 | 内容 |
|------|------|
| 角色 | 运营 |
| 前置 | 首页无控制器实体 SEO；主题编辑器可编辑 homepage 布局 |
| 主成功步骤 | 1. 打开主题编辑器首页布局 2. 填写 `meta_title` / `meta_description` 并发布 3. 打开店面 `/` 查看源码 head |
| 备选/异常 | 未填 `meta_title` 时可用布局 `title` 参数兜底 title；仍无则走 Provider/系统默认 |
| 期望结果 | `<title>` 与 meta description 含运营填写值 |
| 映射 acceptance | WB-OP-home-layout-seo / UT-resolver-fallback |

### UC-2 商品实体 SEO 压过布局

| 字段 | 内容 |
|------|------|
| 角色 | 运营 |
| 前置 | 商品已填 SEO 标题；商品布局亦有 meta_title |
| 主成功步骤 | 1. 打开商品详情页 2. 查看 head title |
| 备选/异常 | 无 |
| 期望结果 | title 为商品 SEO，而非布局参数 |
| 映射 acceptance | UT-entity-wins-layout |

### UC-3 布局显示名不泄漏

| 字段 | 内容 |
|------|------|
| 角色 | 系统 |
| 前置 | 布局 `@meta.name` 为「搜索页默认布局」，无显式 SEO、无实体 bag |
| 主成功步骤 | 打开搜索页查看 `<title>` |
| 备选/异常 | 无 |
| 期望结果 | title 不含「搜索页默认布局」字样作唯一主标题来源 |
| 映射 acceptance | UT-no-layout-name-leak |
