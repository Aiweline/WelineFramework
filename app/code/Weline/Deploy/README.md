# Weline_Deploy 模块

## 简介

Weline_Deploy 模块提供了基于 Git 的自动部署功能，可以从远程仓库拉取代码并更新项目。

## 功能特性

- ✅ 从 Git 仓库自动拉取代码
- ✅ 支持多个分支部署
- ✅ 部署前自动备份
- ✅ 强制更新模式（丢弃本地修改）
- ✅ CNC清理和维护
- ✅ 配置文件管理

## 安装

1. 模块已包含在项目中，无需额外安装
2. 运行命令更新命令列表：
```bash
php bin/w command:upgrade
```

## 使用指南

### 1. 配置环境文件

在项目根目录创建 `.env` 文件（从 `.env.sample` 复制）：

```bash
cp .env.sample .env
```

编辑 `.env` 文件，配置以下必需信息：

```env
# Git 仓库配置
GIT_REPO_URL=https://github.com/your-username/your-repo.git
GIT_BRANCH=main
GIT_USERNAME=your_username
GIT_TOKEN=your_access_token

# 部署配置
BACKUP_BEFORE_DEPLOY=true
```

### 2. 执行部署

#### 基本部署
```bash
php bin/w deploy:build
```

#### 指定分支部署
```bash
php bin/w deploy:build -b develop
```

#### 强制更新（丢弃本地修改）
```bash
php bin/w deploy:build --force
```

#### 禁用备份
```bash
php bin/w deploy:build --no-backup
```

### 3. 查看帮助信息
```bash
php bin/w deploy:build --help
```

## 配置说明

### Git 配置

| 配置项 | 说明 | 是否必需 |
|--------|------|---------|
| GIT_REPO_URL | Git 仓库地址 | 是 |
| GIT_BRANCH | 分支名称（默认：main） | 否 |
| GIT_USERNAME | Git 用户名 | 否 |
| GIT_TOKEN | Git 访问令牌或密码 | 否 |

### 部署配置

| 配置项 | 说明 | 默认值 |
|--------|------|--------|
| DEPLOY_METHOD | 部署方法 | git |
| BACKUP_BEFORE_DEPLOY | 部署前是否备份 | true |
| CLEAN_BEFORE_DEPLOY | 部署前是否清理 | false |

## 工作流程

部署命令会执行以下步骤：

1. **读取配置** - 从 `.env` 文件读取配置
2. **验证配置** - 检查必需配置项是否存在
3. **备份项目** - 备份当前项目（可选）
4. **Git 操作** - 拉取远程代码
5. **清理维护** - 清理缓存等临时文件

## 核心更新排除目录

`php bin/w update:core` 只维护框架核心文件，不会拷贝以下项目级模块目录到目标项目：

- `app/code/Aiweline`
- `app/code/WeShop`

目标项目如需这些模块，应在目标项目中单独维护。

## 安全提示

1. ✅ 不要在代码中硬编码访问令牌
2. ✅ 使用 `.gitignore` 排除 `.env` 文件
3. ✅ 定期更新访问令牌
4. ✅ 在生产环境禁用自动备份

## 故障排查

### 问题：无法读取 .env 文件

**解决方案**：确保项目根目录存在 `.env` 文件，并且配置了正确的权限。

### 问题：Git 拉取失败

**解决方案**：
- 检查 Git 仓库地址是否正确
- 检查网络连接
- 验证访问令牌是否有效
- 如果有本地修改冲突，使用 `--force` 参数

### 问题：备份失败

**解决方案**：
- 确保系统安装了 tar 或 zip 工具
- 检查备份目录权限

## 开发

### 目录结构

```
app/code/Weline/Deploy/
├── register.php           # 模块注册文件
├── composer.json          # 模块依赖
├── Console/               # 命令行工具
│   └── Deploy/
│       └── Build.php      # 部署命令
└── README.md             # 本文档
```

### 贡献

欢迎提交 Issue 和 Pull Request！

## 许可证

MIT License

