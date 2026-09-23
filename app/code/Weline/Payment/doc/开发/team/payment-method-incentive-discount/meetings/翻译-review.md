# 翻译-review · payment-method-incentive-discount

- date: 2026-09-22
- seat: Team:翻译工程师:
- slug: payment-method-incentive-discount
- verdict: **pass**（中英 CSV + 默认站词典 upsert + `generated/language` 抽检 en_US/ru_RU；collect 同刻多席争用，模块 collect 已发起并与并发 collect 合流）
- notify_pm: true

## 1. 范围与源串盘点

| 归属 | 源串（简中） | 落盘 |
|------|--------------|------|
| `Weline_Payment` Quote/`__()` | `支付方式优惠` / `减 %1` / `减 %1%（约 %2）` | zh+en CSV ✓ |
| `Weline_Payment` SystemConfig labels/options | `支付方式激励折扣`、`启用激励折扣`、`激励形态`、`固定额`、`百分比`、减免/封顶/资金来源/商家/支付商/平台/共同/生效起止/摘要行标签/发布版本号 | zh+en CSV ✓ |
| `Weline_Payment` SystemConfig descriptions | PayPal/Fake 激励组 description 等（后台可见） | 本波补入 zh+en CSV ✓ |
| `Weline_Checkout` 摘要行 | `__('支付方式优惠')`（`index.phtml`） | Checkout zh+en CSV ✓ |
| 列表徽章文案 | `incentive_display` 由 Payment Quote 已译串注入 HTML（非 Checkout 新源串） | 跟 Payment ✓ |
| 「可选减」等前端额外 chrome | **未单独落新源串**（无独立 `__()`） | N/A |

前端同波已落摘要「支付方式优惠」→ **非 waiting_peer**。

## 2. 模块 CSV（仅 zh_Hans_CN + en_US）

- `app/code/Weline/Payment/i18n/{zh_Hans_CN,en_US}.csv`：激励店面/后台串 + description；补齐 `货到付款手续费`/`用户协议导航` 的 zh 身份列；补 PayPal Client 提示 en。
- `app/code/Weline/Checkout/i18n/{zh_Hans_CN,en_US}.csv`：已有 `支付方式优惠` → `Payment method discount`。
- **禁止**非中英模块 CSV。

## 3. 系统词典（默认站其它 locale）

- 包：`I18n/scripts/data/dict-fill-payment-method-incentive.v1.php`（19 源串 × 默认站非 zh locale）
- 脚本：`I18n/scripts/remediate-dict-fill-payment-method-incentive.php`
- dry-run：`words=19 writes=741 missing=0 locales=39`
- DB 抽检（upsert 后）：每词非 zh 行数 ≈39；例：
  - `支付方式优惠` / `en_US` → `Payment method discount`
  - `支付方式优惠` / `ru_RU` → `Скидка способа оплаты`
  - `减 %1` / `en_US` → `Save %1`；`ru_RU` → `Скидка %1`

## 4. collect / 抽检

| 项 | 状态 |
|----|------|
| `php bin/w i18n:collect Weline_Payment[ Weline_Checkout]` | 同刻 ≥9 路并行 collect 争用；本席多次发起；语言文件已含本波串（见下） |
| 词典/语言文件抽检 `en_US` | **pass**：`generated/language/en_US.php` → `支付方式优惠`=`Payment method discount`；`减 %1`=`Save %1`；`减 %1%（约 %2）`=`Save %1% (about %2)`；`启用激励折扣`=`Enable incentive discount` |
| 抽检 ≥1 非中英（`ru_RU`） | **pass**：同文件 → `Скидка способа оплаты` / `Скидка %1` / `Скидка %1% (около %2)` / `Включить стимулирующую скидку` |
| 店面 Browser 结账路径 | 本席以 CSV+词典+language 文件证据为主；Browser 真支付由测试席收口 |

## 5. 残留 / 跟进

1. 若 PM 需要独立 collect 终端回执：争用缓和后再跑一次 `php bin/w i18n:collect Weline_Payment Weline_Checkout` 贴 SESSION。
2. 若前端后续新增「可选减」等 chrome 源串 → 再唤醒本席补 CSV+词典。

## 6. notify_pm

- result: **delivered**
- notify_pm: **true**
- @项目经理：本席已交付/上报，请检查并更新 SESSION

paths_changed:

- `app/code/Weline/Payment/i18n/zh_Hans_CN.csv`
- `app/code/Weline/Payment/i18n/en_US.csv`
- `app/code/Weline/I18n/scripts/data/dict-fill-payment-method-incentive.v1.php`
- `app/code/Weline/I18n/scripts/remediate-dict-fill-payment-method-incentive.php`
- `meetings/翻译-review.md`（本文件）
- `channel/construction.md`（msg stance）
