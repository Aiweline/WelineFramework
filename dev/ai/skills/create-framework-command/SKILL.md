---
name: create-framework-command
description: 创建控制台命令。CommandAbstract 继承、command:upgrade 注册、Console 目录结构。
globs:
  - "**/Console/**/*.php"
  - "**/Command/**/*.php"
alwaysApply: false
---

# create-framework-command（极简版）

## 何时使用

- 创建 CLI 命令
- 添加控制台功能
- 终端操作

## 必做

- 继承 CommandAbstract 或实现 CommandInterface
- 创建后执行 `php bin/w command:upgrade` 注册
- 命令放 Console/ 目录，命名空间符合规范

## 最小示例

```php
class YourCommand extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): mixed
    {
        // 业务逻辑
        return 0;
    }
}
```

```bash
php bin/w command:upgrade
```

## 禁止

- 创建命令后不执行 command:upgrade
- 直接修改 generated/commands.php
