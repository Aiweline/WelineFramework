<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\CertificateRetirementReplayBackoff;

final class CertificateRetirementReplayBackoffTest extends TestCase
{
    public function testNoProgressDoublesIntervalUntilCap(): void
    {
        $backoff = new CertificateRetirementReplayBackoff(10.0);
        $backoff->noteResult(0, false, true);
        self::assertSame(20.0, $backoff->intervalSeconds());
        $backoff->noteResult(0, true, true);
        self::assertSame(60.0, $backoff->intervalSeconds());
        $backoff->noteResult(0, true, false);
        self::assertSame(180.0, $backoff->intervalSeconds());
        $backoff->noteResult(0, true, false);
        self::assertSame(300.0, $backoff->intervalSeconds());
    }

    public function testProgressResetsToMinimum(): void
    {
        $backoff = new CertificateRetirementReplayBackoff(120.0);
        $backoff->noteResult(2, false, true);
        self::assertSame(10.0, $backoff->intervalSeconds());
        self::assertTrue($backoff->isDue(100.0, 89.0));
        self::assertFalse($backoff->isDue(100.0, 95.0));
    }
}
