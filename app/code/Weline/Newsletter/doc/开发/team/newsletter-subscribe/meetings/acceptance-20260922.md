# acceptance · newsletter-subscribe（验收波 · 测试）

| 字段 | 值 |
|------|-----|
| 席位 | **测试** |
| 时间 | 2026-09-22T10:48:00+08:00 |
| 仓库 | `/Users/weline/Project/Official/框架` |
| BASE（冻结字面） | `http://p05113ef3.test.weline.com:9555/` |
| BASE（实机可探活） | `https://p05113ef3.test.weline.com:9555/` |
| verdict | **pass** |
| 套件 | `newsletter-subscribe-plan-suite` |

---

## 1. curl 探活

| URL | 结果 |
|-----|------|
| `http://p05113ef3.test.weline.com:9555/` | **400**（nginx：plain HTTP sent to HTTPS port） |
| `https://p05113ef3.test.weline.com:9555/` | **200** |

结论：冻结 BASE 主机/端口正确，但本机 WLS 9555 为 HTTPS；e2e / WB-OP 使用 `https://`（与 Theme/Framework 其它 plan-suite 一致）。**未**改冻结 UC 意图。

---

## 2. Unit

```bash
php vendor/bin/phpunit app/code/Weline/Newsletter/Test/Unit --bootstrap app/bootstrap_phpunit.php
```

结果：**OK (17 tests, 127 assertions)**

---

## 3. Playwright e2e（正式 runner）

```bash
php bin/w e2e:run --module=Weline_Newsletter --project=chromium
```

| case id | UC | 结果 |
|---------|----|------|
| `newsletter-subscribe-footer-happy` | UC-1 | pass |
| `newsletter-subscribe-checkout-auto-coupon` | UC-2 | pass（含结账自动用券可观测） |
| `newsletter-subscribe-validation` | UC-3 | pass |
| `newsletter-subscribe-campaign-sync` | UC-4 | pass（契约 upsert + 后台页 rule_id） |
| `e2e-plan-suite` | 汇总 | pass |

合计：**5 passed (42.6s)**

规格路径：`app/code/Weline/Newsletter/Test/e2e/frontend/newsletter-subscribe-plan-suite.spec.js`  
Fixture：`newsletter-subscribe-fixture.php`

---

## 4. Browser WB-OP（页脚订阅可见）

- 打开前：`Network.enable` → `Network.setCacheDisabled({cacheDisabled:true})`（Playwright CDP；Cursor ide-browser / chrome-devtools 本回合不可用）
- URL：`https://p05113ef3.test.weline.com:9555/?nl_wb=acceptance`
- 断言：`[data-widget-code="footer-newsletter"]` count=1 且可见
- 截图证据：`meetings/acceptance-wb-op-footer-newsletter.png`
- Browser 收口：Playwright context 已 close；ide-browser tabs=空（N/A）

---

## 5. 环境备注（不降级 UC）

1. `SystemConfig::setConfig` 在 cache-namespace 锁争用下可挂起；fixture 对 `marketing_rule_id` 采用 Marketing upsert + SQL 直写，避免骗绿。
2. 首页 required overlay 在 FPC/edge STALE 时可能短暂空槽；cache-bust / MISS 后 footer-newsletter 正常渲染。
3. UC-2 加购：优先 `Weline.Api.resource('cart').add({provider_code,global_offer_uuid})`，再进结账断言券码。

---

## 交付地址

- [店面首页（HTTPS 实机）](https://p05113ef3.test.weline.com:9555/)
- `https://p05113ef3.test.weline.com:9555/`
- 冻结旁注：`http://p05113ef3.test.weline.com:9555/`（本环境 HTTP→HTTPS 端口，探活 400）
