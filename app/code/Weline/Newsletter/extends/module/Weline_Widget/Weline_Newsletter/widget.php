<?php

declare(strict_types=1);

/**
 * Newsletter storefront widgets — unique owner of footer-newsletter / newsletter-popup / sidebar-newsletter.
 * Theme shells retired in the same release unit (D10).
 */
return [
    'footer-newsletter' => [
        'name' => '页脚订阅',
        'description' => '邮件订阅表单；经 required default_injections 注入 partials/footer 的 footer-above 槽；enable_popup 默认同渲订阅弹窗。',
        'type' => 'newsletter',
        'code' => 'footer-newsletter',
        'area' => 'frontend',
        'template' => 'Weline_Newsletter::templates/frontend/widgets/footer-newsletter/default.phtml',
        'page_layouts' => ['*'],
        'position' => ['content'],
        'slot' => 'footer-above',
        'placement' => 'injection',
        'exclusive' => false,
        'supports' => [
            'footer-newsletter',
            'layout-footer-above',
            'layout-footer-newsletter',
            'layout-global-footer-newsletter',
        ],
        'default_injections' => [[
            'layout_type' => 'homepage',
            'slot' => 'footer-above',
            'area' => 'content',
            'sort_order' => 100,
            'required' => true,
            'reason' => '全站页脚上方 footer-above 常驻邮件订阅（与 trust-badges 同槽追加）',
            'config' => [
                'title' => '订阅我们的邮件',
                'description' => '获取最新的优惠信息和新品资讯',
                'layout' => 'horizontal',
                'enable_popup' => true,
                'popup_trigger' => 'deferred',
                'popup_delay_seconds' => 15,
                'popup_scroll_percent' => 40,
                'popup_min_open_seconds' => 3,
                'popup_cookie_days' => 14,
            ],
        ]],
        'params' => [
            'title' => [
                'default' => '订阅我们的邮件',
                'type' => 'string',
                'label' => '标题',
            ],
            'description' => [
                'default' => '获取最新的优惠信息和新品资讯',
                'type' => 'string',
                'label' => '描述',
            ],
            'placeholder' => [
                'default' => '请输入您的邮箱地址',
                'type' => 'string',
                'label' => '占位符',
            ],
            'button_text' => [
                'default' => '订阅',
                'type' => 'string',
                'label' => '按钮文字',
            ],
            'layout' => [
                'default' => 'horizontal',
                'type' => 'select',
                'label' => '布局',
                'options' => [
                    'horizontal' => '横向',
                    'vertical' => '纵向',
                ],
            ],
            'enable_popup' => [
                'default' => true,
                'type' => 'bool',
                'label' => '开启订阅弹窗',
                'description' => '与本页脚条同启；关闭则不渲染弹窗',
            ],
            'popup_trigger' => [
                'default' => 'deferred',
                'type' => 'select',
                'label' => '弹窗触发方式',
                'options' => [
                    'deferred' => '组合延后（停留/滚动/退出意向）',
                    'delay' => '延迟显示',
                    'scroll' => '滚动触发',
                    'exit' => '退出意图',
                ],
            ],
            'popup_delay_seconds' => [
                'default' => 15,
                'type' => 'number',
                'label' => '弹窗延迟秒数',
            ],
            'popup_scroll_percent' => [
                'default' => 40,
                'type' => 'number',
                'label' => '弹窗滚动百分比',
            ],
            'popup_min_open_seconds' => [
                'default' => 3,
                'type' => 'number',
                'label' => '弹窗最早弹出秒数',
            ],
            'popup_cookie_days' => [
                'default' => 14,
                'type' => 'number',
                'label' => '弹窗 Cookie 天数',
            ],
        ],
    ],

    'newsletter-popup' => [
        'name' => '订阅弹窗',
        'description' => '邮件订阅弹窗（古风宣纸信笺）；默认由 footer-newsletter 开启同渲，禁止再 required 注入 content 以免双份。编辑器仍可单独放置。',
        'type' => 'newsletter',
        'code' => 'newsletter-popup',
        'area' => 'frontend',
        'template' => 'Weline_Newsletter::templates/frontend/widgets/newsletter-popup/default.phtml',
        'page_layouts' => ['*'],
        'position' => ['content'],
        'slot' => 'content',
        'placement' => 'injection',
        'supports' => [
            'newsletter-popup',
            'layout-homepage-content',
            'layout-footer-above',
            'content',
        ],
        // 与 footer-newsletter enable_popup 内嵌 XOR：勿再 JSON required 注入 content。
        'default_injections' => [],
        'params' => [
            'title' => [
                'default' => '订阅获取优惠',
                'type' => 'string',
                'label' => '标题',
            ],
            'description' => [
                'default' => '订阅我们的邮件，立即获得10%折扣码！',
                'type' => 'string',
                'label' => '描述',
            ],
            'image' => [
                'default' => '',
                'type' => 'media_image',
                'label' => '信笺图',
                'description' => '可选自定义古风宣纸信笺（建议透明 WebP）；空则用模块默认 newsletter-xinjian-gufeng.webp',
            ],
            'button_text' => [
                'default' => '立即订阅',
                'type' => 'string',
                'label' => '按钮文字',
            ],
            'trigger' => [
                'default' => 'deferred',
                'type' => 'select',
                'label' => '触发方式',
                'options' => [
                    'deferred' => '组合延后（停留/滚动/退出意向）',
                    'delay' => '延迟显示',
                    'scroll' => '滚动触发',
                    'exit' => '退出意图',
                ],
            ],
            'delay_seconds' => [
                'default' => 15,
                'type' => 'number',
                'label' => '延迟秒数',
            ],
            'scroll_percent' => [
                'default' => 40,
                'type' => 'number',
                'label' => '滚动百分比',
            ],
            'min_open_seconds' => [
                'default' => 3,
                'type' => 'number',
                'label' => '最早弹出秒数',
                'description' => '任意触发源在此秒数内禁止弹出（默认 3，首访首屏不立即弹）',
            ],
            'show_once' => [
                'default' => true,
                'type' => 'bool',
                'label' => '仅显示一次',
            ],
            'cookie_days' => [
                'default' => 14,
                'type' => 'number',
                'label' => 'Cookie 天数',
                'description' => '关闭/展示后抑制再次弹出的天数（默认 14）',
            ],
        ],
    ],

    'sidebar-newsletter' => [
        'name' => '侧栏订阅',
        'description' => '侧边栏邮件订阅表单（自 Theme 迁入，避免残留双注册）。',
        'type' => 'newsletter',
        'code' => 'sidebar-newsletter',
        'area' => 'frontend',
        'template' => 'Weline_Newsletter::templates/frontend/widgets/sidebar-newsletter/default.phtml',
        'page_layouts' => ['*'],
        'position' => ['sidebar'],
        'compatible' => true,
        'supports' => [
            'sidebar-newsletter',
            'layout-sidebar-newsletter',
        ],
        'params' => [
            'title' => [
                'default' => '订阅我们',
                'type' => 'string',
                'label' => '标题',
            ],
            'description' => [
                'default' => '订阅获取最新优惠信息',
                'type' => 'string',
                'label' => '描述',
            ],
            'button_text' => [
                'default' => '订阅',
                'type' => 'string',
                'label' => '按钮文字',
            ],
            'placeholder' => [
                'default' => '请输入邮箱地址',
                'type' => 'string',
                'label' => '占位符',
            ],
            'bg_color' => [
                'default' => 'var(--weline-theme-primary)',
                'type' => 'color',
                'label' => '背景色',
            ],
            'text_color' => [
                'default' => 'var(--weline-theme-on-primary)',
                'type' => 'color',
                'label' => '文字色',
            ],
        ],
    ],
];
