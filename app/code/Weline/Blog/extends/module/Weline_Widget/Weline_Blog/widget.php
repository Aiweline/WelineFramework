<?php

declare(strict_types=1);

/**
 * Blog 前台部件：评论容器 + 页脚了解我们扩展（博客/新闻中心链接）+ 页头导航扩展。
 * Theme layouts/partials 禁止内嵌本模块 <w:widget>；靠 default_injections / 拖入补空槽。
 */
return [
    'header-blog-link' => [
        'name' => '页头博客链接',
        'description' => '页头导航扩展槽：博客列表入口；默认注入 header-nav-extensions（分类后方）。',
        'type' => 'navigation',
        'code' => 'header-blog-link',
        'area' => 'frontend',
        'template' => 'Weline_Blog::templates/frontend/widgets/header-blog-link.phtml',
        'page_layouts' => ['*'],
        'position' => ['header'],
        'slot' => 'header-nav-extensions',
        'supports' => [
            'header-blog-link',
            'header-nav-link',
            'layout-header-nav-extensions',
        ],
        'default_injections' => [[
            'layout_type' => 'homepage',
            'slot' => 'header-nav-extensions',
            'area' => 'header',
            'sort_order' => 0,
            'required' => true,
            'reason' => '页头分类后方默认展示博客入口',
            'config' => [
                'label' => '博客',
            ],
        ]],
        'params' => [
            'label' => [
                'default' => '博客',
                'type' => 'string',
                'label' => '链接文字',
            ],
        ],
    ],
    'blog-reviews' => [
        'name' => '博客评论',
        'description' => '万能评论大部件：博客文章评论列表与图文提交；默认注入博客详情评论容器。',
        'type' => 'comment',
        'code' => 'blog-reviews',
        'area' => 'frontend',
        'template' => 'Weline_Blog::templates/frontend/widgets/blog-reviews.phtml',
        'page_layouts' => ['blog'],
        'position' => ['content'],
        'slot' => 'blog-reviews',
        'supports' => [
            'layout-blog-reviews',
            'blog-reviews',
            'review',
            'reviews',
        ],
        'default_injections' => [[
            'layout_type' => 'blog',
            'layout_option' => 'default',
            'slot' => 'blog-reviews',
            'area' => 'content',
            'sort_order' => 0,
            'required' => true,
            'reason' => '博客详情默认在评论容器槽展示万能评论大部件',
            'config' => [
                'title' => '博客评论',
                'intro' => '支持文字、图片与视频，内容审核后公开。',
                'page_size' => 10,
                'layout_mode' => 'stack',
                'form_position' => 'right',
                'form_collapsed' => '1',
            ],
        ]],
        'params' => [
            'title' => [
                'default' => '博客评论',
                'type' => 'string',
                'label' => '标题',
            ],
            'intro' => [
                'default' => '支持文字、图片与视频，内容审核后公开。',
                'type' => 'string',
                'label' => '简介',
            ],
            'page_size' => [
                'default' => 10,
                'type' => 'number',
                'label' => '列表每页条数',
            ],
            'layout_mode' => [
                'default' => 'stack',
                'type' => 'select',
                'label' => '布局方式',
                'options' => [
                    'stack' => '上下布局',
                    'split' => '左右布局',
                ],
            ],
            'form_position' => [
                'default' => 'right',
                'type' => 'select',
                'label' => '表单位置（左右布局）',
                'options' => [
                    'right' => '表单在右',
                    'left' => '表单在左',
                ],
            ],
            'form_collapsed' => [
                'default' => '1',
                'type' => 'select',
                'label' => '表单默认折叠',
                'options' => [
                    '1' => '点击写评论后展开',
                    '0' => '始终显示表单',
                ],
            ],
        ],
    ],
    'footer-blog-link' => [
        'name' => '页脚博客链接',
        'description' => '页脚了解我们扩展槽：博客列表入口；默认注入 footer-about-links。',
        'type' => 'footer',
        'code' => 'footer-blog-link',
        'area' => 'frontend',
        'template' => 'Weline_Blog::templates/frontend/widgets/footer-blog-link.phtml',
        'page_layouts' => ['*'],
        'position' => ['footer'],
        'slot' => 'footer-about-links',
        'supports' => [
            'footer-blog-link',
            'layout-footer-about-links',
        ],
        'default_injections' => [[
            'layout_type' => 'homepage',
            'slot' => 'footer-about-links',
            'area' => 'footer',
            'sort_order' => 0,
            'required' => true,
            'reason' => '页脚了解我们默认展示博客入口',
            'config' => [
                'label' => '博客',
            ],
        ]],
        'params' => [
            'label' => [
                'default' => '博客',
                'type' => 'string',
                'label' => '链接文字',
            ],
        ],
    ],
    'footer-news-link' => [
        'name' => '页脚新闻中心链接',
        'description' => '页脚了解我们扩展槽：指向博客新闻分类；默认注入 footer-about-links。',
        'type' => 'footer',
        'code' => 'footer-news-link',
        'area' => 'frontend',
        'template' => 'Weline_Blog::templates/frontend/widgets/footer-news-link.phtml',
        'page_layouts' => ['*'],
        'position' => ['footer'],
        'slot' => 'footer-about-links',
        'supports' => [
            'footer-news-link',
            'layout-footer-about-links',
        ],
        'default_injections' => [[
            'layout_type' => 'homepage',
            'slot' => 'footer-about-links',
            'area' => 'footer',
            'sort_order' => 10,
            'required' => true,
            'reason' => '页脚了解我们默认展示新闻中心（博客 news 分类）',
            'config' => [
                'label' => '新闻中心',
                'category_slug' => 'news',
            ],
        ]],
        'params' => [
            'label' => [
                'default' => '新闻中心',
                'type' => 'string',
                'label' => '链接文字',
            ],
            'category_slug' => [
                'default' => 'news',
                'type' => 'string',
                'label' => '博客分类 slug',
            ],
        ],
    ],
];
