<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\AiSiteWebsiteShellService;

final class AiSiteWebsiteShellServiceTest extends TestCase
{
    public function testNormalizeCodeRejectsDefaultAndUnsafe(): void
    {
        $service = $this->newServiceWithoutDb();
        self::assertSame('aisite_demo_abc123', $service->normalizeCode('AiSite_Demo_ABC123'));
        self::assertSame('', $service->normalizeCode('default'));
        self::assertSame('', $service->normalizeCode('Default'));
        self::assertSame('', $service->normalizeCode('bad code!'));
        self::assertSame('', $service->normalizeCode(''));
    }

    public function testPendingUrlUsesInternalHost(): void
    {
        $service = $this->newServiceWithoutDb();
        self::assertSame(
            'https://aisite_demo_abc123.aisite-pending.weline.internal',
            $service->pendingUrl('aisite_demo_abc123')
        );
    }

    public function testScopeConstantMatchesPageBuilderProvisioning(): void
    {
        self::assertSame('pagebuilder_ai_site', AiSiteWebsiteShellService::SCOPE);
        self::assertSame('aisite_', AiSiteWebsiteShellService::CODE_PREFIX);
    }

    private function newServiceWithoutDb(): AiSiteWebsiteShellService
    {
        // normalizeCode / pendingUrl do not touch Website persistence.
        return (new \ReflectionClass(AiSiteWebsiteShellService::class))
            ->newInstanceWithoutConstructor();
    }
}
