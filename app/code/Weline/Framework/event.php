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
    
    // ========== 模块升级事件 ==========
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
    
    // ========== 服务器事件 ==========
    'Weline_Framework_Server::start_after' => [
        'name' => __('服务器启动后'),
        'description' => __('服务器启动完成后触发，允许其他模块执行启动后的操作。'),
        'doc' => 'server/服务器启动后.md',
    ],
    'Weline_Framework_Server::stop_after' => [
        'name' => __('服务器停止后'),
        'description' => __('服务器停止后触发，允许其他模块执行停止后的清理操作。'),
        'doc' => 'server/服务器停止后.md',
    ],
    
    // ========== 路由事件 ==========
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
    
    // ========== 控制台事件 ==========
    'Weline_Framework_Console::compile' => [
        'name' => __('控制台编译'),
        'description' => __('在控制台编译时触发，允许其他模块执行编译相关操作。'),
        'doc' => 'console/控制台编译.md',
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
    ]
];