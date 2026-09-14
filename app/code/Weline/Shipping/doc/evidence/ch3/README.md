# Ch3 evidence — 真行为验收 2026-09-14

## 命令

```bash
PLAYWRIGHT_DISABLE_PROXY=1 PLAYWRIGHT_TARGET_ORIGIN=https://p05113ef3.test.weline.com:9555 \
  php bin/w e2e:run app/code/Weline/Shipping/Test/e2e/backend/Weline_Shipping-ch3-pack-addon.spec.js \
  --project=chromium --workers=1
php bin/w phpunit:run --module=Weline_Shipping --name=PackAddonChapter3ContractTest
```

## acceptance_ids

| id | 结果 | 证据 |
|---|---|---|
| `ship-ch3-ut-pack` / `ship-ch3-ut-addon` / `ship-ch3-ut-seasonal` | UT PASS | PackAddonChapter3ContractTest |
| `ship-ch3-rt-seed` | policy+季节默认关 | UT |
| `ship-ch3-e2e` | e2e PASS | 真报价 UC |
| `ship-ch3-wb-checkout` | e2e PASS | `wb-checkout.png` |
| `ship-anti-undercharge-plan-suite` | e2e PASS（Ch1–3 串联） | plan-suite + `../ch1/harness-suite_critical.json` |

## harness 摘要

- `ch3_split_boxes`: package_count=2；93000 ≥ 单箱 46500
- `ch3_signature_addon`: 11500 → 12000 + SEED_ADDON_SIGNATURE
- `ch3_seasonal_on`: 临时开燃油后金额上升，事后恢复 is_active=0

**未宣称防漏收全量完成**（第4章未做）。
