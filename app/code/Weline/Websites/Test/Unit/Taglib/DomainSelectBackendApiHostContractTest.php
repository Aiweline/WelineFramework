<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

/**
 * OffCanvas iframe pages do not mint backend Worker bootstrap meta; DomainSelect
 * must reuse the parent frame that owns the authenticated bin-query host.
 */
final class DomainSelectBackendApiHostContractTest extends TestCase
{
    public function testDomainSelectResolvesParentBackendApiHostForAdminRequest(): void
    {
        $path = dirname(__DIR__, 3) . '/Taglib/DomainSelect.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('function resolveBackendApiHost()', $source);
        self::assertStringContainsString('var candidate=window;', $source);
        self::assertStringContainsString('while(candidate)', $source);
        self::assertStringContainsString('weline-worker-backend-bootstrap', $source);
        self::assertStringContainsString('querySelectorAll', $source);
        self::assertStringContainsString('candidate.location.origin!==window.location.origin', $source);
        self::assertStringContainsString('candidate=candidate.parent;', $source);
        self::assertStringContainsString('var host=resolveBackendApiHost();', $source);
        self::assertStringContainsString('host.Weline.adminRequest("websites"', $source);
        self::assertStringNotContainsString('return window.Weline.adminRequest', $source);
    }
}
