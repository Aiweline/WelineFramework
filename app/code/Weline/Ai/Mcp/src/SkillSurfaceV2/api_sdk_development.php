<?php

declare(strict_types=1);

/**
 * skill-v2 surface: api_sdk_development（API 席 / REST + BinQuery）
 *
 * 可执行程序：入口选择 -> QueryProvider 核 -> 每入口权限 -> 编译/query:help -> 文档同变更集。
 * 政策正文在 hard_constraints，此处只留判据、指针与 norm_ids 索引。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'api_sdk_development',
    'spec' => 'skill-v2',
    'label' => 'API 开发规范（REST + BinQuery）',
    'description' => '工程团队 API 席：可复用 I/O 只落 QueryProvider，REST/query-bin//bin/query 只是入口壳；写了 Provider ≠ 可调，权限每入口独立、默认拒绝。用于 Rest/BinQuery/SDK 契约、operation 变更与管理面写读。见 api_per_entry_permission_matrix、api_rest_in_owning_module。',
    'authoritative_doc' => 'app/code/Weline/Framework/doc/3-开发/API接口开发规范.md',
    'triggers' => [
        'REST', 'Rest', 'Api/Rest', 'AbstractRestController', 'BackendRestController', 'FrontendRestController',
        'API接口', 'API 开发', 'API开发', 'API SDK', 'API席', 'Team:API',
        'BinQuery', 'QueryProvider', 'query-bin', '/bin/query', 'binquery', 'w_query',
        'openapi', 'SDK使用指南', '第三方 SDK', '远程 API', 'rest/v1',
    ],
    'authoritative_docs' => [
        'app/code/Weline/Framework/doc/3-开发/API接口开发规范.md',
        'app/code/Weline/Framework/doc/BinQuery/Provider开发指南.md',
        'app/code/Weline/Framework/doc/BinQuery/README.md',
        'app/code/Weline/Api/doc/framework-api-and-auth-contract.md',
        'app/code/Weline/Frontend/doc/Weline.Api使用指南.md',
    ],
    'when' => [
        '新增/变更 Rest 控制器、路由或 SDK 契约',
        '新增 QueryProvider operation 或改 descriptor',
        '管理后台资源写读与批量操作',
        '接口已写但前端/后台调不到',
        '排除：纯 Service 内核、Theme/phtml',
    ],
    'inputs' => [
        '归属 Vendor_Module + 查询意图/资源主键',
        '暴露面：站内 worker / 站外 /bin/query / CDN 公开读 / 薄 REST',
        '鉴权主体：auth 取值、后台 ACL source_id',
        'Query 核 operation 清单与入口选择',
    ],
    'procedure' => [
        '定入口：站内 I/O 走 BinQuery，对外走薄 REST over Query；两壳都不重写业务。',
        '可复用 I/O 写进归属模块 QueryProvider（Attribute），descriptor 只放纯标量。',
        '权限：默认 external/frontend=false；站内 frontend+auth；站外 external+auth+Key scope；后台 backend_acl。',
        'REST 继承 AbstractRestController，写全 @Document/@example；后台 Rest 加 #[Acl]。',
        '跑 framework:compile 与 query:help 确认可发现；REST 跑 setup:upgrade 过门禁。',
        '同变更集更新归属模块 API 文档；管理面按完整操作 + 弹层异步 BinQuery。',
        'Team:API: 双轨（施工+合规复审）收口，记录入口权限矩阵。',
    ],
    'checklist' => [
        '可复用 I/O 只在 QueryProvider，壳内无业务重写？',
        'Rest 只落归属模块 Api/Rest？',
        '每个对外入口都有独立权限声明？',
        'framework:compile 已跑且 query:help 可见？',
        'descriptor 只含纯标量？',
        '归属模块 API 文档同变更集更新？',
        '管理面写读走异步 BinQuery？',
    ],
    'anti_patterns' => [
        '在他模块写他人 Rest（如 I18n 里写 Website）',
        '写了 Provider 就当可调，无 auth/backend_acl',
        'REST 有 #[Acl]、query-bin 缺 backend_acl',
        '站内业务用 native fetch 绕开 query-bin',
        '壳内重写 Provider 业务或直读他模块 Model',
        '改 QueryProvider 不跑 framework:compile',
        '管理面只做新增/开页就宣称完成',
    ],
    'evidence' => [
        'framework:compile 输出 + query:help operation 名',
        'Rest/Provider 路径（@Document/Acl/descriptor）',
        '入口权限矩阵：入口→external/frontend/auth/backend_acl',
    ],
    'norms' => [
        ['id' => 'api_seat_scope_rest_or_binquery', 'summary' => 'API 席只做 REST 与 BinQuery/QueryProvider：不写 Theme/phtml，不抢后端 Service 内核。'],
        ['id' => 'api_shared_query_core', 'summary' => '可复用 I/O 只落 QueryProvider；query-bin、/bin/query、薄 REST 均为入口壳，薄 REST 默认 #[Acl]+w_query，壳内禁止重写业务。'],
        ['id' => 'api_rest_in_owning_module', 'summary' => 'REST 控制器与路由只放资源归属 Vendor_Module 的 Api/Rest 下；禁止跨模块代写 Rest。'],
        ['id' => 'rest_document_acl', 'summary' => '继承 AbstractRestController 系；方法写全 @Document/@example/@param/@return；后台 REST 必须 #[Acl]；setup:upgrade 会拦截不合规接口。'],
        ['id' => 'binquery_provider_attribute_compile', 'summary' => 'QueryProvider 用 BinQueryOperation/BinQueryParam 等 Attribute；descriptor 纯标量；改后 framework:compile，且 query:help 必须可发现。'],
        ['id' => 'binquery_external_flags', 'summary' => '站外 /bin/query 可见须 external=true（frontend 区域另需 frontend=true）；写操作 mode=write 不进 CDN、不进 graph。'],
        ['id' => 'api_per_entry_permission_matrix', 'summary' => '每入口独立门且默认拒绝：REST→#[Acl]；query-bin→auth+backend_acl；/bin/query→external+API Key scope；CDN 仅 read+external+cdn+public；禁止跨入口旁路。'],
        ['id' => 'api_seat_dual_track', 'summary' => '改 Rest/BinQuery/SDK 契约时工程团队必须上场 Team:API:，并跑施工+合规复审双轨。'],
        ['id' => 'sdk_vs_binquery_boundary', 'summary' => '站内浏览器业务 I/O 走 BinQuery（Weline.Api→query-bin）；对外 HTTP/第三方优先薄 REST over Query；禁止用 native fetch 冒充站内 SDK。'],
        ['id' => 'ui_page_controllers_mutations_via_async_binquery', 'summary' => '界面页才用 Controller；资料增删改须异步 BinQuery/QueryProvider；禁止为管理信息开多个 CRUD 控制器或 Controller save/delete。'],
        ['id' => 'admin_simple_create_edit_via_modal_binquery', 'summary' => '不复杂的管理新增/编辑用 Weline.UI 弹层 + 异步 BinQuery；禁止列表页常驻展开表单占版面。'],
        ['id' => 'api_docs_must_update', 'summary' => '新增/变更 REST 或对外 operation 必须同步 owning-module API/REST 文档与 @Document/examples；禁止只改代码不更文档。'],
    ],
    'norm_ids' => [
        'admin_manage_ui_requires_humanized_full_ops',
    ],
    'verification_commands' => [
        'php bin/w framework:compile',
        'php bin/w query:help',
    ],
    // 兼容别名：本体已并入 checklist / anti_patterns / norms，旧模板规则清单不再重复渲染。
    'template_surface_rules' => [
        'required' => [],
        'forbidden' => [],
    ],
];
