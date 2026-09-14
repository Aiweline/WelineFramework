# Ch1 evidence — 真行为验收 2026-09-14

## 命令

```bash
PLAYWRIGHT_DISABLE_PROXY=1 PLAYWRIGHT_TARGET_ORIGIN=https://p05113ef3.test.weline.com:9555 \
  php bin/w e2e:run app/code/Weline/Shipping/Test/e2e/backend/Weline_Shipping-ch1-rate-profile.spec.js \
  --project=chromium --workers=1
php bin/w phpunit:run --module=Weline_Shipping --name=RateTableChapter1ContractTest
```

## acceptance_ids

| id | 结果 | 证据 |
|---|---|---|
| `ship-ch1-ut-brackets` / `ship-ch1-ut-chargeable` / `ship-ch1-ut-max` | UT PASS（含 2kg=11500、超 max 拒表） | RateTableChapter1ContractTest |
| `ship-ch1-rt-seed` | harness `ch1_seed_profiles` ok | `harness-ch1_seed_profiles.json` |
| `ship-ch1-e2e` | e2e PASS | 真报价 UC 全场景 |
| `ship-ch1-wb-admin` | e2e PASS | `wb-admin.png` |

## harness 摘要

- `ch1_americas_2kg`: amount_minor=11500
- `ch1_general_35kg_refuse`: conflict/拒报
- `ch1_heavy_35kg_quote`: amount_minor=220000
- `ch1_missing_dims_refuse`: missing_weight + missing_dims

交付 URL：后台费用模板见汇审。
