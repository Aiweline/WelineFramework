<?php

declare(strict_types=1);

/**
 * skill-v2 surface: payment_development（支付开发工程师席 / 万能支付）
 *
 * 可执行程序：读壳/Provider 边界 -> 最小交付 -> 统一回调与幂等 -> 资金面 -> CSP/密钥 -> 真浏览器闭环 -> review。
 * 政策正文在 hard_constraints（shell_provider_business_isomorph 等），此处只留判据与指针。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'payment_development',
    'spec' => 'skill-v2',
    'label' => '万能支付开发规范',
    'description' => '工程团队支付开发工程师席：Weline_Payment 壳只编排，渠道业务只在 Extends Provider；新支付方式=Provider + SystemConfig 模板 + 结账模板，回调统一 URL 且带 shell_token。用于对接新支付、退款/退货资金面、Webhook/return、对账与 Payable 接入。见 shell_provider_business_isomorph、payment_browser_e2e_closed_loop。',
    'authoritative_doc' => 'app/code/Weline/Payment/doc/payment-shell.md',
    'triggers' => [
        '支付', '支付开发', '支付开发工程师', '万能支付', 'payment', 'Payment',
        'PaymentProvider', 'method_code', '退款', '部分退款', '退货退款', 'refund',
        'Webhook', 'webhook', '对账', 'reconcile', 'Payable', 'createPayment',
        'resumePayment', 'shell_token', 'payment-shell', 'provider-development',
        'Team:支付开发工程师', '支付方式对接', '对接支付', '支付模块',
    ],
    'authoritative_docs' => [
        'app/code/Weline/Payment/doc/payment-shell.md',
        'app/code/Weline/Payment/doc/provider-development.md',
        'app/code/Weline/Payment/doc/webhook.md',
        'app/code/Weline/Payment/doc/extends.md',
        'app/code/Weline/Payment/doc/payment-reconcile.md',
        'app/code/Weline/Framework/doc/3-开发/安全响应头策略.md',
        'dev/ai-command/ai/支付开发.md',
    ],
    'when' => [
        '对接新支付方式或改 Provider 能力/退款/回调',
        '支付创建、继续、授权、捕获、退款、对账改动',
        'Webhook、浏览器 return/cancel 或 OAuth Connect 异常',
        '支付代码已改，待复审与验收收口',
        '排除：纯商品/购物车业务（走各自技能）',
    ],
    'inputs' => [
        '归属 Vendor_Module + method_code（与 config/checkout 三码一致）',
        '改动面：创建/返回/Webhook/退款/对账/Connect',
        '网关事实：验签方式、稳定事件 ID、币种与国家',
        '已读 payment-shell.md 与 provider-development.md 的边界结论',
    ],
    'procedure' => [
        '读 payment-shell.md + provider-development.md（回调另读 webhook.md），定壳/Provider 边界。',
        '新方式：Extends Provider + SystemConfig backend/{method_code}.phtml + 结账模板，三码一致。',
        '渠道业务全写进 Provider（创建/退款/回调解析/能力/cspDirectives），壳 Controller 只编排。',
        '回调走统一 URL + shell_token；verifyCallback/parseCallback 纯函数，先 Inbox 再 CAS 消费。',
        '退款/退货资金面走 Provider.refund + 壳 Refund/Ledger，金额 amount_minor、幂等键稳定。',
        'CSP 由 Provider.cspDirectives 自报，密钥只进 SystemConfig 加密字段；壳与模板不碰卡号。',
        '改完拉起 Team:测试: 真浏览器过该方式全流程，凭 transaction_no/order_uuid 才 review pass。',
    ],
    'checklist' => [
        '渠道业务全在 Extends Provider，壳内无网关重写？',
        '新方式三码（getCode/config 文件/checkout 模板）一致？',
        '回调未手拼 URL 且运行时带 shell_token？',
        'verifyCallback/parseCallback 无写库、队列、远端调用？',
        '金额用 amount_minor，退款经 Provider.refund + Ledger？',
        'CSP 经 cspDirectives 自报，密钥在 SystemConfig 加密字段？',
        '真浏览器走完全流程并有 transaction_no/order_uuid？',
    ],
    'anti_patterns' => [
        '在 Payment 壳 Controller/Service 重写某网关创建或退款',
        '按渠道分裂业务 Controller，而非一个 Extends Provider',
        '为每个网关另登记 Developer Return URL 或手拼回调路由',
        '壳或结账模板接触卡号 PAN/CVV',
        '在 Framework SecurityHeaderDefaults 硬编码网关域名',
        'verifyCallback/parseCallback 里写支付单或发队列',
        '仅壳层冒烟、单测或 curl 就宣称支付完成',
        '只改代码就 review pass，未拉起 Team:测试: 真浏览器',
    ],
    'evidence' => [
        '浏览器通路证据：transaction_no / order_uuid（选择→提交→结果）',
        'Provider 与模板源码路径 + SystemConfig 配置键',
        'meetings/*-review.md：施工 + 合规复审结论',
    ],
    'norms' => [
        ['id' => 'shell_orchestrates_provider_implements', 'summary' => '壳只编排（统一 URL/状态机/Inbox/配置托管/幂等）；渠道业务只在 Extends Provider。见 shell_provider_business_isomorph。'],
        ['id' => 'new_method_minimal_delivery', 'summary' => '对接新支付=extends/module/Weline_Payment/PaymentProvider/{X}Provider.php + SystemConfig backend/{method_code}.phtml（+checkout 模板/CustomerGuide）；三 code 一致；禁止按渠道分裂壳 Controller。'],
        ['id' => 'unified_callback_urls', 'summary' => '统一 browser return/cancel/webhook URL（PaymentShellCallbackUrlCatalog）；运行时须 shell_token 或显式 method_code+transaction_no；禁止手拼或按网关另开 Return URL。'],
        ['id' => 'refund_return_money_path', 'summary' => '全额/部分/多次退款与退货资金面经 Provider.refund + 壳 Refund/Ledger；稳定 refund_code/idempotency；禁止绕过 Payment 锁。'],
        ['id' => 'webhook_pure_verify_parse', 'summary' => 'verifyCallback/parseCallback 纯函数：不写单、不发队列、不调远端；先 Inbox 再消费 CAS。'],
        ['id' => 'payment_security_pci_csp_secrets', 'summary' => '壳不碰卡号；CSP 由 Provider.cspDirectives 自报；密钥走 SystemConfig 加密字段；测连脱敏；禁止硬编码网关域名/密钥。'],
        ['id' => 'capabilities_hard_ceiling', 'summary' => 'getCapabilities 为硬上限，后台只能收窄；金额用 amount_minor；不支持的退款/捕获须明确 unsupported。'],
        ['id' => 'payment_engineer_dual_track', 'summary' => '支付施工必须 Team:支付开发工程师: 上场并跑施工+合规复审；后端/通用 Provider 席不得代写支付域。'],
        ['id' => 'payment_acceptance_real_pathway', 'summary' => '验收须真实支付/取消/失败/退款通路证据（transaction_no/order_uuid）；禁止壳层冒烟冒充实交。'],
        ['id' => 'payment_browser_e2e_closed_loop', 'summary' => '代码改完必须拉起 Team:测试: 用宿主真 Browser（WB-OP、禁缓存）过触及支付方式全流程；仅 Browser pass + 证据齐全才可 review pass；禁止单测冒充实浏览器。'],
    ],
    'verification_commands' => [
        'test -f app/code/Weline/Payment/doc/payment-shell.md',
        'php bin/w setup:upgrade',
    ],
    // 兼容别名：本体已并入 checklist / anti_patterns / norms，旧模板规则清单不再重复渲染。
    'template_surface_rules' => [
        'required' => [],
        'forbidden' => [],
    ],
];
