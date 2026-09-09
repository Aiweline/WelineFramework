<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/** 产品模块维护业务 Demo 声明，由通用 API 文档界面渲染。 */
final class ProductApiDemoDescriptor
{
    /** @return array<string, mixed> */
    public static function describe(): array
    {
        return [
            'id' => 'product-rest-demo',
            'title' => (string)__('产品创建、发布与多语言编辑'),
            'description' => (string)__('使用当前登录身份调用真实产品 API。创建后自动回读版本并显式发布，随后返回真实店面链接；每一步响应可检查。'),
            'fields' => [
                [
                    'name' => 'website_id',
                    'label' => (string)__('Website ID'),
                    'type' => 'integer',
                    'default' => 0,
                ],
                [
                    'name' => 'sku',
                    'label' => (string)__('SKU（每次新建使用唯一值）'),
                    'type' => 'text',
                    'generate' => 'unique',
                    'prefix' => 'API-DEMO-',
                ],
                [
                    'name' => 'name',
                    'label' => (string)__('当前语言的产品名称'),
                    'type' => 'text',
                    'default' => 'API 文档演示商品',
                ],
                [
                    'name' => 'locale',
                    'label' => (string)__('当前编辑语言'),
                    'type' => 'locale',
                    'default' => 'zh_Hans_CN',
                ],
                [
                    'name' => 'price_minor',
                    'label' => (string)__('创建价格（最小货币单位，CNY 为分）'),
                    'type' => 'integer',
                    'default' => 100,
                ],
                [
                    'name' => 'currency',
                    'label' => (string)__('币种'),
                    'type' => 'text',
                    'default' => 'CNY',
                ],
                [
                    'name' => 'translations',
                    'label' => (string)__('各语言译文 JSON（同语言的显式字段优先）'),
                    'type' => 'json',
                    'default' => [
                        'en_US' => [
                            'name' => 'API documentation demo product',
                        ],
                    ],
                ],
                [
                    'name' => 'translate_to',
                    'label' => (string)__('自动翻译目标 JSON（需已配置翻译服务）'),
                    'type' => 'json',
                    'default' => [],
                ],
                [
                    'name' => 'product_id',
                    'label' => (string)__('产品 ID'),
                    'type' => 'integer',
                    'readonly' => true,
                    'default' => '',
                ],
                [
                    'name' => 'global_product_uuid',
                    'label' => (string)__('产品 UUID'),
                    'type' => 'text',
                    'default' => '',
                ],
                [
                    'name' => 'local_version',
                    'label' => (string)__('Website 版本'),
                    'type' => 'integer',
                    'readonly' => true,
                    'default' => '',
                ],
                [
                    'name' => 'expected_version',
                    'label' => (string)__('全局身份版本'),
                    'type' => 'integer',
                    'readonly' => true,
                    'default' => '',
                ],
            ],
            'actions' => [
                [
                    'id' => 'create_publish',
                    'label' => (string)__('创建并发布'),
                    'required_fields' => [
                        'website_id',
                        'sku',
                        'name',
                        'locale',
                        'price_minor',
                        'currency',
                    ],
                    'steps' => [
                        [
                            'id' => 'create',
                            'label' => (string)__('创建草稿'),
                            'api' => [
                                'class' => 'Weline\\Product\\Api\\Rest\\V1\\Products',
                                'method' => 'postCreate',
                            ],
                            'request' => [
                                'website_id' => [
                                    '$field' => 'website_id',
                                ],
                                'locale' => [
                                    '$field' => 'locale',
                                ],
                                'payload' => [
                                    'name' => [
                                        '$field' => 'name',
                                    ],
                                    'sku' => [
                                        '$field' => 'sku',
                                    ],
                                    'product_type' => 'simple',
                                    'price_minor' => [
                                        '$field' => 'price_minor',
                                    ],
                                    'currency' => [
                                        '$field' => 'currency',
                                    ],
                                ],
                                'translations' => [
                                    '$field' => 'translations',
                                ],
                                'translate_to' => [
                                    '$field' => 'translate_to',
                                ],
                            ],
                            'capture' => [
                                'global_product_uuid' => 'data.identity.global_product_uuid',
                                'product_id' => 'data.product_id',
                                'expected_version' => 'data.identity.version',
                            ],
                        ],
                        [
                            'id' => 'detail',
                            'label' => (string)__('回读产品'),
                            'api' => [
                                'class' => 'Weline\\Product\\Api\\Rest\\V1\\Products',
                                'method' => 'getDetail',
                            ],
                            'request' => [
                                'website_id' => [
                                    '$field' => 'website_id',
                                ],
                                'global_product_uuid' => [
                                    '$field' => 'global_product_uuid',
                                ],
                                'locale' => [
                                    '$field' => 'locale',
                                ],
                                'currency' => [
                                    '$field' => 'currency',
                                ],
                            ],
                            'capture' => [
                                'global_product_uuid' => 'data.identity.global_product_uuid',
                                'product_id' => 'data.product.product_id',
                                'local_version' => 'data.product.publish_version',
                                'expected_version' => 'data.identity.version',
                                'storefront_urls' => 'data.storefront_urls',
                                'name' => 'data.content.name',
                            ],
                        ],
                        [
                            'id' => 'publish',
                            'label' => (string)__('发布产品'),
                            'api' => [
                                'class' => 'Weline\\Product\\Api\\Rest\\V1\\Products',
                                'method' => 'postPublish',
                            ],
                            'request' => [
                                'website_id' => [
                                    '$field' => 'website_id',
                                ],
                                'global_product_uuid' => [
                                    '$field' => 'global_product_uuid',
                                ],
                                'expected_version' => [
                                    '$field' => 'expected_version',
                                ],
                                'payload' => [
                                    'local_version' => [
                                        '$field' => 'local_version',
                                    ],
                                    'locale' => [
                                        '$field' => 'locale',
                                    ],
                                    'currency' => [
                                        '$field' => 'currency',
                                    ],
                                ],
                            ],
                            'capture' => [
                                'local_version' => 'data.product.publish_version',
                                'expected_version' => 'data.identity.version',
                            ],
                        ],
                        [
                            'id' => 'detail',
                            'label' => (string)__('回读产品'),
                            'api' => [
                                'class' => 'Weline\\Product\\Api\\Rest\\V1\\Products',
                                'method' => 'getDetail',
                            ],
                            'request' => [
                                'website_id' => [
                                    '$field' => 'website_id',
                                ],
                                'global_product_uuid' => [
                                    '$field' => 'global_product_uuid',
                                ],
                                'locale' => [
                                    '$field' => 'locale',
                                ],
                                'currency' => [
                                    '$field' => 'currency',
                                ],
                            ],
                            'capture' => [
                                'global_product_uuid' => 'data.identity.global_product_uuid',
                                'product_id' => 'data.product.product_id',
                                'local_version' => 'data.product.publish_version',
                                'expected_version' => 'data.identity.version',
                                'storefront_urls' => 'data.storefront_urls',
                                'name' => 'data.content.name',
                            ],
                        ],
                    ],
                    'tone' => 'primary',
                ],
                [
                    'id' => 'edit',
                    'label' => (string)__('保存当前语言并回读'),
                    'required_fields' => [
                        'website_id',
                        'global_product_uuid',
                        'local_version',
                        'locale',
                        'name',
                    ],
                    'steps' => [
                        [
                            'id' => 'edit',
                            'label' => (string)__('保存当前语言'),
                            'api' => [
                                'class' => 'Weline\\Product\\Api\\Rest\\V1\\Products',
                                'method' => 'putEdit',
                            ],
                            'request' => [
                                'website_id' => [
                                    '$field' => 'website_id',
                                ],
                                'global_product_uuid' => [
                                    '$field' => 'global_product_uuid',
                                ],
                                'locale' => [
                                    '$field' => 'locale',
                                ],
                                'payload' => [
                                    'local_version' => [
                                        '$field' => 'local_version',
                                    ],
                                    'name' => [
                                        '$field' => 'name',
                                    ],
                                ],
                                'translate_to' => [
                                    '$field' => 'translate_to',
                                ],
                            ],
                            'capture' => [
                                'local_version' => 'data.local_version',
                                'expected_version' => 'data.identity.version',
                            ],
                        ],
                        [
                            'id' => 'detail',
                            'label' => (string)__('回读产品'),
                            'api' => [
                                'class' => 'Weline\\Product\\Api\\Rest\\V1\\Products',
                                'method' => 'getDetail',
                            ],
                            'request' => [
                                'website_id' => [
                                    '$field' => 'website_id',
                                ],
                                'global_product_uuid' => [
                                    '$field' => 'global_product_uuid',
                                ],
                                'locale' => [
                                    '$field' => 'locale',
                                ],
                                'currency' => [
                                    '$field' => 'currency',
                                ],
                            ],
                            'capture' => [
                                'global_product_uuid' => 'data.identity.global_product_uuid',
                                'product_id' => 'data.product.product_id',
                                'local_version' => 'data.product.publish_version',
                                'expected_version' => 'data.identity.version',
                                'storefront_urls' => 'data.storefront_urls',
                                'name' => 'data.content.name',
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'edit_translations',
                    'label' => (string)__('保存多语言译文并回读'),
                    'required_fields' => [
                        'website_id',
                        'global_product_uuid',
                        'local_version',
                    ],
                    'steps' => [
                        [
                            'id' => 'edit_translations',
                            'label' => (string)__('保存多语言译文'),
                            'api' => [
                                'class' => 'Weline\\Product\\Api\\Rest\\V1\\Products',
                                'method' => 'putEdit',
                            ],
                            'request' => [
                                'website_id' => [
                                    '$field' => 'website_id',
                                ],
                                'global_product_uuid' => [
                                    '$field' => 'global_product_uuid',
                                ],
                                'payload' => [
                                    'local_version' => [
                                        '$field' => 'local_version',
                                    ],
                                ],
                                'translations' => [
                                    '$field' => 'translations',
                                ],
                            ],
                            'capture' => [
                                'local_version' => 'data.local_version',
                                'expected_version' => 'data.identity.version',
                            ],
                        ],
                        [
                            'id' => 'detail',
                            'label' => (string)__('回读产品'),
                            'api' => [
                                'class' => 'Weline\\Product\\Api\\Rest\\V1\\Products',
                                'method' => 'getDetail',
                            ],
                            'request' => [
                                'website_id' => [
                                    '$field' => 'website_id',
                                ],
                                'global_product_uuid' => [
                                    '$field' => 'global_product_uuid',
                                ],
                                'locale' => [
                                    '$field' => 'locale',
                                ],
                                'currency' => [
                                    '$field' => 'currency',
                                ],
                            ],
                            'capture' => [
                                'global_product_uuid' => 'data.identity.global_product_uuid',
                                'product_id' => 'data.product.product_id',
                                'local_version' => 'data.product.publish_version',
                                'expected_version' => 'data.identity.version',
                                'storefront_urls' => 'data.storefront_urls',
                                'name' => 'data.content.name',
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'publish',
                    'label' => (string)__('发布已保存的产品'),
                    'required_fields' => [
                        'website_id',
                        'global_product_uuid',
                        'local_version',
                        'expected_version',
                    ],
                    'steps' => [
                        [
                            'id' => 'publish',
                            'label' => (string)__('发布产品'),
                            'api' => [
                                'class' => 'Weline\\Product\\Api\\Rest\\V1\\Products',
                                'method' => 'postPublish',
                            ],
                            'request' => [
                                'website_id' => [
                                    '$field' => 'website_id',
                                ],
                                'global_product_uuid' => [
                                    '$field' => 'global_product_uuid',
                                ],
                                'expected_version' => [
                                    '$field' => 'expected_version',
                                ],
                                'payload' => [
                                    'local_version' => [
                                        '$field' => 'local_version',
                                    ],
                                    'locale' => [
                                        '$field' => 'locale',
                                    ],
                                    'currency' => [
                                        '$field' => 'currency',
                                    ],
                                ],
                            ],
                            'capture' => [
                                'local_version' => 'data.product.publish_version',
                                'expected_version' => 'data.identity.version',
                            ],
                        ],
                        [
                            'id' => 'detail',
                            'label' => (string)__('回读产品'),
                            'api' => [
                                'class' => 'Weline\\Product\\Api\\Rest\\V1\\Products',
                                'method' => 'getDetail',
                            ],
                            'request' => [
                                'website_id' => [
                                    '$field' => 'website_id',
                                ],
                                'global_product_uuid' => [
                                    '$field' => 'global_product_uuid',
                                ],
                                'locale' => [
                                    '$field' => 'locale',
                                ],
                                'currency' => [
                                    '$field' => 'currency',
                                ],
                            ],
                            'capture' => [
                                'global_product_uuid' => 'data.identity.global_product_uuid',
                                'product_id' => 'data.product.product_id',
                                'local_version' => 'data.product.publish_version',
                                'expected_version' => 'data.identity.version',
                                'storefront_urls' => 'data.storefront_urls',
                                'name' => 'data.content.name',
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'detail',
                    'label' => (string)__('回读产品'),
                    'required_fields' => [
                        'website_id',
                        'global_product_uuid',
                    ],
                    'steps' => [
                        [
                            'id' => 'detail',
                            'label' => (string)__('回读产品'),
                            'api' => [
                                'class' => 'Weline\\Product\\Api\\Rest\\V1\\Products',
                                'method' => 'getDetail',
                            ],
                            'request' => [
                                'website_id' => [
                                    '$field' => 'website_id',
                                ],
                                'global_product_uuid' => [
                                    '$field' => 'global_product_uuid',
                                ],
                                'locale' => [
                                    '$field' => 'locale',
                                ],
                                'currency' => [
                                    '$field' => 'currency',
                                ],
                            ],
                            'capture' => [
                                'global_product_uuid' => 'data.identity.global_product_uuid',
                                'product_id' => 'data.product.product_id',
                                'local_version' => 'data.product.publish_version',
                                'expected_version' => 'data.identity.version',
                                'storefront_urls' => 'data.storefront_urls',
                                'name' => 'data.content.name',
                            ],
                        ],
                    ],
                ],
            ],
            'links' => [
                [
                    'label' => (string)__('查看店面产品'),
                    'field' => 'storefront_urls',
                ],
            ],
        ];
    }
}
