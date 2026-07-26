<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\UnitTest\TestCore;
use Weline\Visitor\Service\PixelChannelFunnelService;
use Weline\Visitor\Service\PixelChannelHotTotalsService;

/**
 * B12：热表简化漏斗（§2.4 四步；按 session 去重顺序步进）。
 */
class PixelChannelFunnelServiceTest extends TestCore
{
    private PixelChannelFunnelService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelChannelFunnelService(new PixelChannelHotTotalsService());
    }

    public function testEventMatchesStepIncludesSearchPrefix(): void
    {
        self::assertTrue($this->service->eventMatchesStep('page_view', PixelChannelFunnelService::STEP_LANDING));
        self::assertTrue($this->service->eventMatchesStep('cta_click', PixelChannelFunnelService::STEP_INTERACTION));
        self::assertTrue($this->service->eventMatchesStep('search_product', PixelChannelFunnelService::STEP_INTERACTION));
        self::assertTrue($this->service->eventMatchesStep('add_to_cart', PixelChannelFunnelService::STEP_ADD_TO_CART));
        self::assertTrue($this->service->eventMatchesStep('purchase', PixelChannelFunnelService::STEP_CONVERSION));
        self::assertFalse($this->service->eventMatchesStep('view_item', PixelChannelFunnelService::STEP_LANDING), '非电商四步');
    }

    public function testSequentialRatesFromStep1(): void
    {
        // 10 落地；其中 6 互动；4 加购；2 转化（均满足前序）
        $flags = [];
        for ($i = 1; $i <= 10; $i++) {
            $flags['s' . $i] = [
                'landing' => true,
                'interaction' => $i <= 6,
                'add_to_cart' => $i <= 4,
                'conversion' => $i <= 2,
            ];
        }
        // 1 个只有转化无落地 → 顺序门槛下不计任何步
        $flags['orphan'] = [
            'landing' => false,
            'interaction' => false,
            'add_to_cart' => false,
            'conversion' => true,
        ];

        $result = $this->service->computeFromSessionFlags($flags);
        self::assertSame(10, $result['step1_sessions']);
        self::assertSame(11, $result['scored_sessions']);

        $byKey = [];
        foreach ($result['steps'] as $step) {
            $byKey[$step['key']] = $step;
        }
        self::assertSame(10, $byKey['landing']['sessions']);
        self::assertSame(1.0, $byKey['landing']['rate_from_step1']);
        self::assertSame(6, $byKey['interaction']['sessions']);
        self::assertSame(0.6, $byKey['interaction']['rate_from_step1']);
        self::assertSame(4, $byKey['add_to_cart']['sessions']);
        self::assertSame(0.4, $byKey['add_to_cart']['rate_from_step1']);
        self::assertSame(2, $byKey['conversion']['sessions']);
        self::assertSame(0.2, $byKey['conversion']['rate_from_step1']);
        self::assertSame(0.4, $byKey['interaction']['dropoff_from_prev']); // 1 - 6/10
        self::assertSame(0.5, $byKey['conversion']['dropoff_from_prev']); // 1 - 2/4
    }

    public function testComputeFromEventsBuildsFlagsAndRequiresOrder(): void
    {
        $events = [
            ['session_id' => 'a', 'event' => 'page_view'],
            ['session_id' => 'a', 'event' => 'cta_click'],
            ['session_id' => 'a', 'event' => 'add_to_cart'],
            ['session_id' => 'a', 'event' => 'purchase'],
            ['session_id' => 'b', 'event' => 'page_enter'],
            ['session_id' => 'b', 'event' => 'search_sku'],
            // c: 跳过互动直接加购 → 加购不计
            ['session_id' => 'c', 'event' => 'page_view'],
            ['session_id' => 'c', 'event' => 'add_to_cart'],
            // 无 session 忽略
            ['session_id' => '', 'event' => 'page_view'],
        ];
        $result = $this->service->computeFromEvents($events);
        $byKey = [];
        foreach ($result['steps'] as $step) {
            $byKey[$step['key']] = $step;
        }
        self::assertSame(3, $byKey['landing']['sessions']);
        self::assertSame(2, $byKey['interaction']['sessions']);
        self::assertSame(1, $byKey['add_to_cart']['sessions'], '仅 a 顺序完整到加购');
        self::assertSame(1, $byKey['conversion']['sessions']);
    }

    public function testBuildForChannelUsesInjectedRunner(): void
    {
        $seen = null;
        $result = $this->service->buildForChannel(
            ['code' => 'summer_sale', 'website_id' => 8],
            30,
            static function (string $code, ?int $websiteId, array $window) use (&$seen): array {
                $seen = [$code, $websiteId, (int)$window['days']];

                return [
                    [
                        'session_id' => 's1',
                        'step_landing' => 1,
                        'step_interaction' => 1,
                        'step_add_to_cart' => 0,
                        'step_conversion' => 0,
                    ],
                    [
                        'session_id' => 's2',
                        'step_landing' => 1,
                        'step_interaction' => 0,
                        'step_add_to_cart' => 0,
                        'step_conversion' => 0,
                    ],
                ];
            }
        );

        self::assertSame(['summer_sale', 8, 30], $seen);
        self::assertSame(2, $result['step1_sessions']);
        self::assertSame(1, $result['steps'][1]['sessions']);
        self::assertSame('', $result['error']);
    }

    public function testSqlGroupsBySessionAndIsNotEcommerceFourStep(): void
    {
        $window = (new PixelChannelHotTotalsService())->resolveWindow(7);
        [$sql, $params] = $this->service->buildSessionStepSql('summer_sale', 3, $window);
        self::assertStringContainsString('GROUP BY', $sql);
        self::assertStringContainsString('step_landing', $sql);
        self::assertStringContainsString('step_interaction', $sql);
        self::assertStringContainsString('page_view', $sql);
        self::assertStringContainsString('add_to_cart', $sql);
        self::assertStringNotContainsString('view_item', $sql);
        self::assertStringNotContainsString('begin_checkout', $sql);
        self::assertSame('summer_sale', $params[':channel_code']);
        self::assertSame(3, $params[':website_id']);
    }

    public function testDetailKeepsMarketingFunnelAsDefaultMode(): void
    {
        $root = BP . '/app/code/Weline/Visitor';
        self::assertFileExists($root . '/Service/PixelChannelFunnelService.php');

        $controller = (string)\file_get_contents($root . '/Controller/Backend/TrafficChannel.php');
        self::assertStringContainsString('PixelChannelFunnelService', $controller);
        // F05b：默认仍走营销简漏斗，仅在显式 funnel_mode=ecommerce 时切换
        self::assertStringContainsString('PixelChannelFunnelMode::isEcommerce', $controller);
        self::assertSame(
            \Weline\Visitor\Service\PixelChannelFunnelMode::MARKETING,
            \Weline\Visitor\Service\PixelChannelFunnelMode::DEFAULT_MODE
        );
        self::assertSame(
            \Weline\Visitor\Service\PixelChannelFunnelMode::MARKETING,
            \Weline\Visitor\Service\PixelChannelFunnelMode::normalize(null)
        );

        $detail = (string)\file_get_contents($root . '/view/templates/Backend/TrafficChannel/detail.phtml');
        self::assertStringContainsString('channel-funnel', $detail);
        self::assertStringContainsString('<lang>营销简漏斗</lang>', $detail);
        self::assertStringContainsString('data-funnel-mode-switch', $detail);
        // 步骤文案与基准列由模式服务提供，模板不再硬编码营销步名
        self::assertStringContainsString('PixelChannelFunnelMode::baselineLabel', $detail);
        self::assertStringNotContainsString('view_item', $detail);
        self::assertStringNotContainsString('begin_checkout', $detail);
    }
}
