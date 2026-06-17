# WelineFramework

[Languages](./README.md) | [简体中文](../../README.md)

WelineFramework is a PHP framework for modular web applications, admin systems, and commerce scenarios. It organizes modules, routing, ORM, events/hooks, themes, backend ACL, i18n, WLS long-running service, and CLI tooling so business modules can remain extensible and maintainable.

## Choose A Path

- New local setup: use the one-click installer.
- Existing PHP, Composer, and database: use the clean install path.
- Architecture: [Weline architecture](../weline/README.md).
- AI / Codex work: start from [AI-ENTRY.md](../../AI-ENTRY.md).

## Requirements

- PHP `^8.4`
- Composer `^2.7`
- MySQL / MariaDB / PostgreSQL
- Nginx / Apache or Weline built-in server (WLS)

Run installer commands as the current user. Do not start the one-click installer directly with `sudo`.

## One-Click Install

Linux / macOS / Git Bash:

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
```

Windows PowerShell:

```powershell
$f="$env:TEMP\weline-bootstrap.ps1"; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' -OutFile $f; & $f
```

Common options: `-b dev`, `-y`, `-f`, `--path-only`, `php`, `pgsql`, `mysql`.

## Clean Install

```bash
git clone https://gitee.com/aiweline/WelineFramework.git weline
cd weline
composer install
php bin/w command:upgrade
php bin/w system:install:sample
```

Start Weline built-in server (WLS):

```bash
php bin/w server:start
```

## Useful Commands

| Command | Purpose |
|---|---|
| `php bin/w` | List commands |
| `php bin/w setup:upgrade` | Upgrade modules, schema, and config |
| `php bin/w setup:upgrade --route` | Refresh routes after controller changes |
| `php bin/w server:start` | Start Weline built-in server (WLS) |
| `php bin/w query:help <provider>` | Inspect Query Provider contracts |

## Documentation

- [Project docs](../README.md)
- [Architecture overview](../weline/架构总览.md)
- [Development guide](../开发文档.md)
- [Deployment guide](../部署文档.md)
- [AI assistant entry](../../AI-README.md)

## Notes

Do not edit `generated/` artifacts directly. Do not write `routes.xml` manually. User-visible text should go through i18n. AI tests must use an isolated WLS instance on port `9502+`, not the default `9501`.
