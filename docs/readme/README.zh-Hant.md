# WelineFramework

[語言索引](./README.md) | [簡體中文](../../README.zh-CN.md)

WelineFramework 是面向模組化 Web 應用、後台系統與電商業務場景的 PHP 框架。它圍繞模組、路由、ORM、事件/Hook、主題模板、後台 ACL、i18n、WLS 長執行服務與 CLI 工具組織工程能力，讓業務模組能穩定擴展、清晰部署、持續維護。

## 選擇路徑

- 零基礎快速搭建本地環境：使用一鍵安裝腳本。
- 已有 PHP、Composer、資料庫：使用純淨安裝。
- 架構文件：[Weline 架構](../weline/README.md)。
- AI / Codex 在本倉工作：從 [AI-ENTRY.md](../../AI-ENTRY.md) 開始。

## 環境要求

- PHP `^8.4`
- Composer `^2.7`
- MySQL / MariaDB / PostgreSQL
- Nginx / Apache 或 Weline 框架內建伺服器（WLS）

請以目前使用者執行安裝命令，不要直接使用 `sudo` 啟動一鍵安裝。

## 一鍵安裝

Linux / macOS / Git Bash:

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
```

Windows PowerShell:

```powershell
$f="$env:TEMP\weline-bootstrap.ps1"; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' -OutFile $f; & $f
```

常用參數：`-b dev`、`-y`、`-f`、`--path-only`、`php`、`pgsql`、`mysql`。

## 純淨安裝

```bash
git clone https://gitee.com/aiweline/WelineFramework.git weline
cd weline
composer install
php bin/w command:upgrade
php bin/w system:install:sample
```

啟動 Weline 框架內建伺服器（WLS）：

```bash
php bin/w server:start
```

## 常用命令

| 命令 | 用途 |
|---|---|
| `php bin/w` | 查看可用命令 |
| `php bin/w setup:upgrade` | 升級模組、資料結構與設定 |
| `php bin/w setup:upgrade --route` | Controller 變更後刷新路由 |
| `php bin/w server:start` | 啟動 Weline 框架內建伺服器（WLS） |
| `php bin/w query:help <provider>` | 查看 Query Provider 契約 |

## 文件

- [專案文件](../README.md)
- [架構總覽](../weline/架构总览.md)
- [開發文件](../开发文档.md)
- [部署文件](../部署文档.md)
- [AI 助手入口](../../AI-README.md)

## 注意

不要直接修改 `generated/` 產物。不要手寫 `routes.xml`。使用者可見文案應走 i18n。AI 測試必須使用 `9502+` 連接埠上的獨立 WLS 實例，不要使用預設 `9501`。
