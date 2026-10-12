# 模块边界污染检查表（代码复审 HARD）

权威：MCP `website_concept_seed_not_in_modules`；`规则/30-后端与数据.md` §6.2。  
**抽象门禁**：约束当前与未来一切 website / 设计主题 / 品牌开场——不是 grocery/hanfu/daocharms 黑名单。

## 扫描范围

| 扫 | 不扫作违规 |
|----|------------|
| `app/code/**/*.php|phtml|js|css` 生产路径 | `**/Test/**`、`**/test/**` 里**负向**断言与 fixture |
| 模块 `data/`、`scripts/`、`Sample/`、`Setup/` 开站种子 | `doc/开发日志.md` 历史叙述 |
| 模块默认 widget/layout 文案与媒体路径 | 文件头版权行 |

## 检查项（任一命中且落在模块 = blocking）

### A. 站码闸

- [ ] `websiteCode === '<concrete>'` / `=== "daocharms"|hanfu|grocery|…` 驱动 UI/货架/SEO  
- [ ] `ALLOWED_WEBSITE_CODES` 白名单把站概念留在模块（应迁 design，而非加闸）

### B. 开站种子 / 货架

- [ ] 模块内 `*CatalogSeeder.php`、`seed-*`、`import-*` 且绑定 website code / 主题  
- [ ] `Product/Sample/`、`Blog/Sample/` 站开场包（正确家：`app/design/{Vendor}/{theme}/`）  
- [ ] SKU 前缀/货架排序绑定某一垂直（如模块内强制 `HF-` 汉服优先）

### C. 品牌默认回退

- [ ] Websites/Seo/Smtp/Theme/Blog/Product 把某一品牌站名/简介/SEO 主体/邮件壳/促销条/热搜当**系统默认**  
- [ ] 空数据部件灌入某一站分类树/轮播/口碑文案

### D. 设计主题错放模块

- [ ] 基座 Theme 默认 `homepage/default.phtml` 整页某一品牌店面  
- [ ] `app/code/**/Weline_<brand>/` 或 `daocharms-*.css` 等品牌皮肤树挂在模块而非 `app/design`

## 正确落点

| 内容 | 家 |
|------|-----|
| 站/主题开站货架、分类树、CatalogSeeder、品牌文案包 | `app/design/{Vendor}/{theme}/data|scripts|…` |
| 生产运维事实、站柜脚本 | `websites/<site>/` |
| 无站门禁全局领域默认 | 模块 Setup/Tax 等 |

## 周边架构隐患（汇报，不擅自扩修）

扫主路径时顺带标：补丁式「加站码白名单」vs 架构级「种子迁出」。写入 review 的 `architecture_smells_peripheral`，由用户/PM 裁决是否顺带处理。

## 内部已见模式（例子，不是规则正文）

以下仅作**例子**帮助识别同类问题，审查时按抽象清单判，勿写成「只禁这几个站」：

- Product 筛选 hook：`$websiteCode === 'daocharms'`  
- Websites：`WebsiteBrandIdentitySeedService` 默认种「长安汉服」  
- Theme：默认 homepage 整页 DaoCharms；`HeaderCommerceData` 按站码分流热搜  
- Seo：`HanfuSubjectSeedService`；Blog locale-packs 汉服作者身份  

## 守卫

仓内：`app/code/Weline/Framework/Test/Unit/Architecture/WebsiteConceptSeedNotInModulesGuardTest.php`  
复审可建议施工席补负向契约，但**本席不代写**。
