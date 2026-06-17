# WelineFramework

[Sprachen](./README.md) | [简体中文](../../README.md)

WelineFramework ist ein PHP-Framework für modulare Webanwendungen, Admin-Systeme und Commerce-Szenarien. Es bündelt Module, Routing, ORM, Events/Hooks, Themes, Backend-ACL, i18n, den langlebigen WLS-Dienst und CLI-Werkzeuge, damit Business-Module erweiterbar und wartbar bleiben.

## Pfad Auswählen

- Neues lokales Setup: den One-Click-Installer verwenden.
- PHP, Composer und Datenbank sind vorhanden: die saubere Installation verwenden.
- Architektur: [Weline-Architektur](../weline/README.md).
- AI / Codex-Arbeit: mit [AI-ENTRY.md](../../AI-ENTRY.md) beginnen.

## Anforderungen

- PHP `^8.4`
- Composer `^2.7`
- MySQL / MariaDB / PostgreSQL
- Nginx / Apache oder der integrierte Weline-Server (WLS)

Installationsbefehle mit dem aktuellen Benutzer ausführen. Den One-Click-Installer nicht direkt mit `sudo` starten.

## One-Click-Installation

Linux / macOS / Git Bash:

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
```

Windows PowerShell:

```powershell
$f="$env:TEMP\weline-bootstrap.ps1"; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' -OutFile $f; & $f
```

Häufige Optionen: `-b dev`, `-y`, `-f`, `--path-only`, `php`, `pgsql`, `mysql`.

## Saubere Installation

```bash
git clone https://gitee.com/aiweline/WelineFramework.git weline
cd weline
composer install
php bin/w command:upgrade
php bin/w system:install:sample
```

Integrierten Weline-Server (WLS) starten:

```bash
php bin/w server:start
```

## Nützliche Befehle

| Befehl | Zweck |
|---|---|
| `php bin/w` | Befehle anzeigen |
| `php bin/w setup:upgrade` | Module, Schema und Konfiguration aktualisieren |
| `php bin/w setup:upgrade --route` | Routen nach Controller-Änderungen aktualisieren |
| `php bin/w server:start` | Integrierten Weline-Server (WLS) starten |
| `php bin/w query:help <provider>` | Query-Provider-Verträge prüfen |

## Dokumentation

- [Projektdokumentation](../README.md)
- [Architekturüberblick](../weline/架构总览.md)
- [Entwicklungsleitfaden](../开发文档.md)
- [Deployment-Leitfaden](../部署文档.md)
- [AI-Assistent Einstieg](../../AI-README.md)

## Hinweise

`generated/`-Artefakte nicht direkt bearbeiten. `routes.xml` nicht manuell schreiben. Sichtbare Texte sollten über i18n laufen. AI-Tests müssen eine isolierte WLS-Instanz auf Port `9502+` verwenden, nicht den Standardport `9501`.
