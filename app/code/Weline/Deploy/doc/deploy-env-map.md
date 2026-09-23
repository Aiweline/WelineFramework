# 部署期环境档位映射（deploy-env-map）

机制在 `Weline_Deploy`；**映射文件本身不入库**。目标机（如生产）若存在该文件，发布完成后会自动按档位扭转 SystemConfig；没有文件则完全跳过。

## 文件

| 路径 | 是否入库 | 作用 |
|---|---|---|
| `deploy.env-map.php`（项目 / `deploy_root` 根） | **否**（根 `.gitignore`） | 线上/目标机落地后生效 |
| `deploy.env-map.php.example` | 是 | 复制模板 |

```bash
cp deploy.env-map.php.example deploy.env-map.php
# 按站点 Scope 与支付方式改 rules，仅放在目标环境，勿 git add
```

## 何时执行

1. **自动**：`deploy:release` 成功后事件 `Weline_Deploy::release_after` → `DeployEnvMapReleaseAfter`（sort=200）。读取事件里的 `deploy_root`，否则回退 `BP`。
2. **手动**：`php bin/w deploy:env-map:apply`（规范名 `deploy:env:map:apply`；支持 `--dry-run` / `--level=prod` / `--deploy-root=` / `--json`）。

无文件时结果为 `status=skipped`、`reason=map_file_missing`，不写库。

## 档位

由 `app/etc/env.php` 的 `system.deploy`（或文件内 `target_level`、CLI `--level`）归一化：

| 原值 | level |
|---|---|
| `dev` / `local` / `test` | `dev` |
| `pre` / `staging` | `staging` |
| `prod` / `production` | `prod` |

某 rule 的 `values_by_level` 没有当前档位 → **跳过该 rule**（不会默认写成 live）。

## Scope（与后台对齐）

`scopes` 必须显式列出：

- `global` → `default.default.default`
- 或后台同款三段 storage scope，如 `hanfu.__website__.default`

**不支持** `scopes: ['*']`。

写入走 `SystemConfig::setScopedConfig`，与配置中心 website/store/channel 范围一致；**locale 固定为 `default`**（不跟当前用户语种走）。

## 支付示例

见根目录 `deploy.env-map.php.example`：把 `payment/method/paypal/environment` 在 `dev` 写成 `sandbox`、`prod` 写成 `live`。PayPal live 凭据不要写进映射文件。

## SMTP 示例

同一文件可追加 `Weline_Smtp` 的 `smtp_host` / `smtp_port` / `smtp_username` / `smtp_secure` / `smtp_auth`（以及目标机本地的 `smtp_password`）。建议 **只映射 `prod`**，本机 `dev` 不写，避免覆盖本地调试账户。SMTP 密码仅允许出现在目标机不入库的 `deploy.env-map.php`，禁止写进 `.example` 或提交 Git。

## 契约与规格

- Spec：`doc/开发/spec/deploy-env-map.md`
- 结果 schema：`deploy-env-map-result.v1`
