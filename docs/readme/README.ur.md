# WelineFramework

[زبانیں](./README.md) | [简体中文](../../README.md)

WelineFramework ماڈیولر ویب ایپلی کیشنز، ایڈمن سسٹمز اور commerce scenarios کے لیے PHP framework ہے۔ یہ modules، routing، ORM، events/hooks، themes، backend ACL، i18n، WLS long-running service اور CLI tools کو منظم کرتا ہے تاکہ business modules قابل توسیع اور maintainable رہیں۔

## راستہ منتخب کریں

- نیا local setup: one-click installer استعمال کریں۔
- PHP، Composer اور database پہلے سے موجود ہیں: clean install استعمال کریں۔
- Architecture: [Weline architecture](../weline/README.md).
- AI / Codex کام: [AI-ENTRY.md](../../AI-ENTRY.md) سے شروع کریں۔

## ضروریات

- PHP `^8.4`
- Composer `^2.7`
- MySQL / MariaDB / PostgreSQL
- Nginx / Apache یا Weline built-in server (WLS)

Install commands موجودہ user سے چلائیں۔ One-click installer کو براہ راست `sudo` سے start نہ کریں۔

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

Weline built-in server (WLS) start کریں:

```bash
php bin/w server:start
```

## مفید Commands

| Command | مقصد |
|---|---|
| `php bin/w` | commands list دیکھیں |
| `php bin/w setup:upgrade` | modules، schema اور config upgrade کریں |
| `php bin/w setup:upgrade --route` | controller changes کے بعد routes refresh کریں |
| `php bin/w server:start` | Weline built-in server (WLS) start کریں |
| `php bin/w query:help <provider>` | Query Provider contracts دیکھیں |

## Documentation

- [Project docs](../README.md)
- [Architecture overview](../weline/架构总览.md)
- [Development guide](../开发文档.md)
- [Deployment guide](../部署文档.md)
- [AI assistant entry](../../AI-README.md)

## Notes

`generated/` artifacts کو directly edit نہ کریں۔ `routes.xml` manual نہ لکھیں۔ User-visible text کو i18n سے گزرنا چاہیے۔ AI tests کے لیے default `9501` نہیں بلکہ `9502+` port پر isolated WLS instance استعمال کریں۔
