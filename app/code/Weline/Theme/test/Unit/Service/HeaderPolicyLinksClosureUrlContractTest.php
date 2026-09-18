<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: header-policy-links must not compile @url/$this inside anonymous functions.
 */
final class HeaderPolicyLinksClosureUrlContractTest extends TestCase
{
    public function testSourceAvoidsThisInsideRenderLinkClosure(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/header/header-policy-links/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('static function (array $link, string $class)', $source);
        self::assertStringContainsString('$resolvePolicyHref', $source);
        self::assertStringNotContainsString('@url{$split[\'url_path\']}', $source);
        self::assertDoesNotMatchRegularExpression(
            '/\$renderLink\s*=\s*function[\s\S]*?\$this->getUrl/',
            $source,
            'renderLink must not call $this->getUrl (compiled @url inside closure fatals)'
        );
    }

    public function testTextileHeritageAvoidsAtUrlTaglib(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/content/textile-heritage/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringNotContainsString('@url{$linkPath}', $source);
        self::assertStringContainsString('$linkHref', $source);
    }

    public function testRepairUnhealthyIncludesUnclosedTagCodes(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/SlotRendererService.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString("\$code === 'unclosed_tag'", $source);
        self::assertStringContainsString('flushBeforeYield()', $source);
    }
}
