<?php

declare(strict_types=1);

return [
    'scope-switcher' => [
        'name' => '范围切换',
        'description' => '前台网站/店铺/渠道范围切换；仅存在非 default 店或渠时显示。',
        'type' => 'header',
        'code' => 'scope-switcher',
        'area' => 'frontend',
        'template' => 'Weline_Websites::templates/frontend/widgets/scope-switcher.phtml',
        // Bake/registry 回落：与模板 @widget.source 同值；禁内联 <style>。
        'source' => 'Weline_Websites::css/widgets/scope-switcher.css',
        'page_layouts' => ['*'],
        'position' => ['header'],
        'slot' => 'scope-switcher',
        'supports' => [
            'scope-switcher',
            'layout-header-scope-switcher',
        ],
        'default_injections' => [[
            'layout_type' => 'homepage',
            'slot' => 'scope-switcher',
            'area' => 'header',
            'sort_order' => 35,
            'required' => true,
            'reason' => 'Header chrome 默认提供范围切换入口',
            'config' => [],
        ]],
        'params' => [],
    ],
];
