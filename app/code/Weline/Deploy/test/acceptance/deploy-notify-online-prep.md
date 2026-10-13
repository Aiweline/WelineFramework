# Deploy 通知 / ExpectWatch / skip-worktree — 上线测试准备

目标机：**`ssh changanhanfu`**（公网源站 `199.102.216.204`，应用根 `/www/wwwroot/changanhanfu.com`）。  
本包：`Weline_Deploy` **1.1.14**（分阶段 w_msg）+ **1.1.15**（分支 tip ExpectWatch + skip-worktree 门禁）。

## 0. 上线前（本机）

1. 确认契约 / 夹具绿：
   - `php vendor/bin/phpunit app/code/Weline/Deploy/test/Unit/Service/DeployReleaseNotifierContractTest.php app/code/Weline/Deploy/test/Unit/Service/DeployWebhookExpectWatchContractTest.php app/code/Weline/Deploy/test/Unit/Service/DeploySkipWorktreeGateContractTest.php --bootstrap app/bootstrap_phpunit.php`
   - `php app/code/Weline/Deploy/test/acceptance/deploy-smell-ab-fixture.php` → `"ok": true`
   - `php app/code/Weline/Deploy/test/acceptance/deploy-release-notify-fixture.php` → `"ok": true`
2. **提交** Deploy 改动进 tip（用户明示「提交」后再 commit），再按站柜流程打版本 / 触发 Webhook 或 CLI 发布。
3. 本需求**不**改 nginx / Uptime。

## 1. 部署到 changanhanfu 后（必做）

在应用根以 `weline` 执行：

```bash
cd /www/wwwroot/changanhanfu.com
# 确认版本
php -r 'echo (include "app/code/Weline/Deploy/etc/module.php")["version"], "\n";'
# 期望：1.1.15

php bin/w setup:upgrade -m Weline_Deploy
php bin/w cron:task:collect
php bin/w cron:task:list | grep deploy_webhook
# 期望同时有：
#   deploy_webhook_drain
#   deploy_webhook_expect
```

通知主题（可订阅飞书）：

```bash
php -r '
require "app/bootstrap.php";
$c = w_obj(\Weline\Backend\Service\TopicCollector::class);
$c->collect();
$m = w_obj(\Weline\Backend\Model\NotificationTopic::class);
$rows = $m->clearQuery()->where("topic_code","deploy_release")->select()->fetchArray();
echo json_encode($rows, JSON_UNESCAPED_UNICODE), "\n";
'
```

后台：通知中心订阅 **`deploy_release`** + 外发渠道。

## 2. 真机 UC（上线后）

| UC | 步骤 | 期望 |
|---|---|---|
| UC-1 阶段通知 | CLI 或 Webhook 触发一次短发布（可用已有 tip） | 通知中心出现 `queued`/`started`/…/`success` 或 `failed` |
| UC-2 tip miss | `deploy_trigger_mode=branch` 时，远程 tip 前进但故意不入队；等 ≥60s 让 Expect Cron 跑 | 一条 `webhook_miss`（reason 含 branch tip）；**不**自动开部署 |
| UC-3 tip 清除 | 同 tip 波次 Webhook 入队（或 current 已到该 SHA）后再 tick | pending 清除，不再重复 miss |
| UC-4 skip-worktree | 测试路径打 skip-worktree 后触发 release（仅受控环境） | `workdir_blocked` + 发布失败；清 skip 位后可再发 |

## 3. 未完成则不算可测

- tip 仍是旧 Deploy（&lt;1.1.15）  
- `cron:task:list` 无 `deploy_webhook_expect`  
- 未订 `deploy_release`（外发看不到；库内 `system_notification` 仍可查）
