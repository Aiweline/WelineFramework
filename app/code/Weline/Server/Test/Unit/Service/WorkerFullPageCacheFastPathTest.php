<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\WorkerFullPageCacheFastPath;

final class WorkerFullPageCacheFastPathTest extends TestCase
{
    public function testAuthorizationAlwaysBypassesRawWorkerFpc(): void
    {
        $reflection = new \ReflectionClass(WorkerFullPageCacheFastPath::class);
        $fastPath = $reflection->newInstanceWithoutConstructor();
        $mustBypass = $reflection->getMethod('mustBypass');

        self::assertTrue($mustBypass->invoke($fastPath, ['authorization' => 'Bearer private-token']));
        self::assertFalse($mustBypass->invoke($fastPath, ['authorization' => '']));
        self::assertFalse($mustBypass->invoke($fastPath, []));
    }
}
