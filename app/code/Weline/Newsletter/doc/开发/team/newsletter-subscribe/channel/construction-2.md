## msg-1 | 2026-09-22T10:05:00+08:00 | from:项目经理 | to:前端 | thread:construction-2 | kind:handoff
agent_id: parent
body:
施工-2 唤醒前端席：完成 D9（Newsletter 部件迁入+注入+BinQuery JS）并同发布 D10（Theme 删壳）。席位对外前缀「前端」；删壳在回报标明「代主题 D10 已同发」。禁止问用户；禁止 git restore/clean。
---

## msg-2 | 2026-09-22T10:20:00+08:00 | from:前端 | to:项目经理 | thread:construction-2 | kind:closed
agent_id: newsletter-frontend-d9-d10
body:
**前端** D9 + **代主题 D10 已同发** → closed。

### D9
- 迁入模板：`Weline_Newsletter::templates/frontend/widgets/{footer-newsletter,newsletter-popup,sidebar-newsletter}/default.phtml`
- 注册 `extends/module/Weline_Widget/Weline_Newsletter/widget.php`：
  - `footer-newsletter`：slot=`footer-newsletter`；supports 含 layout-footer-newsletter；exclusive；required injection → homepage / footer / footer-newsletter
  - `newsletter-popup`：page_layouts=`*`；cookie_days 默认 14；position content；required injection → content sort_order=910（对照 StoreMusic）
  - `sidebar-newsletter` 一并迁入防残留
- 模板：topic_promo / topic_new_arrivals 默认 checked；隐私「可随时退订」；POST→`newsletter/subscribe`；BinQuery 经 `data-weline-load=newsletterSubscribe`
- data-testid：`newsletter-footer` / `newsletter-footer-form` / `newsletter-popup` / `newsletter-popup-form` / `newsletter-subscribe-success`（结账钩子留给 Marketing）
- JS：`view/statics/js/newsletter-subscribe.js` → `Weline.Api.resource('newsletter').subscribe`；成功展示 coupon_code；弹窗 cookie 14 天；无原生 fetch
- 弹窗无 PNG 时宣纸纹理 CSS（`var(--weline-theme-*)`）

### D10（代主题同发）
- Theme `widget.php` 移除 newsletter 三项路径
- 删除 Theme 三模板源文件
- `FooterPartialComposer::renderNewsletter` 空实现（停用硬编码）

### 验证
```
php bin/w widget:refresh
php bin/w resource:compile
php vendor/bin/phpunit app/code/Weline/Newsletter/Test/Unit/Widget/NewsletterWidgetOwnerContractTest.php app/code/Weline/Theme/test/Unit/FooterPartialComposerTest.php
```
结果：OK (7 tests, 67 assertions)；registry owner 唯一=`Weline_Newsletter`（footer-newsletter / newsletter-popup / sidebar-newsletter）。

result=closed
status: closed
---
