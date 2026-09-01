<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class ConfigGuideLocateOnlyContractTest extends TestCase
{
    public function testGuideParamsKeepsLocateWhenGuideKeyMissing(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Backend/Config.php';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('仅有 guide_locate 时也要保留定位', $src);
        self::assertStringContainsString('if ($mergedKeys === [] && $locateOnly !== \'\')', $src);
        self::assertStringContainsString("\$search = trim((string)\$this->request->getGet('q', ''));", $src);
    }
}
