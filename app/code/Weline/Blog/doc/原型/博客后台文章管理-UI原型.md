# 博客后台 · 文章管理 UI 原型（v1）

> 对齐 Weline 后台主题（`w-grid` / `w-card` / `w-button` / Taglib），参考 `Weline_Cms::Backend/Page` 列表与 `Weline_Review::Backend/Review` 工具栏模式。

## 页面一：文章列表 `/blog/backend/post-admin/index`

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ w-backend-page__heading                                                     │
│  博客文章                              [+ 新建文章]  [↗ 万能分类·博客]      │
│  管理当前网站下的博客文章与发布状态                                           │
├─────────────────────────────────────────────────────────────────────────────┤
│ w-card · 筛选工具栏 (blog-admin__toolbar)                                    │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌─────────────────────┐ │
│  │ w:websites   │ │ w:lang       │ │ 状态 ▼       │ │ 🔍 搜索标题/slug…   │ │
│  │ website:select│ │ locale       │ │ 全部/草稿/发布│ │ [筛选] [重置]       │ │
│  └──────────────┘ └──────────────┘ └──────────────┘ └─────────────────────┘ │
├─────────────────────────────────────────────────────────────────────────────┤
│ w-card · 列表区                                                              │
│  共 12 篇 · 当前网站 p05113ef3                                              │
│  ┌───────────────────────────────────────────────────────────────────────┐ │
│  │ 标题          │ Slug              │ 语言   │ 分类     │ 状态 │ 更新  │操作│
│  ├───────────────┼───────────────────┼────────┼──────────┼──────┼───────┼───┤
│  │ Hello Weline… │ hello-weline-blog │ en_US  │ Tech…    │ 已发布│08-27 │编辑│
│  │ 产品动态草稿  │ product-update    │ zh_Hans│ 产品动态 │ 草稿 │08-26 │编辑│
│  └───────────────────────────────────────────────────────────────────────┘ │
│  [上一页]  第 1 / 3 页  [下一页]                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 列表规范

| 元素 | 组件 | 说明 |
|------|------|------|
| 页头 | `w-backend-page__heading` + `w-cluster` | 标题 + 主操作 |
| 新建 | `w-button` `data-tone="primary"` | 跳转 form |
| 分类入口 | `w-button` `data-variant="outline"` | 链到万能分类 `space=blog` |
| 网站筛选 | `<w:websites:website:select>` | 禁止手写 select |
| 语言筛选 | `<w:lang:locale:select>` 或等效 Taglib | 与文章 locale 字段一致 |
| 状态 | `w-badge` | draft=warning, published=success, disabled=muted |
| 空态 | `w-text data-tone="muted"` | 「暂无文章」+ 新建 CTA |
| 表格 | `w-table` / theme table 类 | 禁止裸 Bootstrap `table table-striped` |

---

## 页面二：文章编辑 `/blog/backend/post-admin/form`

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ [← 返回列表]                                    [👁 预览前台] [保存]       │
├─────────────────────────────────────────────────────────────────────────────┤
│ w-grid · 12 列                                                             │
│  ┌─ 主栏 span 8 ─────────────────────┐  ┌─ 侧栏 span 4 ────────────────┐ │
│  │ w-card · 内容                      │  │ w-card · 发布设置             │ │
│  │  标题 * [________________________] │  │  w:websites:website:select   │ │
│  │  Slug * [hello-weline-blog      ]  │  │  w:lang:locale:select        │ │
│  │  摘要   [________________________] │  │  分类 ▼ (来自 blog 分类)      │ │
│  │  正文   [富文本 / Markdown 区    ] │  │  状态 ▼ 草稿/已发布/已禁用    │ │
│  │         [                        ] │  │  作者 [________]              │ │
│  │         [                        ] │  │  关键词 [tag1, tag2]         │ │
│  └────────────────────────────────────┘  │ w-card · 封面                 │ │
│                                           │  w:file:picker 或 URL         │ │
│                                           │  [缩略图预览]                 │ │
│                                           └───────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 表单规范

| 字段 | 组件 | 校验 |
|------|------|------|
| website_id | Taglib website select | 非负，默认当前站 |
| locale | Taglib lang select | 必填 |
| title / slug | `w-input` | slug 小写唯一 |
| excerpt / content | textarea（后续可接编辑器 Hook） | — |
| category_id | select（数据来自 Catalog/blog） | 可选 |
| status | select + badge 预览 | draft/published/disabled |
| cover_image | file Taglib 或 URL input | 可选 |
| 保存 | `w-button` primary + CSRF | 成功 Toast |

---

## 主题 Token（与 CMS/Review 一致）

```css
--blog-admin-surface: var(--weline-theme-surface);
--blog-admin-border: var(--weline-theme-border);
--blog-admin-text: var(--weline-theme-text);
--blog-admin-muted: var(--weline-theme-text-muted);
```

工具栏背景：`linear-gradient(120deg, var(--weline-theme-primary-surface), transparent 46%)`

---

## 实现清单（原型确认后）

- [ ] `view/templates/backend/post-admin/index.phtml` — 主题化列表
- [ ] `view/templates/backend/post-admin/form.phtml` — 双栏工作台
- [ ] `view/statics/css/backend/blog-post-admin.css` — 局部样式
- [ ] `PostAdmin.php` — 筛选参数、website/locale 选项、预览 URL
- [ ] 契约测试 — Taglib 占位、禁止 alert/confirm、data-testid

## 参考文件

- `Weline_Cms/view/templates/Backend/Page/listing.phtml`
- `Weline_Cms/view/templates/Backend/Page/edit.phtml`
- `Weline_Review/view/templates/Backend/Review/index.phtml`
