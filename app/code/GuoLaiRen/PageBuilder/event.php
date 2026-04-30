<?php
/**
 * GuoLaiRen_PageBuilder 模块事件规约
 *
 * 仅保留通知型/多模块协作型事件。
 * 数据查询操作已迁移到 WebsitesQueryProvider（统一查询器），不再使用事件。
 */
return [
    'GuoLaiRen_PageBuilder::website_save_after' => [
        'name' => __('网站保存后'),
        'description' => __('新建或更新站点时触发，用于将站点归属到当前后台用户等后续处理。'),
        'doc' => 'website_save_after.md',
    ],

    'GuoLaiRen_PageBuilder::quickbuild::query_services' => [
        'name' => __('快速建站 - 查询可用服务'),
        'description' => __('查询所有可用的建站相关服务能力。各模块通过观察者将自身服务信息追加到 data.services 数组。'),
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'category' => ['type' => 'string', 'description' => '服务分类过滤：domain|dns|cdn|ssl|template|provisioning|all'],
            'website_id' => ['type' => 'integer|null', 'description' => '关联网站 ID'],
            'services' => ['type' => 'array', 'description' => '各模块追加的服务列表（引用传递）'],
        ],
    ],

    'GuoLaiRen_PageBuilder::quickbuild::start_provisioning' => [
        'name' => __('快速建站 - 启动一站式配置'),
        'description' => __('启动一站式配置流程（DNS→CDN→SSL），由 Weline_Saas 的 DomainProvisioningService 执行。'),
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'domain' => ['type' => 'string', 'description' => '域名'],
            'registrar_account_id' => ['type' => 'integer', 'description' => '域名商账号 ID'],
            'options' => ['type' => 'array', 'description' => '配置选项'],
            'result' => ['type' => 'array|null', 'description' => '启动结果（引用传递）'],
        ],
    ],

    'GuoLaiRen_PageBuilder::quickbuild::query_provisioning_orders' => [
        'name' => __('快速建站 - 查询配置订单'),
        'description' => __('查询配置订单列表，由 Weline_Saas 响应。'),
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'filter' => ['type' => 'array', 'description' => '过滤条件'],
            'orders' => ['type' => 'array', 'description' => '订单列表（引用传递）'],
        ],
    ],

    // 以下事件已迁移到 WebsitesQueryProvider（统一查询器），不再使用事件：
    // - query_registrar_accounts, check_availability, purchase
    // - query_registrars, save_registrar_account, delete_registrar_account
    // - query_domain_list, test_connection, query_config_fields
];
