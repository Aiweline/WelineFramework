# 部件席 · 合规复审（footer-newsletter / newsletter-popup）

日期：2026-09-22  
席位：`Team:部件开发工程师:`  
依据：`部件开发指南.md` / `theme_layout_widget_owner` / 审图返工 Variant A

## 审图 fail → pass

| 项 | 原 fail | 落地 | 判定 |
|----|---------|------|------|
| footer 对比 | `--on-primary` 白字压浅底 | 根条带自带 surface/primary 轻混底；文案 `theme-text` / `text-muted` | **pass** |
| footer 布局 | 浮卡 + 大空隙 | 全宽条带 + 版心 `max-width` 网格两列；平板堆叠 | **pass** |
| footer 控件 | 原生蓝 checkbox / 非 w-* | `w-input` + `w-button`；`accent-color: primary` | **pass** |
| 弹窗几何 | `max-width:500px` + `width:90%` 扁条 | `max-inline-size: min(22rem, 92vw)` | **pass** |
| 弹窗 CTA | 拉满宽 | `.popup-submit { inline-size: auto }` 居中 | **pass** |
| 朱印 | 空心粉圈 | 实心 primary 方印 +「礼」 | **pass** |
| 文案源串 | 混语风险 | 模板源串简中；zh/en CSV 齐；`generated/language/zh_Hans_CN_total.csv` 词条正确 | **pass**（源/CSV） |
| 注入规范 | 缺 placement | `widget.php` 两部件 `placement=injection` + 原有 `default_injections`；`generated/widgets.php` 已含 | **pass** |
| JS | — | 仍 `data-weline-load="newsletterSubscribe"` | **pass** |
| 双路径 | — | 未改 Theme 布局内嵌 Newsletter；删除过期 `pub/static/.../widgets/newsletter` Theme 壳 | **pass** |

## 验证证据

- `php bin/w frontend:check-theme-layout-widgets` → 0 violations
- `php bin/w widget:refresh` → Part registry refresh completed（update: 2）
- `php bin/w i18n:collect`（全量）→ Language packs collected successfully
- curl `https://p05113ef3.test.weline.com:9555/zh_Hans_CN/?_v=…`（FPC MISS）：footer 含 `newsletter-band` / `w-input` / `w-button` / `theme-text`；popup 含 `popup-seal__mark`「礼」/ `min(22rem, 92vw)` / `accent-color: primary` / 紧凑 CTA

## 残留观察（已收口）

- 中文路径下主题勾选/退订曾英译：根因为 `w_i18n_locale_dictionary` zh 行误存英文；已改回身份译，清 FPC + 重启后 `优惠活动`/`新品上新`/`可随时退订` 中文 **pass**。

## 总判

**pass**（视觉 Variant A + 部件注入规范）
