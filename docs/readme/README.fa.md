# WelineFramework

[زبان‌ها](./README.md) | [简体中文](../../README.md)

WelineFramework یک فریم‌ورک PHP برای برنامه‌های وب ماژولار، سیستم‌های مدیریتی و سناریوهای تجارت است. این پروژه ماژول‌ها، routing، ORM، events/hooks، themeها، backend ACL، i18n، سرویس بلندمدت WLS و ابزارهای CLI را سازمان‌دهی می‌کند تا ماژول‌های کسب‌وکار قابل توسعه و قابل نگهداری بمانند.

## انتخاب مسیر

- راه‌اندازی محلی جدید: از نصب‌کننده یک‌مرحله‌ای استفاده کنید.
- PHP، Composer و دیتابیس آماده است: نصب تمیز را انتخاب کنید.
- معماری: [معماری Weline](../weline/README.md).
- کار AI / Codex: از [AI-ENTRY.md](../../AI-ENTRY.md) شروع کنید.

## نیازمندی‌ها

- PHP `^8.4`
- Composer `^2.7`
- MySQL / MariaDB / PostgreSQL
- Nginx / Apache یا سرور داخلی Weline (WLS)

دستورهای نصب را با کاربر فعلی اجرا کنید. نصب‌کننده یک‌مرحله‌ای را مستقیم با `sudo` اجرا نکنید.

## نصب یک‌مرحله‌ای

Linux / macOS / Git Bash:

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
```

Windows PowerShell:

```powershell
$f="$env:TEMP\weline-bootstrap.ps1"; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' -OutFile $f; & $f
```

گزینه‌های رایج: `-b dev`, `-y`, `-f`, `--path-only`, `php`, `pgsql`, `mysql`.

## نصب تمیز

```bash
git clone https://gitee.com/aiweline/WelineFramework.git weline
cd weline
composer install
php bin/w command:upgrade
php bin/w system:install:sample
```

اجرای سرور داخلی Weline (WLS):

```bash
php bin/w server:start
```

## دستورهای مفید

| دستور | کاربرد |
|---|---|
| `php bin/w` | نمایش دستورها |
| `php bin/w setup:upgrade` | ارتقای ماژول‌ها، schema و config |
| `php bin/w setup:upgrade --route` | به‌روزرسانی route پس از تغییر controller |
| `php bin/w server:start` | اجرای سرور داخلی Weline (WLS) |
| `php bin/w query:help <provider>` | بررسی قراردادهای Query Provider |

## مستندات

- [مستندات پروژه](../README.md)
- [نمای کلی معماری](../weline/架构总览.md)
- [راهنمای توسعه](../开发文档.md)
- [راهنمای استقرار](../部署文档.md)
- [ورودی دستیار AI](../../AI-README.md)

## نکات

فایل‌های تولیدی `generated/` را مستقیم ویرایش نکنید. `routes.xml` را دستی ننویسید. متن قابل مشاهده برای کاربر باید از i18n عبور کند. تست‌های AI باید از نمونه WLS جدا روی پورت `9502+` استفاده کنند، نه پورت پیش‌فرض `9501`.
