<?php
return [
    // ========== 应用生命周期事件 ==========
    'Weline_Framework::App::run_before' => [
        'name' => __('应用运行前'),
        'description' => __('应用运行前，你可以在这里做一些初始化操作。'),
        'doc' => 'app/应用运行前.md',
    ],
    'Weline_Framework::App::run_after' => [
        'name' => __('应用运行后'),
        'description' => __('应用运行后，你可以在这里做一些后续操作。'),
        'doc' => 'app/应用运行后.md',
    ],
    'Weline_Framework::App::url_parsed_after' => [
        'name' => __('URL解析后'),
        'description' => __('URL解析后，你可以在这里做一些后续操作。'),
        'doc' => 'app/URL解析后.md',
    ],
    
    // ========== 国际化事件 ==========
    'Weline_Framework::get_words_file' => [
        'name' => __('获取翻译文件'),
        'description' => __('在获取国际化翻译文件时触发，允许其他模块自定义翻译文件路径。'),
        'doc' => 'phrase/获取翻译文件.md',
    ],
    
    // ========== 系统升级事件 ==========
    'Weline_Framework_Setup::upgrade_after' => [
        'name' => __('系统升级后'),
        'description' => __('系统升级完成后触发，允许其他模块执行升级后的操作。'),
        'doc' => 'setup/系统升级后.md',
    ],
    'Weline_Framework_System::system_update_after' => [
        'name' => __('系统更新后'),
        'description' => __('系统更新完成后触发，允许其他模块执行更新后的操作。'),
        'doc' => 'system/系统更新后.md',
    ],
    
    // ========== 模块生命周期事件 ==========
    'Weline_Framework_Module::module_upgrade_before' => [
        'name' => __('模块升级前'),
        'description' => __('模块升级前触发，允许其他模块在升级前执行必要的操作。'),
        'doc' => 'module/模块升级前.md',
    ],
    'Weline_Framework_Module::module_upgrade' => [
        'name' => __('模块升级'),
        'description' => __('模块升级时触发，允许其他模块监听模块升级过程。'),
        'doc' => 'module/模块升级.md',
    ],
    'Weline_Framework_Module::module_install_after' => [
        'name' => __('模块安装后'),
        'description' => __('模块安装完成后触发，允许其他模块执行安装后的操作。'),
        'doc' => 'module/模块安装后.md',
    ],
    'Framework_Module::module_uninstall_after' => [
        'name' => __('模块卸载后'),
        'description' => __('模块卸载完成后触发，允许其他模块在卸载后执行清理和后续操作。'),
        'doc' => 'module/模块卸载后.md',
    ],
    
    // 服务器启动/停止事件已迁移至 Weline_Server 模块（Weline_Server::start_after / Weline_Server::stop_after）
    
    // ========== 路由事件 ==========
    'Weline_Framework_Router::before_start' => [
        'name' => __('路由开始前'),
        'description' => __('在路由开始处理之前触发，允许其他模块在路由开始前执行操作（如维护模式检查、全局访问控制等）。'),
        'doc' => 'router/路由开始前.md',
    ],
    'Weline_Framework_Router::process_uri_before' => [
        'name' => __('URI处理前'),
        'description' => __('在处理URI之前触发，允许其他模块修改URI或执行预处理。'),
        'doc' => 'router/URI处理前.md',
    ],
    'Weline_Framework_Router::route_before' => [
        'name' => __('路由处理前'),
        'description' => __('在路由处理之前触发，允许其他模块在路由前执行操作。'),
        'doc' => 'router/路由处理前.md',
    ],
    'Weline_Framework_Router::route_after' => [
        'name' => __('路由处理后'),
        'description' => __('在路由处理完成后触发，允许其他模块修改路由结果。'),
        'doc' => 'router/路由处理后.md',
    ],
    'Weline_Framework_Router::backend_whitelist_url' => [
        'name' => __('后端白名单URL'),
        'description' => __('在后端控制器初始化时触发，允许其他模块添加后端白名单URL。'),
        'doc' => 'router/后端白名单URL.md',
    ],
    'Weline_Framework_Router::backend_no_login_redirect_url' => [
        'name' => __('后端未登录重定向URL'),
        'description' => __('在后端控制器初始化时触发，允许其他模块添加未登录时的重定向URL。'),
        'doc' => 'router/后端未登录重定向URL.md',
    ],
    
    // ========== URL事件 ==========
    'Weline_Framework_Url::detect_language' => [
        'name' => __('检测语言'),
        'description' => __('在URL解析时检测语言时触发，允许其他模块自定义语言检测逻辑。'),
        'doc' => 'url/检测语言.md',
    ],
    'Weline_Framework_Url::detect_currency' => [
        'name' => __('检测货币'),
        'description' => __('在URL解析时检测货币时触发，允许其他模块自定义货币检测逻辑。'),
        'doc' => 'url/检测货币.md',
    ],
    'Weline_Framework_Url::detect_website' => [
        'name' => __('检测网站'),
        'description' => __('在URL解析时检测网站时触发，允许其他模块自定义网站检测逻辑。'),
        'doc' => 'url/检测网站.md',
    ],
    'Weline_Framework_Url::url_generate_rewrite' => [
        'name' => __('URL生成重写'),
        'description' => __('在生成URL重写规则时触发，允许其他模块自定义URL重写规则。'),
        'doc' => 'url/URL生成重写.md',
    ],
    'Weline_Framework_Url::seo_decode' => [
        'name' => __('SEO解码'),
        'description' => __('在URL SEO解码时触发，允许其他模块自定义SEO解码逻辑。'),
        'doc' => 'url/SEO解码.md',
    ],
    
    // ========== 视图事件 ==========
    'Weline_Framework_View::fetch_file' => [
        'name' => __('视图文件获取'),
        'description' => __('在获取视图文件时触发，允许其他模块自定义视图文件路径。可以通过修改 filename 字段来改变要加载的视图文件。'),
        'doc' => 'view/视图文件获取.md',
    ],
    // 动态事件：使用 {position} 表示动态位置，可以匹配 Framework_View::head、Framework_View::footer 等
    'Framework_View::{position}' => [
        'name' => __('视图位置'),
        'description' => __('在渲染视图指定位置时触发，允许其他模块注入该位置的内容。position 可以是 head、footer 等。'),
        'doc' => 'view/视图位置.md',
    ],
    'Weline_Framework_Template::after_tags_config' => [
        'name' => __('标签配置后'),
        'description' => __('在模板标签配置完成后触发，允许其他模块修改标签配置。'),
        'doc' => 'template/标签配置后.md',
    ],
    'Weline_Framework_Template::after_compile' => [
        'name' => __('模板编译后'),
        'description' => __('在模板编译完成后触发，允许其他模块处理编译后的模板内容。可以修改模板内容、提取信息、注入代码等。'),
        'doc' => 'template/模板编译后.md',
    ],
    'Weline_Framework_Template::after_render' => [
        'name' => __('模板渲染后'),
        'description' => __('在模板完成渲染（输出HTML）之后触发，允许其他模块对最终HTML进行分析、提取静态资源、注入追踪代码等操作。注意：此时不应再出现PHP代码，只能处理纯HTML字符串。'),
        'doc' => 'template/模板渲染后.md',
    ],
    
    // ========== 控制器事件 ==========
    'Weline_Framework_Controller::fetch_file_before' => [
        'name' => __('控制器模板获取前'),
        'description' => __('在控制器获取模板文件前触发，允许其他模块修改模板文件路径或执行预处理操作。事件数据包含 fileName、controller、layoutType 等信息。'),
        'doc' => 'controller/控制器模板获取前.md',
    ],
    'Weline_Framework_Controller::fetch_file_after' => [
        'name' => __('控制器模板获取后'),
        'description' => __('在控制器获取模板文件后触发，允许其他模块修改渲染后的模板内容。事件数据包含 fileName、content 等信息。'),
        'doc' => 'controller/控制器模板获取后.md',
    ],
    
    // ========== 缓存事件 ==========
    'Weline_Framework_Cache::integration::cache_flushed' => [
        'name' => __('缓存清理完成'),
        'description' => __('当 CacheFactory 的 flush() 或 clear() 被调用后触发。允许其他模块（如 Server）监听缓存变更并执行后续操作（如通知 WLS Worker 重载内存缓存）。在 HTTP 请求和 CLI 环境下均会触发。'),
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'identity' => ['type' => 'string', 'required' => true, 'description' => '缓存实例标识（如 router_cache、theme_cache 等）'],
            'operation' => ['type' => 'string', 'required' => true, 'description' => '操作类型：flush 或 clear'],
            'tip' => ['type' => 'string', 'required' => false, 'description' => '缓存说明'],
        ],
    ],
    'Weline_Framework_Cache::driver_create_before' => [
        'name' => __('缓存驱动创建前'),
        'description' => __('在创建缓存驱动实例前触发，允许其他模块（如 Weline_Server）接管驱动，例如 WLS 模式下将 File 缓存替换为内存缓存。'),
        'doc' => 'cache/driver_create_before.md',
    ],
    'Weline_Framework_Session::driver_create_before' => [
        'name' => __('Session 驱动创建前'),
        'description' => __('在创建 Session 驱动实例前触发，允许其他模块（如 Weline_Server）接管驱动，例如 WLS 模式下将 File Session 替换为内存 Session。'),
        'doc' => 'session/driver_create_before.md',
    ],
    'Weline_Framework_Session::storage_resolve' => [
        'name' => __('Session 存储解析'),
        'description' => __('在 SessionFactory 解析存储类型时触发，允许外部模块（如 WLS）声明自己的存储类型和配置。实现 Session 模块与具体存储后端的解耦。'),
        'doc' => 'session/storage_resolve.md',
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'storage_type' => ['type' => 'string|null', 'required' => false, 'description' => '存储类型（如 wls, file, redis 等），由 Observer 设置'],
            'storage_config' => ['type' => 'array', 'required' => false, 'description' => '存储配置数组，由 Observer 提供'],
        ],
    ],
    
    // ========== 控制台事件 ==========
    'Weline_Framework_Console::compile' => [
        'name' => __('控制台编译'),
        'description' => __('在控制台编译时触发，允许其他模块执行编译相关操作。'),
        'doc' => 'console/控制台编译.md',
    ],
    'Weline_Framework::cli::command_executed' => [
        'name' => __('CLI命令执行完成'),
        'description' => __('在CLI命令执行完成后触发，允许其他模块监听命令执行并执行后续操作（如通知WLS Worker重载）。'),
        'doc' => 'console/CLI命令执行完成.md',
        'version' => '1.0.0',
        'type' => 'application',
        'data_contract' => [
            'command' => ['type' => 'string', 'required' => true, 'description' => '执行的命令名称'],
            'args' => ['type' => 'array', 'required' => true, 'description' => '命令参数'],
        ],
    ],
    
    
    // ========== REST控制器事件 ==========
    'Weline_Framework_RestController::init_before' => [
        'name' => __('REST控制器初始化前'),
        'description' => __('在REST控制器初始化前触发，允许其他模块在初始化前执行操作。'),
        'doc' => 'controller/REST控制器初始化前.md',
    ],
    'Weline_Framework_RestController::init_after' => [
        'name' => __('REST控制器初始化后'),
        'description' => __('在REST控制器初始化后触发，允许其他模块在初始化后执行操作。'),
        'doc' => 'controller/REST控制器初始化后.md',
    ],
    
    // ========== 应用控制器事件 ==========
    'Weline_Framework_App::backend_controller_init_before' => [
        'name' => __('后端控制器初始化前'),
        'description' => __('在后端控制器初始化前触发，允许其他模块在初始化前执行操作。'),
        'doc' => 'app/后端控制器初始化前.md',
    ],
    'Weline_Framework_App::backend_controller_init_after' => [
        'name' => __('后端控制器初始化后'),
        'description' => __('在后端控制器初始化后触发，允许其他模块在初始化后执行操作。'),
        'doc' => 'app/后端控制器初始化后.md',
    ],
    'Weline_Framework_FrontendController::init_before' => [
        'name' => __('前端控制器初始化前'),
        'description' => __('在前端控制器初始化前触发，允许其他模块在初始化前执行操作。'),
        'doc' => 'controller/前端控制器初始化前.md',
    ],
    'Weline_Framework_FrontendController::init_after' => [
        'name' => __('前端控制器初始化后'),
        'description' => __('在前端控制器初始化后触发，允许其他模块在初始化后执行操作。'),
        'doc' => 'controller/前端控制器初始化后.md',
    ],
    'Weline_Framework_FrontendRestController::init_before' => [
        'name' => __('前端REST控制器初始化前'),
        'description' => __('在前端REST控制器初始化前触发，允许其他模块在初始化前执行操作。'),
        'doc' => 'controller/前端REST控制器初始化前.md',
    ],
    'Weline_Framework_FrontendRestController::init_after' => [
        'name' => __('前端REST控制器初始化后'),
        'description' => __('在前端REST控制器初始化后触发，允许其他模块在初始化后执行操作。'),
        'doc' => 'controller/前端REST控制器初始化后.md',
    ],
    
    // ========== 模块事件 ==========
    'Weline_Framework_Module::controller_attributes' => [
        'name' => __('控制器属性'),
        'description' => __('在解析控制器属性时触发，允许其他模块修改控制器属性。'),
        'doc' => 'module/控制器属性.md',
    ],
    
    // ========== Cookie事件 ==========
    'Weline_Framework_Cookie::lang_local' => [
        'name' => __('Cookie语言本地化'),
        'description' => __('在设置Cookie语言本地化时触发，允许其他模块自定义语言本地化逻辑。'),
        'doc' => 'cookie/Cookie语言本地化.md',
    ],
    
    // ========== ACL事件 ==========
    'Weline_Framework_Acl::dispatch' => [
        'name' => __('ACL分发'),
        'description' => __('在ACL权限检查时触发，允许其他模块自定义权限检查逻辑。'),
        'doc' => 'acl/ACL分发.md',
    ],
    
    // ========== 数据库事件 ==========
    'Weline_Framework_Database::indexer' => [
        'name' => __('数据库索引器'),
        'description' => __('在数据库索引操作时触发，允许其他模块执行索引相关操作。'),
        'doc' => 'database/数据库索引器.md',
    ],
    'Weline_Framework_Database::indexer_listing' => [
        'name' => __('数据库索引器列表'),
        'description' => __('在获取数据库索引器列表时触发，允许其他模块添加自定义索引器。'),
        'doc' => 'database/数据库索引器列表.md',
    ],
    'Weline_Framework_Database::model_update_before' => [
        'name' => __('模型更新前'),
        'description' => __('在数据库模型更新前触发，允许其他模块在更新前执行操作。'),
        'doc' => 'database/模型更新前.md',
    ],
    'Weline_Framework_Database::model_update_after' => [
        'name' => __('模型更新后'),
        'description' => __('在数据库模型更新后触发，允许其他模块在更新后执行操作。'),
        'doc' => 'database/模型更新后.md',
    ],
    
    // ========== 模型动态事件 ==========
    // 动态事件：使用 {table_name} 表示动态表名，可以匹配任何表名的事件
    '{table_name}_model_load_before' => [
        'name' => __('模型加载前'),
        'description' => __('在模型加载数据前触发，允许其他模块在加载前执行操作。{table_name} 是动态表名，例如：user_model_load_before。'),
        'doc' => 'model/模型加载前.md',
    ],
    '{table_name}_model_load_after' => [
        'name' => __('模型加载后'),
        'description' => __('在模型加载数据后触发，允许其他模块在加载后执行操作。{table_name} 是动态表名，例如：user_model_load_after。'),
        'doc' => 'model/模型加载后.md',
    ],
    '{model_class}_model_save_before' => [
        'name' => __('模型保存前'),
        'description' => __('在模型保存数据前触发，允许其他模块在保存前执行操作。{model_class} 是模型类名（下划线格式），例如：Weline_Admin_Model_User_model_save_before。'),
        'doc' => 'model/模型保存前.md',
    ],
    '{model_class}_model_save_after' => [
        'name' => __('模型保存后'),
        'description' => __('在模型保存数据后触发，允许其他模块在保存后执行操作。{model_class} 是模型类名（下划线格式），例如：Weline_Admin_Model_User_model_save_after。'),
        'doc' => 'model/模型保存后.md',
    ],
    '{table_name}_model_delete_before' => [
        'name' => __('模型删除前'),
        'description' => __('在模型删除数据前触发，允许其他模块在删除前执行操作。{table_name} 是动态表名，例如：user_model_delete_before。'),
        'doc' => 'model/模型删除前.md',
    ],
    '{table_name}_model_delete_after' => [
        'name' => __('模型删除后'),
        'description' => __('在模型删除数据后触发，允许其他模块在删除后执行操作。{table_name} 是动态表名，例如：user_model_delete_after。'),
        'doc' => 'model/模型删除后.md',
    ],
    
    // ========== 注册事件 ==========
    'Weline_Framework_Register::register_installer' => [
        'name' => __('注册安装器'),
        'description' => __('在注册模块安装器时触发，允许其他模块自定义安装器路径。'),
        'doc' => 'register/注册安装器.md',
    ],
    
    // ========== HTTP事件 ==========
    'Weline_Framework_Http::integration::client_ip_keys' => [
        'name' => __('客户端IP头Keys收集'),
        'description' => __('在解析客户端真实IP前触发，允许CDN模块等通过观察者注册 $_SERVER keys。Framework 提供基础 keys，观察者应 prepend 其专有 keys（如 HTTP_CF_CONNECTING_IP），以实现任意 CDN 供应商兼容。'),
        'doc' => 'http/客户端IP头Keys收集.md',
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'keys' => ['type' => 'array', 'required' => true, 'description' => '用于解析真实IP的 $_SERVER keys，按优先级排序。观察者应 array_unshift 追加其 keys。'],
        ],
    ],
    'Weline_Framework_Http::process_area' => [
        'name' => __('处理区域'),
        'description' => __('在处理HTTP请求区域时触发，允许其他模块自定义区域处理逻辑。'),
        'doc' => 'http/处理区域.md',
    ],
    'Weline_Framework_Http::http_response_no_router_before' => [
        'name' => __('响应无路由前'),
        'description' => __('在响应无路由请求前触发，允许其他模块处理无路由请求。'),
        'doc' => 'http/响应无路由前.md',
    ],
    'Framework_Http::response_redirect_before' => [
        'name' => __('响应重定向前'),
        'description' => __('在HTTP响应重定向前触发，允许其他模块修改重定向URL。'),
        'doc' => 'http/响应重定向前.md',
    ],
    
    // ========== 资源编译事件 ==========
    'Weline_Framework_Resource::compiler' => [
        'name' => __('资源编译器'),
        'description' => __('在编译资源文件时触发，允许其他模块监听并处理资源编译过程。事件数据包含区域、类型、资源列表等信息。'),
        'doc' => 'resource/资源编译器.md',
    ],
    'Framework_Resource::compiler_after' => [
        'name' => __('资源编译后'),
        'description' => __('在所有资源文件编译完成后触发，允许其他模块执行编译后的处理操作。事件数据包含资源类型和所有区域的资源内容。'),
        'doc' => 'resource/资源编译后.md',
    ],
    
    // ========== 卸载服务事件 ==========
    'Weline_Framework_UninstallService::uninstall' => [
        'name' => __('卸载服务'),
        'description' => __('在卸载服务执行卸载操作时触发，允许其他模块监听卸载过程并执行相应的清理操作。'),
        'doc' => 'uninstall/卸载服务.md',
    ],
    
    // ========== 部署模式事件 ==========
    'Weline_Framework_Deploy_Mode_Set::prod_after' => [
        'name' => __('部署模式切换到生产环境后'),
        'description' => __('在部署模式切换到生产环境(prod)后触发，允许其他模块执行生产环境相关的操作，如生成加密token等。'),
        'doc' => 'deploy/部署模式切换到生产环境后.md',
    ],

    // ========== 动态模块查询事件（无需各模块单独注册） ==========
    '{Module}::query' => [
        'name' => __('模块查询'),
        'description' => __('动态事件：任意 Module_Name::query 触发时，自动路由到 FrameworkQueryService::execute()，由 QueryProviderRegistry 中已注册的查询器处理。模块通过 extends 注册 QueryProviderInterface 实现即可。'),
        'doc' => 'query/模块查询动态事件.md',
    ],

    // ========== Query 统一查询事件 ==========
    'Weline_Framework_Query::before_execute' => [
        'name' => __('统一查询执行前'),
        'description' => __('统一查询执行前触发，可用于鉴权、参数校验、限流与拦截。'),
        'doc' => 'query/统一查询执行前.md',
    ],
    'Weline_Framework_Query::provider_execute' => [
        'name' => __('【已废弃】统一查询执行提供者'),
        'description' => __('已废弃：查询由 extends 注册的查询器（QueryProviderInterface）执行，不再通过事件分发。请实现 QueryProviderInterface 并在 extends/module/Weline_Framework/Query/ 下注册。'),
        'doc' => 'query/统一查询执行提供者.md',
    ],
    'Weline_Framework_Query::after_execute' => [
        'name' => __('统一查询执行后'),
        'description' => __('统一查询执行后触发，可用于结果过滤和审计。'),
        'doc' => 'query/统一查询执行后.md',
    ]
];