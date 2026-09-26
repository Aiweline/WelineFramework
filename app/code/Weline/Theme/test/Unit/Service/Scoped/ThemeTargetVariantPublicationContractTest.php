<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;

final class ThemeTargetVariantPublicationContractTest extends TestCase
{
    public function testCmsPublicationUsesCanonicalScopedLayoutAndI18nResources(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 4) . '/Service/LayoutWorkspace.php');

        self::assertStringContainsString('resolveScopedTargetDraft', $source);
        self::assertStringContainsString('publishScopedTargetResources', $source);
        self::assertStringContainsString('ThemeEditorContext::RESOURCE_LAYOUT', $source);
        self::assertStringContainsString('ThemeEditorContext::RESOURCE_I18N', $source);
        self::assertStringContainsString('ThemeScopedWorkspaceRequestService', $source);
    }

    public function testIdempotentPublicationRepairsCompatibilityProjection(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 4) . '/Service/Scoped/ThemeScopedWorkspace.php');

        self::assertGreaterThanOrEqual(3, substr_count($source, '->projectPublished('));
        // Idempotent path must re-project then return release_id with idempotent=true
        // (dispatch may sit between projectPublished and the return array).
        self::assertMatchesRegularExpression(
            "/projectPublished\\([\\s\\S]*?'idempotent'\\s*=>\\s*true/s",
            $source,
        );
        self::assertStringContainsString("'idempotent' => true", $source);
        self::assertStringContainsString("'release_id' => \$currentRelease->getId()", $source);
    }
}
