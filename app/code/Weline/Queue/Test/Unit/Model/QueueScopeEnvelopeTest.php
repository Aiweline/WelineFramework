<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Model;

// The root Composer lock contains a packaged Weline_Queue copy. Load the
// canonical in-tree model explicitly so this repository test cannot exercise
// stale vendor code with the same namespace.
require_once dirname(__DIR__, 3) . '/Model/Queue.php';

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeEnvelope;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Queue\Model\Queue;

final class QueueScopeEnvelopeTest extends TestCase
{
    public function testQueueV1FixedColumnsRoundTripZeroWebsiteChannelScope(): void
    {
        $queue = $this->queueWithoutPersistence();
        $queue->setScopeEnvelope(ScopeEnvelope::of(ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        )));

        self::assertSame('channel', $queue->getData(Queue::schema_fields_SCOPE_KIND));
        self::assertSame(0, $queue->getData(Queue::schema_fields_SCOPE_WEBSITE_ID));
        self::assertSame('v1', $queue->getData(Queue::schema_fields_SCOPE_ENVELOPE_VERSION));
        self::assertSame(
            'v1|channel|0|default|default|default|normal|v1',
            $queue->getScopeEnvelope()?->canonicalKey(),
        );
    }

    public function testSettingScopeEnvelopePreservesPreparedQueueFields(): void
    {
        $queue = $this->queueWithoutPersistence();
        $queue->setTypeId(7)->setName('async delivery')->setContent('{"delivery_id":1}');

        $queue->setScopeEnvelope(ScopeEnvelope::of(ScopeIdentity::global()));

        self::assertSame(7, $queue->getTypeId());
        self::assertSame('async delivery', $queue->getName());
        self::assertSame('{"delivery_id":1}', $queue->getContent());
        self::assertSame('global', $queue->getData(Queue::schema_fields_SCOPE_KIND));
    }

    public function testQueueRejectsFutureContextVersionBeforeOldColumnsCanLoseIt(): void
    {
        $queue = $this->queueWithoutPersistence();

        $this->expectException(\LogicException::class);
        $queue->setScopeEnvelope(ScopeEnvelope::of(ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
            'v2',
        )));
    }

    public function testQueueTreatsOnlyCompletelyEmptyScopeStorageAsLegacy(): void
    {
        self::assertNull($this->queueWithoutPersistence()->getScopeEnvelope());
    }

    public function testQueueRejectsPopulatedInvalidScopeStorageInsteadOfDowngradingToGlobal(): void
    {
        $invalidRows = [
            'future_envelope' => [
                Queue::schema_fields_SCOPE_KIND => 'global',
                Queue::schema_fields_SCOPE_ENVELOPE_VERSION => 'v2',
            ],
            'global_with_zero_website' => [
                Queue::schema_fields_SCOPE_KIND => 'global',
                Queue::schema_fields_SCOPE_WEBSITE_ID => 0,
                Queue::schema_fields_SCOPE_ENVELOPE_VERSION => 'v1',
            ],
            'partial_channel' => [
                Queue::schema_fields_SCOPE_KIND => 'channel',
                Queue::schema_fields_SCOPE_WEBSITE_ID => 0,
                Queue::schema_fields_SCOPE_WEBSITE_CODE => 'default',
            ],
            'kind_whitespace' => [
                Queue::schema_fields_SCOPE_KIND => ' global ',
                Queue::schema_fields_SCOPE_ENVELOPE_VERSION => 'v1',
            ],
            'oversized_website_code' => [
                Queue::schema_fields_SCOPE_KIND => 'website',
                Queue::schema_fields_SCOPE_WEBSITE_ID => 1,
                Queue::schema_fields_SCOPE_WEBSITE_CODE => str_repeat('a', 65),
                Queue::schema_fields_SCOPE_ENVELOPE_VERSION => 'v1',
            ],
            'oversized_website_id' => [
                Queue::schema_fields_SCOPE_KIND => 'website',
                Queue::schema_fields_SCOPE_WEBSITE_ID => 2147483648,
                Queue::schema_fields_SCOPE_WEBSITE_CODE => 'default',
                Queue::schema_fields_SCOPE_ENVELOPE_VERSION => 'v1',
            ],
        ];

        foreach ($invalidRows as $label => $row) {
            $queue = $this->queueWithoutPersistence();
            $queue->setData($row);
            try {
                $queue->getScopeEnvelope();
                self::fail('Invalid populated Scope storage must fail closed: ' . $label);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function queueWithoutPersistence(): Queue
    {
        /** @var Queue $queue */
        $queue = (new \ReflectionClass(Queue::class))->newInstanceWithoutConstructor();

        return $queue;
    }
}
