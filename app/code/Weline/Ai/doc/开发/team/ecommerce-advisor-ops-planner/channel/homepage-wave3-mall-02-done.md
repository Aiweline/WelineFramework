# channel — WO-HP-P3-MALL-02 席 A 回执

日期：2026-09-22  
席位：`Team:主题开发工程师:` + `Team:部件开发工程师:`（席 A）  
工单：`WO-HP-P3-MALL-02`（今日特价上移）  
`work_mode`: **`design_theme`** + **`default_theme`**  
顾问拍板：精选先于特价；顺序 = 精选 → 品类磁贴 → **今日特价** → 新品…  
`notify_pm`: **true**

## 达标

| 口径 | 结果 |
|------|------|
| 滚动 ≤1.5 屏见特价区 | **dealsScreens ≈ 1.47**（标题「今日特价」进入视口） |
| 倒计时 | 可见（区文案/计时器 DOM） |
| ≥2 折扣卡 | **≥4** `weline-product-card` 在 deals 段 |
| 顺序 | … → featured → categories → **deals** → promo → new → bestsellers |

## 改动要点

1. 布局槽保持 `homepage-deals` 在品类之后、新品之前（未把特价插到精选前）。  
2. 为压高度配合 MALL-01：信任条/精选首屏压缩，使特价进入 ≤1.5 屏。  
3. **未**深改 deals 卡角标（席 B）。

## related_web_urls

- https://p05113ef3.test.weline.com:9555/zh_Hans_CN/

`notify_pm: true`

@项目经理：本席已交付/上报，请检查并更新 SESSION。
