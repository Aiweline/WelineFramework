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
    'Weline_Framework_Runtime::worker_bootstrap_after' => [
        'name' => __('WLS worker bootstrap after'),
        'description' => __('Triggered once after each WLS worker has loaded framework registries. Observers must only warm request-independent runtime caches.'),
        'doc' => 'runtime/worker_bootstrap_after.md',
    ],
    'Weline_Framework::telemetry::request_collected' => [
        'name' => __('请求遥测数据已采集'),
        'description' => __('在一次请求生命周期结束时触发。Framework 仅广播通用遥测快照（trace、request、runtime、summary、result），由上层模块（如 DeveloperWorkspace、监控模块）自行监听并处理。'),
        'doc' => 'app/请求遥测数据已采集.md',
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'request' => ['type' => 'array', 'required' => true, 'description' => '请求快照：uri/method/is_backend/is_api_backend/is_api_frontend/is_ajax/is_iframe 等'],
            'runtime' => ['type' => 'array', 'required' => true, 'description' => '运行时快照：mode/timestamp'],
            'trace' => ['type' => 'array', 'required' => true, 'description' => '链路追踪数据：spans（含 category/parent/db_duration_ms）'],
            'summary' => ['type' => 'array', 'required' => true, 'description' => '汇总数据：total_duration_ms/db_total_ms/spans_total/category_totals 等'],
            'extensions' => ['type' => 'array', 'required' => false, 'description' => '扩展区：errors/external_calls 等非核心字段，供未来扩展'],
            'result' => ['type' => 'string', 'required' => false, 'description' => '当前响应字符串，监听者可按需修改（例如注入调试面板脚本）'],
        ],
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
    'Weline_Framework_Setup::before_route_collection' => [
        'name' => __('路由收集前'),
        'description' => __('在收集路由之前触发，允许其他模块在路由收集前执行操作（如菜单收集，确保 ControllerAttributes 断言时 ACL 已有 type=menus 节点）。'),
        'doc' => 'setup/路由收集前.md',
    ],
    'Weline_Framework_Setup::after_route_collection' => [
        'name' => __('路由收集后'),
        'description' => __('在路由收集完成后触发，用于 ACL 等与路由阶段收集的数据做 diff（如清理已卸载模块的 type=pc 权限）。'),
        'doc' => 'setup/路由收集后.md',
    ],
    'Weline_Framework_Setup::collect_taglib_registry' => [
        'name' => __('收集标签注册表'),
        'description' => __('在 setup:upgrade 注册表阶段触发，允许外部模块执行标签注册表收集并通过 result 回传结果。'),
        'doc' => 'setup/收集标签注册表.md',
    ],
    'Weline_Framework_Setup::cleanup_missing_module_acl_residues' => [
        'name' => __('清理缺失模块 ACL 残留'),
        'description' => __('在 setup:upgrade 检测到异常卸载/搬迁模块时触发，允许外部模块清理 ACL/菜单残留并回填 cleaned_count。'),
        'doc' => 'setup/清理缺失模块ACL残留.md',
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
    'Weline_Framework_Module::remove_before_backup' => [
        'name' => __('模块移除前备份'),
        'description' => __('在 module:remove 卸载前触发，允许外部模块执行数据库等备份并通过 result 回传结果。'),
        'doc' => 'module/模块移除前备份.md',
    ],
    'Weline_Framework_Module::remove_after_taglib_collect' => [
        'name' => __('模块移除后标签收集'),
        'description' => __('在 module:remove 卸载后触发，允许外部模块执行 Taglib 注册表收集。'),
        'doc' => 'module/模块移除后标签收集.md',
    ],
    'Weline_Framework_Module::remove_acl_diff_begin' => [
        'name' => __('模块移除 ACL Diff 开始'),
        'description' => __('在 module:remove 的 ACL 差集处理开始前触发，允许外部模块初始化收集上下文。'),
        'doc' => 'module/模块移除ACL差集开始.md',
    ],
    'Weline_Framework_Module::remove_acl_diff_cleanup' => [
        'name' => __('模块移除 ACL Diff 清理'),
        'description' => __('在 module:remove 路由收集完成后触发，允许外部模块执行 ACL 差集清理并回传结果。'),
        'doc' => 'module/模块移除ACL差集清理.md',
    ],
    'Weline_Framework_Module::disable_after_registry_update' => [
        'name' => __('模块禁用后注册表更新'),
        'description' => __('在 module:disable 更新模块注册信息后触发，允许外部模块按禁用模块列表执行菜单等同步。'),
        'doc' => 'module/模块禁用后注册表更新.md',
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
    'Weline_Framework_Router::guard::overflow' => [
        'name' => __('URL 越界拦截'),
        'description' => __('当 UrlGuardObserver 判定本次请求越界（如 max id 越界、参数白名单不通过等）时触发。事件数据包含 uri、guard_name、details、params_keys、timestamp，便于队列/CDN/告警模块订阅做后续处理（如把命中信息入队、推送 CDN 黑名单、通知告警）。'),
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'uri' => ['type' => 'string', 'required' => true, 'description' => '触发越界的请求 URI（不含 querystring）'],
            'guard_name' => ['type' => 'string', 'required' => true, 'description' => '触发的 Guard 名称'],
            'details' => ['type' => 'array', 'required' => true, 'description' => 'Guard 提供的诊断详情（如 actual/max/allowed）'],
            'params_keys' => ['type' => 'array', 'required' => true, 'description' => '本次请求参数的 key 列表（不含值，避免泄漏）'],
            'timestamp' => ['type' => 'int', 'required' => true, 'description' => '事件时间戳'],
        ],
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
    'Weline_Framework_View::resolve_theme_asset_url' => [
        'name' => __('解析主题静态资源 URL'),
        'description' => __('Framework 通过该事件向外部模块请求主题资源 URL。观察者可读取 module_name/area/relative_path 并回填 url。'),
        'doc' => 'view/解析主题静态资源URL.md',
    ],
    'Weline_Framework_View::resolve_preview_token' => [
        'name' => __('解析预览 Token'),
        'description' => __('Framework 通过该事件向外部模块请求预览态信息。观察者可回填 is_preview 与 preview_token。'),
        'doc' => 'view/解析预览Token.md',
    ],
    'Weline_Framework_View::resolve_theme_cache_suffix' => [
        'name' => __('解析视图缓存主题后缀'),
        'description' => __('Framework 在视图编译文件缓存 key 中追加主题/预览相关后缀时触发。观察者可根据 filename/area 回填 suffix，用于按主题/预览隔离缓存。'),
        'doc' => 'view/解析视图缓存主题后缀.md',
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
    'Weline_Framework_Template::compile_decision' => [
        'name' => __('模板编译决策'),
        'description' => __('在模板编译新鲜度判断前触发，允许模块要求本次模板强制重新编译。'),
        'doc' => 'template/模板编译决策.md',
    ],
    'Weline_Framework_Template::before_compile' => [
        'name' => __('模板编译前'),
        'description' => __('在模板源内容进入标签编译前触发，允许模块对静态模板内容进行编译前改写。'),
        'doc' => 'template/模板编译前.md',
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

    // ========== 日志事件 ==========
    'Weline_Framework_Log::resolve_runtime' => [
        'name' => __('日志运行模式解析'),
        'description' => __('在解析当前日志运行模式（fpm | wls）时触发。默认来自配置 log.runtime，观察者可修改 data["runtime"] 为 wls（如 WLS 进程内由 Weline_Server 设置）。'),
        'doc' => 'log/日志运行模式解析.md',
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
    // 已废弃：ModelManager 不再 dispatch，请使用 Weline_Framework_Schema::table_ddl_before / table_ddl_after
    'Weline_Framework_Database::model_update_before' => [
        'name' => __('模型更新前（已废弃）'),
        'description' => __('已废弃，请使用 table_ddl_before。原在数据库模型更新前触发。'),
        'doc' => 'database/模型更新前.md',
    ],
    'Weline_Framework_Database::model_update_after' => [
        'name' => __('模型更新后（已废弃）'),
        'description' => __('已废弃，请使用 table_ddl_after。原在数据库模型更新后触发。'),
        'doc' => 'database/模型更新后.md',
    ],
    // Schema DDL 事件（替代 model_update_before/after）
    'Weline_Framework_Schema::table_ddl_before' => [
        'name' => __('表 DDL 执行前'),
        'description' => __('在执行表结构 DDL 前触发，允许其他模块（如 ModuleManager）做表名冲突检查。'),
        'doc' => 'database/table_ddl_before.md',
    ],
    'Weline_Framework_Schema::table_ddl_after' => [
        'name' => __('表 DDL 执行后'),
        'description' => __('在执行表结构 DDL 后触发，允许其他模块（如 ModuleManager）记录表映射、清理缓存等。'),
        'doc' => 'database/table_ddl_after.md',
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
    'Weline_Framework_Manager::result_bridge_url' => [
        'name' => __('结果桥接页地址'),
        'description' => __('获取 success/error/info/warning 结果桥接页 URL。观察者通过 data["bridge_url"] 返回地址，供 iframe 重定向后显示 BackendToast。'),
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
    'Weline_Framework_UninstallService::module_db_backup' => [
        'name' => __('模块数据库备份'),
        'description' => __('在 UninstallService 卸载模块前触发，允许外部模块执行数据库表备份并通过 result 回传结果。'),
        'doc' => 'uninstall/模块数据库备份.md',
    ],
    'Weline_Framework_UninstallService::module_db_restore' => [
        'name' => __('模块数据库恢复'),
        'description' => __('在 UninstallService 回滚卸载时触发，允许外部模块执行数据库表恢复并通过 result 回传结果。'),
        'doc' => 'uninstall/模块数据库恢复.md',
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
    ],
    
    // ========== 调度器事件 ==========
    'Weline_Framework::scheduler::wait' => [
        'name' => __('调度器等待'),
        'description' => __('由 SchedulerSystem 在 WLS 模式下 dispatch，通知调度器注册定时器或 fd 等待。Observer 根据 data.type 分支处理（sleep/usleep/waitFd 等）。FPM/CLI 下无 Observer 监听，不产生副作用。'),
        'doc' => 'scheduler/调度器等待.md',
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'type' => ['type' => 'string', 'required' => true, 'description' => '等待类型：sleep | usleep | waitFd'],
            'fiber' => ['type' => 'Fiber', 'required' => true, 'description' => '当前挂起的 Fiber 实例'],
            'seconds' => ['type' => 'int', 'required' => false, 'description' => 'type=sleep 时的秒数'],
            'microseconds' => ['type' => 'int', 'required' => false, 'description' => 'type=usleep 时的微秒数'],
        ],
    ],
];
