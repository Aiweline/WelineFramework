# 博客文章方法论（精写 · 入库 · 多语）

配合 `weline-blog-article` 技能。正文仓 = **Weline_Blog**。

## 1. 选题与落地选型

| 内容类型 | 落地 | 不要 |
|----------|------|------|
| 文化/工艺/科普长文 | Blog `/blog/{slug}` | CMS 可视化拼装当正文 |
| About/FAQ/活动落地 | CMS + Theme 部件 | 硬塞 Blog 当页面编辑器 |
| 商品卖点楼层 | Product 详情优化技能 | 与本技能混用冒充博客 |

Slug：小写短横线，主题稳定（例 `textile-yunjin`）。英文存储行：`{slug}-en`；其它语种后缀见 `BlogContentResolver::localeSlugSuffixMap()`。

## 2. 写前查证（强制）

每篇至少检索并记录：

- 官方/非遗/馆藏定义（UNESCO、ihchina、国家馆、Met 等）
- 产地与工艺可核验点（机具、组织、绣法）
- ≥2 条可点 References URL

产出：`app/code/Weline/Blog/data/{topic}-sources.md`（或 seed 注释表）。  
**规则：来源表没有的断言，正文禁止出现。**

## 3. 精写骨架（中英各自完整）

目标长度：中文约 1800–2800 字（HTML 去标签后）；英文等量专业文。

1. 导语：定义、产地、谱系位置  
2. `<h2>` 历史脉络  
3. `<h2>` 工艺与结构（可一句对比相邻品类）  
4. `<h2>` 纹样、用色与用途  
5. 文内 `<figure class="hanfu-article-figure">`（藏品名/馆藏/许可链接）  
6. `<h2>` 鉴别与常见误读  
7. `<h2>` 当代传承与站内语境（汉服/购买边界，禁虚假非遗背书）  
8. `<h2>` 要点速览 + `<ul>`  
9. `<aside class="editorial-sources">` References  

作者署名沿用站内惯例（如 `Amayun Editorial`）。

## 4. 配图纪律

- 优先开放许可：Commons / Met CC0 / 博物馆声明 / 论文 CC BY  
- 本地：`pub/media/blog/{topic}/{slug}/cover.*` + `inline-*.*`  
- figure / 封面登记 object_title、collection、license、source_url  
- **禁止** ImageGen / AI 纹样冒充实物（编辑插画若用，必须 figcaption 标明「非实物证据」且不得当工艺证明）

## 5. 入库管线

```text
查证 → 配图 → content.php（zh/en）
  → seed-*-articles.php（BlogCategoryAdminService + BlogPostAdminService）
  → export en 源 JSON
  → locale-packs/{locale}/batch-*.json（默认站除 zh/en 外全部）
  → upsert-blog-locale-packs.php
```

- `website_id=0`；`status=published`  
- 幂等：已存在则 update，勿盲目 purge  
- 默认站语种以本地 DB `WebsiteLanguage::getWebsiteLanguageCodes(0)` 为准（常见含 zh_Hans_CN、en_US、ar_SA、bn_BD、es_ES、fr_FR、hi_IN、id_ID、pt_BR、ur_PK）  
- **禁止**为译写拉起 Ollama（用户本回合明确要求除外）

## 6. 入口导向

若文章服务首页/部件目录（如织艺谱系）：

- Catalog / 配置 `link` → `/blog/{slug}`  
- 模板宜 **优先 Catalog 链接**，避免布局里残留 `/search?q=`  
- 改完后：`ThemeRuntimeCacheCleaner::clearAllThemeRelatedCaches` + `php bin/w server:reload`

## 7. 验收

- 契约/UT（链接、种子路径）  
- `curl` 探活与交付字面 URL 一致（本机 `*.test.weline.com` HTTPS 端口以 env 为准）  
- Browser：禁缓存；抽验中文详情 + ≥1 外语前缀  
- 交付 Markdown 可点链；汇审；关 Browser  

## 8. 先例索引

| 主题 | 线索 |
|------|------|
| 织艺谱系六篇 | `seed-textile-heritage-articles.php`、`textile-heritage-articles-content.php`、`locale-packs/*/batch-textile-heritage.json` |
| 56 民族服饰 | `seed-china-ethnic-articles.php`、`locale-packs/_source/batch-*.json` |
