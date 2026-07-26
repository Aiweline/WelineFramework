<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\PixelChannelFunnelService;
use Weline\Visitor\Service\PixelEcommerceFunnelService;
use Weline\Visitor\Service\Report\PixelQueryRouter;

/**
 * F01：字典电商四步漏斗（热；与 B12 营销简漏斗隔离）。
 */
final class PixelEcommerceFunnelServiceTest extends TestCase
{
    private PixelEcommerceFunnelService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelEcommerceFunnelService(new PixelQueryRouter());
    }

    public function testEventMatchesDictionaryFourSteps(): void
    {
        self::assertTrue($this->service->eventMatchesStep('view_item', PixelEcommerceFunnelService::STEP_VIEW_ITEM));
        self::assertTrue($this->service->eventMatchesStep('add_to_cart', PixelEcommerceFunnelService::STEP_ADD_TO_CART));
        self::assertTrue($this->service->eventMatchesStep('begin_checkout', PixelEcommerceFunnelService::STEP_BEGIN_CHECKOUT));
        self::assertTrue($this->service->eventMatchesStep('checkout_success', PixelEcommerceFunnelService::STEP_CHECKOUT_SUCCESS));
        self::assertTrue($this->service->eventMatchesStep('purchase', PixelEcommerceFunnelService::STEP_CHECKOUT_SUCCESS));
        self::assertFalse($this->service->eventMatchesStep('page_view', PixelEcommerceFunnelService::STEP_VIEW_ITEM));
        self::assertFalse($this->service->eventMatchesStep('cta_click', PixelEcommerceFunnelService::STEP_ADD_TO_CART));
        self::assertFalse($this->service->eventMatchesStep('lead_submit', PixelEcommerceFunnelService::STEP_CHECKOUT_SUCCESS));
    }

    public function testSequentialRatesFromViewItem(): void
    {
        $flags = [];
        for ($i = 1; $i <= 10; $i++) {
            $flags['s' . $i] = [
                'view_item' => true,
                'add_to_cart' => $i <= 6,
                'begin_checkout' => $i <= 4,
                'checkout_success' => $i <= 2,
            ];
        }
        $flags['orphan'] = [
            'view_item' => false,
            'add_to_cart' => false,
            'begin_checkout' => false,
            'checkout_success' => true,
        ];

        $result = $this->service->computeFromSessionFlags($flags);
        self::assertSame(10, $result['step1_sessions']);
        self::assertSame(11, $result['scored_sessions']);

        $byKey = [];
        foreach ($result['steps'] as $step) {
            $byKey[$step['key']] = $step;
        }
        self::assertSame(10, $byKey['view_item']['sessions']);
        self::assertSame(1.0, $byKey['view_item']['rate_from_step1']);
        self::assertSame(6, $byKey['add_to_cart']['sessions']);
        self::assertSame(0.6, $byKey['add_to_cart']['rate_from_step1']);
        self::assertSame(4, $byKey['begin_checkout']['sessions']);
        self::assertSame(0.4, $byKey['begin_checkout']['rate_from_step1']);
        self::assertSame(2, $byKey['checkout_success']['sessions']);
        self::assertSame(0.2, $byKey['checkout_success']['rate_from_step1']);
        self::assertSame(0.4, $byKey['add_to_cart']['dropoff_from_prev']);
        self::assertSame(0.5, $byKey['checkout_success']['dropoff_from_prev']);
    }

    public function testComputeFromEventsRequiresOrderAndAcceptsPurchaseAlias(): void
    {
        $events = [
            ['session_id' => 'a', 'event' => 'view_item'],
            ['session_id' => 'a', 'event' => 'add_to_cart'],
            ['session_id' => 'a', 'event' => 'begin_checkout'],
            ['session_id' => 'a', 'event' => 'purchase'],
            ['session_id' => 'b', 'event' => 'view_item'],
            ['session_id' => 'b', 'event' => 'add_to_cart'],
            // c: 跳过 begin_checkout → 购买不计
            ['session_id' => 'c', 'event' => 'view_item'],
            ['session_id' => 'c', 'event' => 'add_to_cart'],
            ['session_id' => 'c', 'event' => 'checkout_success'],
            ['session_id' => '', 'event' => 'view_item'],
        ];
        $result = $this->service->computeFromEvents($events);
        $byKey = [];
        foreach ($result['steps'] as $step) {
            $byKey[$step['key']] = $step;
        }
        self::assertSame(3, $byKey['view_item']['sessions']);
        self::assertSame(3, $byKey['add_to_cart']['sessions']);
        self::assertSame(1, $byKey['begin_checkout']['sessions']);
        self::assertSame(1, $byKey['checkout_success']['sessions'], '仅 a 顺序完整');
    }

    public function testBuildForWebsiteUsesInjectedRunnerAndClamps(): void
    {
        $seen = null;
        $result = $this->service->buildForWebsite(
            8,
            new DateTimeImmutable('2026-01-01 00:00:00'),
            new DateTimeImmutable('2026-01-20 23:59:59'),
            static function (int $websiteId, DateTimeImmutable $from, DateTimeImmutable $to) use (&$seen): array {
                $seen = [
                    $websiteId,
                    $from->format('Y-m-d'),
                    $to->format('Y-m-d'),
                ];

                return [
                    [
                        'session_id' => 's1',
                        'step_view_item' => 1,
                        'step_add_to_cart' => 1,
                        'step_begin_checkout' => 0,
                        'step_checkout_success' => 0,
                    ],
                    [
                        'session_id' => 's2',
                        'step_view_item' => 1,
                        'step_add_to_cart' => 0,
                        'step_begin_checkout' => 0,
                        'step_checkout_success' => 0,
                    ],
                ];
            }
        );

        self::assertTrue($result['window_clamped']);
        self::assertSame(8, $seen[0] ?? null);
        // 热短窗 7 天：from 钳到 to-6 天
        self::assertSame('2026-01-14', $seen[1] ?? null);
        self::assertSame('2026-01-20', $seen[2] ?? null);
        self::assertSame(2, $result['step1_sessions']);
        self::assertSame(1, $result['steps'][1]['sessions']);
        self::assertSame('', $result['error']);
    }

    public function testSqlIsEcommerceFourStepNotMarketingFunnel(): void
    {
        $from = new DateTimeImmutable('2026-07-20 00:00:00');
        $to = new DateTimeImmutable('2026-07-26 23:59:59');
        [$sql, $params] = $this->service->buildSessionStepSql(3, $from, $to);

        self::assertStringContainsString('GROUP BY', $sql);
        self::assertStringContainsString('step_view_item', $sql);
        self::assertStringContainsString('step_begin_checkout', $sql);
        self::assertStringContainsString('view_item', $sql);
        self::assertStringContainsString('begin_checkout', $sql);
        self::assertStringContainsString('checkout_success', $sql);
        self::assertStringContainsString('purchase', $sql);
        self::assertStringNotContainsString('page_view', $sql);
        self::assertStringNotContainsString('step_landing', $sql);
        self::assertStringNotContainsString('step_interaction', $sql);
        self::assertSame(3, $params[':website_id']);
    }

    public function testDetailWiresEcommerceFunnelSeparatelyFromChannelMarketing(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root . '/Service/PixelEcommerceFunnelService.php');

        $controller = (string)\file_get_contents($root . '/Controller/Backend/PixelDashboard.php');
        self::assertStringContainsString('PixelEcommerceFunnelService', $controller);
        self::assertStringContainsString('ecommerce_funnel', $controller);

        $detail = (string)\file_get_contents($root . '/view/templates/Backend/PixelDashboard/detail.phtml');
        self::assertStringContainsString('ecommerce-funnel', $detail);
        self::assertStringContainsString('电商漏斗', $detail);
        self::assertStringContainsString('view_item', $detail);
        self::assertStringContainsString('begin_checkout', $detail);

        // F05b：渠道详情可切换电商四步，但默认仍是营销简漏斗，且两套步骤定义互不污染
        $channelDetail = (string)\file_get_contents($root . '/view/templates/Backend/TrafficChannel/detail.phtml');
        self::assertStringContainsString('<lang>营销简漏斗</lang>', $channelDetail);
        self::assertStringContainsString('<lang>电商四步</lang>', $channelDetail);
        self::assertStringNotContainsString('ecommerce-funnel', $channelDetail);
        self::assertSame(
            ['landing', 'interaction', 'add_to_cart', 'conversion'],
            PixelChannelFunnelService::STEP_ORDER
        );
        self::assertSame(
            PixelEcommerceFunnelService::STEP_ORDER,
            \Weline\Visitor\Service\PixelChannelEcommerceFunnelService::STEP_ORDER
        );
    }
}
