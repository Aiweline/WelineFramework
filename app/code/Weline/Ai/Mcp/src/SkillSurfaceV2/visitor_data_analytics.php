<?php

declare(strict_types=1);

/**
 * skill-v2 surface: visitor_data_analytics（数据分析席 / 像素事件）
 *
 * 可执行程序：技能引用 -> 事件字典/链 -> 声明式采集 -> GTM/GA4 互斥 -> 去重顺序 -> scope -> Vendor -> 双轨复审。
 * 政策正文在 hard_constraints（analytics_engineer_for_visitor_work 等），此处只留判据与指针。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'visitor_data_analytics',
    'spec' => 'skill-v2',
    'label' => 'Visitor 数据分析 / 像素事件规范',
    'description' => '工程团队数据分析席：整个 Weline_Visitor 模块（像素 runtime、事件字典/链、PixelEventVendor、沙盒监视、转化去重、报表）归本席施工。采集只走 WelinePixel.track 与声明式属性；GTM 与 GA4 直连互斥，转化先 emit 再去重占用后扇出。用于像素埋点、事件链、供应商对接与访客报表。见 analytics_engineer_for_visitor_work。',
    'authoritative_doc' => 'app/code/Weline/Visitor/doc/像素拓展使用指南.md',
    'triggers' => [
        '数据分析', '像素事件', 'Visitor像素', '访客事件', 'Team:数据分析',
        'Weline_Visitor', 'WelinePixel', 'weline-pixel', 'pixel.js',
        'data-pixel-event', 'data-visitor-event', 'data-cta-event', 'data-ga-event',
        'PixelEventVendor', '事件链', 'event_chain', '沙盒监视', 'GtmBridge',
        'GTM', 'GA4', 'dataLayer', '转化去重', '访客统计', '像素报表',
    ],
    'authoritative_docs' => [
        'app/code/Weline/Visitor/doc/像素拓展使用指南.md',
        'app/code/Weline/Visitor/doc/Visitor_Pixel_GTM_GA4_系统设计.md',
        'app/code/Weline/Visitor/doc/像素事件供应商管理-定稿合同.md',
        'app/code/Weline/Visitor/doc/event/事件链注册.md',
        'app/code/Weline/Visitor/doc/event/访客像素标签.md',
        'app/code/Weline/Visitor/doc/开发/spec/conversion-event-dedupe.md',
        'app/code/Weline/Visitor/doc/数据分析功能使用指南.md',
        'app/code/Weline/Websites/doc/store-saleschannel-scope.md',
    ],
    'when' => [
        '改 Weline_Visitor 像素、事件、报表或后台看板',
        '新增埋点、事件名、事件链或转化去重',
        '对接 GA4/GTM/Meta/TikTok 等像素供应商',
        '排查事件丢失、双计、去重失效或 scope 配错',
        '排除：通用 Framework Event 契约（走 event_extension）',
    ],
    'inputs' => [
        '事件名与业务意图；值/币种/商品标识',
        '所在页面/区块的 weline-code（Section 溯源）',
        '配置范围：Website/Store/Channel 与路径过滤',
        '供应商清单与其 SDK 域名（CSP 用）',
    ],
    'procedure' => [
        '开工取本技能 + frontend_development + taglib_ui_control 三个技能引用，读像素拓展使用指南。',
        '定事件：先查 event_dictionary.json，缺则登记；链式完成事件必须带 __event_chain_complete。',
        '埋点只用 WelinePixel.track 或声明式 weline-pixel:: 与 data-pixel-event 等属性，禁旁路 dataLayer。',
        'GTM 与 GA4 直连互斥：GtmBridge 是唯一 dataLayer 出口；page_view 不由 Pixel 重复 push。',
        '转化顺序：sandbox.emit → 去重占用 → 扇出；主 track 不得因去重 return null。',
        '配置范围用 <w:scope>（Website→Store→Channel）；路径过滤 scope_json 不是站店渠。',
        '第三方写 Extends PixelEventVendorInterface（含 cspDirectives）；跑本席双轨复审与报表验收。',
    ],
    'checklist' => [
        '事件名已在 event_dictionary.json 登记？',
        '采集只走 WelinePixel.track/声明式属性，无旁路？',
        'GTM 与 GA4 直连只开一个？',
        '完成事件带 __event_chain_complete？',
        '去重顺序为 emit→占用→扇出？',
        'scope 与 scope_json 未混用？',
        'Vendor 的 cspDirectives 已声明 SDK 域名？',
        '本席双轨（施工+合规复审）已跑？',
    ],
    'anti_patterns' => [
        '业务模块手写 dataLayer.push 或私接三方像素脚本',
        'GTM 与 GA4 直连同时开（双计）',
        'Pixel 重复 push page_view',
        '把站店渠 Scope 当成 scope_json 路径过滤',
        '在 Visitor 壳或 Framework Defaults 写死 SDK 域名',
        '只派前端/后端写 pixel runtime，不上数据分析席',
        '主 track 因去重直接 return null 丢事件',
        '产品角色接管 Team:事件: 的通用 Event 契约',
    ],
    'evidence' => [
        'event_dictionary.json 改动 + 事件名/链式标记',
        '沙盒监视或面板中 emit→去重→扇出 记录',
        'meetings/数据分析-review.md 双轨结论',
    ],
    'norms' => [
        ['id' => 'visitor_module_owns_seat', 'summary' => '整个 Weline_Visitor 模块归数据分析席施工（含报表后端与本模块 etc/event.xml、像素桥接 Observer）；禁止只派前端/后端代写壳与 runtime。'],
        ['id' => 'not_framework_event_seat', 'summary' => '产品角色 ≠ Team:事件:（通用 Framework Event）；本席拥有 Visitor 域 Event 契约与像素桥接 Observer；禁止产品混岗。'],
        ['id' => 'frontend_skill_refs_required', 'summary' => '开工前必须 get_skill(visitor_data_analytics) + get_skill(frontend_development) + get_skill(taglib_ui_control)；仅 Weline.Api.resource|graph|stream；后台走主题 Token/Taglib。'],
        ['id' => 'pixel_track_only', 'summary' => '采集只走 WelinePixel.track / weline-pixel:: / data-pixel-event|data-visitor-event|data-cta-event|data-ga-event；禁止业务旁路 dataLayer 或私接三方像素脚本；禁止用 Observer 替代采集主路径。'],
        ['id' => 'gtm_ga4_mutex_dual_channel', 'summary' => 'GtmBridge 为唯一 GTM dataLayer 出口（event=weline_visitor）；开 GTM 必须关 GA4 直连防双计；page_view 由 GTM/GA4 配置发送，Pixel 不重复 push。'],
        ['id' => 'event_dictionary_and_chain', 'summary' => '改事件须动 event_dictionary.json；complete_event 须带 __event_chain_complete 才入库；转化去重：sandbox.emit→占用→扇出；主 track 禁因去重 return null。'],
        ['id' => 'scope_vs_path_filter', 'summary' => '配置范围=Website→Store→Channel 继承（用 <w:scope>）；路径过滤 scope_json 不是站店渠 Scope。'],
        ['id' => 'vendor_extends_csp', 'summary' => '第三方对接写 Extends PixelEventVendorInterface（须含 cspDirectives 自报 SDK 域名）；禁止在 Visitor 壳或 Framework Defaults 硬编码 SDK 域名。'],
        ['id' => 'peer_frontend_ok', 'summary' => '本席是开发席且须掌握前端技能；声明式埋点可 channel 请前端协助；复杂 runtime/Vendor/报表/本模块 UI 必须本席施工。'],
        ['id' => 'peer_api_widget_for_reports', 'summary' => '跨模块 QueryProvider 变更 peer API 席；Dashboard/报表部件 peer 部件开发工程师。'],
        ['id' => 'analytics_engineer_dual_track', 'summary' => 'Visitor/像素相关施工必须 Team:数据分析: 上场并跑施工+合规复审双轨。'],
    ],
    'verification_commands' => [
        'test -f app/code/Weline/Visitor/doc/像素拓展使用指南.md',
        'test -f app/code/Weline/Visitor/doc/开发/spec/conversion-event-dedupe.md',
    ],
    // 兼容别名：本体已并入 checklist / anti_patterns / norms，旧模板规则清单不再重复渲染。
    'template_surface_rules' => [
        'required' => [],
        'forbidden' => [],
    ],
];
