#### 更新说明
发行版本：

v2.0 优化代码结构，内置服务器，优化框架升级，新增任务队列，计划任务，权限结构，标签结构后台参阅等。

v1.3 自定义标签，优化内核，上线translate快速翻译标签。

v1.1 解决初始化安装问题以及升级框架内核。


# WelineFramework
## 快速入门
### 使用本地命令行环境，快速开始
【注意】此环境仅用于快速搭建开发环境，不可直接用于生产环境。
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

### 使用Docker环境，快速开始
【注意】此镜像不可直接用于生产环境。
拉取镜像，并使用镜像启动容器即可。内置宝塔面板，启动后直接访问http://127.0.0.1即可（网站后台(http://127.0.0.1/admin_123)账户：admin 密码：admin）。宝塔入口为：http://127.0.0.1:8888/weline 账户：weline 密码：weline
```
docker pull aiweline/weline:dev
# -d 后台运行 -p 端口映射 -it 命令行交互 -v 映射本地目录到docker容器中 
docker run -d --name weline -p 80:80 -p 8888:8888 -p 3306:3306 -p 888:888 -p 21:21 -p 22:22 -it -v E:\WelineFramework\DEV-workspace:/www/wwwroot/weline.com aiweline/weline:dev /bin/bash
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
├── bin                 # 命令目录
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
开发文档：https://gitee.com/aiweline/WelineFramework/wikis
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

 **11）内容管理。** 设计运营人员可以自定义cms页面，将支持前端模板和php代码直接在后台编写，实现ajax解析前端模板变量形成可预览页面。新增发布版本控制。（建设中...）

 **12）网站内测机制。** url添加sandbox_key参数将进入金丝雀机制，产生的数据将进入测试系统，不会污染正式系统，最好搭配ip段实现


2、ORM

 **1）Model模型操作。** Model模型使用魔术方法改造成查询器和数据容器，简化orm操作难度，自带归档数据，自带数据分页，自带树形结构数据返回函数，自解析表名，快速join，自定义附加sql,可在查询过程中定义复杂高级操作。

 **2）Model模型数据源。** 支持框架一主多从作为数据源，也支持Model模型所在模组一主多从作为数据源。也就是Model可以从多个指定数据库读取数据，而非单一的从框架主库配置的数据库池子中读取，它可以有自己的数据库池。

 **3）Model模型读写分离。** 可以从给定的主从数据库中读写分离。目前算法是随机算法，并未加入均衡器算法。


 **3、自定义高性能模板渲染。** 

 **1）tab标签。** 支持常用的lang,if,foreach,else,block,template...等等,支持形式：<block .../>,@lang(...),@lang{...}。可以用事件自定义标签。

 **2）缓存去标签化。** 标签一旦解析成为缓存模板【全部由php代码和html代码组成】，不会存在任何标签痕迹，下次读取时也不会再次解析【开发者环境下会一直读取】。

 **3）模板去翻译化。** 语言由标签解析环节就生效，并存储到不同的语言目录，无需PHP代码再次翻译。减少PHP翻译过程。【实时翻译环境下会一直翻译】

 **4）前端Hook机制。** 可以在页面中植入钩子，例如：<hook>head_after</hook>，模板引擎会自动解析这个钩子。


 **4、容器** 

 **1）简化实例化过程。** 且附带实例化执行，自动解析初始化函数依赖，无需使用new ClassName().可以在__construct(\Weline\Demo\Model\Demo $demo)直接实例化$demo.

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


#### 使用说明
下载后解压，或者使用composer创建项目。
然后将项目文件拷贝到网站根目录，访问网站域名进入安装界面，配置好信息后安装完成会进入框架首页。

首页内有简单的介绍以及前后台，默认账户密码都是admin，进入后台后在用户管理内修改账户密码，以免账户信息泄露。

另外，请修改后端入口以及rest接口入口。修改位置：app/code/etc/env.php


#### 升级指南
1、composer 直接 require 框架版本。
2、将环境设置为dev开发模式：php bin/m deploy:mode:set dev
3、composer update
4、php bin/m s:up
5、php bin/m deploy:mode:set prod

#### 参与贡献

1.  Fork 本仓库
2.  新建 Feat_xxx 分支
3.  提交代码
4.  新建 Pull Request
5.  提交代码请联系我。

