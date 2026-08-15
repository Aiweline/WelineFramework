<?php

declare(strict_types=1);

namespace Weline\SessionManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class RequestDeviceMetadataProviderContractTest extends TestCase
{
    public function testProcessLivedProviderResolvesTheCurrentRequestAtCallTime(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/RequestDeviceMetadataProvider.php';
        $source = (string)file_get_contents($path);

        self::assertStringNotContainsString('private readonly Request $request', $source);
        self::assertStringContainsString('ObjectManager::getInstance(Request::class)', $source);
    }
}
