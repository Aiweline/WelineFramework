<?php

declare(strict_types=1);

/**
 * Amayum 站点资料默认值（seed 写入 SystemConfig；运行时以 SystemConfig 为准）。
 *
 * 只放站点专属品牌/导航/页脚事实；页面正文与案例/指南元数据分别在
 * Service/CaseStudyCatalog.php 与 Service/GuideCatalog.php。
 */
return [
    'profile' => [
        'brand_name' => 'Amayum',
        'product_name' => 'WelineFramework',
        'legal_name' => 'Aiweline（深圳）',
        'site_url' => 'https://amayum.com',
        'default_locale' => 'zh-CN',
        'timezone' => 'Asia/Shanghai',
        'contact_email' => 'hello@amayum.com',
        'location' => '深圳 · 中国',
        'response_sla' => '1 个工作日内回复（Asia/Shanghai）',
        'og_image' => '/static/Amayum/official/frontend/assets/images/og-default.webp',
        'theme' => 'app/design/Amayum/official',
        'theme_key' => 'amayum-official',
        'links' => [
            'github' => 'https://github.com/Aiweline/WelineFramework',
            'gitee' => 'https://gitee.com/aiweline/WelineFramework',
            'docs' => 'https://www.aiweline.com',
        ],
        'seo_defaults' => [
            'title_suffix' => '| Amayum',
            'og_image' => '/static/Amayum/official/frontend/assets/images/og-default.webp',
            'organization_name' => 'Amayum',
            'organization_url' => 'https://amayum.com',
        ],
    ],
    'nav' => [
        'items' => [
            ['label' => '首页', 'href' => '/', 'order' => 1, 'visible' => true],
            ['label' => '框架能力', 'href' => '/commerce', 'order' => 2, 'visible' => true],
            ['label' => '例子', 'href' => '/case-studies', 'order' => 3, 'visible' => true],
            ['label' => '指南', 'href' => '/journal', 'order' => 4, 'visible' => true],
            ['label' => '联系', 'href' => '/diagnosis', 'order' => 5, 'visible' => true],
        ],
        'cta' => [
            'label' => '预约架构诊断',
            'href' => '/diagnosis#diagnosis',
        ],
    ],
    'footer' => [
        'columns' => [
            [
                'title' => '产品',
                'links' => [
                    ['label' => '框架能力', 'href' => '/commerce'],
                    ['label' => '例子', 'href' => '/case-studies'],
                    ['label' => 'GitHub', 'href' => 'https://github.com/Aiweline/WelineFramework', 'external' => true],
                    ['label' => 'Gitee', 'href' => 'https://gitee.com/aiweline/WelineFramework', 'external' => true],
                    ['label' => '安装文档', 'href' => 'https://www.aiweline.com', 'external' => true],
                ],
            ],
            [
                'title' => '指南',
                'links' => [
                    ['label' => '独立站指南', 'href' => '/journal'],
                    ['label' => '建站平台怎么选', 'href' => '/journal/build-platform'],
                    ['label' => 'SEO 排查清单', 'href' => '/journal/independent-store-seo'],
                    ['label' => 'Shopify 替代方案', 'href' => '/journal/shopify-alternative'],
                ],
            ],
            [
                'title' => '例子',
                'links' => [
                    ['label' => '長安漢服', 'href' => 'https://changanhanfu.com', 'external' => true],
                    ['label' => 'PlayingInChina', 'href' => 'https://playinginchina.com', 'external' => true],
                    ['label' => 'DaoCharms', 'href' => 'https://daocharms.com', 'external' => true],
                ],
            ],
            [
                'title' => '公司',
                'links' => [
                    ['label' => '关于 Amayum', 'href' => '/#about'],
                    ['label' => '联系', 'href' => '/diagnosis'],
                    ['label' => '隐私与条款', 'href' => '/legal'],
                ],
            ],
        ],
        'bottom' => [
            ['label' => '© 2026 Aiweline（深圳）', 'href' => ''],
            ['label' => 'Amayum · Built on WelineFramework', 'href' => ''],
            ['label' => '站点地图', 'href' => '/sitemap'],
            ['label' => '回到顶部', 'href' => '#top'],
        ],
    ],
    'proof_facts' => [
        [
            'label' => 'PHP 8.4+',
            'detail' => '框架运行时要求；扩展与第三方库需单独核验。',
        ],
        [
            'label' => 'MIT 许可',
            'detail' => 'WelineFramework 以 MIT 协议开源。',
        ],
        [
            'label' => '127 个已注册模块',
            'detail' => '包含基础设施、AI、后台与开发工具，不等于 127 个电商功能。',
        ],
        [
            'label' => 'WLS + FPM',
            'detail' => 'PHP-FPM/FastCGI 是一等部署路径；内置 WLS 运行时可选用。',
        ],
    ],
    'flags' => [
        'contact_smtp_enabled' => true,
        'show_archive_links' => false,
        'show_repo_links' => true,
    ],
];
