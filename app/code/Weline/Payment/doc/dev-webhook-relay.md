# Payment Dev Webhook Relay

Provider 无关的开发环境 Webhook 转发：任意本框架线上站（开启中继门禁）固定接收 Provider Webhook；**本机后台静默 SSE 客户端**拉取事件并按官方 inbound 重放。无需打开浏览器终端。

## 启用（后台统一配置）

**不要**在 `app/etc/env.php` 写 `payment.dev_relay`。到后台控制台开关即可：

1. 打开 `payment/backend/dev-relay`（支付钩子 / 开发 Webhook 转发）
2. 勾选 **启用开发 Webhook 中继**
3. 生产站若作中转主机，勾选 **允许本生产站作为线上中转主机**（开启时会自动带上）
4. 点击 **保存配置**

配置写入站点 `var/payment-dev-relay-settings.json`（控制台「保存配置」）。若历史上曾用 env 启用，首次读配置会自动迁入该文件。

## 操作流程（推荐：本机面板观察）

设计原则：**本机常开中继**；线上只负责收 Webhook / 注入探针；在 Weline 面板一键发送并看重放。

### 1. 两边后台先启用

- 本机 + 线上均打开 `payment/backend/dev-relay` → 勾选启用 → **保存配置**
- 线上需勾选「允许本生产站作为线上中转主机」

### 2. 本机面板一键探测（推荐）

1. 任意本机页输入 `weline` → 最右侧 Tab **高级维护** → 二级 Tab **DevRelay 中继**
2. 面板顶部 **Provider Webhook** 区块：复制线上官方回调 URL，粘贴到 PayPal（或其它 Provider）Developer Webhooks  
   - 形态：`https://www.aiweline.com/payment/frontend/callback/notify?endpoint_code={method}.sandbox.default`  
   - **勿**填本机 `*.test.weline.com`，**勿**填 `/payment/dev-relay/*`  
   - 面板提供**搜索 + 下拉选择**（覆盖已注册支付方式的 sandbox/live 默认端点及库中 active 端点）；选中后复制完整 URL；选择会记在本机 localStorage
3. 点 **发送探测**（无需填 Token、无需打开线上 demo）
4. 面板自动：补全已存凭证 → 保活中继 → 发线上探针 → 展示本机 SSE 重放结果

本机「静默中继」启停始终可点：点 **开启中继** 会自动打开中继开关，并读取 JSON 请求体中的线上地址与 Token（也可回落已记住凭证）。首次需填写线上用户 API Token（会记到本机 `var/payment-dev-relay-local.secret.json`，关闭中继后仍保留）；之后可留空再开。

### 3. CLI（可选）

```bash
php bin/w payment:devrelay:start --online-base-url=https://www.aiweline.com --user-token=YOUR_TOKEN
php bin/w payment:devrelay:status
php bin/w payment:devrelay:loopback --online-base-url=https://www.aiweline.com --user-token=YOUR_TOKEN
php bin/w payment:devrelay:stop
```

### 4. 浏览器 / 外部工具（可选，非面板路径）

| 用途 | 地址 |
|------|------|
| 浏览器表单发探针 | `GET /payment/dev-relay/demo` |
| 程序/curl 探针 | `POST /payment/dev-relay/probe` + Bearer |

示例：`https://www.aiweline.com/payment/dev-relay/demo`

## 公网 API（免后台 Cookie）

| 方法 | 路径 | 鉴权 | 说明 |
|------|------|------|------|
| GET | `/payment/dev-relay/demo` | 无（页面内填 Token） | 浏览器发探针演示页 |
| POST | `/payment/dev-relay/pair` | Bearer 用户 Token | 创建线上会话，返回 `stream_url` |
| POST | `/payment/dev-relay/update-inbound` | Bearer + relay token | 回写本机 inbound |
| POST | `/payment/dev-relay/close` | Bearer + relay token | 关闭会话 |
| GET | `/payment/dev-relay/stream` | session_code + relay token | SSE |
| GET | `/payment/dev-relay/event` | session_code + relay token | 拉取事件载荷 |
| POST | `/payment/dev-relay/ack` | session_code + relay token | 重放结果 |
| POST | `/payment/dev-relay/inbound` | session_code + relay token | 本机官方 inbound |
| POST | `/payment/dev-relay/probe` | Bearer 用户 Token | 连调探针（需活跃会话） |
| GET | `/payment/dev-relay/worker-status` | 本机同源 | 面板轮询状态 |
| POST | `/payment/dev-relay/worker-start` | 本机同源 | 面板启 worker |
| POST | `/payment/dev-relay/worker-stop` | 本机同源 | 面板停 worker |
| POST | `/payment/dev-relay/panel-probe` | 本机同源 | 面板代发线上探针 |

站点地址必须是完整 URL（`https://host` 或 `https://host/subpath`），不要只填裸域名。

## 约束

- 线上 **不会** 直接 POST 到 `*.test.weline.com`；由本机 worker 拉 SSE 后重放
- PayPal 2xx 不等待本机；本机离线时线上 inbox 仍写入
- 出站 API 默认本机直连 Provider；`online_proxy` 时经线上代发

## PayPal 验收

1. 线上登记 Webhook → inbox 有记录
2. 本机 worker running → 本机 inbox 出现相同 `provider_event_id`
3. `payment:devrelay:stop` 后不再重放
