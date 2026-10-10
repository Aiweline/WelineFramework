# 订单编辑卖家审查（挂账）

status: ready-for-plan  
slug: order-edit-hang-merchant-confirm  
module: Weline_B2B（动作）+ Weline_Order（壳 UI）

## 澄清记录

- 用户：待商家确认阶段订单编辑看不到确认；需卖家审查后才可付尾款。
- 钉死：产品语义「卖家审查」；按钮「通过审查」/「驳回」；状态码仍 `awaiting_merchant_approval` → `awaiting_balance` / `rejected`。

## 用户故事

作为后台卖家运营，我在订单编辑页看到「待商家确认」时，可以直接通过或驳回挂账审查，以便客户继续付尾款或关闭挂单。

## EARS / UC

| id | EARS | acceptance |
|----|------|------------|
| UC-approve | When hang_status is awaiting_merchant_approval and seller posts 通过审查 on order edit, the system shall set hang to awaiting_balance and return to order edit (ControlCenter approve + order_id). | WB-OP 235：badge→待付尾款 |
| UC-reject | When hang_status is awaiting_merchant_approval and seller posts 驳回, the system shall reject hang (existing reject) and return to order edit. | UT/契约；勿误伤议价单 |
| UC-hide | When hang_status is not awaiting_merchant_approval, order edit shall not show approve/reject actions. | Tob policy UT |

## covers_use_cases

- UC-approve
- UC-reject
- UC-hide
