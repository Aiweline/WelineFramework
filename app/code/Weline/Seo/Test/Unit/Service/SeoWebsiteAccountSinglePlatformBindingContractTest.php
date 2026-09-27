<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class SeoWebsiteAccountSinglePlatformBindingContractTest extends TestCase
{
    public function testBindingServiceEnforcesSinglePlatformAndDedupe(): void
    {
        $root = dirname(__DIR__, 3);
        $bindingSrc = (string)file_get_contents($root . '/Service/SeoWebsiteAccountBindingService.php');
        $modelSrc = (string)file_get_contents($root . '/Model/SeoWebsiteAccount.php');
        self::assertStringContainsString('enforceSinglePlatformBinding', $bindingSrc);
        self::assertStringContainsString('dedupeByCanonicalPlatform', $bindingSrc);
        self::assertStringContainsString('enforceSinglePlatformBinding', $modelSrc);
        self::assertStringContainsString('return $this->dedupeByCanonicalPlatform($accounts);', $bindingSrc);
    }
}
