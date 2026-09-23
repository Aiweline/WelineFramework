<?php

declare(strict_types=1);

/**
 * StoreMusic 前台部件：进店音乐浮层。
 * 店面全站仍可由 base::body-end Hook 兜底；homepage required default_injection
 * 保证「恢复原始布局」后应用 Tab / 默认注入回填仍保留。
 * 曲目与播放行为在主题编辑器「部件配置」中编辑（params）；SystemConfig / 后台页作 Hook 兜底与迁移回退。
 */

/** @var list<array{url:string,title:string,intro:array<string,string>}> $defaultTracks */
$defaultTracksFile = dirname(__DIR__, 4) . '/etc/default-tracks.php';
$defaultTracks = is_file($defaultTracksFile) ? require $defaultTracksFile : [];
if (!is_array($defaultTracks)) {
    $defaultTracks = [];
}

return [
    'store-music' => [
        'name' => '进店音乐',
        'description' => '左下角进店氛围音乐浮层；应用默认注入，恢复原始布局后 required 回填。曲目与播放在部件配置中设置。',
        'type' => 'content',
        'code' => 'store-music',
        'area' => 'frontend',
        'template' => 'Weline_StoreMusic::templates/frontend/widgets/store-music.phtml',
        'page_layouts' => ['*'],
        'position' => ['content'],
        'slot' => 'content',
        'supports' => [
            'store-music',
            'layout-homepage-content',
            'content',
        ],
        'default_injections' => [[
            'layout_type' => 'homepage',
            'slot' => 'content',
            'area' => 'content',
            'sort_order' => 900,
            'required' => true,
            'reason' => '店面默认进店音乐浮层；恢复原始布局后 required 回填',
            'config' => [
                'enabled' => false,
                'tracks' => $defaultTracks,
                'delay_seconds' => 3,
                'try_autoplay' => true,
                'loop' => true,
                'default_volume' => 8,
                'avatar_spin' => false,
                'waveform_default' => false,
            ],
        ]],
        'params' => [
            'enabled' => [
                'default' => false,
                'type' => 'bool',
                'label' => '启用进店音乐',
                'description' => '关闭后前台不再展示进店音乐浮层；需至少一首曲目才会显示。默认关闭。',
                'group' => 'basic',
                'i18n' => false,
            ],
            'tracks' => [
                'default' => $defaultTracks,
                'type' => 'array',
                'label' => '进店曲目',
                'description' => '从媒体库选择曲目（可多选添加）；拖拽排序。部件曲目为空时回退到系统配置歌单。简介可按编辑器语言分别填写。',
                'group' => 'basic',
                'i18n' => false,
                'sortable' => true,
                'add_label' => '添加曲目',
                'add_with_media_label' => '选择曲目添加',
                'empty_message' => '尚未添加曲目。请用「选择曲目添加」从媒体库挑选。',
                'item_schema' => [
                    'url' => [
                        'type' => 'media_image',
                        'label' => '曲目',
                        'default' => '',
                        'placeholder' => '从媒体库选择曲目',
                        'i18n' => false,
                        'media_options' => [
                            'kind' => 'audio',
                            'default_directory' => 'store-music',
                            'ext' => 'mp3,wav,ogg,oga,m4a,aac,flac,opus,wma,weba',
                            'size' => '20971520',
                            'usage' => '0',
                            'value_mode' => '',
                            'picker_title' => '选择曲目',
                        ],
                    ],
                    'title' => [
                        'type' => 'string',
                        'label' => '曲名',
                        'default' => '',
                        'i18n' => false,
                    ],
                    'intro' => [
                        'type' => 'textarea',
                        'label' => '简介',
                        'default' => '',
                        'description' => '按当前编辑语言保存；店面按访客语言展示。',
                        'i18n' => true,
                    ],
                ],
            ],
            'delay_seconds' => [
                'default' => 3,
                'type' => 'range',
                'label' => '延迟秒数',
                'description' => '页面 load 完成后再等待的秒数，然后再尝试加载/播放。',
                'group' => 'advanced',
                'min' => 1,
                'max' => 15,
                'step' => 1,
                'unit' => 's',
                'i18n' => false,
            ],
            'try_autoplay' => [
                'default' => true,
                'type' => 'bool',
                'label' => '尝试自动播放',
                'description' => '延迟后尝试自动出声；浏览器拦截时浮层提示点击播放。',
                'group' => 'advanced',
                'i18n' => false,
            ],
            'loop' => [
                'default' => true,
                'type' => 'bool',
                'label' => '循环播放',
                'description' => '单曲或歌单循环。',
                'group' => 'advanced',
                'i18n' => false,
            ],
            'default_volume' => [
                'default' => 8,
                'type' => 'range',
                'label' => '默认音量',
                'description' => '0–100。进店氛围请保持轻声（建议 6–10）。',
                'group' => 'advanced',
                'min' => 0,
                'max' => 100,
                'step' => 1,
                'unit' => '%',
                'i18n' => false,
            ],
            'avatar_spin' => [
                'default' => false,
                'type' => 'bool',
                'label' => '播放时旋转头像',
                'description' => '开启后播放中头像匀速旋转；默认关闭。',
                'group' => 'style',
                'i18n' => false,
            ],
            'waveform_default' => [
                'default' => false,
                'type' => 'bool',
                'label' => '默认开启波形背景',
                'description' => '开启后访客首次进入默认打开全页波形；仍可在浮层上关闭。',
                'group' => 'style',
                'i18n' => false,
            ],
        ],
    ],
];
