# REQ-MARKETING-0011 — 挽回阶梯 + 自动发券

## EARS

- When 活动 `max_steps≥2` 且当前 step≥2 且配置了 `incentive_rule_id`，the system shall 调用 RandomCoupon 发券并将 `coupon_code` 注入邮件与 SendLog。
- When step=1，the system shall 不发券。
- When 同 campaign+主体键+step 已记录，the system shall 不重发该步。
- When `segment_id>0` 且客户未命中分群，the system shall skip（`segment_mismatch`）。

## 主体键

- 未付：`order:{uuid}`
- 结账：`qt:{token}`
- 购物车：`cart:{cart_key}`（2b 继承同一套激励字段）

## 非目标

- 打开率/点击率；游客邮箱捕获。
