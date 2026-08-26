<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Docs pages are storefront routes (/dev/tool/docs*). They must use the
 * frontend theme blank.full layout as content fragments so Theme head/body
 * hooks and Frontend Header Base (via Theme head → Frontend public head)
 * are not bypassed by a standalone HTML document shell.
 */
final class DocsFrontendRuntimeHeaderContractTest extends TestCase
{
    public function testDocsControllerUsesBlankFullLayout(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Docs.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("protected ?string \$layoutType = 'blank.full';", $src);
    }

    public function testDocsIndexIsBlankFullContentFragment(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Docs/index.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertDoesNotMatchRegularExpression('/<!doctype\\s+html/i', $src);
        self::assertDoesNotMatchRegularExpression('/<html\\b/i', $src);
        self::assertStringNotContainsString('Weline_Backend::header/base.phtml', $src);
        self::assertStringNotContainsString('Weline\\Backend\\Block\\Header\\Base', $src);
        self::assertStringContainsString('weline-developer-docs.css', $src);
        self::assertStringContainsString('weline-developer-docs.js', $src);
    }

    public function testApiManagerIsBlankFullContentFragment(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Docs/api-manager.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertDoesNotMatchRegularExpression('/<!doctype\\s+html/i', $src);
        self::assertDoesNotMatchRegularExpression('/<html\\b/i', $src);
        self::assertStringNotContainsString('Weline_Backend::header/base.phtml', $src);
        self::assertStringNotContainsString('Weline\\Backend\\Block\\Header\\Base', $src);
        self::assertStringContainsString('weline-developer-api.css', $src);
        self::assertStringContainsString('weline-developer-api.js', $src);
    }
}
