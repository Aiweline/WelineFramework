<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Live-preview Tokens must carry typed editor_context so publish-and-exit can
 * resolve layout identity without feeding PreviewContext shell target_type.
 */
final class ThemePreviewTypedEditorContextBinderContractTest extends TestCase
{
    public function testBinderServiceMaterializesIdentityClaims(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/ThemePreviewTypedEditorContextBinder.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('function decodeClaims(', $source);
        self::assertStringContainsString('function materializeClaims(', $source);
        self::assertStringContainsString('function bindIntoPreviewContext(', $source);
        self::assertStringContainsString("['identity' => \$scopeIdentity->toArray()]", $source);
        self::assertStringContainsString('TARGET_TYPE_LAYOUT', $source);
        self::assertStringContainsString('ThemeVirtualLayout::TARGET_GLOBAL', $source);
        self::assertStringContainsString("'locale' => 'default'", $source);
    }

    public function testPreviewTokenMintBindsTypedEditorContext(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/PreviewTokenService.php';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('ensureTypedEditorContext(', $source);
        self::assertStringContainsString('ThemePreviewTypedEditorContextBinder', $source);
        self::assertStringContainsString('ScopeIdentity::global()', $source);
        self::assertStringContainsString("'editor_context'", $source);
    }
}
