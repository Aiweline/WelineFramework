<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 嵌入 WLS Panel 时外壳已渲染 page title，内页不得再输出同名 H1。
 */
final class WlsDeployEmbeddedTitleContractTest extends TestCase
{
    private function templateSource(): string
    {
        $base = \defined('BP') ? (string)\BP : (\dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
        return (string)file_get_contents(
            $base . 'app/code/Weline/Deploy/view/templates/backend/WlsDeploy/index.phtml'
        );
    }

    public function testEmbeddedModeGatesInnerPageTitle(): void
    {
        $src = $this->templateSource();

        self::assertStringContainsString('<?php if (!$embedded): ?>', $src);
        self::assertStringContainsString('class="wdep-title"', $src);
        self::assertMatchesRegularExpression(
            '/<\?php\s+if\s*\(\s*!\$embedded\s*\)\s*:\s*\?>\s*<p class="wdep-kicker">[\s\S]*?<h1 class="wdep-title">/',
            $src
        );
    }

    public function testEmbeddedCssHidesInnerTitleAsBeltAndSuspenders(): void
    {
        $src = $this->templateSource();

        self::assertStringContainsString(
            '.wls-deploy-shell.is-embedded .wdep-title',
            $src
        );
        self::assertStringContainsString(
            '.wls-deploy-shell.is-embedded .wdep-kicker',
            $src
        );
    }
}
