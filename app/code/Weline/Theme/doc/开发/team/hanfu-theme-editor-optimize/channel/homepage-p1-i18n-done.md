# channel — homepage-p1-i18n-done（UC-P1-04 Hero CTA 文案）

日期：2026-09-23  
席位：`Team:翻译工程师:`（别名 i18n）  
工单：`HF-ED-P1-04` / contracts `UC-P1-04`  
顾问 brief：`channel/hanfu-theme-editor-ops-brief.md`（双主 CTA→单主 CTA；次 CTA 降文字链）  
状态：`closed`  
`notify_pm`: **true**

## @项目经理：本席已交付/上报，请检查并更新 SESSION

本席只译文案与词典，**未改布局/CSS**。禁 Ollama（自身模型译写）。

## 与部件席衔接状态

| 项 | 状态 |
|----|------|
| design Hero 次 CTA | **已改** `app/design/Weline/hanfu/.../hero-slider/default.phtml`：`slide-text-link`（文字链），源串仍为 `按场景选` |
| Theme 默认层 Hero | 本席开工时仍为 `w-button-outline` 次按钮；**源串未变**（`浏览精选` / `按场景选`） |
| 源串是否切换「即刻寻衣」 | **部件尚未改主 CTA 源串**；本席已按 ops-brief/品牌规格 **预置** `即刻寻衣` 中英 CSV + 全语种词典，部件若改串可直接消费 |

## 落盘摘要

### 模块 CSV（仅 zh+en）

`Weline_Theme`：

| source | zh | en |
|--------|----|----|
| 浏览精选 | 浏览精选 | Shop Featured |
| 按场景选 | 按场景选 | Shop by Occasion |
| 即刻寻衣（预置） | 即刻寻衣 | Find Your Hanfu |

禁止写非中英模块 CSV。

### 系统词典（默认站其它已选 locale）

- 解析：`website_id=0` → **40** codes（含 `zh_Hans_CN`）；非中英 **39** 语种 upsert + `publishLocale`
- 包：`app/code/Weline/I18n/scripts/data/dict-fill-hanfu-hero-cta-p1.v1.php`
- 脚本：`app/code/Weline/I18n/scripts/remediate-dict-fill-hanfu-hero-cta-p1.php --apply`
- 对齐：词典 `en_US` 与模块 CSV 一致（旧词典曾为 Browse Featured Items / Select by Scenario，已改正）

### collect

```text
php bin/w i18n:collect Weline_Theme
→ Module Weline_Theme localization collection successful!
→ 缓存清理 dispatch fails: Control Port Connection refused（并行席曾杀 WLS worker；收集已完成）
```

## 抽检（≥1 非中英）

店面 HTTP 本回合 `400/502`（WLS control 拒连），以 **published language pack** 抽检：

| locale | 浏览精选 | 按场景选 | 即刻寻衣 |
|--------|----------|----------|----------|
| `ru_RU` | Смотреть избранное | Выбрать по поводу | Найди своё ханьфу |
| `de_DE` | Empfohlenes entdecken | Nach Anlass shoppen | Finde dein Hanfu |
| `fr_FR` | Voir la sélection | Acheter par occasion | Trouvez votre hanfu |

均非中文 source 占位、非错误回落英文 catalog。

## 复审证据

`meetings/翻译-review.md`（本 team）

## related_web_urls

- 验收面（编辑器草稿 · theme_id=3）：https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/theme/backend/theme-editor?theme_id=3&page_type=homepage&layout_option=default&editor_area=frontend&preview_area=frontend&status=draft&interaction_mode=edit
- 店面对照（WLS 恢复后）：https://p05113ef3.test.weline.com:9555/ru_RU/

## 升级项目经理

`notify_pm: true` — `@项目经理：翻译工程师 UC-P1-04 文案/词典已 closed（中英 CSV + 39 locale 词典 publish + Theme collect success）。部件 design 已降文字链、源串未改；「即刻寻衣」已预置。请更新 SESSION；WLS 恢复后可补店面 ru_RU 冒烟。`
