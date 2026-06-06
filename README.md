# WelineFramework

## 架构文档

- **[框架架构总览](./docs/weline/架构总览.md)**（分层、双运行时 FPM/WLS、路由、ORM、事件与扩展、模块约定、特性速查）
- **Weline 文档目录**：[docs/weline/README.md](./docs/weline/README.md)
- **项目文档索引**：[docs/README.md](./docs/README.md)

开发时若需极简 ASCII 图节省上下文，可见：`dev/ai/diagrams/00-INDEX.txt`。

## 如何安装（一键命令）

**推荐方式**：直接远程下载引导脚本并运行，由脚本完成克隆与安装（需已安装 [Git](https://git-scm.com)，未安装时安装脚本会尝试自动安装）。**无需先手动 clone。**

**Linux / macOS / Git Bash（复制整行到终端执行）：**

**请以当前用户执行，不要用 sudo**（Homebrew 禁止以 root 运行；需要权限时脚本会提示输入本机密码）：

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
```

指定分支（如 server-opt）：在末尾加 `-b server-opt`：

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s -- -b server-opt
```

**参数说明**：

- `-b <分支>`：指定克隆分支（默认为 master）
- `--path-only`：仅写入 PATH 环境变量
- `php` / `pgsql` / `mysql`：指定安装组件
- `-f`：强制重新安装，**会清除已有数据，请谨慎操作**
- `-y`：安装/升级过程中所有询问自动 yes，跳过确认提示

**Windows：系统默认不能直接运行 .sh，可用下面两种方式之一。**

- **方式一（推荐）**：安装 [Git for Windows](https://git-scm.com/download/win) 后，打开 **Git Bash**，执行上面同一条命令（`curl -fsSL ... | bash -s --`）。
- **方式二**：在 **PowerShell** 中执行（默认 master 分支）：

```powershell
iex (New-Object Net.WebClient).DownloadString('https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1')
```

若需指定分支，请先下载再带参数运行：  
`Invoke-WebRequest -Uri "https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1" -OutFile bootstrap.ps1; .\bootstrap.ps1 -Branch server-opt`

- 支持参数说明同上，`-f` 强制重新安装、**数据会被清空**，`-y` 全部 yes 自动确认。

- **方式三**：在 **CMD** 下无法直接运行 .sh/.ps1 时，使用 clone 后执行 bat：

```cmd
git clone https://gitee.com/aiweline/WelineFramework.git weline && cd weline && bin\install.bat
```

---

## 通用安装脚本说明（bin 目录）

| 脚本 | 说明 |
|------|------|
| **bin/install.bat** | **Windows 通用入口**：CMD / PowerShell 下直接运行，无需 Git Bash。在项目根执行 `bin\install.bat`。 |
| **bin/install** | **Linux/Mac/Git Bash 通用入口**：自动识别系统并执行对应安装。在项目根执行 `./bin/install`。 |
| **bin/install.sh** | Linux/Mac 安装逻辑，一般通过 `./bin/install` 调用，也可直接 `./bin/install.sh`。 |
| **bin/bootstrap.sh** | 一键引导（Linux/macOS/Git Bash）：克隆仓库并执行 install，用于 `curl \| bash`。 |
| **bin/bootstrap.ps1** | 一键引导（Windows PowerShell）：克隆仓库并执行 install.bat，用于 `iex (DownloadString(...))`。 |

- 安装脚本会安装 PHP 到 `extend/server/php`、配置 php.ini（含 openssl/sockets 等）、执行 composer、环境检测与数据库初始化。
- 支持参数：`-b <分支>` 指定克隆分支（缺省 master）；`--path-only` 仅写入 PATH；`php` / `pgsql` / `mysql` 指定安装组件；`-f` 强制重新安装（会清空已有数据）；`-y` 安装过程自动 yes 跳过所有确认。
- 每个项目会使用自己的 `extend/server/pgsql/data`。当 PostgreSQL 默认端口 5432 已被其他项目占用时，安装/启动流程会自动选择 5433+ 并同步到 `weline.env`、`postgresql.conf` 和 `app/etc/env.php`。

---

## 更新说明

更新内容

1. **新增Weline_Database企业级数据库迁移系统**
   - 支持版本控制、数据备份、安全回滚
   - 语义化命名规范：`{action}__{description}_{date}-{version}.php`
   - 智能数据备份和恢复，即使删除表结构也能完整恢复数据
   - 支持依赖管理、条件验证、批量操作
   - 命令行工具：`db:migrate:upgrade`、`db:migrate:rollback`、`db:migrate:status`

2. **新增Weline_Ai AI助手工具模块**
   - 统一的AI模型管理和多租户支持
   - 国际化、移动端、计费系统等企业级功能
   - ORM使用规范验证和静态代码分析工具
   - 综合错误处理中间件

3. 像素自支持和第三方像素对接支持
4. 优化支持URL结构：[网站]/[区域]/[货币]/[语言]/[路由]/[控制器]/[方法]/[参数]
   结合i18n的多语言支持可提供全球化静态SEO页面，提升全球本地化SEO权重，提升搜索引擎收录。
   提供精密结合CDN实现静态资源缓存，提升网站访问速度，提升搜索引擎收录。

发行版本：

v2.0 优化代码结构，内置服务器，优化框架升级，新增任务队列，计划任务，权限结构，标签结构后台参阅等。

v1.3 自定义标签，优化内核，上线translate快速翻译标签。

v1.1 解决初始化安装问题以及升级框架内核。

---

## 快速入门

### 使用本地命令行环境，快速开始

【注意】此环境仅用于快速搭建开发环境，不可直接用于生产环境。

若本机尚未安装 PHP，可先在项目根执行 **install** 脚本安装 PHP 到 extend/server 并配置环境变量：  
- **Windows CMD**：`bin\install.bat`  
- **Linux/Mac 或 Git Bash**：`./bin/install` 或 `./bin/install.sh`

运行命令：

```shell
# ----示例安装命令 开始----
composer create-project aiweline/weline-framework WelineFramework --prefer-dist
php bin/w command:upgrade
# 如果要换数据库请修改app/env.php数据配置，或者安装使用命令：php bin/w system:install  --db-type=mysql  --db-hostname=127.0.0.1  --db-database=weline  --db-username=weline  --db-password=weline --db-charset=utf8 --db-collate=utf8_general_ci --sandbox_db-type=mysql  --sandbox_db-hostname=127.0.0.1  --sandbox_db-database=sandbox_weline  --sandbox_db-username=sandbox_weline  --sandbox_db-password=sandbox_weline --db-charset=utf8mb4 --sandbox_db-collate=utf8mb4_general_ci
php bin/w setup:upgrade
php bin/w server:start  # 启动框架内置服务器
# ----示例安装命令 结束----
```

开发文档：https://gitee.com/aiweline/WelineFramework/wikis

#### 介绍

测试环境：https://weline.aiweline.com/

测试后台：http://weline.aiweline.com/admin_weline/admin/login 账户：admin 密码：admin

微蓝WelineFramework框架！

~~~
├── app                 # 应用目录
│   ├── code            # -代码
│   ├── design          # -主题
│   ├── etc             # -配置
│   └── i18n            # -语言包
├── bin                 # 命令目录（含 install 通用安装脚本、w 入口等）
├── dev                 # 开发目录
├── extend              # 拓展
├── generated           # 系统自动生成目录
│   ├── code            # -代码
│   ├── language        # -语言
│   └── routers         # -路由
├── pub                 # 公共
│   ├── errors          # -错误文件存放目录
│   ├── readme          # -关于
│   └── static          # -静态文件
├── setup               # 升级安装
│   ├── readme          # -关于
│   ├── static          # -升级安装时的静态目录
│   └── step            # -升级代码
├── var                 # 数据存放目录
│   ├── cache           # -缓存目录【仅文件缓存使用】
│   ├── log             # -日志目录
│   └── session         # -Session存放目录【仅文件session使用】
└── vendor              # Composer第三方拓展目录
~~~

#### 软件架构

    PHP>=8.1
    composer>=2
    nginx/apache
    mysql>=5.8
    mariadb>=10.6

#### 安装教程

composer下载源码

~~~
composer create-project aiweline/weline-framework WelineFramework --prefer-dist
~~~

###一、项目安装

    1.  WEB项目部署
    2.  无需设置繁杂的nginx（项目中有样例设置，include到配置中就可以）或者Apache设置（针对Apache项目中编写有伪静态），仅设置项目目录为部署目录即可。

###二、框架命令

    1.  模块安装命令 bin/m module:upgrade 此命令更新安装模块，以及模块数据。（将执行模块中的Setup\Install.php卸载脚本）
    2.  模块安装命令 bin/m module:disable <module_name> 此命令更新安装模块，以及模块数据。（将执行模块中的Setup\Install.php卸载脚本）
    3.  模块卸载命令 bin/m module:remove <module_name> 此命令备份模块并删除模块。（将执行模块中的Setup\Remove.php卸载脚本）
    4.  其他命令 php bin/m 回车可见

### 框架目的

开发优雅且高可用的框架：主要框架使用更加人性化，简单，灵活，快速。

### 框架特性

跨平台支持：Windows/linux。

**1、自带后台**

**1) acl权限管理。** get,post,delete,update等方法精细级别访问控制器<acl>标签支持块级内容可视控制。

**2）url管理。** 实现任何链接seo自由重写。

**3) i18n全球化词典管理。** 可自行安装国家地区，并收集前端词典进行翻译，运营人员即可完成翻译，也可以自行开发对接第三方api做自动化翻译。

**4）缓存控制器。** 分类型缓存管理，可以单独针对某个缓存进行管理。

**5）计划任务管理。** 收集管理各个模块中的计划任务，可实现解锁，上锁运行等操作。计划任务支持window和linux.

**6）事件管理。** 可以轻松查看正在运行的事件。

**7）插件管理。** 可以查看插件位置。

**8）模组管理。** 实时查看和禁用组件。

**9）SMTP管理。** 配置邮件SMTP。

**10）开发配置。** 内置开发文档，方便开发者查阅开发资料。内置两套开发模板，分别是前端和后端模板，可以快速成型项目。

**11）内容管理。** 设计运营人员可以自定义cms页面，支持前端模板和php代码直接在后台编写，实现ajax解析前端模板变量形成可预览页面，并支持发布版本控制。

**12）网站内测机制。** url添加sandbox_key参数将进入金丝雀机制，产生的数据将进入测试系统，不会污染正式系统，最好搭配ip段实现

2、ORM

**1）Model模型操作。**
Model模型使用魔术方法改造成查询器和数据容器，简化orm操作难度，自带归档数据，自带数据分页，自带树形结构数据返回函数，自解析表名，快速join，自定义附加sql,可在查询过程中定义复杂高级操作。

**2）Model模型数据源。** 支持框架一主多从作为数据源，也支持Model模型所在模组一主多从作为数据源。也就是Model可以从多个指定数据库读取数据，而非单一的从框架主库配置的数据库池子中读取，它可以有自己的数据库池。

**3）Model模型读写分离。** 可以从给定的主从数据库中读写分离。目前算法是随机算法，并未加入均衡器算法。

**3、自定义高性能模板渲染。**

**1）tab标签。** 支持常用的lang,if,foreach,else,block,template...等等,支持形式：<block .../>,@lang(...)
,@lang{...}。可以用事件自定义标签。

**2）缓存去标签化。** 标签一旦解析成为缓存模板【全部由php代码和html代码组成】，不会存在任何标签痕迹，下次读取时也不会再次解析【开发者环境下会一直读取】。

**3）模板去翻译化。** 语言由标签解析环节就生效，并存储到不同的语言目录，无需PHP代码再次翻译。减少PHP翻译过程。【实时翻译环境下会一直翻译】

**4）前端Hook机制。** 可以在页面中植入钩子，例如：<hook>head_after</hook>，模板引擎会自动解析这个钩子。

**4、容器**

**1）简化实例化过程。** 且附带实例化执行，自动解析初始化函数依赖，无需使用new ClassName().可以在__construct(
private \Weline\Demo\Model\Demo $demo)直接实例化$demo.

**2）依赖PHP8的注解解析。** 协助acl解析类或者方法注解，实现注解可直接执行。给出事件，方便控制做类型解析时解析或者执行注解类。作用，注解类直接执行可以实现参数检测，登录检测等快速检测。

**5、预置命令**

协调管理框架，具体可以php bin/m 查看所有命令和使用方法。
常见命名如下：

```
cache                         module # Weline_CacheManager
-cache:clear                         # 缓存清理。
-cache:flush                         # 缓存刷新。
-cache:reset                         # 重置缓存。（删除缓存：手动删除缓存file缓存请删除./var/cache）
-cache:status                        # 缓存状态。[enable/disable]:开启/关闭 [identify...]:缓存识别名
template                      module # Weline_CacheManager
-template:clear                      # 清理模板缓存！
phpunit                       module # Weline_DeveloperWorkspace
-phpunit:run                         # PhpUnite测试套件测试命令
i18n:collect                  module # Weline_I18n
-i18n:collect:realtime               # 是否实时收集翻译词典。[enable/disable]
i18n                          module # Weline_I18n
-i18n:collect                        # 收集翻译词
-i18n:locals                         # 查看本地语言码
maintenance                   module # Weline_Maintenance
-maintenance:disable                 # 关闭维护模式
-maintenance:enable                  # 开启维护模式
resource                      module # Weline_Theme
-resource:compiler                   # 编译资源
cache                         module # Weline_WarmCache
-cache:warm                          # 提供缓存预热,加速网页访问
command                       module # Aiweline_HelloWorld
-command:helloworld                  # 欢迎来到命令交互世界！
init                          module # Aiweline_Tool
-init:data                           # 初始化系统，重新安装
command                       module # Weline_Framework_Console
-command:upgrade                     # 更新命令
deploy:content                module # Weline_Framework_Console
-deploy:content:set                  # 设置静态文件状态
deploy:mode                   module # Weline_Framework_Console
-deploy:mode:set                     # 部署模式设置。（dev:开发模式；prod:生产环境。）
-deploy:mode:show                    # 查看部署环境
deploy                        module # Weline_Framework_Console
-deploy:upgrade                      # 静态资源同步更新。
dev                           module # Weline_Framework_Console
-dev:debug                           # 开发测试：用于运行测试对象！
dev:tool:phpcsfixer           module # Weline_Framework_Console
-dev:tool:phpcsfixer:disable         # 禁用php-cs-fixer代码美化工具
-dev:tool:phpcsfixer:enable          # 启用php-cs-fixer代码美化工具
dev:tool                      module # Weline_Framework_Console
-dev:tool:phpcsfixer                 # 代码美化工具
-dev:tool:staticfilerandversion      # 随机静态文件版本号：协助开发模式下实时刷新浏览器更新静态css,js,less等静态文件。
index                         module # Weline_Framework_Database
-index:reindex                       # 重建数据库表索引。示例：index:reindex weline_indexer （其中weline_indexer是模型索引器名，可以多个Model使用同一个索引器）
event:cache                   module # Weline_Framework_Event
-event:cache:clear                   # 清除系统事件缓存！
-event:cache:flush                   # 刷新系统事件缓存！
event                         module # Weline_Framework_Event
-event:cache                         # 事件缓存管理！-c：清除缓存；-f：刷新缓存。
-event:data                          # 事件观察者列表！具体模组的事件请在命令后写明。例如：（ php bin/m event:data Weline_Core Weline_Base）
module                        module # Weline_Framework_Module
-module:disable                      # 禁用模块
-module:enable                       # 模块启用
-module:listing                      # 查看模块列表
-module:remove                       # 移除模块以及模块数据！并执行卸载脚本（如果有）
-module:status                       # 获取模块列表
-module:upgrade                      # 升级模块
translate:model               module # Weline_Framework_Phrase
-translate:model:set                 # 设置翻译模式：online,实时翻译;default,缓存翻译。
plugin:cache                  module # Weline_Framework_Plugin
-plugin:cache:clear                  # 插件缓存清理！
plugin:di                     module # Weline_Framework_Plugin
-plugin:di:compile                   # 【插件】系统依赖编译
plugin:status                 module # Weline_Framework_Plugin
-plugin:status:set                   # 状态操作：0/1 0:关闭，1:启用
rpc                           module # Weline_Framework_Rpc
-rpc:start                           # 启动RPC服务。
setup:di                      module # Weline_Framework_Setup
-setup:di:compile                    # DI依赖编译
setup                         module # Weline_Framework_Setup
-setup:upgrade                       # 框架代码刷新。
system:install                module # Weline_Framework_System
-system:install:sample               # 安装脚本样例
system                        module # Weline_Framework_System
-system:install                      # 框架安装
```

**6、主题Theme。**

可以复写所有module中的模板，轻松实现自定义主题。

**7、自带Pixel像素。**
系统内置pixel像素，使用像素标签：

```html

<pixel name="xxx"></pixel>
```

配合元素class属性实现像素跟踪。例如添加购物车事件：add-to-cart,在按钮class中添加weline-pixel::
add-to-cart即可。同时需要指定值时只需要在class中添加weline-pixel::add-to-cart:value即可。
自定义事件：在元素class中添加weline-pixel::dianji-tijiao即可,需要指定值的话同理，在class中添加weline-pixel::dianji-tijiao:
value。
总之：格式是weline-pixel::{name}指定事件名，如果需要指定值，在class中添加weline-pixel::{name}:value即可，例如weline-pixel::
dianji-tijiao:value。

#### 使用说明

下载后解压，或者使用composer创建项目。
然后将项目文件拷贝到网站根目录，访问网站域名进入安装界面，配置好信息后安装完成会进入框架首页。

首页内有简单的介绍以及前后台，默认账户密码都是admin，进入后台后在用户管理内修改账户密码，以免账户信息泄露。

另外，请修改后端入口以及rest接口入口。修改位置：app/code/etc/env.php

## Weline_Database 企业级数据库迁移系统

### 核心特性

- **企业级稳定性**: 事务安全、依赖管理、错误恢复、状态跟踪
- **数据安全保障**: 智能备份、安全回滚、数据验证、版本控制
- **语义化命名**: 清晰的文件命名规范，便于管理和维护
- **智能数据恢复**: 即使删除表结构也能完整恢复数据

### 快速开始

#### 1. 查看迁移状态
```bash
php bin/w db:migrate:status --module=Weline_Ai
```

#### 2. 升级迁移
```bash
php bin/w db:migrate:upgrade --module=Weline_Ai --file=create_table__users_20250101-v1.0.0.php
```

#### 3. 回滚迁移
```bash
php bin/w db:migrate:rollback --module=Weline_Ai --file=create_table__users_20250101-v1.0.0.php
```

### 迁移脚本配置

#### 文件命名规范
```
{action}__{description}_{date}-{version}.php
```

**示例**:
- `create_table__users_20250101-v1.0.0.php` - 创建用户表
- `add_column__email_20250102-v1.0.1.php` - 添加邮箱字段
- `drop_column__raw_data_20250103-v1.0.2.php` - 删除原始数据字段

#### 迁移脚本模板
```php
<?php
namespace Weline\YourModule\Setup\Db\Migration;

use Weline\Database\Interface\MigrationInterface;
use Weline\Framework\Database\ConnectionFactory;

class YourMigrationClassName implements MigrationInterface
{
    private ConnectionFactory $connectionFactory;
    
    public function __construct(ConnectionFactory $connectionFactory)
    {
        $this->connectionFactory = $connectionFactory;
    }
    
    public function install(): bool
    {
        // 您的迁移逻辑
        return true;
    }
    
    public function uninstall(): bool
    {
        // 您的回滚逻辑
        return true;
    }
    
    public function getInfo(): array
    {
        return [
            'name' => '迁移名称',
            'description' => '迁移描述',
            'version' => '1.0.0',
            'date' => '2025-01-01',
            'author' => 'YourName'
        ];
    }
    
    public function validate(): bool
    {
        // 验证前置条件
        return true;
    }
    
    public function getDependencies(): array
    {
        // 返回依赖的迁移文件
        return [];
    }
    
    public function getDescription(): string
    {
        return '迁移描述';
    }
    
    public function getVersion(): string
    {
        return '1.0.0';
    }
    
    public function getDate(): string
    {
        return '2025-01-01';
    }
}
```

### 数据安全特性

#### 智能备份机制
```php
// 删除字段前自动备份数据
private function backupColumnData(string $table, string $column): array
{
    $connection = $this->connectionFactory->getConnection();
    $query = $connection->select()
        ->from($table, ['id', $column])
        ->where("{$column} IS NOT NULL");
    
    return $connection->fetchAll($query);
}
```

#### 安全回滚机制
```php
// 回滚时自动恢复数据
private function restoreColumnData(string $table, string $column, array $data): void
{
    if (empty($data)) {
        return;
    }
    
    $connection = $this->connectionFactory->getConnection();
    
    foreach ($data as $row) {
        $connection->update(
            $table,
            [$column => $row[$column]],
            ['id = ?' => $row['id']]
        );
    }
}
```

### 高级功能

- **依赖管理**: 自动检查迁移依赖关系
- **条件验证**: 验证迁移前置条件
- **批量操作**: 支持复杂的批量数据操作
- **事务安全**: 所有操作都在事务中执行
- **错误恢复**: 失败时自动回滚

### 文档资源

- [开发文档](app/code/Weline/Database/doc/开发/数据库迁移系统开发文档.md)
- [使用手册](app/code/Weline/Database/doc/用户/数据库迁移系统使用手册.md)

## Weline_Ai AI助手工具模块

### 核心功能

- **统一AI模型管理**: 支持多种AI提供商
- **多租户支持**: 完整的数据隔离和权限管理
- **国际化支持**: 多语言接口和内容本地化
- **移动端优化**: 推送通知和离线支持
- **企业级功能**: 计费系统、A/B测试、安全扫描

### ORM使用规范

- **严格合规**: 完全符合WelineFramework ORM标准
- **静态分析**: 自动检测ORM使用合规性
- **框架学习**: 深入学习WelineFramework源码
- **错误处理**: 处理所有类型错误的中间件

#### 升级指南

1、composer 直接 require 框架版本。
2、将环境设置为dev开发模式：php bin/w deploy:mode:set dev
3、composer update
4、php bin/w s:up
5、php bin/w deploy:mode:set prod

#### 参与贡献

1. Fork 本仓库
2. 新建 Feat_xxx 分支
3. 提交代码
4. 新建 Pull Request
5. 提交代码请联系我。

