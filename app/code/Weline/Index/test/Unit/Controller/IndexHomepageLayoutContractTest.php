<?php
declare(strict_types=1);

namespace Weline\Index\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class IndexHomepageLayoutContractTest extends TestCase
{
    public function testIndexBindsThemeHomepageLayoutWhenFrontendThemeActive(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Index.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("\$this->layoutType = 'homepage'", $src);
        self::assertStringContainsString('homepage-shell.phtml', $src);
        self::assertStringContainsString('shouldUseThemeHomepageLayout', $src);
        self::assertStringContainsString('ThemeContextProviderInterface', $src);
        self::assertStringContainsString("Weline_Index::templates/Index.phtml", $src);
    }
}
