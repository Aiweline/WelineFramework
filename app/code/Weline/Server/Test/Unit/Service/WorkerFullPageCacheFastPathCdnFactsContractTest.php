<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class WorkerFullPageCacheFastPathCdnFactsContractTest extends TestCase
{
    public function testMustBypassUsesSharedFactsBuilderNotEmptyEnv(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/WorkerFullPageCacheFastPath.php';
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('FpcBypassFactsBuilder::build', $src);
        self::assertStringNotContainsString("'env' => []", $src);
    }
}
