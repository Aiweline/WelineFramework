# WelineFramework

[Ngôn ngữ](./README.md) | [简体中文](../../README.md)

WelineFramework là framework PHP cho ứng dụng web dạng mô-đun, hệ thống quản trị và các kịch bản thương mại. Nó tổ chức module, routing, ORM, event/hook, theme, backend ACL, i18n, dịch vụ chạy dài WLS và công cụ CLI để các module nghiệp vụ dễ mở rộng và bảo trì.

## Chọn Cách Bắt Đầu

- Thiết lập local mới: dùng trình cài đặt một bước.
- Đã có PHP, Composer và database: dùng cài đặt sạch.
- Kiến trúc: [kiến trúc Weline](../weline/README.md).
- Công việc AI / Codex: bắt đầu từ [AI-ENTRY.md](../../AI-ENTRY.md).

## Yêu Cầu

- PHP `^8.4`
- Composer `^2.7`
- MySQL / MariaDB / PostgreSQL
- Nginx / Apache hoặc server tích hợp của Weline (WLS)

Chạy lệnh cài đặt bằng user hiện tại. Không chạy trực tiếp trình cài đặt một bước bằng `sudo`.

## Cài Đặt Một Bước

Linux / macOS / Git Bash:

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
```

Windows PowerShell:

```powershell
$f="$env:TEMP\weline-bootstrap.ps1"; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' -OutFile $f; & $f
```

Tùy chọn thường dùng: `-b dev`, `-y`, `-f`, `--path-only`, `php`, `pgsql`, `mysql`.

## Cài Đặt Sạch

```bash
git clone https://gitee.com/aiweline/WelineFramework.git weline
cd weline
composer install
php bin/w command:upgrade
php bin/w system:install:sample
```

Khởi động server tích hợp Weline (WLS):

```bash
php bin/w server:start
```

## Lệnh Hữu Ích

| Lệnh | Mục đích |
|---|---|
| `php bin/w` | Liệt kê lệnh |
| `php bin/w setup:upgrade` | Nâng cấp module, schema và config |
| `php bin/w setup:upgrade --route` | Làm mới route sau khi đổi controller |
| `php bin/w server:start` | Khởi động server tích hợp Weline (WLS) |
| `php bin/w query:help <provider>` | Xem hợp đồng Query Provider |

## Tài Liệu

- [Tài liệu dự án](../README.md)
- [Tổng quan kiến trúc](../weline/架构总览.md)
- [Hướng dẫn phát triển](../开发文档.md)
- [Hướng dẫn triển khai](../部署文档.md)
- [Lối vào trợ lý AI](../../AI-README.md)

## Ghi Chú

Không chỉnh sửa trực tiếp artefact trong `generated/`. Không viết `routes.xml` thủ công. Văn bản hiển thị cho người dùng nên đi qua i18n. Test AI phải dùng instance WLS riêng trên cổng `9502+`, không dùng cổng mặc định `9501`.
