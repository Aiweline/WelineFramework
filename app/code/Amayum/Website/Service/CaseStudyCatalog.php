<?php

declare(strict_types=1);

namespace Amayum\Website\Service;

/**
 * 三个运行中的例子：只写已验证事实与明确不证明项。
 *
 * 单一来源：/case-studies 可见内容与 ItemList schema 共用本目录。
 */
final class CaseStudyCatalog
{
    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return [
            [
                'key' => 'changanhanfu',
                'name' => '長安漢服',
                'domain' => 'changanhanfu.com',
                'url' => 'https://changanhanfu.com',
                'type' => 'production',
                'type_label' => '生产环境',
                'theme' => 'hanfu',
                'environment' => '生产环境 WLS 8×512M、MySQL',
                'problem' => '海外用户卡在尺码、面料与到手税费。',
                'approach' => [
                    '商品属性结构化',
                    '尺码按身体数据',
                    '英语主站 + 语言分批',
                    '税费与物流节点前置',
                ],
                'verified_facts' => [
                    '主题 hanfu（父主题 Default）',
                    '生产 app root /www/wwwroot/changanhanfu.com',
                    'WLS 8×512M；MySQL',
                    '观察到的 333 个商品 URL、10 个店面币种',
                    '该站结账使用 PayPal',
                    '已接 Google/Bing/IndexNow/Yandex',
                ],
                'not_proven' => [
                    '不证明多网关支付',
                    '不证明所有语言已翻译',
                    '不证明增长/转化',
                ],
            ],
            [
                'key' => 'playinginchina',
                'name' => 'PlayingInChina',
                'domain' => 'playinginchina.com',
                'url' => 'https://playinginchina.com',
                'type' => 'production',
                'type_label' => '生产环境',
                'theme' => 'playinginchina',
                'environment' => '生产环境 WLS 4×512M、MySQL',
                'problem' => '线路、门票、地图与支付分散在多个平台，信息以中文为主。',
                'approach' => [
                    '线路与门票两种商品模型',
                    '日期库存',
                    '英文优先',
                    '移动端行程页',
                ],
                'verified_facts' => [
                    '主题 playinginchina',
                    'default_language=en_US，zh_Hans_CN 第二',
                    'WLS 4×512M；MySQL',
                    '338 个已发布商品（8 个套餐 + 330 张门票）、37 个分类',
                    '383 条 URL 重写、21 条路由测试 200',
                    '386 个静态页作为 SEO/CDN 补充',
                ],
                'not_proven' => [
                    '不证明全语言内容',
                    '386 个静态页不是框架 SSR',
                    '不证明增长/转化',
                ],
            ],
            [
                'key' => 'daocharms',
                'name' => 'DaoCharms',
                'domain' => 'daocharms.com',
                'url' => 'https://daocharms.com',
                'type' => 'local',
                'type_label' => '本地 / 非生产',
                'theme' => 'daocharms',
                'environment' => '本地/非生产；无生产 app root',
                'problem' => '品类容易被做成猎奇陈列。',
                'approach' => [
                    '编辑式 PDP',
                    '材质与工艺先行',
                    '克制动效',
                    '文化来源写清楚',
                ],
                'verified_facts' => [
                    '设计主题 daocharms',
                    '本地 guest 试用订单 USD 280.93、tax 21.18、shipping 7.75',
                    'payment fake_card',
                ],
                'not_proven' => [
                    '不证明真实支付/物流/税费',
                    '不是 live store',
                    '不证明增长/转化',
                ],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        foreach ($this->all() as $caseStudy) {
            if ($caseStudy['key'] === $key) {
                return $caseStudy;
            }
        }

        return null;
    }

    /** @return list<array{name: string, url: string, description: string}> */
    public function itemList(): array
    {
        $items = [];
        foreach ($this->all() as $caseStudy) {
            $items[] = [
                'name' => (string)$caseStudy['name'],
                'url' => (string)$caseStudy['url'],
                'description' => (string)$caseStudy['problem'],
            ];
        }

        return $items;
    }
}
