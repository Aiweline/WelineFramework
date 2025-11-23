# module:create 命令使用文档

## 📖 命令概述

`module:create` 是 WelineFramework 框架提供的模块创建命令，用于快速创建模块模组。该命令支持交互式向导和二次操作模式，可以方便地创建新模块或对已存在的模块进行二次操作。

## 🎯 命令信息

**命令名称**: `module:create`

**命令简述**: 快速创建模块模组。支持交互式创建、控制器生成、高级定制等功能。

**功能描述**: 快速创建模块模组，支持交互式向导和二次操作

## 🔧 命令语法

```bash
php bin/w module:create [-m|--module=<模块名>] [-c|--check] [-h|--help]
```

## 📋 选项说明

| 选项 | 长选项 | 说明 |
|------|--------|------|
| `-m` | `--module=<模块名>` | 指定模块名称（格式：Vendor_ModuleName） |
| `-c` | `--check` | 检测指定模块的完整性 |
| `-h` | `--help` | 显示帮助信息 |

## 💡 使用示例

### 1. 交互式创建模块

不带任何参数运行命令，进入交互式创建向导：

```bash
php bin/w module:create
```

**说明**: 
- 命令会引导您逐步输入模块信息
- 如果系统中已有模块，会显示模块选择菜单
- 支持创建全新的模块

### 2. 指定模块名创建

直接指定模块名称创建模块：

```bash
php bin/w module:create -m Weline_Demo
```

或者使用长选项：

```bash
php bin/w module:create --module=Weline_Demo
```

**说明**:
- 如果模块已存在，会自动进入二次操作模式
- 如果模块不存在，会进入创建流程
- 模块名格式必须为 `Vendor_ModuleName`（例如：`Weline_Demo`）

### 3. 进入二次操作模式

对已存在的模块进行二次操作：

```bash
php bin/w module:create -m Weline_Demo
```

**说明**:
- 如果检测到模块的配置文件，会自动进入二次操作模式
- 可以继续添加控制器、模型、事件等组件
- 支持模块的增量开发

### 4. 检测模块完整性

检测指定模块的完整性：

```bash
php bin/w module:create -m Weline_Demo -c
```

或者：

```bash
php bin/w module:create -m Weline_Demo --check
```

**说明**:
- 检查模块的目录结构是否完整
- 验证模块配置文件是否正确
- 检测模块依赖关系

### 5. 查看帮助信息

查看命令的详细帮助信息：

```bash
php bin/w module:create -h
```

或者：

```bash
php bin/w module:create --help
```

## 📝 模块命名规范

模块名称必须遵循以下格式：

- **格式**: `Vendor_ModuleName`
- **规则**:
  - 必须以字母开头
  - 只能包含字母、数字和下划线
  - 必须包含一个下划线分隔符
  - 下划线前后都必须有字母或数字

**正确示例**:
- `Weline_Demo`
- `Weline_Admin`
- `Aiweline_Test`

**错误示例**:
- `weline-demo` (包含连字符)
- `WelineDemo` (缺少下划线)
- `_Demo` (以下划线开头)
- `Weline_` (下划线后为空)

## 🔄 工作流程

### 新建模块流程

1. **输入模块名称**
   - 交互式输入或通过 `-m` 参数指定
   - 验证模块名格式
   - 检查模块是否已存在

2. **创建模块目录结构**
   - 自动创建标准的模块目录结构
   - 生成必要的配置文件

3. **配置模块信息**
   - 设置模块版本号
   - 添加模块描述
   - 配置路由信息

4. **添加模块组件**（可选）
   - 创建控制器
   - 创建模型
   - 配置事件
   - 添加插件
   - 配置服务

### 二次操作模式

当模块已存在时，命令会进入二次操作模式，支持：

- 添加新的控制器
- 创建新的模型
- 配置事件监听器
- 添加插件
- 配置服务
- 设置模块继承关系

## 📁 模块目录结构

命令会自动创建以下标准目录结构：

```
app/code/Vendor/ModuleName/
├── Block/              # Block类目录
├── Console/            # 控制台命令目录
├── Controller/         # 控制器目录
├── doc/                # 文档目录
├── etc/                # 配置文件目录
│   ├── module.xml      # 模块配置文件
│   └── ...
├── Model/              # 模型目录
├── Observer/           # 观察者目录
├── Plugin/             # 插件目录
├── Setup/              # 安装脚本目录
├── view/               # 视图文件目录
└── registration.php    # 模块注册文件
```

## ⚠️ 注意事项

1. **模块名格式**: 必须严格遵守 `Vendor_ModuleName` 格式
2. **模块唯一性**: 模块名在系统中必须唯一
3. **配置文件**: 命令会在模块目录下创建配置文件，用于记录模块的创建和操作历史
4. **权限要求**: 确保对 `app/code` 目录有写入权限
5. **PHP版本**: 确保PHP版本符合框架要求

## 🔍 相关命令

- `module:enable` - 启用模块
- `module:disable` - 禁用模块
- `module:upgrade` - 升级模块
- `module:uninstall` - 卸载模块

## 📚 更多信息

- 模块开发完整指南: `app/code/Weline/Framework/doc/3-开发/模块开发完整指南.md`
- 快速开始指南: `app/code/Weline/Framework/doc/2-快速开始/02-快速创建模组-Hello World.md`
- 模块管理文档: `app/code/Weline/Framework/doc/2-快速开始/09-模组管理.md`

## 📞 技术支持

如有问题，请访问：
- 官方网站: https://aiweline.com
- 论坛: https://bbs.aiweline.com
- 邮箱: aiweline@qq.com

