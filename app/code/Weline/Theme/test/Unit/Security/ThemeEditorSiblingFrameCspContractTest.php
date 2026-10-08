<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Extends\Module\Weline_Framework\Security\Csp\ThemeEditorSiblingFrameCsp;

final class ThemeEditorSiblingFrameCspContractTest extends TestCase
{
    public function testAllowsLocalProjectSiblingHostsForEditorCanvas(): void
    {
        $directives = (new ThemeEditorSiblingFrameCsp())->contribution()->directives;

        self::assertContains('https://*.test.weline.com', $directives['frame-src'] ?? []);
        self::assertContains('http://*.test.weline.com', $directives['frame-src'] ?? []);
        self::assertContains('https://*.weline.test', $directives['frame-src'] ?? []);
        self::assertContains('http://*.weline.test', $directives['frame-src'] ?? []);
    }
}
