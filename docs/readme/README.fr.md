# WelineFramework

[Langues](./README.md) | [Chinois simplifié](../../README.zh-CN.md)

WelineFramework est un framework PHP pour les applications web modulaires, les systèmes d'administration et les scénarios commerce. Il organise les modules, le routage, l'ORM, les événements/hooks, les thèmes, l'ACL backend, l'i18n, le service longue durée WLS et les outils CLI afin de garder les modules métier extensibles et maintenables.

## Choisir Un Parcours

- Nouvelle installation locale : utilisez l'installateur en une étape.
- PHP, Composer et base de données déjà disponibles : utilisez l'installation propre.
- Architecture : [architecture Weline](../weline/README.md).
- Travail AI / Codex : commencez par [AI-ENTRY.md](../../AI-ENTRY.md).

## Prérequis

- PHP `^8.4`
- Composer `^2.7`
- MySQL / MariaDB / PostgreSQL
- Nginx / Apache ou serveur intégré Weline (WLS)

Exécutez les commandes d'installation avec l'utilisateur courant. Ne lancez pas directement l'installateur en une étape avec `sudo`.

## Installation En Une Étape

Linux / macOS / Git Bash:

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
```

Windows PowerShell:

```powershell
$f="$env:TEMP\weline-bootstrap.ps1"; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' -OutFile $f; & $f
```

Options courantes : `-b dev`, `-y`, `-f`, `--path-only`, `php`, `pgsql`, `mysql`.

## Installation Propre

```bash
git clone https://gitee.com/aiweline/WelineFramework.git weline
cd weline
composer install
php bin/w command:upgrade
php bin/w system:install:sample
```

Démarrer le serveur intégré Weline (WLS) :

```bash
php bin/w server:start
```

## Commandes Utiles

| Commande | Rôle |
|---|---|
| `php bin/w` | Lister les commandes |
| `php bin/w setup:upgrade` | Mettre à jour modules, schéma et configuration |
| `php bin/w setup:upgrade --route` | Rafraîchir les routes après changement de contrôleur |
| `php bin/w server:start` | Démarrer le serveur intégré Weline (WLS) |
| `php bin/w query:help <provider>` | Inspecter les contrats Query Provider |

## Documentation

- [Documentation du projet](../README.md)
- [Vue d'ensemble architecture](../weline/架构总览.md)
- [Guide de développement](../开发文档.md)
- [Guide de déploiement](../部署文档.md)
- [Entrée assistant AI](../../AI-README.md)

## Notes

Ne modifiez pas directement les artefacts `generated/`. N'écrivez pas `routes.xml` manuellement. Les textes visibles par l'utilisateur doivent passer par i18n. Les tests AI doivent utiliser une instance WLS isolée sur un port `9502+`, pas le port par défaut `9501`.
