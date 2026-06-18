# WelineFramework

[Bahasa](./README.md) | [Tionghoa Sederhana](../../README.zh-CN.md)

WelineFramework adalah framework PHP untuk aplikasi web modular, sistem admin, dan skenario commerce. Framework ini mengatur modul, routing, ORM, event/hook, tema, backend ACL, i18n, layanan jangka panjang WLS, dan alat CLI agar modul bisnis tetap mudah diperluas dan dirawat.

## Pilih Jalur

- Setup lokal baru: gunakan installer satu langkah.
- PHP, Composer, dan database sudah tersedia: gunakan instalasi bersih.
- Arsitektur: [arsitektur Weline](../weline/README.md).
- Pekerjaan AI / Codex: mulai dari [AI-ENTRY.md](../../AI-ENTRY.md).

## Kebutuhan

- PHP `^8.4`
- Composer `^2.7`
- MySQL / MariaDB / PostgreSQL
- Nginx / Apache atau server bawaan Weline (WLS)

Jalankan perintah instalasi sebagai pengguna saat ini. Jangan menjalankan installer satu langkah langsung dengan `sudo`.

## Instalasi Satu Langkah

Linux / macOS / Git Bash:

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
```

Windows PowerShell:

```powershell
$f="$env:TEMP\weline-bootstrap.ps1"; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' -OutFile $f; & $f
```

Opsi umum: `-b dev`, `-y`, `-f`, `--path-only`, `php`, `pgsql`, `mysql`.

## Instalasi Bersih

```bash
git clone https://gitee.com/aiweline/WelineFramework.git weline
cd weline
composer install
php bin/w command:upgrade
php bin/w system:install:sample
```

Mulai server bawaan Weline (WLS):

```bash
php bin/w server:start
```

## Perintah Berguna

| Perintah | Tujuan |
|---|---|
| `php bin/w` | Melihat daftar perintah |
| `php bin/w setup:upgrade` | Upgrade modul, skema, dan konfigurasi |
| `php bin/w setup:upgrade --route` | Refresh route setelah perubahan controller |
| `php bin/w server:start` | Mulai server bawaan Weline (WLS) |
| `php bin/w query:help <provider>` | Periksa kontrak Query Provider |

## Dokumentasi

- [Dokumentasi proyek](../README.md)
- [Ringkasan arsitektur](../weline/架构总览.md)
- [Panduan pengembangan](../开发文档.md)
- [Panduan deployment](../部署文档.md)
- [Entri asisten AI](../../AI-README.md)

## Catatan

Jangan mengedit artefak `generated/` secara langsung. Jangan menulis `routes.xml` manual. Teks yang terlihat oleh pengguna harus melalui i18n. Tes AI harus memakai instance WLS terisolasi di port `9502+`, bukan port default `9501`.
