# WelineFramework

[Bahasa](./README.md) | [简体中文](../../README.md)

WelineFramework ialah framework PHP untuk aplikasi web modular, sistem pentadbiran dan senario commerce. Ia menyusun module, routing, ORM, event/hook, theme, backend ACL, i18n, perkhidmatan WLS long-running dan alat CLI supaya module perniagaan mudah dikembangkan dan diselenggara.

## Pilih Laluan

- Setup tempatan baharu: guna pemasang satu langkah.
- PHP, Composer dan database sudah tersedia: guna pemasangan bersih.
- Seni bina: [Weline architecture](../weline/README.md).
- Kerja AI / Codex: mula dari [AI-ENTRY.md](../../AI-ENTRY.md).

## Keperluan

- PHP `^8.4`
- Composer `^2.7`
- MySQL / MariaDB / PostgreSQL
- Nginx / Apache atau server terbina dalam Weline (WLS)

Jalankan arahan pemasangan sebagai pengguna semasa. Jangan mulakan pemasang satu langkah terus dengan `sudo`.

## Pemasangan Satu Langkah

Linux / macOS / Git Bash:

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
```

Windows PowerShell:

```powershell
$f="$env:TEMP\weline-bootstrap.ps1"; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' -OutFile $f; & $f
```

Pilihan biasa: `-b dev`, `-y`, `-f`, `--path-only`, `php`, `pgsql`, `mysql`.

## Pemasangan Bersih

```bash
git clone https://gitee.com/aiweline/WelineFramework.git weline
cd weline
composer install
php bin/w command:upgrade
php bin/w system:install:sample
```

Mulakan server terbina dalam Weline (WLS):

```bash
php bin/w server:start
```

## Arahan Berguna

| Arahan | Tujuan |
|---|---|
| `php bin/w` | Senaraikan arahan |
| `php bin/w setup:upgrade` | Naik taraf module, schema dan config |
| `php bin/w setup:upgrade --route` | Segarkan route selepas perubahan controller |
| `php bin/w server:start` | Mulakan server terbina dalam Weline (WLS) |
| `php bin/w query:help <provider>` | Semak kontrak Query Provider |

## Dokumentasi

- [Dokumentasi projek](../README.md)
- [Ringkasan seni bina](../weline/架构总览.md)
- [Panduan pembangunan](../开发文档.md)
- [Panduan deployment](../部署文档.md)
- [Masuk pembantu AI](../../AI-README.md)

## Nota

Jangan edit artefak `generated/` secara terus. Jangan tulis `routes.xml` secara manual. Teks yang dilihat pengguna perlu melalui i18n. Ujian AI mesti menggunakan instance WLS berasingan pada port `9502+`, bukan port lalai `9501`.
