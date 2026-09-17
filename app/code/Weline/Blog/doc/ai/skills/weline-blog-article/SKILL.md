---
name: weline-blog-article
description: >-
  Weline Blog article methodology. SKIP MCP (content_ops_skills_skip_mcp)—host
  Read this path + 新建文章.md only; no prepare_project/get_skill. Research-backed
  posts, provenance images, default-website locales, BlogPostAdminService.
  HARD on 新建文章/写博客/审查文章/文章可行性/精写文章. Not CMS; not product 详情优化.
---

# 博客文章（新建 · 审查）

**仓内权威。跳过 MCP。** 宿主薄镜像只指路；SOP 以本文件 + `dev/ai-command/blog/新建文章.md` 为准。

## MCP 排斥（严重）

- **禁止** `prepare_project` / `resolve_skill` / `get_skill` / 拉项目索引。
- 只 Read：本目录 `SKILL.md` / `methodology.md` / `review-checklist.md` + `新建文章.md`。

| 模式 | 触发例 | 做什么 |
|------|--------|--------|
| **create** | 新建文章、写博客、精写文章、织艺/文化长文 | 查证→配图→中英精写→全默认站语种→入库→验收 |
| **review** | 审查文章、文章可行性、审博客质量 | 按同一方法论打分；不可行则给补救清单 |
| **remediate** | 修文章、补全语种、改搜索链为文章链 | 按审查缺口执行 create 子集 |

## 硬闸（先读）

1. **涉及文章必用本技能**：新建/改写/翻译博客正文、审查可行性、把卡片/搜索链改成文章链——先 Read 本文件 + `新建文章.md`。
2. **长文走 Blog**（`/blog/{slug}`），不走 CMS 可视化页当正文仓。
3. **写前上网查证**：无权威 URL 不下笔工艺/史实断言；落 `*-sources.md` 或等价来源表。
4. **配图**：开放许可真图 + provenance；**禁止** AI 纹样冒充实物（馆藏/织样/绣品）。
5. **写入**：只经 `BlogPostAdminService`；分类经 `BlogCategoryAdminService`。
6. **翻译**：默认站 `WebsiteLanguage::getWebsiteLanguageCodes(0)` **全部**语种真译；**禁止启动 Ollama**（除非用户本回合明确要求）。
7. **改主题部件链接后**：清主题运行时缓存（`ThemeRuntimeCacheCleaner::clearAllThemeRelatedCaches`）+ `server:reload`，否则 FPC/编译模板仍可能出旧链。

## Agent 必做（create）

1. **跳过 MCP**；Read [methodology.md](methodology.md) 骨架与落地路径。  
2. 定 slug / 分类 / 受众；**逐篇 Web 检索**并写来源表。  
3. 登记配图到 `pub/media/blog/...`（封面+文内 figure+许可）。  
4. 精写 `zh_Hans_CN` + `en_US`；幂等 seed 入库。  
5. 导出 en 源 → 其余默认站语种 locale-packs → `upsert-blog-locale-packs.php`。  
6. 若有入口部件/Catalog：主链改为 `blog/{slug}`（经 `@url` / `<base>`；勿裸 `/blog/...`，勿 `/search?q=`）。  
7. UT/契约 + Browser 禁缓存抽验中文+至少一外语；交付 URL；关 Browser；开发日志。

## Agent 必做（review）

1. Read [review-checklist.md](review-checklist.md)。  
2. 对目标文章（slug / URL / DB 行）逐项打 **pass / fail / n/a**。  
3. 输出：**可行性结论**（可发 / 需补 / 不可发）+ 缺口清单 + 建议 remediate 步骤。  
4. 用户要求「修」时进入 remediate，禁止只点评。

## 关键路径（默认）

| 项 | 路径 |
|----|------|
| 指令 | `dev/ai-command/blog/新建文章.md` |
| 内容数据/种子 | `app/code/Weline/Blog/data/seed-*-articles.php`、`*-content.php` |
| 语种包 | `app/code/Weline/Blog/data/locale-packs/{locale}/batch-*.json` |
| upsert | `app/code/Weline/Blog/data/upsert-blog-locale-packs.php` |
| 先例 | 织艺谱系 `textile-heritage-*`；民族服饰 `seed-china-ethnic-articles.php` |

## 禁止

- 无来源编造非遗/工艺细节  
- AI 生成图冒充馆藏/织绣实物  
- 只译 `en_US` 声称「已翻译」  
- 文化长文塞 CMS HTML 页  
- 首页/部件仍链 `/search?q=主题词` 却声称文章已交付  
- 改 phtml 不清主题/FPC 缓存就宣布首页已更新  

## 参考

- [methodology.md](methodology.md) — 精写骨架与入库管线  
- [review-checklist.md](review-checklist.md) — 可行性审查表  
- `app/code/Weline/Blog/doc/ARCHITECTURE.md`  
- Theme 开放图 provenance：`Theme/doc/织艺谱系素材来源.md`（同类纪律）
