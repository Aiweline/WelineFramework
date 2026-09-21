# Spec: deploy-env-map（部署期环境档位配置扭转）

| 字段 | 值 |
|---|---|
| slug | `deploy-env-map` |
| 模块 | `Weline_Deploy` |
| work_kind | `feature` |
| plan_complexity | `simple`（单模块、无 UI、复用 `Weline_Deploy::release_after` + SystemConfig `setScopedConfig`；用户已确认契约） |
| ui_skill_decision | `skip`（无 Web 面） |
| fe_be_scope | `be_only` |

## 背景

项目在本地开发时常把支付等方式设为沙盒；拷库/发布到线上后需要切到正式。不能对所有部署无脑切换，也不能把目标环境映射文件提交进 Git。

## 方案

1. **机制入库**：`Weline_Deploy` 提供读取映射、按档位写 SystemConfig、发布后钩子与 CLI。
2. **映射文件不入库**：项目根（或发布 `deploy_root`）的 `deploy.env-map.php` 列入 `.gitignore`；仓库只提供 `deploy.env-map.php.example`。
3. **有文件才生效**：目标机上不存在该文件 → 跳过（`status=skipped`），不改任何配置。
4. **Scope 显式**：每条 rule 必须声明 `scopes[]`，与后台 ConfigEmbed / SystemConfig 三段 storage scope 对齐；禁止 `*` 扫全表（v1）。

## EARS

- WHEN 发布完成且 `deploy_root/deploy.env-map.php`（或 BP 回退）不存在 THEN 系统 SHALL 跳过扭转并记录 `map_file_missing`。
- WHEN 映射文件存在且 `schema=deploy-env-map.v1` AND 当前档位在某 rule 的 `values_by_level` 中有值 THEN 系统 SHALL 对该 rule 的每个 scope 调用 `setScopedConfig` 写入对应值。
- WHEN 当前档位在某 rule 无映射 THEN 系统 SHALL 跳过该 rule（不写、不默认 live）。
- WHEN CLI `deploy:env-map:apply --dry-run` THEN 系统 SHALL 只报告将要写入的变更，不调用写配置。
- IF 映射文件不可读或 schema 非法 THEN 系统 SHALL 返回 `failed`；发布钩子内不得中断整个发布（记日志）；CLI 以非 0 退出。

## 用例

### UC-1 线上有映射文件，发布后自动切正式

1. 运维在生产 `deploy_root` 放置 `deploy.env-map.php`（不进 Git）。
2. 执行 `deploy:release`（或等价发布）。
3. `release_after` 观察者调用 apply。
4. 期望：`payment/method/.../environment` 等在声明的 scope 上变为 `live`（按文件映射）。

### UC-2 无映射文件

1. 项目根无 `deploy.env-map.php`。
2. 发布完成。
3. 期望：无 SystemConfig 写入；结果 `skipped`。

### UC-3 手工 / CI 单独执行

1. `php bin/w deploy:env-map:apply`
2. 期望：与钩子同一服务路径；可用 `--dry-run`、`--level=prod` 覆盖档位探测。

## Contracts

### 文件契约 `deploy-env-map.v1`

```php
<?php
return [
    'schema' => 'deploy-env-map.v1',
    // 可选；缺省由 Env system.deploy 归一化为 level
    'target_level' => null, // 'dev'|'staging'|'prod'
    'rules' => [
        [
            'module' => 'Weline_Payment',
            'area' => 'backend',
            'key' => 'payment/method/paypal/environment',
            'scopes' => [
                'default.default.default', // global
                // 'shop.__website__.default', // website 层示例
            ],
            'values_by_level' => [
                'dev' => 'sandbox',
                'prod' => 'live',
            ],
        ],
    ],
];
```

### Level 归一化

| Env `system.deploy`（及同义） | level |
|---|---|
| `dev` / `local` / `test` | `dev` |
| `pre` / `staging` | `staging` |
| `prod` / `production` | `prod` |
| 其它 / 空 | `prod`（与 Deploy 现有「未标明则偏生产」一致）；仍须 rule 有该档映射才写入 |

### Scope 别名

| 写法 | storage scope |
|---|---|
| `global` | `default.default.default` |
| `a.b.c`（三段） | 原样小写 |

### 结果契约 `deploy-env-map-result.v1`

```json
{
  "schema": "deploy-env-map-result.v1",
  "status": "skipped|applied|failed",
  "reason": "map_file_missing|ok|invalid_schema|...",
  "map_path": "/abs/deploy.env-map.php",
  "target_level": "prod",
  "dry_run": false,
  "changes": [
    {
      "module": "Weline_Payment",
      "area": "backend",
      "key": "payment/method/paypal/environment",
      "scope": "default.default.default",
      "value": "live",
      "written": true
    }
  ],
  "errors": []
}
```

## 非目标（本轮）

- 不把 live 密钥写进映射文件。
- 不做 `scopes: ['*']` 全表扫描。
- 不新增后台 UI。
- 不强制所有项目携带该文件。
