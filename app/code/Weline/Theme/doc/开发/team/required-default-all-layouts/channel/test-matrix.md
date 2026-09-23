# Team:测试: 全布局必装矩阵 — required-default-all-layouts

> seat：`Team:测试:`  
> plan_id：`test-matrix`  
> result：**closed**  
> notify_pm：**true**  
> @项目经理：theme-fix 后复验全绿（矩阵 fail_count=0 + curl 抽检 + Browser 主信息区可见）。请收口 SESSION / 汇审。

---

## 结论（2026-09-22T22:20+08）

**PASS / closed。**  
PM 裁决已生效：`related-products` / `cross-sell` 为 N/A（无槽不强制）；blog 探针为详情 URL。  
首轮矩阵 login 曾 HTTP 0（抖动）；独立 curl×3 + 复跑矩阵均为 PASS，不记功能 FAIL。

---

## 1) 矩阵脚本

```bash
PATH="/usr/bin:/bin:/usr/sbin:/sbin:/opt/homebrew/bin:$PATH" \
  php var/tmp/verify-required-injections-matrix.php
# fail_count=0  EXIT=0
# wrote var/tmp/required-injection-matrix-result.json
```

| layout | HTTP | 结果 | 备注 |
|--------|------|------|------|
| homepage | 200 | **PASS** | |
| products | 200 | **PASS** | category-filters / recommended-products / storefront-pixel-bootstrap |
| category | 200 | **PASS** | |
| product | 200 | **PASS** | 含 product-info + 购买三件套 + pixel；**无** related/cross-sell 强制项 |
| cart | 200 | **PASS** | |
| checkout | 200 | **PASS** | |
| account/login | 200 | **PASS** | 复跑；首轮 HTTP 0 为 Host 抖动 |
| blog | 200 | **PASS** | 探针 `/blog/aliexpress-hanfu-europe-sea` → blog-reviews |
| not_found | 404 | **PASS** | |

---

## 2) 独立 curl 抽检

Host：`https://p05113ef3.test.weline.com:9555`

| 页 | 断言 | 结果 |
|----|------|------|
| `/products` | `data-widget-code="category-filters"` ≥1；槽 `list-filters` | **PASS** |
| `/products` | `data-widget-code="storefront-pixel-bootstrap"` ≥1；`header-pixel-bootstrap` | **PASS** |
| `/products` | `recommended-products` ≥1 | **PASS** |
| PDP 干净 URL | `product-info` ≥1；`product-main` **无** render-error 注释 | **PASS** |
| PDP | `storefront-pixel-bootstrap` + pixel 壳 | **PASS** |
| PDP | `product-add-to-cart` ≥1 | **PASS** |
| login ×3 | HTTP 200 + `account-social-login` | **PASS** |

---

## 3) Browser（PDP 主信息区）

| 门禁 | 执行 |
|------|------|
| 宿主 | chrome-devtools（ide-browser 不可用） |
| 非抢占 | `background:true` / 省略前台 |
| 禁缓存 | `ignoreCache:true` 导航干净 PDP |
| 抹自动化 | `initScript` 抹 `navigator.webdriver` |

观测：

- `data-slot-id="product-main"` 存在且 **mainVisible=true**（正文 ≈2746 字）
- `data-widget-code="product-info"` ×1
- **无** `theme-layout-entity:render-error` 注释
- 可见标题：Yueya Nishang Chang'an Memory…
- `storefront-pixel-bootstrap` 存在

交付后：本回合验收标签 **已 close**。

---

## 4) 口径备忘

| 项 | 裁决 |
|----|------|
| related-products / cross-sell | **N/A**（`showRelatedProducts=false` 无槽） |
| blog-reviews | 验详情 URL，非 hub |
| 首轮 login HTTP 0 | 抖动；复跑 PASS |

未改生产实现骗绿。

---

## 交付元数据

| 字段 | 值 |
|------|-----|
| result | **closed** |
| notify_pm | true |
| fail_count | 0 |
| matrix_json | `var/tmp/required-injection-matrix-result.json` |
| verified_at | 2026-09-22T22:20+08 |
| related_web_urls | `/` `/products` `/product/…4d375d6d` `/blog/aliexpress-hanfu-europe-sea` `/customer/account/login` |

## @项目经理

notify_pm: **true**  
请更新 SESSION：`test-matrix` → closed；审查索引「测试」verdict=**approved**；可进入汇审 / 视情况关 main。
