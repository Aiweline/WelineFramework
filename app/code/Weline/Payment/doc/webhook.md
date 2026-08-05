# Payment Webhook（P2F-003 / P2F-004）

P2F-003 把回调接收阶段接入生产持久化；P2F-004 把 Inbox 消费接入
Queue 和同一默认连接事务。回调线程只负责验证并写入不可变 Inbox，
Controller 或 Provider 仍不得直接推进支付、库存或后续业务状态。

## 两阶段

| 阶段 | 组件 | 行为 |
|---|---|---|
| A 回调线程 | `PaymentCallbackReceiver` | endpoint lookup → raw verify → pure parse → immutable inbox → **提交后** 2xx |
| B 消费者 | `PaymentInboxConsumer` | same default connector → CAS 状态 → **唯一** inventory commit（无 inventory-command outbox）→ effect outbox → applied |

成功 Attempt 的确定性 effect：`invoice:create:v1`、`fulfillment:action:v1`、`notification:paid:v1`（见 Order [`invoice-fulfillment-tombstone.md`](../../Order/doc/invoice-fulfillment-tombstone.md)）。

## Connector 硬门禁

`PaymentConnectorGuard`：Payment/Order/Inventory 指纹必须全部等于
`default`；仅仅“三者相同”不够，同一非默认连接也会在**任何状态写前**
失败并报告 `payment_connector_plan_deviation`。

## Consumer 原子事务

`PaymentInboxConsumer` 实现 `QueueConsumerInterface`，由
`queue:collect` 收集。每个 Inbox 在同一默认连接事务中依次执行：

1. 锁定 Inbox，校验 schema version、可消费状态和 `consumer_version`。
2. 锁定 Intent 与最新 Attempt，以 `version + cas_token` 做单调 CAS。
3. succeeded 时以 Attempt code 派生幂等键，提交唯一 Inventory ledger。
4. 写入 `invoice:create:v1`、`fulfillment:action:v1`、
   `notification:paid:v1` 三条确定性 effect outbox。
5. 所有事实成功后才把 Inbox 标记为 `applied`；倒序状态标记为
   `ignored` 并保存原因。

Inventory commit 与 Payment 事实属于同一数据库事务，不生成
inventory-command outbox。事务中任一点失败都会整体回滚，重试继续使用
原 Inbox、Attempt、Inventory ledger 和 effect key，因此不会产生半状态
或重复副作用。

## Endpoint / Secret

- `PaymentWebhookEndpoint`：数据库 endpoint 事实源，冻结
  `endpoint_code / provider_code / method_code / merchant_account /
  environment / context_version / scope_snapshot`。
- `PaymentWebhookSecret`：`(endpoint_code, secret_version)` 唯一；
  `secret_ref` 必须是 `SecretRefCipher` 密封引用，禁止明文和 Base64
  伪加密。
- secret 解析只接受 `active` / `grace` 且处于有效时间窗的版本；
  Inbox 同时冻结实际命中的 `verification_secret_version`。
- `active` endpoint 正常接收；`disabled` 立即 410；`tombstone` 在
  `retain_until` 前继续履行历史回调义务，过期后 410。
- Provider 绑定中 `provider_code` 与 `method_code` 是两个独立身份，
  例如 Fake Provider 为 `fake` / `fake_card`。历史 endpoint 可按冻结
  绑定从 Provider registry 回退解析，不依赖可变 PaymentMethod 行永远存在。

生产目录入口为
`WebhookEndpointDirectoryInterface::resolveActive()`、
`resolveVerificationSecrets()` 和 `resolveSecretMaterial()`。测试 memory
目录只能通过显式 `forTesting()` 使用。

## 接收顺序

1. 通过 `endpoint_code` 查询持久 endpoint，校验状态和保留期。
2. 校验 Provider 时间窗，并按 active/grace secret 版本逐一验签。
3. 调用 Provider 的纯 `verifyCallback()` / `parseCallback()`；两者禁止
   写数据库、发队列、调远端或推进业务状态。
4. 对 Provider 原始请求字节计算 `payload_hash`，并用
   `SecretRefCipher` 分别密封 raw body、headers、signature。
5. 在本地事务写入唯一
   `(endpoint_code, provider_event_id)` Inbox；只有提交成功后返回 2xx。

Controller 必须从 Request/ParameterBag 读取真实 raw body，禁止把已解析
参数重新 JSON 编码后冒充签名字节。WLS 状态码必须通过框架 Response 的
`setHttpResponseCode()` 设置，不能只调用原生 `http_response_code()`。

## HTTP 策略

| 情况 | HTTP | 入箱 |
|---|---|---|
| 错签名 / 过期时间 / 未知 endpoint | 4xx | 否 |
| endpoint disabled | 410 | 否 |
| 缺 `provider_event_id` | 400 | 否 |
| 同 event 不同 hash | 409 `event_id_payload_conflict` + urgent | 否（保留原 inbox） |
| inbox 提交失败 | 500 retry | 否 |
| 首次成功 / 幂等重放 | 200 | 首次写一条 |

拒绝审计只包含 endpoint、动作、错误 code、Inbox code 等脱敏字段；日志和
返回值都不能包含 raw body、secret、signature 或完整 headers。

## 回滚

停 endpoint（`disableEndpoint`）或
`PaymentInboxConsumer::setEnabled(false)`；已入箱保留并可前向重放，
已提交 ledger/effect 不逆向删除，也不恢复 Controller/Provider 直接写状态。

## 验证

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Payment/Test/Unit/bootstrap.php \
  app/code/Weline/Payment/Test/Unit/Service/PaymentCallbackReceiverTest.php

php vendor/bin/phpunit --bootstrap app/code/Weline/Payment/Test/Unit/bootstrap.php \
  app/code/Weline/Payment/Test/Unit/Service/PaymentInboxConsumerTest.php

php bin/w queue:collect
php bin/w queue:type:listing Weline_Payment
php bin/w setup:schema:check -m Weline_Payment --json
php bin/w setup:upgrade --route
```
