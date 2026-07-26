<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\PixelChannelEcommerceFunnelService;
use Weline\Visitor\Service\PixelChannelFunnelMode;
use Weline\Visitor\Service\PixelChannelFunnelService;
use Weline\Visitor\Service\PixelChannelHotTotalsService;
use Weline\Visitor\Service\PixelEcommerceFunnelService;

/**
 * F05b：渠道详情漏斗可切换（营销简漏斗 / 电商四步）。
 */
final class PixelChannelEcommerceFunnelServiceTest extends TestCase
{
    private PixelChannelEcommerceFunnelService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelChannelEcommerceFunnelService(
            new PixelChannelHotTotalsService(),
            new PixelEcommerceFunnelService()
        );
    }

    public function testModeNormalizationDefaultsToMarketing(): void
    {
        self::assertSame(PixelChannelFunnelMode::MARKETING, PixelChannelFunnelMode::normalize(null));
        self::assertSame(PixelChannelFunnelMode::MARKETING, PixelChannelFunnelMode::normalize(''));
        self::assertSame(PixelChannelFunnelMode::MARKETING, PixelChannelFunnelMode::normalize('bogus'));
        self::assertSame(PixelChannelFunnelMode::MARKETING, PixelChannelFunnelMode::normalize('MARKETING'));
        self::assertSame(PixelChannelFunnelMode::ECOMMERCE, PixelChannelFunnelMode::normalize(' Ecommerce '));
        self::assertTrue(PixelChannelFunnelMode::isEcommerce('ecommerce'));
        self::assertFalse(PixelChannelFunnelMode::isEcommerce('marketing'));
        self::assertFalse(PixelChannelFunnelMode::isEcommerce(null));
    }

    public function testStepsMatchDictionaryFourStepsNotMarketing(): void
    {
        self::assertSame(PixelEcommerceFunnelService::STEP_ORDER, PixelChannelEcommerceFunnelService::STEP_ORDER);
        self::assertNotSame(PixelChannelFunnelService::STEP_ORDER, PixelChannelEcommerceFunnelService::STEP_ORDER);
        self::assertTrue($this->service->eventMatchesStep('view_item', 'view_item'));
        self::assertTrue($this->service->eventMatchesStep('purchase', 'checkout_success'));
        self::assertFalse($this->service->eventMatchesStep('page_view', 'view_item'));
        self::assertFalse($this->service->eventMatchesStep('lead_submit', 'checkout_success'));
    }

    public function testSequentialGateSharesEcommerceSemantics(): void
    {
        $events = [
            ['session_id' => 'a', 'event' => 'view_item'],
            ['session_id' => 'a', 'event' => 'add_to_cart'],
            ['session_id' => 'a', 'event' => 'begin_checkout'],
            ['session_id' => 'a', 'event' => 'purchase'],
            // 跳步会话：无 add_to_cart，则后续步不计
            ['session_id' => 'b', 'event' => 'view_item'],
            ['session_id' => 'b', 'event' => 'begin_checkout'],
            ['session_id' => 'b', 'event' => 'checkout_success'],
            // 无 session_id 丢弃
            ['session_id' => '', 'event' => 'view_item'],
        ];

        $result = $this->service->computeFromEvents($events);
        $byKey = [];
        foreach ($result['steps'] as $step) {
            $byKey[$step['key']] = $step;
        }

        self::assertSame(2, $result['step1_sessions']);
        self::assertSame(2, $byKey['view_item']['sessions']);
        self::assertSame(1, $byKey['add_to_cart']['sessions']);
        self::assertSame(1, $byKey['begin_checkout']['sessions']);
        self::assertSame(1, $byKey['checkout_success']['sessions']);
        self::assertSame(0.5, $byKey['checkout_success']['rate_from_step1']);
    }

    public function testDaysNormalizeToChannelWindowsSevenOrThirty(): void
    {
        self::assertSame(7, $this->service->normalizeDays(7));
        self::assertSame(30, $this->service->normalizeDays(30));
        self::assertSame(7, $this->service->normalizeDays(1));
        self::assertSame(7, $this->service->normalizeDays(90));
        self::assertSame(PixelChannelFunnelService::DEFAULT_DAYS, PixelChannelEcommerceFunnelService::DEFAULT_DAYS);
    }

    public function testSqlFiltersChannelAndUsesEcommerceColumns(): void
    {
        $window = (new PixelChannelHotTotalsService())->resolveWindow(30);
        [$sql, $params] = $this->service->buildSessionStepSql('summer_sale', 3, $window);

        self::assertStringContainsString('GROUP BY', $sql);
        self::assertStringContainsString('channel_code', $sql);
        self::assertStringContainsString('step_view_item', $sql);
        self::assertStringContainsString('step_begin_checkout', $sql);
        self::assertStringContainsString('step_checkout_success', $sql);
        self::assertStringContainsString('purchase', $sql);
        self::assertStringNotContainsString('step_landing', $sql);
        self::assertStringNotContainsString('step_interaction', $sql);
        self::assertStringNotContainsString('page_view', $sql);
        self::assertSame('summer_sale', $params[':channel_code']);
        self::assertSame(3, $params[':website_id']);
        self::assertSame((string)$window['start_date'], $params[':start_date']);

        // 未绑定站点时不带 website_id
        [, $paramsNoSite] = $this->service->buildSessionStepSql('summer_sale', null, $window);
        self::assertArrayNotHasKey(':website_id', $paramsNoSite);
    }

    public function testBuildForChannelUsesInjectedRunnerAndReportsWindow(): void
    {
        $captured = [];
        $result = $this->service->buildForChannel(
            ['code' => 'summer_sale', 'website_id' => 2],
            30,
            static function (string $code, ?int $websiteId, array $window) use (&$captured): array {
                $captured = ['code' => $code, 'website_id' => $websiteId, 'window' => $window];

                return [
                    ['session_id' => 's1', 'step_view_item' => 1, 'step_add_to_cart' => 1, 'step_begin_checkout' => 1, 'step_checkout_success' => 1],
                    ['session_id' => 's2', 'step_view_item' => 1, 'step_add_to_cart' => 0, 'step_begin_checkout' => 1, 'step_checkout_success' => 1],
                ];
            }
        );

        self::assertSame('summer_sale', $captured['code']);
        self::assertSame(2, $captured['website_id']);
        self::assertSame(30, (int)$captured['window']['days']);
        self::assertSame(30, $result['days']);
        self::assertSame('summer_sale', $result['channel_code']);
        self::assertSame(2, $result['website_id']);
        self::assertSame('', $result['error']);
        self::assertSame(2, $result['step1_sessions']);
        self::assertSame(1, $result['steps'][3]['sessions']);
        self::assertNotSame('', (string)$result['start_date']);
    }

    public function testBuildForChannelSwallowsQueryErrorsAndKeepsEmptySteps(): void
    {
        $result = $this->service->buildForChannel(
            ['code' => 'summer_sale'],
            7,
            static function (): array {
                throw new \RuntimeException('flat column missing');
            }
        );

        self::assertSame('flat column missing', $result['error']);
        self::assertSame(0, $result['step1_sessions']);
        self::assertCount(4, $result['steps']);

        // 空 code 直接返回空结果且不报错
        $empty = $this->service->buildForChannel([], 7, static fn(): array => [['session_id' => 's1', 'step_view_item' => 1]]);
        self::assertSame('', $empty['error']);
        self::assertSame(0, $empty['step1_sessions']);
    }

    public function testDetailControllerAndTemplateWireBothModes(): void
    {
        $root = dirname(__DIR__, 3);

        $controller = (string)\file_get_contents($root . '/Controller/Backend/TrafficChannel.php');
        self::assertStringContainsString('PixelChannelEcommerceFunnelService', $controller);
        self::assertStringContainsString('PixelChannelFunnelService', $controller);
        self::assertStringContainsString("getGet('funnel_mode')", $controller);
        self::assertStringContainsString("assign('funnel_mode'", $controller);

        $detail = (string)\file_get_contents($root . '/view/templates/Backend/TrafficChannel/detail.phtml');
        self::assertStringContainsString('data-funnel-mode-switch', $detail);
        self::assertStringContainsString('<lang>营销简漏斗</lang>', $detail);
        self::assertStringContainsString('<lang>电商四步</lang>', $detail);
        // 模式切换与日窗切换都锚回同一张卡片，且互相保留对方参数
        self::assertStringContainsString("#channel-funnel", $detail);
        self::assertStringContainsString('$funnelUrl(7, $funnelMode)', $detail);
        self::assertStringContainsString('$funnelUrl($timelineDays, \Weline\Visitor\Service\PixelChannelFunnelMode::ECOMMERCE)', $detail);
    }
}
