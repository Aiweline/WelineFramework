<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Visitor\Model\PixelChannel;
use Weline\Visitor\Service\PixelChannelLookupService;
use Weline\Visitor\Service\PixelEventService;

/**
 * B09：S3 rule 匹配（注入 rules；A03 仍纯函数）。
 */
class PixelChannelRuleBindingTest extends TestCore
{
    private PixelChannelLookupService $lookup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lookup = new PixelChannelLookupService();
    }

    public function testRefererHostRuleFillsCodeNameAndSocialType(): void
    {
        $rules = [[
            'kind' => PixelChannel::KIND_RULE,
            'code' => 'facebook',
            'name' => 'Facebook',
            'traffic_type' => PixelChannel::TRAFFIC_SOCIAL,
            'match_mode' => PixelChannel::MATCH_REFERER_HOST,
            'match_value' => 'facebook,fb.com',
            'priority' => 100,
            'enabled' => 1,
            'website_id' => 0,
        ]];

        $bound = $this->lookup->applyRuleBinding([
            'channel_code' => '',
            'channel_name' => '',
            'traffic_type' => 'referral',
            'referer_host' => 'l.facebook.com',
            'utm_source' => '',
            'utm_medium' => '',
        ], 1, $rules);

        self::assertTrue($bound['rule_bound']);
        self::assertSame('facebook', $bound['channel_code']);
        self::assertSame('Facebook', $bound['channel_name']);
        self::assertSame('social', $bound['traffic_type']);
        self::assertSame(PixelChannel::MATCH_REFERER_HOST, $bound['rule_match_mode']);
    }

    public function testRuleSkippedWhenChannelCodeAlreadyPresent(): void
    {
        $called = false;
        $rules = [[
            'code' => 'facebook',
            'name' => 'Facebook',
            'traffic_type' => 'social',
            'match_mode' => PixelChannel::MATCH_REFERER_HOST,
            'match_value' => 'facebook',
            'priority' => 1,
            'enabled' => 1,
            'website_id' => 0,
        ]];
        // applyRuleBinding 不调用 loader when code present — rules unused but should not apply
        $bound = $this->lookup->applyRuleBinding([
            'channel_code' => 'summer_ad',
            'channel_name' => '夏季',
            'traffic_type' => 'paid',
            'referer_host' => 'facebook.com',
        ], 1, $rules);

        self::assertSame('summer_ad', $bound['channel_code']);
        self::assertArrayNotHasKey('rule_bound', $bound);
        unset($called);
    }

    public function testPriorityAndSitePreference(): void
    {
        $rules = [
            [
                'code' => 'global_fb',
                'name' => 'Global FB',
                'traffic_type' => 'social',
                'match_mode' => PixelChannel::MATCH_REFERER_HOST,
                'match_value' => 'facebook',
                'priority' => 50,
                'enabled' => 1,
                'website_id' => 0,
            ],
            [
                'code' => 'site_fb',
                'name' => 'Site FB',
                'traffic_type' => 'social',
                'match_mode' => PixelChannel::MATCH_REFERER_HOST,
                'match_value' => 'facebook',
                'priority' => 50,
                'enabled' => 1,
                'website_id' => 9,
            ],
        ];
        $bound = $this->lookup->applyRuleBinding([
            'channel_code' => '',
            'referer_host' => 'm.facebook.com',
        ], 9, $rules);
        self::assertSame('site_fb', $bound['channel_code']);
    }

    public function testUtmMediumAndClickIdAndQueryParamMatchers(): void
    {
        self::assertTrue($this->lookup->ruleMatches([
            'match_mode' => PixelChannel::MATCH_UTM_MEDIUM,
            'match_value' => 'cpc,ppc',
            'enabled' => 1,
        ], ['utm_medium' => 'cpc']));

        self::assertTrue($this->lookup->ruleMatches([
            'match_mode' => PixelChannel::MATCH_CLICK_ID,
            'match_value' => 'gclid',
            'enabled' => 1,
        ], ['gclid' => 'abc']));

        self::assertTrue($this->lookup->ruleMatches([
            'match_mode' => PixelChannel::MATCH_QUERY_PARAM,
            'match_value' => 'utm_source=newsletter',
            'enabled' => 1,
        ], ['utm_source' => 'newsletter']));

        self::assertFalse($this->lookup->ruleMatches([
            'match_mode' => PixelChannel::MATCH_REFERER_HOST,
            'match_value' => 'facebook',
            'enabled' => 0,
        ], ['referer_host' => 'facebook.com']));
    }

    public function testHydrateAppliesRuleWhenNoWch(): void
    {
        $lookup = new class extends PixelChannelLookupService {
            public function applyCampaignBinding(array $attribution, int $websiteId, ?callable $finder = null): array
            {
                return $attribution;
            }

            public function applyRuleBinding(array $attribution, int $websiteId, ?array $rules = null): array
            {
                if (($attribution['channel_code'] ?? '') !== '') {
                    return $attribution;
                }
                $attribution['channel_code'] = 'facebook';
                $attribution['channel_name'] = 'Facebook';
                $attribution['traffic_type'] = 'social';
                $attribution['rule_bound'] = true;

                return $attribution;
            }
        };

        /** @var PixelEventService $service */
        $service = ObjectManager::getInstance(PixelEventService::class);
        $prop = (new \ReflectionClass($service))->getProperty('channelLookupService');
        $prop->setAccessible(true);
        $prop->setValue($service, $lookup);

        try {
            $data = $service->hydratePreparedAttribution(
                [
                    'url' => 'https://example.test/home',
                    'referer' => 'https://l.facebook.com/',
                    'websiteId' => 3,
                ],
                [
                    'url' => 'https://example.test/home',
                    'referer' => 'https://l.facebook.com/',
                    'website_id' => 3,
                    'session_id' => 'wps-b09',
                ]
            );
            self::assertSame('facebook', $data['channel_code']);
            self::assertSame('Facebook', $data['channel_name']);
            self::assertSame('social', $data['traffic_type']);
        } finally {
            $prop->setValue($service, null);
        }
    }
}
