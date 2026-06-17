# WelineFramework

[ภาษา](./README.md) | [简体中文](../../README.md)

WelineFramework คือ PHP framework สำหรับเว็บแอปแบบโมดูล ระบบแอดมิน และงาน commerce โดยจัดการ modules, routing, ORM, events/hooks, themes, backend ACL, i18n, บริการ WLS แบบ long-running และเครื่องมือ CLI เพื่อให้โมดูลธุรกิจขยายและดูแลต่อได้ง่าย

## เลือกวิธีเริ่มต้น

- ตั้งค่า local ใหม่: ใช้ one-click installer
- มี PHP, Composer และ database แล้ว: ใช้ clean install
- สถาปัตยกรรม: [Weline architecture](../weline/README.md)
- งาน AI / Codex: เริ่มจาก [AI-ENTRY.md](../../AI-ENTRY.md)

## ความต้องการ

- PHP `^8.4`
- Composer `^2.7`
- MySQL / MariaDB / PostgreSQL
- Nginx / Apache หรือ Weline built-in server (WLS)

รันคำสั่งติดตั้งด้วย user ปัจจุบัน อย่าเริ่ม one-click installer ด้วย `sudo` โดยตรง

## One-Click Install

Linux / macOS / Git Bash:

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
```

Windows PowerShell:

```powershell
$f="$env:TEMP\weline-bootstrap.ps1"; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' -OutFile $f; & $f
```

ตัวเลือกที่ใช้บ่อย: `-b dev`, `-y`, `-f`, `--path-only`, `php`, `pgsql`, `mysql`

## Clean Install

```bash
git clone https://gitee.com/aiweline/WelineFramework.git weline
cd weline
composer install
php bin/w command:upgrade
php bin/w system:install:sample
```

เริ่ม Weline built-in server (WLS):

```bash
php bin/w server:start
```

## คำสั่งที่ใช้บ่อย

| คำสั่ง | จุดประสงค์ |
|---|---|
| `php bin/w` | ดูรายการคำสั่ง |
| `php bin/w setup:upgrade` | อัปเกรด modules, schema และ config |
| `php bin/w setup:upgrade --route` | refresh routes หลังแก้ controller |
| `php bin/w server:start` | เริ่ม Weline built-in server (WLS) |
| `php bin/w query:help <provider>` | ตรวจ Query Provider contracts |

## เอกสาร

- [เอกสารโปรเจกต์](../README.md)
- [ภาพรวมสถาปัตยกรรม](../weline/架构总览.md)
- [คู่มือนักพัฒนา](../开发文档.md)
- [คู่มือ deploy](../部署文档.md)
- [ทางเข้า AI assistant](../../AI-README.md)

## หมายเหตุ

อย่าแก้ artefact ใน `generated/` โดยตรง อย่าเขียน `routes.xml` เอง ข้อความที่ผู้ใช้เห็นควรผ่าน i18n การทดสอบ AI ต้องใช้ WLS instance แยกบนพอร์ต `9502+` ไม่ใช่พอร์ต default `9501`
