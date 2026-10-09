# AI E2E 录制与发布门禁

权威硬规则：`e2e_ai_record_into_module_formal_path`、`full_release_requires_commerce_pathway_e2e`（见 MCP `hard-constraints.v1` / `AI硬规则索引.md`）。  
运行入口仍是正式 runner：`php bin/w e2e:run`（`e2e_playwright_formal_runner_only`）。

## AI 怎么录制

1. 录制/落盘到**所属模块**的 `test/e2e` 或 `Test/e2e`，按面分目录：`frontend/` 或 `backend/`。
2. 选择器优先页内高唯一钩子（`data-testid` 等）；没有再用次稳选择器（接受不稳）。
3. 录完后只用正式 runner 跑，例如：

```bash
php bin/w e2e:run app/code/Weline/Checkout/test/e2e/frontend/your-case.spec.js --project=chromium
```

4. 禁止把 `node -e` / 一次性 `chromium.launch` 探活当成可交付 E2E。  
5. 宿主 WB-OP（IDE Browser）仍用于交互验收，与 Playwright 录制脚本互补，不互替。

## 全量发布前购物通路门禁

「全量」= **固化购物多范围通路套件**，不是仓库全部 Theme/Admin smoke。

```bash
php bin/w e2e:run --list-suites
php bin/w e2e:run --suite=commerce-release --project=chromium
```

清单：[`manifests/commerce-release-gate.v1.json`](manifests/commerce-release-gate.v1.json)。  
增删通路：改该 JSON，并保持磁盘上有对应 `.spec.js`（缺文件 fail-closed）。  
Sandbox 密钥缺失 → 该条 FAIL，不得 skip 冒充绿。

已录制多范围示例（Checkout）：`app/code/Weline/Checkout/test/e2e/frontend/commerce-multi-scope-checkout-pathways.spec.js`  
覆盖匿名 / 登录 / 批发（tob）结账 + DEMO10 优惠券 + 订单留言 + 提交下单；清单 id `CR-MULTI-SCOPE-CHECKOUT`。  
快捷支付通路：`commerce-express-checkout-pathway.spec.js`（`CR-EXPRESS-CHECKOUT-ORDER`）。  
帮付/快捷购买通路（HelpPay）：`commerce-helppay-pathways.spec.js`（`CR-HELPPAY-PATHWAYS-ORDER`）。  
B2B 测试店通路（独立店渠，非默认 `/b2b`）：`commerce-b2b-store-checkout-pathways.spec.js`（`CR-B2B-STORE-CHECKOUT-PATHWAYS`；入口 `/e2e-b2b-commerce/web`）。  
建议本机 Host：`WELINE_E2E_STOREFRONT_ORIGIN=https://p05113ef3.test.weline.com`（避免 `127.0.0.1` worker 被 attack_guard 干扰）。

## 自动化收口必须回报证据数据（硬）

权威：`automation_test_report_durable_evidence`。  
Agent 跑完 `e2e:run` / 套件等自动化验收后，面向用户**禁止只说「N passed / 测试完了」**；必须点名可独立回查的数据（有则必报：`order_uuid`、订单号、`transaction_no`、券码、邮箱、fixture id 等）。若通路故意未下单，须明示「无订单」并列出实际产生的最高证据。

用户明示全量发布 / 购物相关生产同步前：套件未全 PASS 只能报「发布门禁未过」。
