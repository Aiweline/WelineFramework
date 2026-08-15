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
        $path = BP . '/app/code/Weline/MediaManager/Block/WelineMedia.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('getBackendUrlPath(', $source);
        self::assertStringContainsString("'media/backend/manager/iframe'", $source);
        self::assertStringContainsString('Do not merge the parent workbench query', $source);
        self::assertDoesNotMatchRegularExpression(
            '/getBackendUrlPath\(\s*[\'"]media\/backend\/manager\/iframe[\'"]\s*,\s*\$params\s*,\s*true\s*\)/',
            $source
        );
        self::assertDoesNotMatchRegularExpression(
            '/getBackendUrl\(\s*[\'"]media\/backend\/manager\/iframe[\'"]/',
            $source
        );
    }

    public function testOpenMediaManagerCoercesConnectorToSameOrigin(): void
    {
        $path = BP . '/app/code/Weline/MediaManager/view/blocks/weline-media.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('function sameOriginConnector(connectorUrl)', $source);
        self::assertStringContainsString('window.location.origin + parsed.pathname', $source);
        self::assertStringContainsString('connectorUrl = sameOriginConnector(connectorUrl);', $source);
        self::assertStringContainsString('ignoreBackdropUntil', $source);
    }
}
