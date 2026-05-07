# Weline_Bot

Weline_Bot provides bot roles, skills, schedules, chat sessions, memory models, and scenario adapters for the AI assistant module.

## Setup Contract

The module install script implements `Weline\Framework\Setup\InstallInterface` and its `setup()` method must accept `Weline\Framework\Setup\Data\Setup` plus `Weline\Framework\Setup\Data\Context`. This keeps clean setup compatible with the framework setup runner and prevents signature drift during `php bin/w setup:upgrade`.

## Validation

- `php -l app/code/Weline/Bot/Setup/Install.php`
- `php -d error_reporting=22527 -d display_errors=0 extend/phpunit.phar --bootstrap app/autoload.php --no-configuration --no-coverage app/code/Weline/Bot/Test/Unit/Setup/InstallSignatureTest.php`
