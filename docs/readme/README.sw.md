# WelineFramework

[Lugha](./README.md) | [简体中文](../../README.md)

WelineFramework ni framework ya PHP kwa programu za wavuti za moduli, mifumo ya admin, na matukio ya biashara. Inapanga modules, routing, ORM, events/hooks, themes, backend ACL, i18n, huduma ya WLS ya muda mrefu, na zana za CLI ili business modules ziwe rahisi kupanua na kutunza.

## Chagua Njia

- Usanidi mpya wa local: tumia one-click installer.
- PHP, Composer na database tayari zipo: tumia clean install.
- Architecture: [Weline architecture](../weline/README.md).
- Kazi ya AI / Codex: anza na [AI-ENTRY.md](../../AI-ENTRY.md).

## Mahitaji

- PHP `^8.4`
- Composer `^2.7`
- MySQL / MariaDB / PostgreSQL
- Nginx / Apache au Weline built-in server (WLS)

Endesha install commands kama user wa sasa. Usianzishe one-click installer moja kwa moja kwa `sudo`.

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

Anzisha Weline built-in server (WLS):

```bash
php bin/w server:start
```

## Commands Muhimu

| Command | Kusudi |
|---|---|
| `php bin/w` | Orodhesha commands |
| `php bin/w setup:upgrade` | Upgrade modules, schema na config |
| `php bin/w setup:upgrade --route` | Refresh routes baada ya controller changes |
| `php bin/w server:start` | Anzisha Weline built-in server (WLS) |
| `php bin/w query:help <provider>` | Kagua Query Provider contracts |

## Documentation

- [Project docs](../README.md)
- [Architecture overview](../weline/架构总览.md)
- [Development guide](../开发文档.md)
- [Deployment guide](../部署文档.md)
- [AI assistant entry](../../AI-README.md)

## Notes

Usihariri moja kwa moja `generated/` artifacts. Usiandike `routes.xml` kwa mkono. User-visible text inapaswa kupitia i18n. AI tests lazima zitumie isolated WLS instance kwenye port `9502+`, si default `9501`.
