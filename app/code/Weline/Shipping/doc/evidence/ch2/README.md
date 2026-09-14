# Ch2 evidence — 真行为验收 2026-09-14

## 命令

```bash
PLAYWRIGHT_DISABLE_PROXY=1 PLAYWRIGHT_TARGET_ORIGIN=https://p05113ef3.test.weline.com:9555 \
  php bin/w e2e:run app/code/Weline/Shipping/Test/e2e/backend/Weline_Shipping-ch2-gates-surcharge.spec.js \
  --project=chromium --workers=1
php bin/w phpunit:run --module=Weline_Shipping --name=GatesSurchargeChapter2ContractTest
```

## acceptance_ids

| id | 结果 | 证据 |
|---|---|---|
| `ship-ch2-ut-gate` / `ship-ch2-ut-surcharge` | UT PASS | GatesSurchargeChapter2ContractTest |
| `ship-ch2-rt-seed` | 六省种子契约 | UT + Upgrade |
| `ship-ch2-e2e` | e2e PASS | 真报价 UC |
| `ship-ch2-wb-checkout` | e2e PASS | `wb-checkout.png` |

## harness 摘要

- `ch2_cn_xj_surcharge`: SEED_SURCHARGE_CN_XJ +2500
- `ch2_pobox_refuse` / `ch2_hazard_refuse`: conflict
- `ch2_free_keeps_remote`: free_reason + 偏远 2500
- `ch2_external_no_shop_surcharge`: Local-only overlay
