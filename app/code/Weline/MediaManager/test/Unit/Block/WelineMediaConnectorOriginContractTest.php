<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Block;

use PHPUnit\Framework\TestCase;

/**
 * Media picker iframe must stay on the parent page origin.
 * Absolute https connectors on http workbench pages break cookies/postMessage.
 */
final class WelineMediaConnectorOriginContractTest extends TestCase
{
    public function testBlockUsesBackendUrlPathForIframeConnector(): void
    {
        $path = dirname(__DIR__, 3) . '/Block/WelineMedia.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('getBackendUrlPath(', $source);
        self::assertStringContainsString("'media/backend/manager/iframe'", $source);
        self::assertStringContainsString("'weline_filemanager/backend/media-reference/bind'", $source);
        self::assertStringContainsString("assign('bind_url'", $source);
        self::assertStringContainsString('Do not merge the parent workbench query', $source);
        self::assertStringNotContainsString("getUrl('media/", $source);
        self::assertStringNotContainsString('isBackend()', $source);
        self::assertDoesNotMatchRegularExpression(
            '/getBackendUrlPath\(\s*[\'"]media\/backend\/manager\/iframe[\'"]\s*,\s*\$params\s*,\s*true\s*\)/',
            $source
        );
        self::assertDoesNotMatchRegularExpression(
            '/getBackendUrl\(\s*[\'"]media\/backend\/manager\/iframe[\'"]/',
            $source
        );
    }

    public function testPickerTemplateUsesTheRelativeConnectorSuppliedByTheBlock(): void
    {
        $path = dirname(__DIR__, 3) . '/view/blocks/weline-media.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('data-w-aspect-ratio-tolerance', $source);
        self::assertStringContainsString('data-src="{{connector}}"', $source);
        self::assertStringContainsString('data-w-bind-url="<?= htmlspecialchars((string)($this->getData(\'bind_url\') ?? \'\')', $source);
        self::assertStringContainsString('aspect_ratio_hint', $source);
        self::assertStringContainsString('recommend_size_display', $source);
        self::assertStringContainsString('data-aspect-ratio=', $source);
        self::assertStringNotContainsString('@backend-url{weline_filemanager/backend/media-reference/bind}', $source);
        self::assertStringNotContainsString('getBackendUrl(', $source);
        self::assertStringNotContainsString('window.location.origin', $source);
    }
}
