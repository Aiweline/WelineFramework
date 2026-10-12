<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * ServerManager 首页脚本契约：禁止语法损坏导致「永远加载中」。
 */
final class ServerManagerIndexContractTest extends TestCase
{
    private function source(): string
    {
        $path = dirname(__DIR__, 3) . '/view/templates/backend/ServerManager/index.phtml';
        self::assertFileExists($path);
        return (string)file_get_contents($path);
    }

    public function testBadgeExpressionIsValidJavascript(): void
    {
        $source = $this->source();
        self::assertStringNotContainsString('summary.missing > ?', $source);
        self::assertStringContainsString('summary.missing > 0 ?', $source);
    }

    public function testPanelVisibilityUsesHiddenAttribute(): void
    {
        $source = $this->source();
        self::assertStringContainsString('setPanelState', $source);
        self::assertStringContainsString('loadingEl.hidden', $source);
        self::assertStringContainsString('id="error-state" hidden', $source);
        self::assertStringContainsString('id="content-container" hidden', $source);
    }

    public function testLoadServerDataWaitsForAdminRequestBridge(): void
    {
        $source = $this->source();
        self::assertStringContainsString('ensureAdminRequest', $source);
        self::assertStringContainsString('showPageLoadingState()', $source);
        self::assertStringContainsString('showError(', $source);
        self::assertStringContainsString("adminRequest('server'", $source);
    }
}
