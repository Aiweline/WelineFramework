<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

/**
 * 默认页脚链接分组（Partials 与 footer-links 部件共用）
 */
final class FooterDefaultLinksHelper
{
    /**
     * @return list<array{title:string,links:list<array{label:string,url:string}>}>
     */
    public static function widgetLinkGroups(): array
    {
        return [
            [
                'title' => '关于我们',
                'links' => [
                    ['label' => '公司简介', 'url' => '/about'],
                    ['label' => '联系我们', 'url' => '/contact'],
                    ['label' => '加入我们', 'url' => '/careers'],
                    ['label' => '新闻中心', 'url' => '/news'],
                ],
            ],
            [
                'title' => '客户服务',
                'links' => [
                    ['label' => '帮助中心', 'url' => '/help'],
                    ['label' => '配送说明', 'url' => '/shipping'],
                    ['label' => '退换政策', 'url' => '/returns'],
                    ['label' => '常见问题', 'url' => '/faq'],
                ],
            ],
            [
                'title' => '购物指南',
                'links' => [
                    ['label' => '如何购物', 'url' => '/guide/shopping'],
                    ['label' => '支付方式', 'url' => '/guide/payment'],
                    ['label' => '配送方式', 'url' => '/guide/delivery'],
                    ['label' => '售后服务', 'url' => '/guide/service'],
                ],
            ],
            [
                'title' => '法律条款',
                'links' => [
                    ['label' => '隐私政策', 'url' => '/privacy'],
                    ['label' => '使用条款', 'url' => '/terms'],
                    ['label' => 'Cookie政策', 'url' => '/cookies'],
                    ['label' => '免责声明', 'url' => '/disclaimer'],
                ],
            ],
        ];
    }

    /**
     * @return list<array{title:string,items:list<array{text:string,url:string}>}>
     */
    public static function partialLinkGroups(): array
    {
        $groups = [];
        foreach (self::widgetLinkGroups() as $group) {
            $items = [];
            foreach ($group['links'] as $link) {
                $items[] = [
                    'text' => (string)($link['label'] ?? ''),
                    'url' => (string)($link['url'] ?? '#'),
                ];
            }
            $groups[] = [
                'title' => (string)($group['title'] ?? ''),
                'items' => $items,
            ];
        }

        return $groups;
    }
}
