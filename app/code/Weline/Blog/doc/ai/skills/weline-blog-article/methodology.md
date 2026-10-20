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
8. 收束按题型来：谱系文可以有短摘要；试穿、礼俗、洗护不要每篇都收成「要点速览」  
9. `<aside class="editorial-sources">` References  

上面九步是文化谱系长文的一种骨架，不是所有博客的模具。试穿、礼俗、洗护按题目另选结构和语气。

作者署名沿用站内惯例（如 `Amayun Editorial`）。

## 3b. 去 AI 水印 · 对口专业（落盘前硬）

入库前对**每一个语种正文**做这一关。成稿要读起来像该题目的对口专业人员所写。未过关不得 seed / upsert。

1. **先定专业身份（一句）**  
   按本题写明谁在说话，例如纺织工艺、服饰形制、馆藏编目、品牌编辑。后文的术语、详略、不敢下的结论，都跟这个身份走。禁止百科导览腔、客服腔、万能导师腔、口号腔。换题目必须换身份，禁止一套腔套所有文。

2. **清可见水印（套话与结构）**  
   删掉换题仍能原句复用的句子。中文禁止：综上所述、值得一提的是、不难发现、在当今社会、本文将、让我们、毋庸置疑、深入探讨、赋能、助力、打造，以及「首先 / 其次 / 最后 / 总之」把全文切成课件。禁止每段等长、每段都「总—分—升华」、三连排比。  
   英文同禁：delve、it's worth noting、in today's world、in conclusion、not only…but also 连环、leverage、pivotal、underscore、realm，以及用 tapestry / landscape 做空泛比喻。

3. **清不可见水印**  
   落盘前扫描正文，去掉零宽字符（U+200B、U+200C、U+200D、U+FEFF）、字节顺序标记、私用区字符，以及无来源的异常同形字。正文不得夹带隐藏标记。

4. **按专业写**  
   用具体器物、工序、年代、馆藏名、组织名下判断；形容词必须能被来源表撑住。句子长短不齐。该省则省，该钉死则钉死。允许专业限定（「馆方目录记作…」「目前可见标本…」）。  
   其它语种用**该语种里同一专业**的写法重写，禁止把中文套话逐句译过去。

5. **自家的短处，以及谁合适、谁不合适**  
   在推自己的货或自己的做法时，写明弊端，写明什么人合适、什么人不合适。禁止兜成「大家都合适」。语气可以轻松，参数（叠法、腰位、水温、纤维、洗标）要专业、可执行。题型自选语气：试衣间、礼俗、洗护不得共用同一套小标题和同一套收束。换题仍能整段搬家的「谁穿都好看 / 怎么洗都没问题」，删。

审查：口吻与专业身份不符、套话成篇、把自家的货说成谁都合适、或不可见字符未清 → **不可发**。

## 4. 配图纪律

- 优先开放许可：Commons / Met CC0 / 博物馆声明 / 论文 CC BY  
- 本地：`pub/media/blog/{topic}/{slug}/cover.*` + `inline-*.*`  
- figure / 封面登记 object_title、collection、license、source_url  
- **禁止** ImageGen / AI 纹样冒充实物（编辑插画若用，必须 figcaption 标明「非实物证据」且不得当工艺证明）
- **禁止重图**：同一文件、去掉 query 后的同一 URL、或同一 `source_url` 只许出现一次；缩略图、改 alt、换 `width`/`height` 仍算同一张。篇内：封面已经用过的图，正文 `<figure>` 不得再放；正文多张 figure 也不得彼此重复；没有另一张不同的图时，正文不插图，只留封面。全站：写前扫描该站每篇封面和正文 `img`，已被占用的图不得再给另一篇当封面或正文图。相关文章卡片带出的是别篇自己的封面，不计入本篇用图。审查命中 → **不可发**。

## 5. 入库管线

```text
查证 → 配图 → content.php（zh/en，过 §3b 去 AI 水印）
  → seed-*-articles.php（BlogCategoryAdminService + BlogPostAdminService）
  → export en 源 JSON
  → locale-packs/{locale}/batch-*.json（默认站除 zh/en 外全部；每语种过 §3b）
  → upsert-blog-locale-packs.php
```

- `website_id=0` 表示默认站（非全站广播）；其它站须显式选对应 website_id；`status=published`  
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
