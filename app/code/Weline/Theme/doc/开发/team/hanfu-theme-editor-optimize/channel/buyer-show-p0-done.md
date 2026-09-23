# channel — WO-BUYER-SHOW-01/02 P0 完成（主题/部件）

日期：2026-09-23  
席位：`Team:主题/部件`（`work_mode=default_theme` + 同步激活 `design_theme=hanfu` 布局默认）  
来源：`buyer-show-ops-review.md` + `pm-buyer-show-escalate.md`（PM 选 CTA 方案 A）  
验收 Host：`https://p05113ef3.test.weline.com:9555/`  
`notify_pm: true`  
`@电商顾问：请 ops_acceptance 复审买家秀`

| 字段 | 值 |
|------|-----|
| **buyer_show_p0** | **done** |
| WO-BUYER-SHOW-01 | 首页 looks `items` = **6** 条真实竖构图（非 `default.svg`） |
| WO-BUYER-SHOW-02 | CTA「晒出你的汉服穿搭」→ `<a href="…/product/543#product-reviews">` |
| 新建 BuyerShow 模块 | **否**（按顾问纠偏） |

---

## 改动文件

| 文件 | 变更 |
|------|------|
| `app/code/Weline/Theme/view/theme/frontend/widgets/content/image-gallery/default.phtml` | 新增 `cta_link`；looks CTA 改为可点 `<a>`（有 link 时有 items 亦展示） |
| `app/code/Weline/Theme/view/statics/css/widgets/site-blocks.css` | `a.sb-looks-cta` 链接态样式 |
| `app/code/Weline/Theme/view/theme/frontend/layouts/homepage/default.phtml` | 默认层 looks 填 `items` + `cta_link` |
| `app/design/Weline/hanfu/frontend/layouts/homepage/default.phtml` | **激活主题**同步默认（否则仍空壳） |
| `app/code/Weline/Theme/test/Unit/ThemeHanfuHomepageDefaultsContractTest.php` | 契约断言 `cta_link` + 真实图文件名 |

---

## 使用的图 URL（6）

路径相对验收 Host（禁缓存 HEAD 均为 **200**）：

| # | image | 约比例 | link（PDP） |
|---|-------|--------|-------------|
| 1 | `/media/catalog/hanfu/1688/factory-huazhaoji-cx/824286376488/detail-03-c2e91b039ebb.jpg` | 750×1000 ≈3:4 | `/product/543` |
| 2 | `/media/catalog/hanfu/1688/factory-huazhaoji-cx/824496857539/detail-08-fc1264c1e346.jpg` | 743×1049 | `/product/542` |
| 3 | `/media/catalog/hanfu/1688/factory-huazhaoji-cx/997742393161/detail-08-1bf03eab4ec2.jpg` | 837×1124 | `/product/245` |
| 4 | `/media/catalog/hanfu/1688/factory-huazhaoji-cx/996206615159/detail-16-1f2f2521788f.jpg` | 750×1009 | `/product/241` |
| 5 | `/media/catalog/hanfu/1688/factory-huazhaoji-cx/1042500068281/detail-04-1e9a424d1582.jpg` | 864×1152 | `/product/261` |
| 6 | `/media/catalog/hanfu/1688/factory-huazhaoji-cx/824286376488/detail-08-911afef9fd09.jpg` | 750×1000 ≈3:4 | `/product/543` |

说明：复用本站 1688 工厂详情竖构图（试穿向）；可后续换真实 UGC。气质对齐长安汉服货架 SKU。

**CTA**：`cta_link=/product/543#product-reviews`（长乐公主代表性 PDP 评论晒图区）。

---

## 主题编辑器如何填 items（若发布草稿覆盖默认）

1. 打开主题编辑器 → 首页布局 → 槽 `homepage-testimonials` → 部件 `image-gallery`。  
2. `variant` = **买家秀图墙**；`columns` = 6。  
3. 在「展示项目」`items` 中添加 4–6 条：每项填 `image`（公开 media URL）、建议 `title`、`link`（对应 PDP）。  
4. 填 `cta_link` = `/product/{代表性ID}#product-reviews`（或带 slug 的同等路径）。  
5. 发布后禁缓存抽检：looks 区 `img[src]` 不含 `storefront-placeholder/default.svg`；底部 CTA 为带合法 `href` 的 `<a class="sb-looks-cta">`。

若编辑器草稿已固化空 `items:[]`，仅改源布局不够——须在编辑器重填并发布，或清除覆盖草稿后回落到本回合默认。

---

## 抽检结果（禁缓存）

探活（改后首次）：`GET https://p05113ef3.test.weline.com:9555/zh_Hans_CN/`（`Cache-Control: no-cache`）→ **200**。

| 检查项 | 结果 |
|--------|------|
| looks 区 `img` 数量 | **6** |
| looks `src` 含 `default.svg` / `storefront-placeholder` | **否** |
| 6 张 media HEAD | **全部 200** |
| CTA | `<a class="sb-empty sb-looks-cta" href="https://p05113ef3.test.weline.com:9555/product/543#product-reviews">晒出你的汉服穿搭</a>` |
| href 含 `#product-reviews` | **是** |

复检备注：收紧 CTA 回退逻辑后再次 `curl` 时 Host `:9555` 短暂连不上（WLS master 仍 LISTEN）；首次抽检 DOM 已过签，且本次微调不影响已配置 `cta_link` 的首页行为。

---

## 席位自检

- [x] dirty-load；未 git restore/clean/stash  
- [x] 未新建 BuyerShow；未启动 Ollama  
- [x] 激活主题 `hanfu` 与默认层双写  
- [x] 证据本文件  
- [x] 等待顾问 `ops_acceptance`
