<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\FooterDefaultLinksHelper;

final class FooterDefaultLinksHelperTest extends TestCase
{
    public function testPartialLinkGroupsUseTextItems(): void
    {
        $groups = FooterDefaultLinksHelper::partialLinkGroups();

        $this->assertNotEmpty($groups);
        $this->assertArrayHasKey('title', $groups[0]);
        $this->assertArrayHasKey('items', $groups[0]);
        $this->assertArrayHasKey('text', $groups[0]['items'][0]);
        $this->assertArrayHasKey('url', $groups[0]['items'][0]);
    }

    public function testWidgetLinkGroupsUseLabelLinks(): void
    {
        $groups = FooterDefaultLinksHelper::widgetLinkGroups();

        $this->assertNotEmpty($groups);
        $this->assertCount(4, $groups);
        $this->assertSame('了解我们', $groups[0]['title']);
        $this->assertArrayHasKey('links', $groups[0]);
        $this->assertArrayHasKey('label', $groups[0]['links'][0]);
        $this->assertArrayHasKey('url', $groups[0]['links'][0]);
        $this->assertCount(1, $groups[0]['links']);
        $this->assertSame('关于我们', $groups[0]['links'][0]['label']);
        $labels = array_column($groups[0]['links'], 'label');
        $this->assertNotContains('人才招聘', $labels);
        $this->assertNotContains('投资者关系', $labels);
        $this->assertNotContains('博客', $labels);
        $this->assertNotContains('新闻中心', $labels);

        $this->assertSame('合作信息', $groups[1]['title']);
        $partnerLabels = array_column($groups[1]['links'], 'label');
        $this->assertSame(['供应商合作'], $partnerLabels);
        $this->assertNotContains('我要开店', $partnerLabels);
        $this->assertNotContains('加入联盟', $partnerLabels);
        $this->assertNotContains('我要推广', $partnerLabels);
        $this->assertNotContains('自行出版', $partnerLabels);
        $partnerUrls = array_column($groups[1]['links'], 'url');
        $this->assertSame(['/inquiry/suppliers'], $partnerUrls);
        $this->assertNotContains('/publish', $partnerUrls);
        $this->assertNotContains('/sell', $partnerUrls);
        $this->assertNotContains('/advertise', $partnerUrls);
        $this->assertNotContains('/affiliate', $partnerUrls);

        $this->assertSame('支付与账户', $groups[2]['title']);
        $this->assertSame([], $groups[2]['links']);
        $paymentLabels = array_column($groups[2]['links'], 'label');
        $this->assertNotContains('支付方式', $paymentLabels);
        $this->assertNotContains('账户充值', $paymentLabels);
        $this->assertNotContains('礼品卡', $paymentLabels);
        $this->assertNotContains('货币与汇率', $paymentLabels);
    }

    public function testLegalLinksPresent(): void
    {
        $links = FooterDefaultLinksHelper::legalLinks();

        $this->assertNotEmpty($links);
        $this->assertArrayHasKey('text', $links[0]);
        $this->assertArrayHasKey('url', $links[0]);

        $byText = [];
        foreach ($links as $link) {
            $byText[(string)$link['text']] = (string)$link['url'];
        }
        $this->assertSame('/terms', $byText['使用条件'] ?? null);
        $this->assertSame('/privacy', $byText['隐私声明'] ?? null);
        $this->assertSame('/cookies', $byText['Cookie 政策'] ?? null);
        $this->assertSame('/ads-preferences', $byText['广告偏好'] ?? null);
    }
}
