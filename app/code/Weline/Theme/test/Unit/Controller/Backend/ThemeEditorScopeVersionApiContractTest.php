<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * Task 4+6 contract: ThemeEditor exposes scope-* version APIs only; old page-version routes removed.
 */
final class ThemeEditorScopeVersionApiContractTest extends TestCase
{
    public function testThemeEditorDefinesScopeVersionPayloadMethods(): void
    {
        $src = $this->readTheme('Controller/Backend/ThemeEditor.php');

        foreach ([
            'function getScopeVersions(',
            'function getScopeVersionsPayload(',
            'function postCreateScopeDraft(',
            'function createScopeDraftPayload(',
            'function postSaveScopeVersion(',
            'function saveScopeVersionPayload(',
            'function postPublishScopeVersion(',
            'function publishScopeVersionPayload(',
            'function postRestoreScopeDefaults(',
            'function restoreScopeDefaultsPayload(',
            'ThemeVersionPublicationService',
            'themeVersionOwnerFromContext',
        ] as $needle) {
            self::assertStringContainsString($needle, $src, $needle);
        }

        foreach ([
            'function getVersionsPayload(',
            'function saveVersionPayload(',
            'function switchVersionPayload(',
            'function restoreOriginalPayload(',
            'function publishVersionPayload(',
            'function postSaveVersion(',
            'function postSwitchVersion(',
            'function postRestoreOriginal(',
            'function postPublishVersion(',
        ] as $needle) {
            self::assertStringNotContainsString($needle, $src, $needle);
        }
    }

    public function testThemeQueryProviderMapsScopeVersionRoutes(): void
    {
        $src = $this->readTheme(
            'extends/module/Weline_Framework/Query/ThemeQueryProvider.php'
        );

        foreach ([
            "/theme/backend/theme-editor/scope-versions' =>",
            "/theme/backend/theme-editor/create-scope-draft' =>",
            "/theme/backend/theme-editor/save-scope-version' =>",
            "/theme/backend/theme-editor/publish-scope-version' =>",
            "/theme/backend/theme-editor/restore-scope-defaults' =>",
            'getScopeVersionsPayload()',
            'createScopeDraftPayload()',
            'saveScopeVersionPayload()',
            'publishScopeVersionPayload()',
            'restoreScopeDefaultsPayload()',
        ] as $needle) {
            self::assertStringContainsString($needle, $src, $needle);
        }

        foreach ([
            "/theme/backend/theme-editor/versions' =>",
            "/theme/backend/theme-editor/save-version' =>",
            "/theme/backend/theme-editor/switch-version' =>",
            "/theme/backend/theme-editor/restore-original' =>",
            "/theme/backend/theme-editor/publish-version' =>",
        ] as $needle) {
            self::assertStringNotContainsString($needle, $src, $needle);
        }
        // Virtual-theme publish-version remains unrelated.
        self::assertStringContainsString(
            "/theme/backend/virtual-theme/publish-version' =>",
            $src,
        );
    }

    public function testThemeEditorTemplateExposesScopeVersionDataApiAttrs(): void
    {
        $src = $this->readTheme('view/templates/backend/ThemeEditor/index.phtml');

        foreach ([
            'data-api-scope-versions=',
            'data-api-create-scope-draft=',
            'data-api-save-scope-version=',
            'data-api-publish-scope-version=',
            'data-api-restore-scope-defaults=',
        ] as $needle) {
            self::assertStringContainsString($needle, $src, $needle);
        }
    }

    public function testPreviewTokenServiceCarriesOwnerVersionModeRevisionFields(): void
    {
        $src = $this->readTheme('Service/PreviewTokenService.php');

        foreach ([
            "'theme_version_id'",
            "'mode'",
            "'content_revision'",
            "'canonical_scope'",
            "'store_mode'",
            "'area'",
            "'owner_hash'",
        ] as $needle) {
            self::assertStringContainsString($needle, $src, $needle);
        }
    }

    public function testThemeEditorJsPrefersScopeVersionApis(): void
    {
        foreach ([
            'view/statics/js/theme-editor.js',
            'view/statics/ui/pages/weline-theme-editor.js',
        ] as $relative) {
            $src = $this->readTheme($relative);
            foreach ([
                'apiScopeVersions',
                'apiCreateScopeDraft',
                'apiSaveScopeVersion',
                'apiPublishScopeVersion',
                'apiRestoreScopeDefaults',
                'buildScopeVersionPayload',
                'create-scope-draft',
                'publish-scope-version',
                'explicit_historical',
            ] as $needle) {
                self::assertStringContainsString($needle, $src, $relative . ':' . $needle);
            }
            foreach ([
                'preferScopeVersionApis',
                'apiSaveVersion',
                'apiSwitchVersion',
                'apiRestoreOriginal',
                'apiPublishVersion',
                'apiVersions',
            ] as $needle) {
                self::assertStringNotContainsString($needle, $src, $relative . ':' . $needle);
            }
        }
    }

    private function readTheme(string $relativeToTheme): string
    {
        $path = \dirname(__DIR__, 4) . '/' . \ltrim($relativeToTheme, '/');
        $src = \file_get_contents($path);
        self::assertIsString($src, $path);

        return $src;
    }
}
