<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Runtime\WlsRuntime;

final class WlsRuntimePendingResponseStatusTest extends TestCase
{
    protected function setUp(): void
    {
        HeaderCollector::reset();
    }

    protected function tearDown(): void
    {
        HeaderCollector::reset();
    }

    public function testSnapshotAndConsumePendingResponseStatusPreservesExplicitOverride(): void
    {
        $runtime = new class extends WlsRuntime {
            public function capture(HeaderCollector $collector): void
            {
                $this->snapshotPendingResponseState($collector);
            }
        };

        $collector = HeaderCollector::getInstance();
        $collector->setHeader('Content-Type', 'application/json');
        $collector->setStatusCode(200);

        $runtime->capture($collector);
        $status = $runtime->consumePendingResponseStatus();

        self::assertSame(['status_code' => 200, 'explicit' => true], $status);
        self::assertSame(['status_code' => null, 'explicit' => false], $runtime->consumePendingResponseStatus());
        self::assertSame(['Content-Type' => 'application/json'], $runtime->consumePendingHeaders());
    }
}
