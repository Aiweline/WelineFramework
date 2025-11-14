# 快速创建模组 - Hello World

## 摘要

本文档介绍如何在 WelineFramework 中快速创建一个基础模组，包括模组的基本结构、注册方式和验证方法。

## 什么是模组

模组（Module）是 WelineFramework 框架中的基本功能单元，每个模组都是一个独立的功能模块，可以包含控制器、模型、视图、服务等组件。模组采用模块化设计，支持独立开发、部署和维护。

## 为什么需要模组

模组化设计使得系统具有良好的可扩展性和可维护性。通过模组，开发者可以将功能拆分为独立的单元，便于代码复用、团队协作和系统升级。每个模组都有明确的职责边界，降低了系统复杂度。

## 目录文件结构

一个基础的模组目录结构如下：

```
app/code/Weline/HelloWorld/
├── register.php              # 模组注册文件（必需）
├── etc/                      # 配置文件目录
│   └── env.php              # 环境配置文件（可选）
└── Controller/              # 控制器目录（可选）
    └── Index.php            # 示例控制器
```

### 目录说明

- **register.php**: 模组注册文件，用于向框架注册模组信息，包括模组名称、版本、依赖等
- **etc/**: 配置文件目录，存放模组的配置文件
- **Controller/**: 控制器目录，存放控制器类文件

## 创建步骤

### 步骤1: 创建模组目录

在 `app/code/Weline/` 目录下创建 `HelloWorld` 目录：

```bash
mkdir -p app/code/Weline/HelloWorld
```

### 步骤2: 创建注册文件

在模组根目录创建 `register.php` 文件：

```php
<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,           // 注册类型：模组
    'Weline_HelloWorld',        // 模组名称（格式：Vendor_ModuleName）
    __DIR__,                    // 模组路径
    '1.0.0',                    // 版本号
    'Hello World 示例模组',      // 模组描述
    ['Weline_Framework']        // 依赖模组（至少依赖 Weline_Framework）
);
```

### 步骤3: 创建示例控制器（可选）

创建 `Controller/Index.php` 文件：

```php
<?php

declare(strict_types=1);

namespace Weline\HelloWorld\Controller;

use Weline\Framework\App\Controller\FrontendController;

class Index extends FrontendController
{
    public function index()
    {
        $this->assign('message', 'Hello World!');
        return $this->fetch();
    }
}
```

### 步骤4: 创建路由配置（可选）

创建 `etc/env.php` 文件配置路由：

```php
<?php

return [
    'router' => 'hello',  // 路由前缀
    'dependencies' => []
];
```

## 后续处理

### 1. 安装模组

创建完模组后，需要运行安装命令：

```bash
php bin/w module:upgrade
```

或者单独安装该模组：

```bash
php bin/w module:upgrade Weline_HelloWorld
```

### 2. 访问验证

如果创建了控制器，可以通过以下URL访问：

- 前端访问：`http://your-domain/{your-module|env.router}/index/index` 或 `http://your-domain/{your-module|env.router}/index` 或 `http://your-domain/{your-module|env.router}`
- **注意**：
  - 当控制器方法名为 `index()` 时，URL 末尾的 `index/index` 可以省略，框架会自动识别
  - `{your-module|env.router}` 表示如果 `etc/env.php` 中配置了 `router`（如示例中的 `hello`），则使用该值（转为小写），否则使用模块名转小写（下划线保留）。例如：`Weline_HelloWorld` → `weline_helloworld`

## 验证

### 验证模组是否注册成功

运行以下命令查看已安装的模组：

```bash
php bin/w module:list
```

如果看到 `Weline_HelloWorld` 在列表中，说明模组注册成功。

### 验证控制器是否可用

访问控制器URL，如果能看到 "Hello World!" 消息，说明控制器创建成功。

### 常见问题

1. **模组未显示在列表中**：检查 `register.php` 文件语法是否正确，确保使用了正确的命名空间
2. **控制器无法访问**：检查路由配置是否正确，确保运行了 `module:upgrade` 命令
3. **依赖错误**：确保依赖的模组已安装，特别是 `Weline_Framework`

