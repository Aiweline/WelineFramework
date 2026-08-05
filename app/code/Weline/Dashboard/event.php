<?php

return [
    'Weline_Dashboard::layout_page_ensure' => [
        'name' => __('确保 Dashboard 布局页面身份'),
        'description' => __('模块可派发此事件，请求 Dashboard 创建或复用一个同布局页面身份，并在首次创建或布局为空时写入指定 Theme 布局部件。'),
    ],
    'Weline_Dashboard::layout_identity_ready' => [
        'name' => __('Dashboard 布局身份就绪'),
        'description' => __('Dashboard 视图布局身份初始化成功后派发。Theme 可据此按 default_injections.default_view 定向补齐该视图的默认部件（draft+published），并尊重 user_deleted 与空布局哨兵。载荷含 theme_id、page_type=dashboard、view_code、component_area=backend 与完整 layout identity。'),
    ],
];
