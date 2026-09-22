# channel — WO-HP-P1-03 部件回执（评价去重 → UGC 图墙）

日期：2026-09-22  
角色：`Team:部件开发工程师:`（主导）+ 协同前端渲染  
工单：`WO-HP-P1-03`  
`notify_pm: true`  
`@项目经理：本席已交付/上报，请检查并更新 SESSION`

MCP：`prepare_project` 本回合成功（`ready-1790052123921-dc36014f06a757e0`）；随后 `get_skill(widget_development)` 遇 `MCP_RUNTIME_STALE`，已按 `AI硬规则索引.md` + `部件开发指南.md` 继续；未编造规则。

---

## result

`closed`

## 实现策略

1. **保留** `homepage-reviews`「买家评价」证言三卡（星级+短评+署名；三条卖点互不重复的既有 placeholder）。  
2. **原「穿后感言」槽**（`homepage-testimonials`）改为 `image-gallery` + `variant=looks`：**买家秀 / Customer Looks** UGC 图墙。  
3. looks 空配置：6 格店面占位图 + 场景短标签（礼宴/通勤/旅拍等）+ CTA「晒出你的汉服穿搭」；**无**星级、**无**证言短评三卡；文案与买家评价字节级不同。  
4. Theme 基线布局 + hanfu design 布局同步；轻量 CSS token（`--sb-ratio: 3/4` 等，不发明色板）。  
5. 中英 CSV + `i18n:collect Weline_Theme`。

## paths_changed

- `app/code/Weline/Theme/view/theme/frontend/widgets/content/image-gallery/default.phtml`
- `app/code/Weline/Theme/view/statics/css/widgets/site-blocks.css`
- `app/code/Weline/Theme/view/theme/frontend/layouts/homepage/default.phtml`
- `app/design/Weline/hanfu/frontend/layouts/homepage/default.phtml`
- `app/code/Weline/Theme/test/Unit/ThemeHanfuHomepageDefaultsContractTest.php`
- `app/code/Weline/Theme/test/Unit/Widget/TextileHeritageWidgetContractTest.php`（注释口径）
- `app/code/Weline/Theme/i18n/zh_Hans_CN.csv` / `en_US.csv`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave1-widget-03-done.md`（本回执）

## paths_forbidden_untouched

- 包邮门槛（WO-HP-P1-01）
- 三货架查询逻辑（WO-HP-P1-02）
- 未 `git restore` / `clean` / `stash`

## 契约测

```text
php vendor/bin/phpunit \
  app/code/Weline/Theme/test/Unit/ThemeHanfuHomepageDefaultsContractTest.php \
  --filter testEmptyBrandListUsesThemeTextileHeritageCatalog
→ OK (1 test, 33 assertions)
```

## nocache 自验（HTTP DOM）

验收面：`https://p05113ef3.test.weline.com:9555/`（`Cache-Control: no-cache`）

| 检查项 | 结果 |
|--------|------|
| `穿后感言` | **0** |
| `data-widget-code="image-gallery"` | **1**（`variant=looks`，标题 Customer Looks / 买家秀） |
| `data-widget-code="testimonials"` | **1**（标题 Customer reviews / 买家评价） |
| `sb-looks-tile` | **6**（无星级短评结构） |
| `sb-quote` | **3**（仅买家评价一块） |
| 图墙 vs 证言文案重叠 | **∅**（场景标签 ≠ 尺码/面料/客服证言） |

Browser MCP 本回合无法稳定建签（`No browser tab available` / viewId 丢失）；已用禁缓存 HTTP DOM 等价验收。`cursor-ide-browser` N/A close。

## related_web_urls

- [首页验收](https://p05113ef3.test.weline.com:9555/)

## session_hint

WO-HP-P1-03 部件+前端渲染 closed；可唤醒测试席禁缓存复验 / 顾问运营意图复审。
