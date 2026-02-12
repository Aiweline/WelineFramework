# 错误日志

## 2026-02-09 Terraform 安装后 env:check 仍提示未满足

**错误类型**: 环境依赖检测误判  
**错误信息**:
```
依赖 Terraform CLI ✖（未满足）
terraform not found in PATH
```

**根本原因**:
Windows 下安装脚本仅更新用户 PATH，但当前 PHP 进程与其子进程继承的是旧环境变量，
导致 `env:check` 的 PowerShell 子进程无法在 PATH 中找到 `terraform.exe`。

**解决方案**:
- 在 `terraform_windows.ps1` 中检测默认安装目录 `%LOCALAPPDATA%\Terraform`，
  若存在则临时加入 `$env:Path` 并同步写回用户 PATH。
- 重新执行 `php bin/w env:check` 验证通过。

**验证结果**:
- `php bin/w env:check` 显示 `依赖 Terraform CLI ✔`

**预防措施**:
安装完成后可重开终端刷新 PATH，或在检测脚本中优先判断默认安装目录。

**相关文件**:
- `app/code/Weline/Terraform/env/script/terraform_windows.ps1`

---

## 2026-02-09 CLI Printing setStickyFooter 抽象方法调用错误

**错误类型**: 接口/抽象方法实现缺失  
**错误信息**:
```
Fatal error: Uncaught Error: Cannot call abstract method
Weline\Framework\Output\PrintInterface::setStickyFooter()
in app\code\Weline\Framework\Output\Cli\Printing.php:16
```

**根本原因**:
CLI 输出体系使用 `Weline\Framework\Output\Cli\AbstractPrint`，新增的
`setStickyFooter()` 仅实现于 `Weline\Framework\Output\AbstractPrint`，
导致 `Cli\Printing` 调用 `parent::setStickyFooter()` 实际指向接口抽象方法。

**解决方案**:
- 在 `Weline\Framework\Output\Cli\PrintInterface` 补充方法声明
- 在 `Weline\Framework\Output\Cli\AbstractPrint` 实现 `setStickyFooter/clearStickyFooter`
- 在 `printing()` 末尾渲染底栏（ANSI）

**预防措施**:
新增 `PrintInterface` 方法时，必须同步更新 `Output\Cli\AbstractPrint`
及其接口/实现，避免命名空间分支遗漏。

**相关文件**:
- `app/code/Weline/Framework/Output/PrintInterface.php`
- `app/code/Weline/Framework/Output/Cli/PrintInterface.php`
- `app/code/Weline/Framework/Output/Cli/AbstractPrint.php`
- `app/code/Weline/Framework/Output/Cli/Printing.php`
- `app/code/Weline/Server/Console/Server/Start.php`

---

## 2026-02-09 Worker 健康检查误判导致周期性“重启”

**错误类型**: 运行时健康检查误判  
**错误信息**:
```
[Master] Worker #X (端口: 1998X) 需要重启，原因: HTTP 健康检查连续失败 5 次
```

**根本原因**:
健康检查使用配置的监听地址 `0.0.0.0/::` 作为请求目标，
导致 `file_get_contents()` 无法连接，从而持续失败并触发“重启”逻辑。

**解决方案**:
在 `MasterProcess::checkWorkerHealth()` 中，当监听地址为 `0.0.0.0/::` 时，
改用 `127.0.0.1` 作为健康检查目标地址。

**验证结果**:
- 运行 `curl.exe -k https://127.0.0.1:19981/_wls/health` 与 `19982` 返回 `OK`

**预防措施**:
健康检查必须使用可连接地址（loopback），不要直接使用监听地址 `0.0.0.0/::`。

**相关文件**:
- `app/code/Weline/Server/Service/MasterProcess.php`

---

## 2026-02-12 BackendUserRole 安装阶段外键失败（PostgreSQL）

**错误类型**: 数据库外键约束 / 初始化数据设计  
**错误信息**:
```
SQLSTATE[23503]: Foreign key violation
insert or update on table "m_backend_acl_user_role" violates foreign key constraint "USER_ID"
DETAIL: Key (user_id)=(2) is not present in table "m_backend_user".
```

**根本原因**:
`Weline\Backend\Model\Backend\Acl\UserRole::install()` 在建表后固定写入 `user_id=2` 的角色数据，
但 `BackendUser::install()` 默认只创建了一个后台用户（通常为 id=1），导致安装阶段外键失败。

**解决方案**:
- `UserRole::install()` 改为“仅在管理员用户真实存在时分配默认角色”。
- 使用 `BackendUser->load(1)` 判定存在性，存在才插入角色映射，避免硬编码依赖不存在用户。

**验证结果**:
- `php -l app/code/Weline/Backend/Model/Backend/Acl/UserRole.php` 语法检查通过。
- `setup:upgrade` 运行被已有锁文件阻断（`var/process/setup_upgrade.lock`），未完成端到端验证。

**预防措施**:
1. 安装种子数据不要硬编码跨表外键 ID（如 `user_id=2`）。
2. 需要跨表初始化时，先做存在性检查或拆分到专门的数据初始化阶段。

**相关文件**:
- `app/code/Weline/Backend/Model/Backend/Acl/UserRole.php`

---

## 2026-02-12 Pgsql 回退 exec() 占位符未替换导致语法错误

**错误类型**: PostgreSQL 参数规范化状态不一致  
**错误信息**:
```
SQLSTATE[42601]: Syntax error: 7 ERROR:  syntax error at or near ":"
LINE 1: ... VALUES (:2a92c4a34...
```

**根本原因**:
`preparePgsql()` 会规范化参数名（如 `:2xxx` -> `:p2xxx`），并修改 `bound_values`，
但未同步对象中的 SQL 状态；当后续走 `exec()` 回退路径时，`getSqlWithBounds($this->sql)`
使用的是旧占位符 SQL，导致绑定键与 SQL 占位符不一致，替换失败，原始 `:xxx` 被发送到 PostgreSQL。

**解决方案**:
- 在 `preparePgsql()` 参数名规范化后，立即同步 `$this->sql = $sql`，
  保证后续 `exec()` 回退路径中 SQL 与 `bound_values` 键一致。

**验证结果**:
- `php -l app/code/Weline/Framework/Database/Connection/Adapter/Pgsql/Query.php` 语法检查通过。
- `setup:upgrade` 运行被已有锁文件阻断（`var/process/setup_upgrade.lock`），未完成端到端验证。

**预防措施**:
1. 任何 SQL/绑定参数规范化必须保持“同源一致”（SQL 占位符与绑定键同一套命名）。
2. `prepare` 与 `exec` 双路径并存时，必须保证回退路径复用一致状态。

**相关文件**:
- `app/code/Weline/Framework/Database/Connection/Adapter/Pgsql/Query.php`
