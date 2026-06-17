# WelineFramework

[اللغات](./README.md) | [简体中文](../../README.md)

WelineFramework هو إطار PHP لتطبيقات الويب المعيارية، وأنظمة الإدارة، وسيناريوهات التجارة. ينظم الوحدات، والتوجيه، و ORM، والأحداث/hooks، والقوالب، وصلاحيات backend ACL، و i18n، وخدمة WLS طويلة التشغيل، وأدوات CLI حتى تبقى وحدات الأعمال قابلة للتوسعة والصيانة.

## اختر المسار

- إعداد محلي جديد: استخدم المثبت بنقرة واحدة.
- لديك PHP و Composer وقاعدة بيانات: استخدم التثبيت النظيف.
- البنية: [بنية Weline](../weline/README.md).
- عمل AI / Codex: ابدأ من [AI-ENTRY.md](../../AI-ENTRY.md).

## المتطلبات

- PHP `^8.4`
- Composer `^2.7`
- MySQL / MariaDB / PostgreSQL
- Nginx / Apache أو خادم Weline المدمج (WLS)

نفذ أوامر التثبيت بالمستخدم الحالي. لا تشغل المثبت بنقرة واحدة مباشرة باستخدام `sudo`.

## التثبيت بنقرة واحدة

Linux / macOS / Git Bash:

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
```

Windows PowerShell:

```powershell
$f="$env:TEMP\weline-bootstrap.ps1"; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' -OutFile $f; & $f
```

خيارات شائعة: `-b dev`, `-y`, `-f`, `--path-only`, `php`, `pgsql`, `mysql`.

## التثبيت النظيف

```bash
git clone https://gitee.com/aiweline/WelineFramework.git weline
cd weline
composer install
php bin/w command:upgrade
php bin/w system:install:sample
```

تشغيل خادم Weline المدمج (WLS):

```bash
php bin/w server:start
```

## أوامر مفيدة

| الأمر | الغرض |
|---|---|
| `php bin/w` | عرض الأوامر |
| `php bin/w setup:upgrade` | ترقية الوحدات والمخطط والإعدادات |
| `php bin/w setup:upgrade --route` | تحديث المسارات بعد تغيير Controller |
| `php bin/w server:start` | تشغيل خادم Weline المدمج (WLS) |
| `php bin/w query:help <provider>` | فحص عقود Query Provider |

## الوثائق

- [وثائق المشروع](../README.md)
- [نظرة عامة على البنية](../weline/架构总览.md)
- [دليل التطوير](../开发文档.md)
- [دليل النشر](../部署文档.md)
- [مدخل مساعد AI](../../AI-README.md)

## ملاحظات

لا تعدل عناصر `generated/` مباشرة. لا تكتب `routes.xml` يدويا. النصوص التي يراها المستخدم يجب أن تمر عبر i18n. يجب أن تستخدم اختبارات AI نسخة WLS معزولة على منفذ `9502+` وليس المنفذ الافتراضي `9501`.
