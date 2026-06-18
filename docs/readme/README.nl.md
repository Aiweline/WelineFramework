# WelineFramework

[Talen](./README.md) | [Vereenvoudigd Chinees](../../README.zh-CN.md)

WelineFramework is een PHP-framework voor modulaire webapplicaties, beheersystemen en commerce-scenario's. Het organiseert modules, routing, ORM, events/hooks, thema's, backend ACL, i18n, de langlopende WLS-service en CLI-tools zodat businessmodules uitbreidbaar en onderhoudbaar blijven.

## Kies Een Pad

- Nieuwe lokale setup: gebruik de one-click installer.
- PHP, Composer en database zijn al beschikbaar: gebruik de schone installatie.
- Architectuur: [Weline architecture](../weline/README.md).
- AI / Codex werk: begin bij [AI-ENTRY.md](../../AI-ENTRY.md).

## Vereisten

- PHP `^8.4`
- Composer `^2.7`
- MySQL / MariaDB / PostgreSQL
- Nginx / Apache of Weline ingebouwde server (WLS)

Voer installatiecommando's uit als de huidige gebruiker. Start de one-click installer niet direct met `sudo`.

## One-Click Installatie

Linux / macOS / Git Bash:

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
```

Windows PowerShell:

```powershell
$f="$env:TEMP\weline-bootstrap.ps1"; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' -OutFile $f; & $f
```

Veelgebruikte opties: `-b dev`, `-y`, `-f`, `--path-only`, `php`, `pgsql`, `mysql`.

## Schone Installatie

```bash
git clone https://gitee.com/aiweline/WelineFramework.git weline
cd weline
composer install
php bin/w command:upgrade
php bin/w system:install:sample
```

Start Weline ingebouwde server (WLS):

```bash
php bin/w server:start
```

## Handige Commando's

| Commando | Doel |
|---|---|
| `php bin/w` | Commando's tonen |
| `php bin/w setup:upgrade` | Modules, schema en configuratie bijwerken |
| `php bin/w setup:upgrade --route` | Routes vernieuwen na controllerwijzigingen |
| `php bin/w server:start` | Weline ingebouwde server (WLS) starten |
| `php bin/w query:help <provider>` | Query Provider-contracten bekijken |

## Documentatie

- [Projectdocumentatie](../README.md)
- [Architectuuroverzicht](../weline/架构总览.md)
- [Ontwikkelgids](../开发文档.md)
- [Deploymentgids](../部署文档.md)
- [AI-assistent ingang](../../AI-README.md)

## Notities

Bewerk `generated/` artefacten niet direct. Schrijf `routes.xml` niet handmatig. Gebruikerszichtbare tekst hoort via i18n te lopen. AI-tests moeten een geïsoleerde WLS-instantie op poort `9502+` gebruiken, niet de standaardpoort `9501`.
