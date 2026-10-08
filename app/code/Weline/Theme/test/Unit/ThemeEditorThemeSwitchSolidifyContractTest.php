<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);

/**
 * Theme switch / inherit must SSE-solidify before canvas refresh;
 * canvas navigations sync outer shell page_type/URL.
 */
final class ThemeEditorThemeSwitchSolidifyContractTest extends TestCase
{
    public function testBackendExposesSolidifyScopeVersionSseEndpoint(): void
    {
        $controller = $this->read('app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');
        self::assertStringContainsString('function postSolidifyScopeVersion(', $controller);
        self::assertStringContainsString('function solidifyScopeVersionPayload(', $controller);
        self::assertStringContainsString('function streamSolidifyScopeVersion(', $controller);
        self::assertStringContainsString('solidifyCurrentScopeVersion(', $controller);
        self::assertStringContainsString('wantsStandardPublishStream($data)', $controller);
        self::assertStringContainsString('text/event-stream', $controller);
        self::assertStringContainsString('theme-layout solidify', $controller);
        self::assertStringContainsString('含 Filters 等默认注入', $controller);

        $provider = $this->read('app/code/Weline/Theme/extends/module/Weline_Framework/Query/ThemeQueryProvider.php');
        self::assertStringContainsString(
            "'/theme/backend/theme-editor/solidify-scope-version'",
            $provider,
        );
        self::assertStringContainsString('solidifyScopeVersionPayload()', $provider);

        $template = $this->read('app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml');
        self::assertStringContainsString('data-api-solidify-scope-version=', $template);
        self::assertStringContainsString('theme/backend/theme-editor/solidify-scope-version', $template);
    }

    public function testDualEditorsGateThemeSwitchOnSolidifyAndSyncShellFromCanvas(): void
    {
        foreach ([
            'app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js',
            'app/code/Weline/Theme/view/statics/js/theme-editor.js',
        ] as $relative) {
            $source = $this->read($relative);
            self::assertStringContainsString('apiSolidifyScopeVersion', $source, $relative);
            self::assertStringContainsString('function applyThemeBindingWithSolidifyGate(', $source, $relative);
            self::assertStringContainsString('function requestSolidifyScopeWithProgress(', $source, $relative);
            self::assertStringContainsString('function syncEditorShellFromCanvasLocation(', $source, $relative);
            self::assertStringContainsString("Accept: 'text/event-stream'", $source, $relative);
            self::assertStringContainsString('solidify-scope-version', $source, $relative);
            self::assertStringContainsString('applyThemeBindingWithSolidifyGate({', $source, $relative);
            self::assertStringContainsString('syncEditorShellFromCanvasLocation()', $source, $relative);
            self::assertStringContainsString('Never writes previewFrame.src', $source, $relative);
            // Must not load canvas before solidify in theme change path.
            self::assertStringNotContainsString(
                "summary: 'theme_binding_changed',\n                        });\n                        await refreshLayoutOptions",
                $source,
                $relative,
            );
        }
    }

    public function testSolidifyDoesNotWipeDraftResources(): void
    {
        $controller = $this->read('app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');
        $payloadStart = \strpos($controller, 'function solidifyScopeVersionPayload(');
        self::assertNotFalse($payloadStart);
        $payloadSlice = \substr($controller, $payloadStart, 2500);
        self::assertStringNotContainsString('ThemeEditorDraftResetService', $payloadSlice);
        self::assertStringNotContainsString('discardDraft(', $payloadSlice);
        self::assertStringContainsString('solidifyCurrentScopeVersion(', $payloadSlice);
    }

    public function testSolidifyCandidatesExpandProductsFiltersRequiredInjection(): void
    {
        $coordinator = $this->read(
            'app/code/Weline/Theme/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php'
        );
        self::assertStringContainsString('function solidifyCurrentScopeVersion(', $coordinator);
        self::assertStringContainsString('function declaredPageTargets(', $coordinator);
        self::assertStringContainsString('RequiredDefaultInjectionBakeMerger', $coordinator);
        self::assertStringContainsString('mergeDefaults', $coordinator);

        $gate = $this->read(
            'app/code/Weline/Theme/Service/LayoutEntity/ThemeLayoutEntityRequestSolidifyGate.php'
        );
        self::assertStringContainsString('list-filters', $gate);
        self::assertStringContainsString('由 Filters 部件默认注入', $gate);

        $filtersWidget = $this->read(
            'app/code/Weline/Filters/extends/module/Weline_Widget/Weline_Filters/widget.php'
        );
        self::assertStringContainsString("'layout_type' => 'products'", $filtersWidget);
        self::assertStringContainsString("'slot' => 'list-filters'", $filtersWidget);

        // Theme-switch gate must bake full scope (not current page only) so products Filters land.
        $authoritative = $this->read(
            'app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js'
        );
        $gateStart = \strpos($authoritative, 'async function applyThemeBindingWithSolidifyGate(');
        self::assertNotFalse($gateStart);
        $gateSlice = \substr($authoritative, $gateStart, 3500);
        self::assertStringContainsString('requestSolidifyScopeWithProgress(', $gateSlice);
        self::assertStringContainsString('openResetProgressLock(', $gateSlice);
        self::assertStringContainsString('setEditorBusy(true, busyMessage)', $gateSlice);
        self::assertStringContainsString('await loadCanvas()', $gateSlice);
        self::assertStringNotContainsString("showToast(busyMessage, 'info')", $gateSlice);
        $solidifyPos = \strpos($gateSlice, 'requestSolidifyScopeWithProgress(');
        $loadPos = \strpos($gateSlice, 'await loadCanvas()');
        self::assertNotFalse($solidifyPos);
        self::assertNotFalse($loadPos);
        self::assertLessThan($loadPos, $solidifyPos, 'loadCanvas must run after solidify');
    }

    public function testScopeAndThemeSwitchShareBlockingSolidifyProgressLock(): void
    {
        foreach ([
            'app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js',
            'app/code/Weline/Theme/view/statics/js/theme-editor.js',
        ] as $relative) {
            $source = $this->read($relative);
            self::assertStringContainsString('function openResetProgressLock(', $source, $relative);
            self::assertStringContainsString('function queueSolidifyGateAfterNavigation(', $source, $relative);
            self::assertStringContainsString('function runQueuedSolidifyGateIfNeeded(', $source, $relative);
            self::assertStringContainsString('data-w-theme-reset-progress-lock', $source, $relative);
            self::assertStringContainsString('queueSolidifyGateAfterNavigation(scopeBusyTitle, scopeBusyMessage)', $source, $relative);
            self::assertStringContainsString('runQueuedSolidifyGateIfNeeded()', $source, $relative);
            self::assertStringContainsString('bindSolidifyProgressHandler(busyMessage)', $source, $relative);
        }
    }

    private function read(string $relative): string
    {
        $path = BP . $relative;
        self::assertFileExists($path);

        return (string)\file_get_contents($path);
    }
}
