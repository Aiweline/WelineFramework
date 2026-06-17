# WelineFramework

[Языки](./README.md) | [简体中文](../../README.md)

WelineFramework — PHP-фреймворк для модульных веб-приложений, административных систем и коммерческих сценариев. Он объединяет модули, маршрутизацию, ORM, события/hooks, темы, backend ACL, i18n, долгоживущий сервис WLS и CLI-инструменты, чтобы бизнес-модули оставались расширяемыми и поддерживаемыми.

## Выберите Путь

- Новая локальная среда: используйте установщик в один шаг.
- PHP, Composer и база данных уже установлены: используйте чистую установку.
- Архитектура: [архитектура Weline](../weline/README.md).
- Работа AI / Codex: начните с [AI-ENTRY.md](../../AI-ENTRY.md).

## Требования

- PHP `^8.4`
- Composer `^2.7`
- MySQL / MariaDB / PostgreSQL
- Nginx / Apache или встроенный сервер Weline (WLS)

Запускайте установку от текущего пользователя. Не запускайте установщик в один шаг напрямую через `sudo`.

## Установка В Один Шаг

Linux / macOS / Git Bash:

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
```

Windows PowerShell:

```powershell
$f="$env:TEMP\weline-bootstrap.ps1"; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' -OutFile $f; & $f
```

Частые параметры: `-b dev`, `-y`, `-f`, `--path-only`, `php`, `pgsql`, `mysql`.

## Чистая Установка

```bash
git clone https://gitee.com/aiweline/WelineFramework.git weline
cd weline
composer install
php bin/w command:upgrade
php bin/w system:install:sample
```

Запуск встроенного сервера Weline (WLS):

```bash
php bin/w server:start
```

## Полезные Команды

| Команда | Назначение |
|---|---|
| `php bin/w` | Показать команды |
| `php bin/w setup:upgrade` | Обновить модули, схему и конфигурацию |
| `php bin/w setup:upgrade --route` | Обновить маршруты после изменений контроллеров |
| `php bin/w server:start` | Запустить встроенный сервер Weline (WLS) |
| `php bin/w query:help <provider>` | Проверить контракты Query Provider |

## Документация

- [Документация проекта](../README.md)
- [Обзор архитектуры](../weline/架构总览.md)
- [Руководство разработчика](../开发文档.md)
- [Руководство по развертыванию](../部署文档.md)
- [Вход для AI-ассистента](../../AI-README.md)

## Примечания

Не редактируйте артефакты `generated/` напрямую. Не создавайте `routes.xml` вручную. Видимый пользователю текст должен проходить через i18n. AI-тесты должны использовать изолированный экземпляр WLS на порту `9502+`, а не стандартный `9501`.
