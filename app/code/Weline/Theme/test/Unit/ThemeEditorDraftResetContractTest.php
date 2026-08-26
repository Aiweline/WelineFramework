<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);

final class ThemeEditorDraftResetContractTest extends TestCase
{
    public function testResetModalDefaultsSkipI18nAndKeepDangerAccordionCollapsed(): void
    {
        $template = $this->read('app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml');

        self::assertStringContainsString('id="btnResetDraftResources"', $template);
        self::assertStringContainsString('data-theme-editor-action="open-reset-draft-modal"', $template);
        self::assertStringContainsString('id="themeEditorResetDraftModal"', $template);
        self::assertStringContainsString('data-api-reset-draft-resources=', $template);
        self::assertStringContainsString('theme/backend/theme-editor/reset-draft-resources', $template);
        self::assertStringContainsString('w-theme-editor-reset-draft__danger', $template);
        self::assertStringContainsString('id="btnResetDraftAllResources"', $template);

        self::assertMatchesRegularExpression(
            '/name="reset_resource"\s+value="layout"\s+checked/',
            $template,
        );
        self::assertMatchesRegularExpression(
            '/name="reset_resource"\s+value="meta"\s+checked/',
            $template,
        );
        self::assertMatchesRegularExpression(
            '/name="reset_resource"\s+value="appearance"\s+checked/',
            $template,
        );
        self::assertDoesNotMatchRegularExpression(
            '/name="reset_resource"\s+value="i18n"\s+checked/',
            $template,
        );
        self::assertDoesNotMatchRegularExpression(
            '/name="reset_resource"\s+value="theme_binding"\s+checked/',
            $template,
        );
        self::assertMatchesRegularExpression(
            '/name="reset_layout_scope"\s+value="current_layout"\s+checked/',
            $template,
        );
    }

    public function testDualEditorsExposeResetDraftApiAndHandlers(): void
    {
        foreach ([
            'app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js',
            'app/code/Weline/Theme/view/statics/js/theme-editor.js',
        ] as $relative) {
            $source = $this->read($relative);
            self::assertStringContainsString('apiResetDraftResources', $source, $relative);
            self::assertStringContainsString('function openResetDraftModal(', $source, $relative);
            self::assertStringContainsString('function executeResetDraftResources(', $source, $relative);
            self::assertStringContainsString("resources: selection.resources", $source, $relative);
            self::assertStringContainsString("layout_scope: selection.layout_scope", $source, $relative);
            self::assertStringContainsString("selection.resources.includes('layout')", $source, $relative);
            self::assertStringContainsString('refreshDefaultInjectionApplications', $source, $relative);
            self::assertStringContainsString("const defaultChecked = new Set(['layout', 'meta', 'appearance'])", $source, $relative);
            self::assertStringContainsString("['layout', 'meta', 'appearance', 'theme_binding', 'i18n']", $source, $relative);
            self::assertStringNotContainsString('restoreOriginal()', $source, $relative);
        }
    }

    public function testBackendResetServiceAndRouteAvoidVersionHistoryWrites(): void
    {
        $service = $this->read('app/code/Weline/Theme/Service/ThemeEditorDraftResetService.php');
        self::assertStringContainsString('class ThemeEditorDraftResetService', $service);
        self::assertStringContainsString('discardDraft(', $service);
        self::assertStringContainsString('restoreDefaultInjectionsAfterDraftReset(', $service);
        self::assertStringContainsString('default_injections', $service);
        self::assertStringContainsString('clearNonGlobalCaches(', $service);
        self::assertStringContainsString('LAYOUT_SCOPE_ALL', $service);
        self::assertStringNotContainsString('restoreOriginal(', $service);
        self::assertStringNotContainsString('ThemeLayoutVersionService', $service);

        $controller = $this->read('app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');
        self::assertStringContainsString('function resetDraftResourcesPayload(', $controller);
        self::assertStringContainsString('ThemeEditorDraftResetService::class', $controller);
        self::assertStringContainsString("\$data['resources']", $controller);
        self::assertStringContainsString("\$data['layout_scope']", $controller);

        $provider = $this->read('app/code/Weline/Theme/extends/module/Weline_Framework/Query/ThemeQueryProvider.php');
        self::assertStringContainsString(
            "'/theme/backend/theme-editor/reset-draft-resources'",
            $provider,
        );
        self::assertStringContainsString('resetDraftResourcesPayload()', $provider);
    }

    private function read(string $relative): string
    {
        $path = BP . $relative;
        self::assertFileExists($path);

        return (string)\file_get_contents($path);
    }
}
