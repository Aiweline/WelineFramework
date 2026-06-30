<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendHeadSeoSlotGuardTest extends TestCase
{
    public function testBackendHeadDoesNotLoadFrontendSeoOrGeoSlots(): void
    {
        $headTemplate = BP . 'app/code/Weline/Backend/view/templates/public/head.phtml';
        self::assertFileExists($headTemplate);

        $contents = (string) file_get_contents($headTemplate);
        self::assertStringNotContainsString('<w:seo', $contents);
        self::assertStringNotContainsString('<w:geo', $contents);
    }
}
