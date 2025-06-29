<?php return [
    'menu#Weline_Backend' => [
        'menu:collect' => [
            'tip' => '收集菜单',
            'class' => 'Weline\\Backend\\Console\\Menu\\Collect',
            'type' => 'module',
            'module' => 'Weline_Backend',
        ],
    ],
    'user#Weline_Backend' => [
        'user:create' => [
            'tip' => '创建后台用户。php bin/w user:create --username=demo --email=demo@aiweline.com --password=123456',
            'class' => 'Weline\\Backend\\Console\\User\\Create',
            'type' => 'module',
            'module' => 'Weline_Backend',
        ],
    ],
    'user:reset#Weline_Backend' => [
        'user:reset:password' => [
            'tip' => '重置用户密码。php bin/w user:reset:password --email=demo@123.com --password=123456',
            'class' => 'Weline\\Backend\\Console\\User\\Reset\\Password',
            'type' => 'module',
            'module' => 'Weline_Backend',
        ],
    ],
    'cron#Weline_Cron' => [
        'cron:exist' => [
            'tip' => '查看系统定时任务是否存在。',
            'class' => 'Weline\\Cron\\Console\\Cron\\Exist',
            'type' => 'module',
            'module' => 'Weline_Cron',
        ],
        'cron:install' => [
            'tip' => '安装系统定时任务。',
            'class' => 'Weline\\Cron\\Console\\Cron\\Install',
            'type' => 'module',
            'module' => 'Weline_Cron',
        ],
        'cron:listing' => [
            'tip' => '查看系统定时任务是否存在。',
            'class' => 'Weline\\Cron\\Console\\Cron\\Listing',
            'type' => 'module',
            'module' => 'Weline_Cron',
        ],
        'cron:remove' => [
            'tip' => '移除系统定时任务。',
            'class' => 'Weline\\Cron\\Console\\Cron\\Remove',
            'type' => 'module',
            'module' => 'Weline_Cron',
        ],
        'cron:run' => [
            'tip' => '运行系统定时任务。',
            'class' => 'Weline\\Cron\\Console\\Cron\\Run',
            'type' => 'module',
            'module' => 'Weline_Cron',
        ],
    ],
    'cron:task#Weline_Cron' => [
        'cron:task:collect' => [
            'tip' => '收集注册调度任务',
            'class' => 'Weline\\Cron\\Console\\Cron\\Task\\Collect',
            'type' => 'module',
            'module' => 'Weline_Cron',
        ],
        'cron:task:listing' => [
            'tip' => '查看系统定时任务。',
            'class' => 'Weline\\Cron\\Console\\Cron\\Task\\Listing',
            'type' => 'module',
            'module' => 'Weline_Cron',
        ],
        'cron:task:run' => [
            'tip' => '运行计划调度任务。需要运行特定任务时：php bin/w cron:task:run demo demo_run 依次往后添加多个任务名 -f 选项强制解锁运行。',
            'class' => 'Weline\\Cron\\Console\\Cron\\Task\\Run',
            'type' => 'module',
            'module' => 'Weline_Cron',
        ],
    ],
    'phpunit#Weline_DeveloperWorkspace' => [
        'phpunit:run' => [
            'tip' => 'PhpUnite测试套件测试命令',
            'class' => 'Weline\\DeveloperWorkspace\\Console\\PhpUnit\\Run',
            'type' => 'module',
            'module' => 'Weline_DeveloperWorkspace',
        ],
    ],
    'i18n:collect#Weline_I18n' => [
        'i18n:collect:real-time' => [
            'tip' => '是否实时收集翻译词典。[enable/disable]',
            'class' => 'Weline\\I18n\\Console\\I18n\\Collect\\RealTime',
            'type' => 'module',
            'module' => 'Weline_I18n',
        ],
    ],
    'i18n#Weline_I18n' => [
        'i18n:collect' => [
            'tip' => '收集翻译词',
            'class' => 'Weline\\I18n\\Console\\I18n\\Collect',
            'type' => 'module',
            'module' => 'Weline_I18n',
        ],
        'i18n:locals' => [
            'tip' => '查看本地语言码',
            'class' => 'Weline\\I18n\\Console\\I18n\\Locals',
            'type' => 'module',
            'module' => 'Weline_I18n',
        ],
    ],
    'maintenance#Weline_Maintenance' => [
        'maintenance:disable' => [
            'tip' => '关闭维护模式',
            'class' => 'Weline\\Maintenance\\Console\\Maintenance\\Disable',
            'type' => 'module',
            'module' => 'Weline_Maintenance',
        ],
        'maintenance:enable' => [
            'tip' => '开启维护模式',
            'class' => 'Weline\\Maintenance\\Console\\Maintenance\\Enable',
            'type' => 'module',
            'module' => 'Weline_Maintenance',
        ],
    ],
    'queue#Weline_Queue' => [
        'queue:collect' => [
            'tip' => '从各个模组中收集队列类型数据',
            'class' => 'Weline\\Queue\\Console\\Queue\\Collect',
            'type' => 'module',
            'module' => 'Weline_Queue',
        ],
        'queue:run' => [
            'tip' => '运行队列. php bin/w queue:run --id=1',
            'class' => 'Weline\\Queue\\Console\\Queue\\Run',
            'type' => 'module',
            'module' => 'Weline_Queue',
        ],
    ],
    'queue:type#Weline_Queue' => [
        'queue:type:listing' => [
            'tip' => '列出所有队列类型数据，示例：php bin/w queue:type:listing [可选：搜索队列名称]',
            'class' => 'Weline\\Queue\\Console\\Queue\\Type\\Listing',
            'type' => 'module',
            'module' => 'Weline_Queue',
        ],
    ],
    'resource#Weline_Theme' => [
        'resource:compiler' => [
            'tip' => '编译资源',
            'class' => 'Weline\\Theme\\Console\\Resource\\Compiler',
            'type' => 'module',
            'module' => 'Weline_Theme',
        ],
    ],
    'theme#Weline_Theme' => [
        'theme:active' => [
            'tip' => '查看当前主题或者激活特定主题',
            'class' => 'Weline\\Theme\\Console\\Theme\\Active',
            'type' => 'module',
            'module' => 'Weline_Theme',
        ],
        'theme:listing' => [
            'tip' => '查看主题列表',
            'class' => 'Weline\\Theme\\Console\\Theme\\Listing',
            'type' => 'module',
            'module' => 'Weline_Theme',
        ],
        'theme:remove' => [
            'tip' => '卸载主题',
            'class' => 'Weline\\Theme\\Console\\Theme\\Remove',
            'type' => 'module',
            'module' => 'Weline_Theme',
        ],
        'theme:upgrade' => [
            'tip' => '更新主题文件！',
            'class' => 'Weline\\Theme\\Console\\Theme\\Upgrade',
            'type' => 'module',
            'module' => 'Weline_Theme',
        ],
    ],
    'cache#Weline_WarmCache' => [
        'cache:warm' => [
            'tip' => '提供缓存预热,加速网页访问',
            'class' => 'Weline\\WarmCache\\Console\\Cache\\Warm',
            'type' => 'module',
            'module' => 'Weline_WarmCache',
        ],
    ],
    'cache#Weline_Framework_Cache' => [
        'cache:clear' => [
            'tip' => '缓存清理。',
            'class' => 'Weline\\Framework\\Cache\\Console\\Cache\\Clear',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
        'cache:flush' => [
            'tip' => '缓存刷新。',
            'class' => 'Weline\\Framework\\Cache\\Console\\Cache\\Flush',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
        'cache:reset' => [
            'tip' => '重置缓存。（删除缓存：手动删除缓存file缓存请删除./var/cache）',
            'class' => 'Weline\\Framework\\Cache\\Console\\Cache\\Reset',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
        'cache:status' => [
            'tip' => '缓存状态。[enable/disable]:开启/关闭 [identify...]:缓存识别名',
            'class' => 'Weline\\Framework\\Cache\\Console\\Cache\\Status',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'template#Weline_Framework_Cache' => [
        'template:clear' => [
            'tip' => '清理模板缓存！',
            'class' => 'Weline\\Framework\\Cache\\Console\\Template\\Clear',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'command#Weline_Framework_Console' => [
        'command:upgrade' => [
            'tip' => '更新命令',
            'class' => 'Weline\\Framework\\Console\\Console\\Command\\Upgrade',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'deploy:content#Weline_Framework_Console' => [
        'deploy:content:set' => [
            'tip' => '设置静态文件状态',
            'class' => 'Weline\\Framework\\Console\\Console\\Deploy\\Content\\Set',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'deploy:mode#Weline_Framework_Console' => [
        'deploy:mode:set' => [
            'tip' => '部署模式设置。（dev:开发模式；prod:生产环境。）',
            'class' => 'Weline\\Framework\\Console\\Console\\Deploy\\Mode\\Set',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
        'deploy:mode:show' => [
            'tip' => '查看部署环境',
            'class' => 'Weline\\Framework\\Console\\Console\\Deploy\\Mode\\Show',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'deploy#Weline_Framework_Console' => [
        'deploy:upgrade' => [
            'tip' => '静态资源同步更新。',
            'class' => 'Weline\\Framework\\Console\\Console\\Deploy\\Upgrade',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    '#Weline_Framework_Console' => [
        'detail' => [
            'tip' => '查看命令详情，示例：php bin/w detail dev:debug',
            'class' => 'Weline\\Framework\\Console\\Console\\Detail',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'dev#Weline_Framework_Console' => [
        'dev:debug' => [
            'tip' => '开发测试：用于运行测试对象！',
            'class' => 'Weline\\Framework\\Console\\Console\\Dev\\Debug',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'dev:tool:phpcsfixer#Weline_Framework_Console' => [
        'dev:tool:phpcsfixer:disable' => [
            'tip' => '禁用php-cs-fixer代码美化工具',
            'class' => 'Weline\\Framework\\Console\\Console\\Dev\\Tool\\PhpCsFixer\\Disable',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
        'dev:tool:phpcsfixer:enable' => [
            'tip' => '启用php-cs-fixer代码美化工具',
            'class' => 'Weline\\Framework\\Console\\Console\\Dev\\Tool\\PhpCsFixer\\Enable',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'dev:tool#Weline_Framework_Console' => [
        'dev:tool:phpcsfixer' => [
            'tip' => '代码美化工具',
            'class' => 'Weline\\Framework\\Console\\Console\\Dev\\Tool\\PhpCsFixer',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
        'dev:tool:staticfilerandversion' => [
            'tip' => '随机静态文件版本号：协助开发模式下实时刷新浏览器更新静态css,js,less等静态文件。',
            'class' => 'Weline\\Framework\\Console\\Console\\Dev\\Tool\\StaticFileRandVersion',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'server#Weline_Framework_Console' => [
        'server:start' => [
            'tip' => '启用PHP内资本地WebServer服务。',
            'class' => 'Weline\\Framework\\Console\\Console\\Server\\Start',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'index#Weline_Framework_Database' => [
        'index:listing' => [
            'tip' => '索引器列表',
            'class' => 'Weline\\Framework\\Database\\Console\\Index\\Listing',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
        'index:reindex' => [
            'tip' => '重建数据库表索引。示例：index:reindex weline_indexer （其中weline_indexer是模型索引器名，可以多个Model使用同一个索引器）',
            'class' => 'Weline\\Framework\\Database\\Console\\Index\\Reindex',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'event:cache#Weline_Framework_Event' => [
        'event:cache:clear' => [
            'tip' => '清除系统事件缓存！',
            'class' => 'Weline\\Framework\\Event\\Console\\Event\\Cache\\Clear',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
        'event:cache:flush' => [
            'tip' => '刷新系统事件缓存！',
            'class' => 'Weline\\Framework\\Event\\Console\\Event\\Cache\\Flush',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'event#Weline_Framework_Event' => [
        'event:cache' => [
            'tip' => '事件缓存管理！-c：清除缓存；-f：刷新缓存。',
            'class' => 'Weline\\Framework\\Event\\Console\\Event\\Cache',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
        'event:data' => [
            'tip' => '事件观察者列表！具体模组的事件请在命令后写明。例如：（ php bin/w event:data Weline_Core Weline_Base）',
            'class' => 'Weline\\Framework\\Event\\Console\\Event\\Data',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'module#Weline_Framework_Module' => [
        'module:disable' => [
            'tip' => '禁用模块',
            'class' => 'Weline\\Framework\\Module\\Console\\Module\\Disable',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
        'module:enable' => [
            'tip' => '模块启用',
            'class' => 'Weline\\Framework\\Module\\Console\\Module\\Enable',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
        'module:listing' => [
            'tip' => '查看模块列表',
            'class' => 'Weline\\Framework\\Module\\Console\\Module\\Listing',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
        'module:remove' => [
            'tip' => '移除模块以及模块数据！并执行卸载脚本（如果有）',
            'class' => 'Weline\\Framework\\Module\\Console\\Module\\Remove',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
        'module:status' => [
            'tip' => '获取模块列表',
            'class' => 'Weline\\Framework\\Module\\Console\\Module\\Status',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
        'module:upgrade' => [
            'tip' => '升级模块.
  1. --mode[指定升级模式为数据库模型：支持的有model, route] --module Weline_Demo 升级指定模块.',
            'class' => 'Weline\\Framework\\Module\\Console\\Module\\Upgrade',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'translate:model#Weline_Framework_Phrase' => [
        'translate:model:set' => [
            'tip' => '设置翻译模式：online,实时翻译;default,缓存翻译。',
            'class' => 'Weline\\Framework\\Phrase\\Console\\Translate\\Model\\Set',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'plugin:cache#Weline_Framework_Plugin' => [
        'plugin:cache:clear' => [
            'tip' => '插件缓存清理！',
            'class' => 'Weline\\Framework\\Plugin\\Console\\Plugin\\Cache\\Clear',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'plugin:di#Weline_Framework_Plugin' => [
        'plugin:di:compile' => [
            'tip' => '【插件】系统依赖编译',
            'class' => 'Weline\\Framework\\Plugin\\Console\\Plugin\\Di\\Compile',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'plugin:status#Weline_Framework_Plugin' => [
        'plugin:status:set' => [
            'tip' => '状态操作：0/1 0:关闭，1:启用',
            'class' => 'Weline\\Framework\\Plugin\\Console\\Plugin\\Status\\Set',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'rpc#Weline_Framework_Rpc' => [
        'rpc:start' => [
            'tip' => '启动RPC服务。',
            'class' => 'Weline\\Framework\\Rpc\\Console\\Rpc\\Start',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'setup:di#Weline_Framework_Setup' => [
        'setup:di:compile' => [
            'tip' => 'DI依赖编译',
            'class' => 'Weline\\Framework\\Setup\\Console\\Setup\\Di\\Compile',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'setup#Weline_Framework_Setup' => [
        'setup:upgrade' => [
            'tip' => '框架代码刷新。',
            'class' => 'Weline\\Framework\\Setup\\Console\\Setup\\Upgrade',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'system:install#Weline_Framework_System' => [
        'system:install:sample' => [
            'tip' => '安装脚本样例',
            'class' => 'Weline\\Framework\\System\\Console\\System\\Install\\Sample',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
    'system#Weline_Framework_System' => [
        'system:install' => [
            'tip' => '框架安装',
            'class' => 'Weline\\Framework\\System\\Console\\System\\Install',
            'type' => 'framework',
            'module' => 'Weline_Framework',
        ],
    ],
];