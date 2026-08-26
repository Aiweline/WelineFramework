<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

/**
 * 默认页脚链接分组（Partials 与 footer-links 部件共用）
 *
 * 分组结构对齐亚马逊 navFooter 四列：了解我们 / 合作信息 / 支付与账户 / 帮助中心。
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
                'title' => '了解我们',
                'links' => [
                    ['label' => '关于我们', 'url' => '/about'],
                    ['label' => '人才招聘', 'url' => '/careers'],
                    ['label' => '博客', 'url' => '/blog'],
                    ['label' => '新闻中心', 'url' => '/news'],
                    ['label' => '投资者关系', 'url' => '/investors'],
                ],
            ],
            [
                'title' => '合作信息',
                'links' => [
                    ['label' => '我要开店', 'url' => '/sell'],
                    ['label' => '加入联盟', 'url' => '/affiliate'],
                    ['label' => '我要推广', 'url' => '/advertise'],
                    ['label' => '供应商合作', 'url' => '/suppliers'],
                    ['label' => '自行出版', 'url' => '/publish'],
                ],
            ],
            [
                'title' => '支付与账户',
                'links' => [
                    ['label' => '支付方式', 'url' => '/guide/payment'],
                    ['label' => '账户充值', 'url' => '/account/topup'],
                    ['label' => '礼品卡', 'url' => '/gift-cards'],
                    ['label' => '货币与汇率', 'url' => '/currency'],
                ],
            ],
            [
                'title' => '帮助中心',
                'links' => [
                    ['label' => '我的账户', 'url' => '/account'],
                    ['label' => '我的订单', 'url' => '/orders'],
                    ['label' => '配送说明', 'url' => '/shipping'],
                    ['label' => '退换政策', 'url' => '/returns'],
                    ['label' => '帮助中心', 'url' => '/help'],
                    ['label' => '联系客服', 'url' => '/support'],
                ],
            ],
        ];
    }

    /**
     * 底部法律/政策链接（亚马逊版权行风格）
     *
     * @return list<array{text:string,url:string}>
     */
    public static function legalLinks(): array
    {
        return [
            ['text' => '使用条件', 'url' => '/terms'],
            ['text' => '隐私声明', 'url' => '/privacy'],
            ['text' => 'Cookie 政策', 'url' => '/cookies'],
            ['text' => '广告偏好', 'url' => '/ads-preferences'],
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
