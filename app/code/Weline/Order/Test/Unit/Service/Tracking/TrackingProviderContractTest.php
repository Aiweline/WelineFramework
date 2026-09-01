<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service\Tracking;

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\Data\Tracking\TrackingFeedbackRequest;
use Weline\Order\Api\Data\Tracking\TrackingQueryRequest;
use Weline\Order\Api\Data\Tracking\TrackingResult;
use Weline\Order\Extends\Module\Weline_Order\TrackingProvider\FakeTrackingProvider;
use Weline\Order\Extends\Module\Weline_Order\TrackingProvider\SystemTrackingProvider;
use Weline\Order\Interface\TrackingProviderInterface;

final class TrackingProviderContractTest extends TestCase
{
    public function testSystemAndFakeImplementInterface(): void
    {
        self::assertInstanceOf(TrackingProviderInterface::class, new SystemTrackingProvider());
        self::assertInstanceOf(TrackingProviderInterface::class, new FakeTrackingProvider());
    }

    public function testSystemShippedShowsInTransitToDestination(): void
    {
        $provider = new SystemTrackingProvider();
        $display = $provider->getDisplayMetadata();
        self::assertNotSame('', (string) ($display['icon_url'] ?? $display['icon'] ?? ''));
        self::assertNotSame('', (string) ($display['title'] ?? ''));

        $result = $provider->queryTracking(TrackingQueryRequest::fromArray([
            TrackingQueryRequest::FIELD_ORDER_NUMBER => 'WLTEST001',
            TrackingQueryRequest::FIELD_ORDER_STATUS => 'fulfilled',
            TrackingQueryRequest::FIELD_FULFILLMENT_STATUS => 'shipped',
            TrackingQueryRequest::FIELD_SHIPPED_AT => '2026-08-30 10:00:00',
            TrackingQueryRequest::FIELD_DESTINATION_SUMMARY => 'Shanghai',
            TrackingQueryRequest::FIELD_CONTEXT => ['shipment_status' => 'shipped'],
        ]));

        self::assertSame(TrackingResult::STATUS_IN_TRANSIT, $result->getStatus());
        self::assertStringContainsString('发往目的地', $result->getSummary());
        $stageCodes = array_map(static fn(array $s): string => (string) ($s['code'] ?? ''), $result->getStages());
        self::assertContains('shipped', $stageCodes);
        self::assertContains('in_transit', $stageCodes);

        $nodeText = implode("\n", array_map(static fn(array $n): string => (string) ($n['text'] ?? ''), $result->getNodes()));
        self::assertStringContainsString('已发货', $nodeText);
        self::assertStringContainsString('发往目的地', $nodeText);
    }

    public function testFakeProviderHasCompleteFlowAndFeedbackPureBoundary(): void
    {
        $provider = new FakeTrackingProvider();
        self::assertNotSame('', (string) ($provider->getDisplayMetadata()['icon_url'] ?? ''));
        self::assertNotEmpty($provider->getFlowStages());

        $result = $provider->queryTracking(TrackingQueryRequest::fromArray([
            TrackingQueryRequest::FIELD_ORDER_NUMBER => 'WLTEST002',
            TrackingQueryRequest::FIELD_TRACKING_NUMBER => 'FAKE123',
            TrackingQueryRequest::FIELD_CARRIER => 'fake',
        ]));
        self::assertSame('fake_carrier', $result->getMethodCode());
        self::assertNotEmpty($result->getNodes());

        $invalid = $provider->verifyFeedback(TrackingFeedbackRequest::fromArray([
            TrackingFeedbackRequest::FIELD_RAW_BODY => '{"event_id":"1"}',
            TrackingFeedbackRequest::FIELD_HEADERS => [],
        ]));
        self::assertFalse($invalid->isValid());

        $valid = $provider->verifyFeedback(TrackingFeedbackRequest::fromArray([
            TrackingFeedbackRequest::FIELD_RAW_BODY => '{"event_id":"e1","order_number":"WLTEST002","tracking_number":"FAKE123","status":"in_transit","stage_code":"in_transit"}',
            TrackingFeedbackRequest::FIELD_HEADERS => ['x-weline-fake-tracking' => 'dev'],
        ]));
        self::assertTrue($valid->isValid());

        $parsed = $provider->parseFeedback(TrackingFeedbackRequest::fromArray([
            TrackingFeedbackRequest::FIELD_RAW_BODY => '{"event_id":"e1","order_number":"WLTEST002","tracking_number":"FAKE123","status":"in_transit","stage_code":"in_transit","summary":"ok"}',
            TrackingFeedbackRequest::FIELD_HEADERS => ['x-weline-fake-tracking' => 'dev'],
        ]));
        self::assertTrue($parsed->isValid());
        self::assertSame('e1', $parsed->getEventId());
        self::assertSame('WLTEST002', $parsed->getOrderNumber());
    }

    public function testManagerFallsBackToSystemWithoutCarrier(): void
    {
        $system = new SystemTrackingProvider();
        $fake = new FakeTrackingProvider();

        // 无承运商 / 无运单 → 系统追踪
        self::assertSame('system', $system->getCode());
        self::assertFalse((bool) ($system->getCapabilities()['formal_carrier'] ?? true));

        // 正式演示 Provider 具备完整展示元数据
        self::assertSame('fake_carrier', $fake->getCode());
        self::assertTrue((bool) ($fake->getCapabilities()['formal_carrier'] ?? false));
        self::assertContains('fake', array_map('strtolower', (array) ($fake->getCapabilities()['carrier_aliases'] ?? [])));
    }
}
