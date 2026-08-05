<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Queue\Queue\AsyncEventDeliveryQueue;
use Weline\Queue\Service\QueueScopeProducerMapping;
use Weline\Queue\Test\Fixture\FrozenOrderScopeQueue;
use Weline\Queue\Test\Fixture\TombstoneActionScopeQueue;

/**
 * TEST-MIG-P1A-03, TEST-MIG-P1A-04, TEST-MIG-P1A-05 and TEST-MIG-P1A-06:
 * frozen-order recovery, true global mapping, quarantine and tombstone routing.
 */
final class QueueScopeProducerMappingTest extends TestCase
{
    public function testFrozenAsyncEventDeliveryMapsToGlobalNotZeroWebsite(): void
    {
        $mapping = new QueueScopeProducerMapping();
        $decision = $mapping->classify(
            [
                'status' => 'pending',
                'finished' => 0,
                'result' => '',
                'module' => 'Weline_Queue',
            ],
            AsyncEventDeliveryQueue::class,
        );

        self::assertSame(QueueScopeProducerMapping::DECISION_MAP, $decision['decision']);
        self::assertSame(ScopeIdentity::KIND_GLOBAL, $decision['kind']);
        self::assertIsArray($decision['envelope']);
        self::assertSame('global', $decision['envelope']['scope_kind']);
        self::assertArrayHasKey('website_id', $decision['envelope']);
        self::assertNull($decision['envelope']['website_id']);
        self::assertNull($decision['envelope']['website_code']);
        self::assertNull($decision['envelope']['store_code']);
        self::assertNull($decision['envelope']['channel_code']);
    }

    public function testUnknownProducerQuarantinesWithoutGuessingDefaultSite(): void
    {
        $mapping = new QueueScopeProducerMapping();
        $decision = $mapping->classify(
            [
                'status' => 'pending',
                'finished' => 0,
                'result' => '',
                'module' => 'Weline_Geo',
            ],
            'Weline\\Geo\\Queue\\FeedGenerateQueue',
        );

        self::assertSame(QueueScopeProducerMapping::DECISION_QUARANTINE, $decision['decision']);
        self::assertNull($decision['kind']);
        self::assertNull($decision['envelope']);
        self::assertStringContainsString('no_frozen_producer_contract', $decision['reason']);
    }

    public function testDeclaredProviderRecoversStoreScopeFromFrozenOrderSnapshot(): void
    {
        $scope = ScopeIdentity::store(7, 'site-seven', 'main', ScopeIdentity::MODE_NORMAL);
        $mapping = new QueueScopeProducerMapping();
        $decision = $mapping->classify(
            [
                'status' => 'pending',
                'finished' => 0,
                'result' => '',
                'module' => 'Weline_Order',
                'content' => \json_encode([
                    'frozen_order' => [
                        'order_uuid' => 'order-7',
                        'scope_envelope' => \Weline\Framework\Runtime\ScopeEnvelope::of($scope)->toArray(),
                    ],
                ], \JSON_THROW_ON_ERROR),
            ],
            FrozenOrderScopeQueue::class,
        );

        self::assertSame(QueueScopeProducerMapping::DECISION_MAP, $decision['decision']);
        self::assertSame('test.order.frozen_scope', $decision['producer_key']);
        self::assertSame(ScopeIdentity::KIND_STORE, $decision['kind']);
        self::assertSame(7, $decision['envelope']['website_id']);
        self::assertSame('site-seven', $decision['envelope']['website_code']);
        self::assertSame('main', $decision['envelope']['store_code']);
    }

    public function testDeclaredProviderWithUnprovableSnapshotIsQuarantined(): void
    {
        $decision = (new QueueScopeProducerMapping())->classify(
            [
                'status' => 'pending',
                'finished' => 0,
                'result' => '',
                'module' => 'Weline_Order',
                'content' => '{"frozen_order":{"order_uuid":"order-missing-scope"}}',
            ],
            FrozenOrderScopeQueue::class,
        );

        self::assertSame(QueueScopeProducerMapping::DECISION_QUARANTINE, $decision['decision']);
        self::assertNull($decision['envelope']);
        self::assertStringContainsString('declared_scope_provider_unresolved', $decision['reason']);
    }

    public function testTombstoneActionProviderPreservesStoreScope(): void
    {
        $envelope = \Weline\Framework\Runtime\ScopeEnvelope::of(
            ScopeIdentity::store(7, 'site-seven', 'default', ScopeIdentity::MODE_NORMAL),
        );
        $decision = (new QueueScopeProducerMapping())->classify(
            [
                'status' => 'pending',
                'finished' => 0,
                'result' => '',
                'module' => 'Weline_Order',
                'content' => \json_encode([
                    'action' => 'refund',
                    'scope_envelope' => $envelope->toArray(),
                ], \JSON_THROW_ON_ERROR),
            ],
            TombstoneActionScopeQueue::class,
        );

        self::assertSame(QueueScopeProducerMapping::DECISION_MAP, $decision['decision']);
        self::assertSame('test.order.tombstone_action', $decision['producer_key']);
        self::assertSame(ScopeIdentity::KIND_STORE, $decision['kind']);
    }

    public function testTerminalRowsAreCancelledAndAlreadyQuarantinedStaysQuarantine(): void
    {
        $mapping = new QueueScopeProducerMapping();
        $cancelled = $mapping->classify(
            ['status' => 'done', 'finished' => 1, 'result' => ''],
            'Weline\\Geo\\Queue\\FeedGenerateQueue',
        );
        self::assertSame(QueueScopeProducerMapping::DECISION_CANCELLED, $cancelled['decision']);

        $quarantined = $mapping->classify(
            [
                'status' => 'stop',
                'finished' => 1,
                'result' => QueueScopeProducerMapping::QUARANTINE_RESULT_PREFIX . ' x',
            ],
            null,
        );
        self::assertSame(QueueScopeProducerMapping::DECISION_QUARANTINE, $quarantined['decision']);
        self::assertSame('already_quarantined', $quarantined['reason']);
    }

    public function testApplyBackfillsGlobalEnvelopeForFrozenProducer(): void
    {
        $mapping = new QueueScopeProducerMapping();
        $classified = $mapping->classify(
            [
                'queue_id' => 1,
                'status' => 'pending',
                'finished' => 0,
                'result' => '',
                'module' => 'Weline_Queue',
            ],
            \Weline\Queue\Queue\Test::class,
        );
        self::assertSame(QueueScopeProducerMapping::DECISION_MAP, $classified['decision']);
        self::assertSame('global', $classified['kind']);
        self::assertIsArray($classified['envelope']);
        self::assertSame('global', $classified['envelope']['scope_kind'] ?? null);
    }
}
