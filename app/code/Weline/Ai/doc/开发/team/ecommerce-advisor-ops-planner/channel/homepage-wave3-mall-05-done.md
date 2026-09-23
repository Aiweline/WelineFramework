# channel — WO-HP-P3-MALL-05 席 A 回执

日期：2026-09-22  
席位：`Team:主题开发工程师:` + `Team:部件开发工程师:`（席 A）  
工单：`WO-HP-P3-MALL-05`（内容块后置/压缩）  
`work_mode`: **`design_theme`** + **`default_theme`**  
`notify_pm`: **true**

## 达标

| 口径 | 结果 |
|------|------|
| 信任条→热销 货架总高 ≫ 内容装饰 | **shelfH ≈ 3063** vs **contentH ≈ 1304** |
| 品牌 / 买家秀压缩 | `homepage-section--content-compact`；买家秀 CSS 强制 1 行×6，隐藏第 7+ |
| 视频页底 | `homepage-section--page-bottom`；**videoAfter bestsellers = true** |
| 硬禁 | 品牌/买家秀/视频 **不在** 信任条→热销货架链中间 |

## 改动要点

1. 内容槽（brands / testimonials / reviews）加 `--content-compact`；videos 加 `--page-bottom`。  
2. `image-gallery` 支持 columns 5/6；looks 默认抬到 6；`site-blocks.css` 补 columns-5/6。  
3. Theme 默认 brands else：`brand-logos columns=8`；hanfu 仍 textile-heritage（压缩样式作用在 section）。

## 与席 B 边界

未改商品卡角标/通用货架密度实现（MALL-04）；仅首页精选 lead 区做了首屏媒体高度与一行裁剪。

## related_web_urls

- https://p05113ef3.test.weline.com:9555/zh_Hans_CN/

## 契约

`ThemeHanfuHomepageDefaultsContractTest` / `TextileHeritageWidgetContractTest` / `FooterAboveTrustBadgesContractTest`：**20 tests OK**（槽序断言已改为 Wave-3：信任条 → 精选 → 品类 → …）。

Theme `module.php`：**2.2.584 → 2.2.585**。

`notify_pm: true`

@项目经理：本席已交付/上报，请检查并更新 SESSION。席 A 四单（MALL-01/02/03/05）closed，请 DoD 并视需要唤醒顾问复审 / 汇审。
