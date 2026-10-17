<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Maintenance\Service\MaintenanceDevPreview;
use Weline\Theme\Helper\SiteBrand;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllMaintenanceViewContractsPass().
 */
final class MaintenanceViewContractsTest extends TestCase
{
    public function testAllMaintenanceViewContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'MaintenanceDevToolPanelHook::testTabsAfterHookDefersToDynamicRegistration' => function () {
            $this->assert_MaintenanceDevToolPanelHook_testTabsAfterHookDefersToDynamicRegistration();
        },
            'MaintenanceDevToolPanelHook::testSearchAreasAfterHookRegistersFarRightParentWithSubtabs' => function () {
            $this->assert_MaintenanceDevToolPanelHook_testSearchAreasAfterHookRegistersFarRightParentWithSubtabs();
        },
            'MaintenanceFavicon::testStandaloneTemplateDeclaresFaviconLinks' => function () {
            $this->assert_MaintenanceFavicon_testStandaloneTemplateDeclaresFaviconLinks();
        },
            'MaintenanceFavicon::testGeneratorResolvesSiteIconBeforeDefault' => function () {
            $this->assert_MaintenanceFavicon_testGeneratorResolvesSiteIconBeforeDefault();
        },
            'MaintenanceFavicon::testStandaloneTemplateStylesLanguageFlagImages' => function () {
            $this->assert_MaintenanceFavicon_testStandaloneTemplateStylesLanguageFlagImages();
        },
            'MaintenanceFavicon::testStandaloneTemplateUsesResolvedLogoUrl' => function () {
            $this->assert_MaintenanceFavicon_testStandaloneTemplateUsesResolvedLogoUrl();
        },
            'MaintenanceFavicon::testThemeDefaultIconPublicPathsAreStable' => function () {
            $this->assert_MaintenanceFavicon_testThemeDefaultIconPublicPathsAreStable();
        },
            'MaintenanceRecoveryAutoRefresh::testStandaloneTemplateRecoveryScriptReloadsOnNon503AndHardRefresh' => function () {
            $this->assert_MaintenanceRecoveryAutoRefresh_testStandaloneTemplateRecoveryScriptReloadsOnNon503AndHardRefresh();
        },
            'MaintenanceRecoveryAutoRefresh::testCanonicalMaintenanceJsMatchesRecoveryContract' => function () {
            $this->assert_MaintenanceRecoveryAutoRefresh_testCanonicalMaintenanceJsMatchesRecoveryContract();
        },
            'MaintenanceRecoveryAutoRefresh::testThemeUiMirrorMatchesCanonicalRecoveryHelpers' => function () {
            $this->assert_MaintenanceRecoveryAutoRefresh_testThemeUiMirrorMatchesCanonicalRecoveryHelpers();
        },
        ];
    }

private function assert_MaintenanceDevToolPanelHook_testTabsAfterHookDefersToDynamicRegistration(): void
{
    $source = $this->hook_MaintenanceDevToolPanelHook('tabs-after.phtml');
    self::assertStringContainsString('search-areas-after', $source);
    self::assertStringNotContainsString('dev-tool-tab', $source);
    self::assertStringNotContainsString('w:icon', $source);
}

private function assert_MaintenanceDevToolPanelHook_testSearchAreasAfterHookRegistersFarRightParentWithSubtabs(): void
{
    $source = $this->hook_MaintenanceDevToolPanelHook('search-areas-after.phtml');
    self::assertStringContainsString('dev-tool-search-area-maintenance-advanced', $source);
    self::assertStringContainsString('order: 9990', $source);
    self::assertStringContainsString('WelinePanel.registerTab', $source);
    self::assertStringContainsString('WelineAdvancedMaintenance', $source);
    self::assertStringContainsString('registerChild', $source);
    self::assertStringContainsString('wam-subtab', $source);
    self::assertStringContainsString('MaintenanceDevPreview::URI_MARKER', $source);
    self::assertStringContainsString('target="_blank"', $source);
    self::assertStringNotContainsString('<iframe', $source);
    self::assertStringNotContainsString('w:icon', $source);
    self::assertStringNotContainsString('dev-tool-maintenance-preview-refresh', $source);
}

private function hook_MaintenanceDevToolPanelHook(string $filename): string
{
    return (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/view/hooks/Weline_DeveloperWorkspace/backend/partials/dev-tool-panel/' . $filename);
}

private function assert_MaintenanceFavicon_testStandaloneTemplateDeclaresFaviconLinks(): void
{
    $template = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/view/templates/maintenance.phtml');
    self::assertStringContainsString('maintenance_favicon_url', $template);
    self::assertStringContainsString('rel="icon"', $template);
    self::assertStringContainsString('rel="shortcut icon"', $template);
    self::assertStringContainsString('rel="apple-touch-icon"', $template);
    self::assertStringContainsString('SiteBrand::DEFAULT_ICON_PUBLIC_PATH', $template);
}

private function assert_MaintenanceFavicon_testGeneratorResolvesSiteIconBeforeDefault(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/Service/MaintenanceStaticGenerator.php');
    self::assertStringContainsString('resolveMaintenanceBrandUrls', $source);
    self::assertStringContainsString('ThemeBrandResolver', $source);
    self::assertStringContainsString("'site_icon'", $source);
    self::assertStringContainsString('DEFAULT_ICON_PUBLIC_PATH', $source);
    self::assertStringContainsString('DEFAULT_APPLE_TOUCH_ICON_PUBLIC_PATH', $source);
    self::assertStringContainsString('toPublicMediaOrStaticUrl', $source);
    self::assertStringContainsString('resolveFrontendLogoUrl', $source);
    self::assertStringContainsString('/Weline/Theme/view/theme/frontend/assets/images/theme/logo.png', $source);
    self::assertStringContainsString('resolveWebsiteScopedPublishedBrand', $source);
    self::assertStringContainsString('materializeMaintenanceFlagMarkup', $source);
    self::assertStringContainsString('CountryFlagMarkup', $source);
    self::assertStringContainsString('/pub/errors/maintenance/flags/', $source);
}

private function assert_MaintenanceFavicon_testStandaloneTemplateStylesLanguageFlagImages(): void
{
    $template = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/view/templates/maintenance.phtml');
    self::assertStringContainsString('.language-flag-img', $template);
    self::assertStringContainsString('language-flag', $template);
}

private function assert_MaintenanceFavicon_testStandaloneTemplateUsesResolvedLogoUrl(): void
{
    $template = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/view/templates/maintenance.phtml');
    self::assertStringContainsString('maintenance_logo_url', $template);
    self::assertStringContainsString('maintenance-brand__logo-bar', $template);
    self::assertStringContainsString('class="maintenance-logo"', $template);
}

private function assert_MaintenanceFavicon_testThemeDefaultIconPublicPathsAreStable(): void
{
    self::assertSame('/Weline/Theme/view/theme/frontend/assets/images/theme/icon.png', SiteBrand::DEFAULT_ICON_PUBLIC_PATH);
    self::assertSame('/Weline/Theme/view/theme/frontend/assets/images/theme/apple-touch-icon.png', SiteBrand::DEFAULT_APPLE_TOUCH_ICON_PUBLIC_PATH);
    $iconFs = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/assets/images/theme/icon.png';
    $appleFs = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/assets/images/theme/apple-touch-icon.png';
    self::assertFileExists($iconFs);
    self::assertFileExists($appleFs);
}

private function assert_MaintenanceRecoveryAutoRefresh_testStandaloneTemplateRecoveryScriptReloadsOnNon503AndHardRefresh(): void
{
    $template = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/view/templates/maintenance.phtml');
    self::assertStringContainsString('function isRecovered(response)', $template);
    self::assertStringContainsString("redirect: 'manual'", $template);
    self::assertStringContainsString('scheduleHardReload()', $template);
    self::assertStringContainsString('hardReloadDelay = 30000', $template);
    self::assertStringContainsString('/maintenance/frontend/recovery-check', $template);
    self::assertStringContainsString('_maintenance_recovery_probe', $template);
    self::assertStringContainsString('X-Maintenance-Recovery-Check', $template);
    self::assertStringNotContainsString('response.status === 200', $template);
    self::assertStringNotContainsString("redirect: 'follow'", $template);
}

private function assert_MaintenanceRecoveryAutoRefresh_testCanonicalMaintenanceJsMatchesRecoveryContract(): void
{
    $js = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/view/statics/js/maintenance.js');
    self::assertStringContainsString('function isRecovered(response)', $js);
    self::assertStringContainsString("redirect: 'manual'", $js);
    self::assertStringContainsString('scheduleHardReload()', $js);
    self::assertStringContainsString('RECOVERY_CHECK_PATH', $js);
    self::assertStringContainsString('/maintenance/frontend/recovery-check', $js);
    self::assertStringContainsString('_maintenance_recovery_probe', $js);
    self::assertStringContainsString('X-Maintenance-Recovery-Check', $js);
    self::assertStringNotContainsString('response.status === 200', $js);
}

private function assert_MaintenanceRecoveryAutoRefresh_testThemeUiMirrorMatchesCanonicalRecoveryHelpers(): void
{
    $canonical = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/view/statics/js/maintenance.js');
    $themeMirror = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/pages/weline-maintenance.js');
    $normalize = static function (string $source): string {
        $source = \preg_replace('/^(?:\/\*[\s\S]*?\*\/\s*)+/', '', $source) ?? $source;
        return \preg_replace('/\s+/', ' ', \trim($source)) ?? $source;
    };
    self::assertSame($normalize($canonical), $normalize($themeMirror));
}
}
