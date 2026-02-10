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
