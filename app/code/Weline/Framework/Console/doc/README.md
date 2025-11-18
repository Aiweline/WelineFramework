# Weline Framework 控制台命令系统文档

欢迎使用 Weline Framework 控制台命令系统文档！

## 📚 文档目录

### [命令开发指南.md](./命令开发指南.md)

完整的命令开发文档，包含：

- ✅ 命令系统概述
- ✅ 如何创建新命令（两种方式）
- ✅ 命令命名规则和文件位置
- ✅ 必需方法的实现
- ✅ **命令别名功能详解**
  - 使用 `aliases()` 方法
  - 使用 `ALIASES` 常量
  - 别名注册机制
  - 使用示例和最佳实践
- ✅ 命令帮助信息格式化
- ✅ 命令参数处理
- ✅ 完整示例代码
- ✅ 命令注册与更新

### [快速参考.md](./快速参考.md)

快速查阅文档，包含：

- ✅ 快速开始模板
- ✅ 命令别名快速参考
- ✅ 参数处理示例
- ✅ 输出方法速查
- ✅ 命令命名规则表
- ✅ 常用命令列表
- ✅ 检查清单

## 🚀 快速开始

### 创建第一个命令

1. **创建命令类文件**

```php
<?php

namespace YourModule\Console\Hello;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Output\Cli\Printing;

class World extends CommandAbstract
{
    public function __construct(Printing $printer)
    {
        $this->printer = $printer;
    }

    public function execute(array $args = [], array $data = []): void
    {
        $this->printer->success('Hello, World!');
    }

    public function tip(): string
    {
        return 'Hello World 命令';
    }

    public function help(): array|string
    {
        return '显示 Hello, World! 消息';
    }
}
```

2. **文件位置**

```
app/code/YourModule/Console/Hello/World.php
```

3. **更新命令列表**

```bash
php bin/w command:upgrade
```

4. **运行命令**

```bash
php bin/w hello:world
```

### 添加命令别名

在命令类中添加 `aliases()` 方法：

```php
public function aliases(): array
{
    return ['hw', 'hello'];
}
```

然后可以使用别名运行：

```bash
php bin/w hw
php bin/w hello
```

## 📖 核心概念

### 命令接口

所有命令必须实现 `CommandInterface` 接口，或继承 `CommandAbstract` 抽象类。

**必需方法**：
- `execute(array $args, array $data)` - 执行命令
- `tip(): string` - 返回命令简短描述
- `help(): array|string` - 返回命令帮助信息

### 命令别名

命令别名功能允许为命令定义简短的别名，方便快速调用。

**两种定义方式**：
1. **aliases() 方法**（推荐）- 动态返回别名数组
2. **ALIASES 常量** - 静态定义别名数组

**别名注册**：
- 在运行 `php bin/w command:upgrade` 时自动注册
- 别名不会覆盖已有命令
- 支持多个别名

## 🔍 相关文件

### 核心类文件

- `CommandInterface.php` - 命令接口定义
- `CommandAbstract.php` - 命令抽象基类
- `CliAbstract.php` - CLI 抽象基类
- `CommandHelper.php` - 帮助信息格式化工具
- `Command.php` - 命令管理类

### 命令扫描和注册

- `Console/Command/Upgrade.php` - 命令扫描和注册逻辑
  - `getDirFileCommand()` - 扫描命令文件
  - `registerAliases()` - 注册命令别名

## 📝 示例命令

### 框架内置命令示例

- `Console/Server/Start.php` - 启动开发服务器
- `Console/Command/Upgrade.php` - 更新命令列表
- `Console/Deploy/Mode/Set.php` - 设置部署模式

## 🛠️ 常用操作

### 查看所有命令

```bash
php bin/w
```

### 查看命令帮助

```bash
php bin/w <command> --help
```

### 更新命令列表

```bash
php bin/w command:upgrade
```

### 启动开发服务器

```bash
php bin/w server:start
```

## 💡 最佳实践

1. ✅ **使用 CommandAbstract** - 继承抽象类而不是直接实现接口
2. ✅ **使用 CommandHelper** - 格式化帮助信息，保持一致性
3. ✅ **实现 aliases() 方法** - 提供命令别名，方便使用
4. ✅ **参数验证** - 在 execute() 方法中验证必需参数
5. ✅ **清晰的错误提示** - 提供有用的错误消息和帮助信息
6. ✅ **更新命令列表** - 创建或修改命令后运行 `command:upgrade`

## 📞 获取帮助

- **详细文档**：查看 [命令开发指南.md](./命令开发指南.md)
- **快速参考**：查看 [快速参考.md](./快速参考.md)
- **代码示例**：查看框架内置命令实现

## 🔄 文档更新

本文档会随着框架版本更新而更新。如果发现文档有误或需要补充，请及时反馈。

---

**文档版本**：1.0.0  
**最后更新**：2025-01-08  
**维护者**：Weline Framework 开发团队

