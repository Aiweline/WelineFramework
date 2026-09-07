# Order Tracking Dev Relay（开发环境中转）

参照支付 `dev-webhook-relay`：线上固定收物流反馈；本机可探针重放观察 Inbox。当前为精简可运行版。

## 启用

- `WELINE_ENV=DEV` 时自动允许探针；或
- 写入 `var/order-tracking-dev-relay-settings.json`：`{"enabled": true}`

## URL

| 用途 | 路径 |
|---|---|
| 官方反馈入口（登记给承运商） | `/order/frontend/tracking-callback/notify?endpoint_code={method}.{env}.default` |
| 本机/开发探针 | `POST/GET /order/frontend/dev-relay/probe` |
| 最近探针事件 | `GET /order/frontend/dev-relay/events` |

演示 Provider `fake_carrier` 验签头：`X-Weline-Fake-Tracking: dev`。

## 探针示例

```bash
curl -sS 'https://p05113ef3.test.weline.com:9555/order/frontend/dev-relay/probe?endpoint_code=fake_carrier.sandbox.default&order_number=DEMO'
```

## 约束

- 生产承运商 Webhook 应指向统一 notify，而不是 `*.test.weline.com` 专用路径。
- Provider verify/parse 不得推进订单状态；应用侧监听 `order_tracking_feedback_received`。
