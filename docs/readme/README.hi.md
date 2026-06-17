# WelineFramework

[भाषाएँ](./README.md) | [简体中文](../../README.md)

WelineFramework मॉड्यूलर वेब एप्लिकेशन, एडमिन सिस्टम और कॉमर्स परिदृश्यों के लिए PHP फ्रेमवर्क है। यह modules, routing, ORM, events/hooks, themes, backend ACL, i18n, WLS long-running service और CLI tools को व्यवस्थित करता है ताकि business modules विस्तार योग्य और maintainable रहें।

## रास्ता चुनें

- नया स्थानीय सेटअप: one-click installer उपयोग करें।
- PHP, Composer और database पहले से उपलब्ध हैं: clean install उपयोग करें।
- Architecture: [Weline architecture](../weline/README.md).
- AI / Codex कार्य: [AI-ENTRY.md](../../AI-ENTRY.md) से शुरू करें।

## आवश्यकताएँ

- PHP `^8.4`
- Composer `^2.7`
- MySQL / MariaDB / PostgreSQL
- Nginx / Apache या Weline built-in server (WLS)

Install commands वर्तमान user से चलाएँ। One-click installer को सीधे `sudo` से शुरू न करें।

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

Weline built-in server (WLS) शुरू करें:

```bash
php bin/w server:start
```

## उपयोगी Commands

| Command | उद्देश्य |
|---|---|
| `php bin/w` | commands सूची देखें |
| `php bin/w setup:upgrade` | modules, schema और config upgrade करें |
| `php bin/w setup:upgrade --route` | controller changes के बाद routes refresh करें |
| `php bin/w server:start` | Weline built-in server (WLS) शुरू करें |
| `php bin/w query:help <provider>` | Query Provider contracts देखें |

## Documentation

- [Project docs](../README.md)
- [Architecture overview](../weline/架构总览.md)
- [Development guide](../开发文档.md)
- [Deployment guide](../部署文档.md)
- [AI assistant entry](../../AI-README.md)

## Notes

`generated/` artifacts को सीधे edit न करें। `routes.xml` हाथ से न लिखें। User-visible text i18n से जाना चाहिए। AI tests को default `9501` की जगह `9502+` port पर isolated WLS instance उपयोग करना चाहिए।
