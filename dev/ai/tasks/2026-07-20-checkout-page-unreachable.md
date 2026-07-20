# 结账页无法访问

## 问题
`https://p05113ef3.weline.test:9531/checkout` 连接失败（HTTP 000）。

## 根因
原实例 `ai-test-login-error-ux-20260720133456` Worker 已全部退出；此前多次 `--no-ssl` / 并发抢起导致 `WLS_STARTUP_FAIL_FAST`。`default` 实例元数据仍为 `schema_version=3`，无 `-n` 的 `server:status` 会报错，但不阻塞具名实例启动。

## 处理
1. 停止残留 9531 元数据
2. 以 HTTPS 启动专用实例 `ai-test-checkout-revive-20260720-143827`（`-p 9531 -c 2`）
3. 验证：`/checkout` → 302 登录页；登录页 200；`query:help checkout` 正常

## 验收手递
- URL: https://p05113ef3.weline.test:9531/checkout
- 实例: `ai-test-checkout-revive-20260720-143827`
- 端口: 9531
- 停止: `php bin/w server:stop -n ai-test-checkout-revive-20260720-143827`
