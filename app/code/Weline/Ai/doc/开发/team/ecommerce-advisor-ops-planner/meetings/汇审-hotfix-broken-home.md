# 汇审补记 — P0 热修：首页布局塌陷（broken-home）

日期：2026-09-23  
角色：`Team:项目经理:`  
关联：`channel/pm-hotfix-homepage-broken-layout.md` · `channel/homepage-broken-layout-ops-escalation.md`  
验收面：`https://p05113ef3.test.weline.com:9555/` · `/zh_Hans_CN/?nocache=pmhf1`

## 认账（不改写）

运营怒批坏版首页并要求 **项目经理背锅**——属实。Wave-3 汇审视觉门禁失职：未禁缓存抓到 MALL-01 `max-height:7.5rem` 压塌方图锁、Hero CTA 裁切、`Illustrative scene` 外露。锅在 PM DoD，不在运营。

## 源码归属

| 改动方 | 内容 |
|--------|------|
| **父会话** | Theme + hanfu `homepage/default.phtml`：去 7.5rem；Hero 只压媒体、CTA 完整；隐藏 disclosure；`.wpc-image` absolute 铺满；信任条/头像缩小 |
| 席 A [78708b02](78708b02-af5c-462a-b7e6-244ac4805ab4) | **stop 双写** → 改验收/清缓存（PM interrupt） |
| 席 B [807aafe2](807aafe2-6481-4caf-9420-56ac36004aaf) | 勿碰 Hero/卡媒体；避让若已达标则停写 |

## PM DoD 验收（禁缓存 · Chrome DevTools）

| 项 | EN `/` | ZH `/zh_Hans_CN/` |
|----|--------|-------------------|
| 商品卡主图铺满（fillRatio=1，blankGap=0，position=absolute，max-height=none） | **PASS** ×4 | **PASS** ×4 |
| Hero CTA 完整可见、不与信任条重叠、不被 Hero 裁切 | **PASS**（Shop Featured / Shop by Occasion） | **PASS**（浏览精选 / 按场景选） |
| 无 `Illustrative scene`（正文+disclosure） | **PASS** | **PASS** |
| 货架标题完整可读、头像不挡字（elementFromPoint） | **PASS**（Featured Products） | **PASS**（特色产品） |

手段：`php bin/w cache:clear`（模板缓存已清）+ `navigate_page` `ignoreCache` + DOM 度量 + 视口截图。

## 硬规则补记（禁止再犯）

1. **禁止**对首页货架 `.wpc-media` / `.wpc-image` 设 `max-height` 压「一屏见 N 卡」——必须用行数/列数/`overflow`/`limit`，不得破坏方图锁。  
2. Hero 压高只压**媒体层**，不得整块 `overflow:hidden` 裁掉 CTA。  
3. 汇审 DoD 必须含 **禁缓存 Browser 首屏+精选区截图**，不得只靠 DOM 计数 PASS。

## 结论

**热修汇审：PASS**（父会话源码 + PM 双路径验收）。  
本补记不重开 Wave-3 全量；Wave-3 原汇审视觉门禁记为 **证伪**。

`notify_pm` N/A（本席即 PM）
