

# WelineFramework

WelineFramework 是一个基于PHP的开源框架，旨在简化Web应用的开发流程，提供强大的MVC架构支持、模块化设计以及丰富的开发工具。

## 更新说明

### 示例安装命令
如果要换数据库请修改`app/env.php`数据配置，或者使用命令安装：
```bash
php bin/w system:install  --db-type=mysql  --db-hostname=127.0.0.1  --db-database=weline  --db-username=weline  --db-password=weline --db-charset=utf8 --db-collate=utf8_general_ci --sandbox_db-type=mysql  --sandbox_db-hostname=127.0.0.1  --sandbox_db-database=sandbox_weline  --sandbox_db-username=sandbox_weline  --sandbox_db-password=sandbox_weline --db-charset=utf8mb4 --sandbox_db-collate=utf8mb4_general_ci
```

## 介绍

WelineFramework 提供了快速开发Web应用程序的能力，支持模块化开发，具备清晰的代码结构以及良好的扩展性。它适合用于中小型Web应用的快速开发，也支持大型项目的模块化管理。

## 软件架构

- **MVC 架构**：框架采用经典的MVC架构，分离视图、控制器和模型。
- **模块化设计**：项目中的模块可以独立开发、部署和维护。
- **数据库支持**：支持多种数据库类型，通过`env.php`配置文件进行切换。
- **前端组件**：使用Dropzone、HTMX等前端组件支持文件上传和动态前端交互。
- **模板引擎**：支持PHTML模板，便于前后端分离。

## 安装教程

### 一、项目安装

1. **克隆项目**：通过Git将项目克隆到本地。
2. **配置数据库**：根据需求修改`app/env.php`文件中的数据库配置。
3. **使用安装命令安装**：运行如下命令进行安装：
   ```bash
   php bin/w system:install  --db-type=mysql  --db-hostname=127.0.0.1  --db-database=weline  --db-username=weline  --db-password=weline --db-charset=utf8 --db-collate=utf8_general_ci --sandbox_db-type=mysql  --sandbox_db-hostname=127.0.0.1  --sandbox_db-database=sandbox_weline  --sandbox_db-username=sandbox_weline  --sandbox_db-password=sandbox_weline --db-charset=utf8mb4 --sandbox_db-collate=utf8mb4_general_ci
   ```
4. **启动项目**：完成安装后，即可运行本地服务器开始开发。

### 二、框架命令

框架提供了便捷的命令行工具，包括：
- **系统安装命令**：`php bin/w system:install`，用于安装系统。
- **缓存管理**：支持清除和刷新缓存。
- **模块管理**：提供模块的安装、卸载功能。

## 框架目的

WelineFramework 的目标是简化Web开发流程，提供清晰的架构设计和强大的模块化支持，帮助开发者快速构建高性能、可扩展的应用程序。

## 桌面特性

- **模块化开发**：支持模块独立开发、部署。
- **数据库支持**：通过简单的配置即可切换数据库。
- **前端组件**：提供Dropzone、HTMX等组件，支持丰富的前端交互。
- **国际化支持**：通过`LocalModel`实现多语言支持。
- **缓存系统**：提供前端和后端缓存支持，提升性能。
- **表单验证与安全**：框架内置验证机制，确保数据安全。

## 使用说明

- **快速入门**：运行安装命令后，即可使用框架提供的MVC结构开发。
- **模块开发**：通过创建模块目录（如`app/code/WeShop/Store`），定义控制器、模型、视图完成模块开发。
- **缓存管理**：使用`BackendCacheFactory`和`FrontendCacheFactory`管理缓存。
- **前端交互**：利用`dropzone.js`等前端组件实现文件上传、动态交互功能。

## 升级指南

- 通过框架提供的命令行工具升级模块或系统。
- 修改`env.php`文件更新数据库配置。
- 使用`composer update`更新依赖库。

## 参与贡献

欢迎贡献代码、报告问题或提出建议。请遵循开源协议，并在贡献前确保代码符合规范。