# 应用商城更新巡检自动化提示词

本文件记录 `APP_MARKETPLACE_PM_AUTOMATION` 的长期提示词补充。下一轮自动化启动时，应先读取同目录 `state.json`，再读取本文档，最后再按用户当轮提示执行。

## 下一轮最高优先级

先解决上一轮阻塞：客户端 `Weline_AppStore` 已有 active 账号和已安装 `Weline_SampleModule` 1.0.10，但账号授权令牌为空或解密失败，导致后台“我的模块”更新检测在调用官网 `check-update` 前失败。

下一轮不得先追 Office 移动端溢出、mini-cart 溢出或 `/sample-module` 404，除非 OAuth 绑定/令牌问题已经被真实入口验证为已恢复。

## 必须继承的上一轮证据

- 状态文件：`E:\WelineFramework\DEV-workspace\dev\ai\codex\automations\app-marketplace-pm\state.json`
- 上一轮任务：`E:\WelineFramework\DEV-workspace\dev\ai\codex\tasks\2026-06-07\2026-06-07-0156-app-marketplace-update-iteration`
- 阻塞证据：`artifacts/run-blocker-evidence.json`
- 客户端账号状态：`artifacts/client-appstore-state-before.json`
- Browser 证据：`artifacts/browser-validation.json`

上一轮确认：

- 官网 `http://127.0.0.1:9502/apps/weline-sample-module` 返回 200 并显示 `Weline_SampleModule` 1.0.10。
- 客户端 `https://p11005ce4.weline.test:9503/sample-module` 可访问并显示 `Weline_SampleModule` 1.0.10，模块路由实际生效。
- 客户端 `AppStore` active 账号的 token 为空或解密为 null。
- `Weline\AppStore\Controller\Backend\Installed::loadUpdateIndex()` 依赖 `AccountBindService::getApiToken()`，token 缺失时不会调用官网更新检查 API。
- 未授权调用官网 `/api/v1/platform/module/check-update` 返回 401，说明更新检查必须走真实授权链路。

## 下一轮执行要求

1. 先确认 `state.json` 仍为 `blocked` 且 `nextPriority` 指向 OAuth 绑定/令牌恢复；如果状态变化，以最新状态文件为准。
2. 启动或复用官网 9502，仅用于真实官网授权、详情页和 API 验证；不要停止用户原本运行的官网服务。
3. 启动客户端唯一测试 WLS，端口使用 `9503+`，实例名使用 `ai-test-app-marketplace-<runId>`；不要使用默认 `9501`。
4. 通过真实后台/浏览器入口恢复或验证官网账户绑定，优先走 OAuth/登录/回调等产品链路；不得手工向数据库注入 token，不得绕过认证、授权、签名或校验。
5. 令牌恢复后，使用后台“我的模块”或等价真实客户端入口重新检查 `Weline_SampleModule` 更新状态，并记录版本差异。
6. 只有客户端真实检测到可更新后，才继续下载安装、覆盖边界、setup/route/cache/reload 和模块生效验证。
7. 如果缺少账号凭证、回调失败、授权页面不可达或 token 仍为空，立刻把本轮状态落为 `blocked`，记录精确 URL、命令、错误输出和截图。

## 次级风险队列

OAuth 绑定/令牌恢复并完成更新闭环后，再处理以下问题：

- `E:\WelineFramework\Framework-Office-Site\official-apps\manifest.json` 当前严格 JSON 解析失败，且存在乱码/断引号风险。
- Office 应用详情页移动视口存在 language choice panel 负 `left` 横向溢出信号。
- 如果后续再次复现客户端 `/sample-module` 资源 404 或 mini-cart 溢出，再作为独立 UI 切片处理。

## 收尾要求

每轮结束前必须更新：

- `state.json` 的 `status`、`lastResult`、`nextPriority`、`evidence`。
- 当前任务目录的 `task.md`、`plan.md`、`progress.md`、`result.md`。
- `$CODEX_HOME/automations/automation/memory.md`，记录本轮结论和下一轮唯一优先级。
