<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Test\TestCore;
use Weline\Visitor\Model\PixelChannel;
use Weline\Visitor\Service\PixelChannelValidationService;

/**
 * B02：pixel_channel code/name 校验（纯函数，不查库；冲突用注入探测器）。
 */
class PixelChannelValidationServiceTest extends TestCore
{
    private PixelChannelValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelChannelValidationService();
    }

    public function testValidCampaignPasses(): void
    {
        $errors = $this->service->validateForCreate([
            'kind' => PixelChannel::KIND_CAMPAIGN,
            'code' => 'summer-2026_ad',
            'name' => '夏季投放',
            'traffic_type' => PixelChannel::TRAFFIC_PAID,
            'website_id' => 2,
        ]);
        self::assertSame([], $errors);
    }

    public function testCampaignCodePatternRejectsIllegalValues(): void
    {
        foreach (['A_Upper', '-lead', '_lead', 'a', 'has space', '中文码', 'x!', str_repeat('a', 33)] as $bad) {
            $errors = $this->service->validateForCreate([
                'kind' => PixelChannel::KIND_CAMPAIGN,
                'code' => $bad,
                'name' => 'n',
            ]);
            self::assertNotSame([], $errors, "code [$bad] 应被拒绝");
        }
        // 合法边界：2 位与 32 位
        foreach (['ab', 'a1', str_repeat('a', 32)] as $ok) {
            $errors = $this->service->validateForCreate([
                'kind' => PixelChannel::KIND_CAMPAIGN,
                'code' => $ok,
                'name' => 'n',
            ]);
            self::assertSame([], $errors, "code [$ok] 应通过");
        }
    }

    public function testNameAndCodeRequired(): void
    {
        $errors = $this->service->validateForCreate(['kind' => PixelChannel::KIND_CAMPAIGN]);
        self::assertCount(2, $errors);
    }

    public function testInvalidKindTrafficTypeAndWebsiteIdRejected(): void
    {
        $errors = $this->service->validateForCreate([
            'kind' => 'bogus',
            'code' => 'ok-code',
            'name' => 'n',
            'traffic_type' => 'tv',
            'website_id' => -1,
        ]);
        self::assertCount(3, $errors);
    }

    public function testCampaignRejectsMatchFieldsAndRuleRequiresThem(): void
    {
        $errors = $this->service->validateForCreate([
            'kind' => PixelChannel::KIND_CAMPAIGN,
            'code' => 'cmp1',
            'name' => 'n',
            'match_mode' => PixelChannel::MATCH_REFERER_HOST,
            'match_value' => 'facebook.com',
        ]);
        self::assertCount(1, $errors);

        $errors = $this->service->validateForCreate([
            'kind' => PixelChannel::KIND_RULE,
            'code' => 'facebook_organic',
            'name' => 'Facebook',
        ]);
        self::assertCount(2, $errors); // match_mode 无效 + match_value 必填

        $errors = $this->service->validateForCreate([
            'kind' => PixelChannel::KIND_RULE,
            'code' => 'facebook_organic',
            'name' => 'Facebook',
            'traffic_type' => PixelChannel::TRAFFIC_SOCIAL,
            'match_mode' => PixelChannel::MATCH_REFERER_HOST,
            'match_value' => 'facebook.com',
        ]);
        self::assertSame([], $errors);
    }

    public function testCreateConflictDetectedViaInjectedChecker(): void
    {
        $data = [
            'kind' => PixelChannel::KIND_CAMPAIGN,
            'code' => 'summer',
            'name' => 'n',
            'website_id' => 2,
        ];
        $seen = [];
        $exists = function (string $code, int $websiteId) use (&$seen): bool {
            $seen = [$code, $websiteId];
            return true;
        };
        $errors = $this->service->validateForCreate($data, $exists);
        self::assertCount(1, $errors);
        self::assertSame(['summer', 2], $seen);

        self::assertSame([], $this->service->validateForCreate($data, fn () => false));
    }

    public function testUpdateCampaignCodeIsImmutable(): void
    {
        $original = ['kind' => PixelChannel::KIND_CAMPAIGN, 'code' => 'summer', 'name' => 'old'];

        $errors = $this->service->validateForUpdate(
            ['kind' => PixelChannel::KIND_CAMPAIGN, 'code' => 'winter', 'name' => 'new'],
            $original,
        );
        self::assertCount(1, $errors);

        // code 不变可改 name
        $errors = $this->service->validateForUpdate(
            ['kind' => PixelChannel::KIND_CAMPAIGN, 'code' => 'summer', 'name' => 'new'],
            $original,
        );
        self::assertSame([], $errors);

        // rule 不受 code 只读约束
        $errors = $this->service->validateForUpdate(
            [
                'kind' => PixelChannel::KIND_RULE,
                'code' => 'renamed_rule',
                'name' => 'r',
                'match_mode' => PixelChannel::MATCH_UTM_MEDIUM,
                'match_value' => 'cpc',
            ],
            ['kind' => PixelChannel::KIND_RULE, 'code' => 'old_rule', 'name' => 'r'],
        );
        self::assertSame([], $errors);
    }
}
