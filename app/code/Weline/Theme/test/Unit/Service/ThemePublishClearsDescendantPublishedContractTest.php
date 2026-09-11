<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Website/global publish must clear descendant Scope is_published markers
 * so storefront ThemePublishedVersionRuntimeResolver does not keep showing
 * a stale store-level name (e.g. 「原始布局」) after publishing 「test」.
 */
final class ThemePublishClearsDescendantPublishedContractTest extends TestCase
{
    public function testMarkPublishedVersionClearsDescendantPublishedFlags(): void
    {
        $service = $this->read('app/code/Weline/Theme/Service/ThemeLayoutVersionService.php');

        self::assertStringContainsString('unsetPublishedDescendantVersions(', $service);
        self::assertStringContainsString('isDescendantStorageScope(', $service);
        self::assertStringContainsString('ThemeLayoutVersion::schema_fields_ID', $service);
        self::assertStringNotContainsString('schema_fields_VERSION_ID', $service);
        self::assertStringContainsString('clone $this->versionModel', $service);

        $markPos = strpos($service, 'private function markPublishedVersion(');
        self::assertNotFalse($markPos);
        $markBody = substr($service, $markPos, 900);
        self::assertStringContainsString('unsetPublishedVersion(', $markBody);
        self::assertStringContainsString('unsetPublishedDescendantVersions(', $markBody);

        $editor = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');
        $publishPos = strpos($editor, 'async function publishTheme()');
        self::assertNotFalse($publishPos);
        $publishBody = substr($editor, $publishPos, 1800);
        self::assertStringContainsString('version_id: state.currentVersionId', $publishBody);
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BP . '/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }
}
