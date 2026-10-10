# 规格：尾款前卖家审查可配置

## 背景

- ToB 挂账需可按 Website 开关「定金后是否须运营审查才可付尾款」。
- 开启时后台订单编辑「通过审查」是唯一放行手段。

## 冻结用例

| id | Given / When / Then |
|----|---------------------|
| UC-cfg-default-on | 未配置 → 开关视为 true |
| UC-cfg-on-deposit | 开 + 定金成功 → `awaiting_merchant_approval` |
| UC-cfg-off-deposit | 关 + 定金成功 → `awaiting_balance`，无审查按钮 |
| UC-ops-approve / UC-ops-reject | 待审时通过/驳回（既有） |
| UC-unreviewed-block-pay | 待审时 `startPayment(balance)` 拒（不论当前配置） |
| UC-cfg-toggle-no-auto-release | 待审中改配置为关 → 仍待审、仍拒付 |
| UC-path-skip-no-fake-done | 快照 false 时步骤轨无审批步 |
| UC-mail-deposit-review-only | 仅进入审批态发定金待审邮件 |

配置键：`b2b_hang_require_merchant_review_before_balance`。快照：`type_payload.merchant_review_required`。
