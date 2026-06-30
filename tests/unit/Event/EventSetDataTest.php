<?php

declare(strict_types=1);

namespace Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;

final class EventSetDataTest extends TestCase
{
    public function testBatchSetDataPreservesEachProvidedValueOnExistingPayload(): void
    {
        $event = new Event(['data' => ['existing' => 'keep']]);

        $event->setData([
            'result' => 'ok',
            'count' => 2,
        ]);

        self::assertSame('keep', $event->getEvenData('existing'));
        self::assertSame('ok', $event->getEvenData('result'));
        self::assertSame(2, $event->getEvenData('count'));
    }
}
