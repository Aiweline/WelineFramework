# channel — WO-HP-P3-MALL-01 席 A 回执

日期：2026-09-22  
席位：`Team:主题开发工程师:` + `Team:部件开发工程师:`（席 A）  
工单：`WO-HP-P3-MALL-01`（首屏商品化 · 方案 A）  
`work_mode`: **`design_theme`**（主改 `app/design/Weline/hanfu/.../homepage/default.phtml`）+ 同步 **`default_theme`**（Theme 默认 homepage 壳）  
顾问拍板：压矮 Hero（≤50–60vh）+ 信任条下立刻接精选前 4（不嵌 Hero 爆款卡）  
`notify_pm`: **true**

## 达标

| 口径 | 结果（Browser vh≈739） |
|------|------------------------|
| Hero 可视高 | ≈**0.45 vh**（`max-height: min(45vh, 22rem)`，命中 `.wc-theme_widget_hero_slider`） |
| 一屏内 ≥4 带价卡 | **4**（`$` + `.wpc-price`） |
| ≥1 加购/购买可达 | **4** 张卡可见 CTA（`data-action=add-to-cart/buy-now`） |
| 顺序 | Hero → 信任条 → **精选** → … |

## 改动要点

1. 双布局槽序：`homepage-featured` 紧接 `homepage-trust`。  
2. Hero 压高：发布态无 `data-widget-code`，改打 `.homepage-hero` / `.wc-theme_widget_hero_slider`。  
3. 精选首屏：`limit=4` + CSS 只露 1 行×4；卡媒体 `max-height: 7.5rem`（**未改**商品卡角标组件，席 B MALL-04 空间保留）。

## related_web_urls

- https://p05113ef3.test.weline.com:9555/zh_Hans_CN/

`notify_pm: true`

@项目经理：本席已交付/上报，请检查并更新 SESSION。
