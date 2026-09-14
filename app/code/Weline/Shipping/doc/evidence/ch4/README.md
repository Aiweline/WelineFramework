# Ch4 evidence — 履约周边 2026-09-14

## 命令

```bash
php bin/w maintenance:disable
PLAYWRIGHT_DISABLE_PROXY=1 PLAYWRIGHT_TARGET_ORIGIN=https://p05113ef3.test.weline.com:9555 \
  php bin/w e2e:run \
  app/code/Weline/Shipping/Test/e2e/backend/Weline_Shipping-ch4-commerce-edges.spec.js \
  app/code/Weline/Shipping/Test/e2e/backend/Weline_Shipping-anti-undercharge-plan-suite.spec.js \
  --project=chromium --workers=1
php -d opcache.enable_cli=0 vendor/bin/phpunit \
  --bootstrap app/code/Weline/Shipping/Test/Unit/bootstrap.php \
  app/code/Weline/Shipping/Test/Unit/Service/CommerceEdgesChapter4ContractTest.php
```

## acceptance_ids

| id | 结果 | 证据 |
|---|---|---|
| `ship-ch4-ut-incoterm` | PASS | CommerceEdgesChapter4ContractTest + harness `ch4_ddp_notice`（11500=11500） |
| `ship-ch4-ut-return` | PASS | harness `ch4_return_buyer` 1600 / `ch4_return_seller` 0 |
| `ship-ch4-ut-cod` | PASS | COD fee=500；grand=12000（MoneySnapshot） |
| `ship-ch4-ut-split` | PASS | first_only 次程=0；each_shipment 次程=11500>0 |
| `ship-ch4-rt-seed` | PASS | 退货模板 + Shipping 2.9.0 Upgrade |
| `ship-ch4-wb-checkout` | PASS | success.phtml COD 行 + `wb-ch4-rate-template.png` |
| `ship-ch4-e2e` | PASS | Weline_Shipping-ch4-commerce-edges.spec.js |
| `ship-anti-undercharge-plan-suite` | PASS | Ch1–4 suite_critical |

## harness 摘要

- `ch4_ddp_notice`: ddu/ddp amount_minor 均为 11500；incoterm=ddp；duty_notice 非空
- `ch4_return_buyer`: amount_minor=1600（不受前向免邮）
- `ch4_return_seller`: amount_minor=0；reason=seller_pays
- `ch4_cod_in_grand_total`: fee=500；grand=12000
- `ch4_split_first_only`: second=0
- `ch4_split_each_shipment`: second=11500>0（真二次计费）

## 跨模块

- Payment `CodFeeCalculator` 1.9.67
- Checkout freeze totals + submit options + 摘要 COD 行（1.4.79）
- Order `MoneySnapshot.cod_fee_amount_minor` + `shipping_snapshot_json` 扩展键 2.13.24
- Shipping 2.9.0（incoterm / ReturnQuote / CommercePolicy / SplitShipment；后台航线 Incoterm 可编辑）
