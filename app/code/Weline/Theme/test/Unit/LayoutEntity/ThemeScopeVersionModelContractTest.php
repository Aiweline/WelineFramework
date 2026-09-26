<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * Source-string contract: ThemeScopeVersion owns area/lifecycle/content_revision;
 * selection is the mutable published/draft authority (legacy flags remain until Task 6).
 */
final class ThemeScopeVersionModelContractTest extends TestCase
{
    public function testModelHasChromePayloadAndNoPageType(): void
    {
        $path = \dirname(__DIR__, 3) . '/Model/ThemeScopeVersion.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('final class ThemeScopeVersion', $src);
        self::assertStringContainsString("schema_table = 'theme_scope_version'", $src);
        self::assertStringContainsString("schema_fields_CHROME_PAYLOAD_JSON = 'chrome_payload_json'", $src);
        self::assertStringContainsString("schema_fields_STRUCTURE_KEY = 'structure_key'", $src);
        self::assertStringContainsString('uk_theme_scope_version_number', $src);
        self::assertStringContainsString("'store_mode', 'area', 'version_number'", $src);
        self::assertStringContainsString("schema_fields_AREA = 'area'", $src);
        self::assertStringContainsString("schema_fields_LIFECYCLE = 'lifecycle'", $src);
        self::assertStringContainsString("schema_fields_CONTENT_REVISION = 'content_revision'", $src);
        self::assertStringContainsString("schema_fields_CREATION_SOURCE_KIND = 'creation_source_kind'", $src);
        self::assertStringContainsString("schema_fields_CREATION_SOURCE_VERSION_ID = 'creation_source_version_id'", $src);

        self::assertStringNotContainsString('schema_fields_PAGE_TYPE', $src);
        self::assertStringNotContainsString("= 'page_type'", $src);
        self::assertDoesNotMatchRegularExpression("/#\\[Col[^\\]]*page_type/i", $src);
    }

    public function testSelectionAndSnapshotModelsExistWithOwnerKeys(): void
    {
        $base = \dirname(__DIR__, 3) . '/Model/';
        foreach ([
            'ThemeScopeVersionSelection.php' => 'uk_theme_scope_version_selection_owner',
            'ThemeScopeVersionRevision.php' => 'uk_theme_scope_version_revision',
            'ThemeScopeVersionResourceSnapshot.php' => 'uk_theme_scope_version_resource_snapshot',
            'ThemeScopeVersionWidgetDecision.php' => 'uk_theme_scope_version_widget_decision',
        ] as $file => $index) {
            $path = $base . $file;
            self::assertFileExists($path, $file);
            $src = (string)\file_get_contents($path);
            self::assertStringContainsString($index, $src, $file);
        }
    }

    public function testWorkspaceUniqueKeyIncludesThemeVersionAndBindingExternal(): void
    {
        $path = \dirname(__DIR__, 3) . '/Model/ThemeScopeWorkspace.php';
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('uk_theme_scope_workspace_version_identity', $src);
        self::assertStringContainsString('uk_theme_scope_workspace_binding_identity', $src);
        self::assertStringContainsString('THEME_VERSION_EXTERNAL', $src);
        self::assertStringContainsString("schema_fields_THEME_VERSION_ID = 'theme_version_id'", $src);
        self::assertStringNotContainsString('uk_theme_scope_workspace_identity', $src);
    }
}
