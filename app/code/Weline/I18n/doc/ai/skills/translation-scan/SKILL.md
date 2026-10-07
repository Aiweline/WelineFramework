---
name: translation-scan
description: >-
  线上漏译爬取+全站全语种译修。Trigger MUST name site (扫描翻译：长安汉服 /
  扫描翻译 changanhanfu). Random non-default site locale for crawl; then translate
  every found source string into ALL enabled locales of that site. Authority:
  this file + dev/ai-command/ai/翻译扫描.md.
---

# translation-scan（翻译扫描）

## When To Use

```text
扫描翻译：{站点}
扫描翻译 {站点}
翻译扫描：{站点}
translation scan: {site}
```

无站点 → 追问，禁止开爬。非 content_ops「翻译优化」。

## Load First

1. `dev/ai-command/ai/翻译扫描.md`
2. `websites/<site>/README.md`
3. `app/code/Weline/I18n/doc/模块翻译CSV规范.md`（中英 CSV / 其它进词典）

## Hard Rules

1. **站点必填**；禁止猜站。  
2. **扫描语种**：从该站已启用 locale 中 **随机 1 个非默认**（通常排除 `zh_Hans_CN`）；用户显式点名 locale 则用之（不得用默认中文页当扫描语）。  
3. **覆盖**：非动态全量；动态每类 1 URL。  
4. **找问题词**后 **同回合**译该站 **全部** `site_locales`（含 zh+en 基线）；禁止只扫不译、只译 scan_locale。  
5. 落盘：中英 → 模块 CSV + `i18n:collect`；其它 → LocaleDictionary / publishLocale；CMS/导航走实体多语。  
6. **禁止**无故 Ollama；禁止未备份改生产。  
7. 报告：`dev/tmp/translation-scan-{site}-{scan_locale}-{date}.md`（含 `scan_locale` + `site_locales`）。

## Steps

1. 解析站点 → README → Host  
2. 拉该站 language_codes → 随机非默认 `scan_locale`  
3. 探活 → 爬 `/{scan_locale}/…` → 抽问题源串  
4. 分层报告  
5. 对每个源串写全 `site_locales` 译文并落盘/collect  
6. `scan_locale` + ≥1 其它非中英语种抽检  

## Output Template

```markdown
# 翻译扫描 · {site} · scan={scan_locale}
- site_locales: […]
- 扫描页 / 问题词数
## 分层结论
## 问题词 → 全语种译修结果
## 抽检
```
