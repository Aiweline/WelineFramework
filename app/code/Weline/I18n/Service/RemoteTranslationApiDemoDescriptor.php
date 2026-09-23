<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

/** 远程协助翻译模块维护业务 Demo 声明，由通用 API 文档界面渲染。 */
final class RemoteTranslationApiDemoDescriptor
{
    /** @return array<string, mixed> */
    public static function describe(): array
    {
        return [
            'id' => 'remote-translation-assist-demo',
            'title' => (string)__('远程协助翻译：选站、取未译、录入与可选收集'),
            'description' => (string)__('使用当前登录后台身份调用真实 Admin REST。先选站与语种，再按 type（phrase/meta/local_model）取未译；在 items 字段填入译文后录入；可选启动词典收集并轮询状态（local_model 不可 collect）。'),
            'fields' => [
                [
                    'name' => 'website_id',
                    'label' => (string)__('Website ID'),
                    'type' => 'integer',
                    'default' => 0,
                ],
                [
                    'name' => 'type',
                    'label' => (string)__('类型 phrase|meta|local_model'),
                    'type' => 'text',
                    'default' => 'phrase',
                ],
                [
                    'name' => 'locales',
                    'label' => (string)__('目标语种 JSON 数组'),
                    'type' => 'json',
                    'default' => ['en_US'],
                ],
                [
                    'name' => 'limit',
                    'label' => (string)__('未译分页 limit'),
                    'type' => 'integer',
                    'default' => 20,
                ],
                [
                    'name' => 'cursor',
                    'label' => (string)__('未译游标'),
                    'type' => 'text',
                    'default' => '',
                ],
                [
                    'name' => 'websites',
                    'label' => (string)__('可选网站列表'),
                    'type' => 'json',
                    'readonly' => true,
                    'default' => [],
                ],
                [
                    'name' => 'site_locales',
                    'label' => (string)__('网站语种列表'),
                    'type' => 'json',
                    'readonly' => true,
                    'default' => [],
                ],
                [
                    'name' => 'pending_items',
                    'label' => (string)__('未译条目（只读预览）'),
                    'type' => 'json',
                    'readonly' => true,
                    'default' => [],
                ],
                [
                    'name' => 'has_more',
                    'label' => (string)__('是否还有未译'),
                    'type' => 'boolean',
                    'readonly' => true,
                    'default' => false,
                ],
                [
                    'name' => 'items',
                    'label' => (string)__('录入 items JSON（source/locale/translation）'),
                    'type' => 'json',
                    'default' => [
                        [
                            'source' => '',
                            'locale' => 'en_US',
                            'translation' => '',
                        ],
                    ],
                ],
                [
                    'name' => 'ingest_result',
                    'label' => (string)__('录入结果'),
                    'type' => 'json',
                    'readonly' => true,
                    'default' => [],
                ],
                [
                    'name' => 'task_id',
                    'label' => (string)__('收集 task_id'),
                    'type' => 'text',
                    'default' => '',
                ],
                [
                    'name' => 'collect_status',
                    'label' => (string)__('收集状态'),
                    'type' => 'text',
                    'readonly' => true,
                    'default' => '',
                ],
                [
                    'name' => 'collect_percent',
                    'label' => (string)__('收集进度'),
                    'type' => 'integer',
                    'readonly' => true,
                    'default' => 0,
                ],
                [
                    'name' => 'collect_message',
                    'label' => (string)__('收集消息'),
                    'type' => 'text',
                    'readonly' => true,
                    'default' => '',
                ],
            ],
            'actions' => [
                [
                    'id' => 'catalog',
                    'label' => (string)__('选站与语种'),
                    'required_fields' => [
                        'website_id',
                    ],
                    'steps' => [
                        [
                            'id' => 'get_websites',
                            'label' => (string)__('列出可选网站'),
                            'api' => [
                                'class' => 'Weline\\Websites\\Api\\Rest\\V1\\RemoteTranslationCatalog',
                                'method' => 'getWebsites',
                            ],
                            'request' => [],
                            'capture' => [
                                'websites' => 'data.items',
                            ],
                        ],
                        [
                            'id' => 'get_languages',
                            'label' => (string)__('读取网站语种'),
                            'api' => [
                                'class' => 'Weline\\Websites\\Api\\Rest\\V1\\RemoteTranslationCatalog',
                                'method' => 'getLanguages',
                            ],
                            'request' => [
                                'website_id' => [
                                    '$field' => 'website_id',
                                ],
                            ],
                            'capture' => [
                                'site_locales' => 'data.locales',
                            ],
                        ],
                    ],
                    'tone' => 'primary',
                ],
                [
                    'id' => 'pending',
                    'label' => (string)__('取未译'),
                    'required_fields' => [
                        'website_id',
                        'locales',
                        'type',
                    ],
                    'steps' => [
                        [
                            'id' => 'post_pending',
                            'label' => (string)__('分页取未译词条'),
                            'api' => [
                                'class' => 'Weline\\I18n\\Api\\Rest\\V1\\RemoteTranslation',
                                'method' => 'postPending',
                            ],
                            'request' => [
                                'website_id' => [
                                    '$field' => 'website_id',
                                ],
                                'locales' => [
                                    '$field' => 'locales',
                                ],
                                'limit' => [
                                    '$field' => 'limit',
                                ],
                                'cursor' => [
                                    '$field' => 'cursor',
                                ],
                                'type' => [
                                    '$field' => 'type',
                                ],
                            ],
                            'capture' => [
                                'pending_items' => 'data.items',
                                'cursor' => 'data.next_cursor',
                                'has_more' => 'data.has_more',
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'ingest',
                    'label' => (string)__('录入译文'),
                    'required_fields' => [
                        'website_id',
                        'items',
                        'type',
                    ],
                    'steps' => [
                        [
                            'id' => 'post_ingest',
                            'label' => (string)__('录入译文并 publish（local_model 写 Local）'),
                            'api' => [
                                'class' => 'Weline\\I18n\\Api\\Rest\\V1\\RemoteTranslation',
                                'method' => 'postIngest',
                            ],
                            'request' => [
                                'website_id' => [
                                    '$field' => 'website_id',
                                ],
                                'items' => [
                                    '$field' => 'items',
                                ],
                                'type' => [
                                    '$field' => 'type',
                                ],
                            ],
                            'capture' => [
                                'ingest_result' => 'data',
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'collect',
                    'label' => (string)__('可选：启动收集并查状态'),
                    'required_fields' => [
                        'website_id',
                        'type',
                    ],
                    'steps' => [
                        [
                            'id' => 'post_collect_start',
                            'label' => (string)__('启动远程词典收集（local_model→422）'),
                            'api' => [
                                'class' => 'Weline\\I18n\\Api\\Rest\\V1\\RemoteTranslation',
                                'method' => 'postCollectStart',
                            ],
                            'request' => [
                                'website_id' => [
                                    '$field' => 'website_id',
                                ],
                                'type' => [
                                    '$field' => 'type',
                                ],
                            ],
                            'capture' => [
                                'task_id' => 'data.task_id',
                            ],
                        ],
                        [
                            'id' => 'get_collect_status',
                            'label' => (string)__('读取收集状态'),
                            'api' => [
                                'class' => 'Weline\\I18n\\Api\\Rest\\V1\\RemoteTranslation',
                                'method' => 'getCollectStatus',
                            ],
                            'request' => [
                                'task_id' => [
                                    '$field' => 'task_id',
                                ],
                            ],
                            'capture' => [
                                'collect_status' => 'data.status',
                                'collect_percent' => 'data.percent',
                                'collect_message' => 'data.message',
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'collect_status',
                    'label' => (string)__('轮询收集状态'),
                    'required_fields' => [
                        'task_id',
                    ],
                    'steps' => [
                        [
                            'id' => 'get_collect_status',
                            'label' => (string)__('读取收集状态'),
                            'api' => [
                                'class' => 'Weline\\I18n\\Api\\Rest\\V1\\RemoteTranslation',
                                'method' => 'getCollectStatus',
                            ],
                            'request' => [
                                'task_id' => [
                                    '$field' => 'task_id',
                                ],
                            ],
                            'capture' => [
                                'collect_status' => 'data.status',
                                'collect_percent' => 'data.percent',
                                'collect_message' => 'data.message',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
