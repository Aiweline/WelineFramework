<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Docs pages are storefront routes (/dev/tool/docs*). They must load the
 * Frontend runtime header so language/currency switchers use url-frontend
 * instead of mistaking the backend admin key for a path prefix.
 */
final class DocsFrontendRuntimeHeaderContractTest extends TestCase
{
    public function testDocsIndexUsesFrontendHeaderBase(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Docs/index.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('Weline\\Frontend\\Block\\Header\\Base', $src);
        self::assertStringContainsString('Weline_Frontend::header/base.phtml', $src);
        self::assertStringNotContainsString('Weline_Backend::header/base.phtml', $src);
    }

    public function testApiManagerUsesFrontendHeaderBase(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Docs/api-manager.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('Weline\\Frontend\\Block\\Header\\Base', $src);
        self::assertStringContainsString('Weline_Frontend::header/base.phtml', $src);
        self::assertStringNotContainsString('Weline_Backend::header/base.phtml', $src);
    }
}
