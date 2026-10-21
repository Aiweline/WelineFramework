<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Frontend\Helper\FrontendScopeReloadTip;

final class FrontendScopeReloadTipTest extends TestCase
{
    public function testTipCodesCoverScopeAndDeployReload(): void
    {
        self::assertTrue(FrontendScopeReloadTip::isTipCode('deploy_reload_required'));
        self::assertTrue(FrontendScopeReloadTip::isTipCode('scope_reload_required'));
        self::assertTrue(FrontendScopeReloadTip::isTipCode('scope_binding_required'));
        self::assertTrue(FrontendScopeReloadTip::isTipCode('scope_context_conflict'));
        self::assertTrue(FrontendScopeReloadTip::isTipCode('request_scope_already_conflicts'));
        self::assertTrue(FrontendScopeReloadTip::isTipCode('scope_token_expired'));
        self::assertTrue(FrontendScopeReloadTip::isTipCode('scope_token_context_conflict'));
        self::assertTrue(FrontendScopeReloadTip::isTipCode('scope_bootstrap_waiting'));
        self::assertFalse(FrontendScopeReloadTip::isTipCode('protocol_error'));
    }

    public function testClientRePunchesScopeTokenBeforeGuardedReload(): void
    {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/weline-api.js');
        self::assertMatchesRegularExpression(
            "/const isScopeStampRecoverableError = \(error\) => \{[\s\S]*?code === 'scope_token_expired'[\s\S]*?code === 'scope_token_context_conflict'[\s\S]*?\n    \};/",
            $script,
        );
        self::assertDoesNotMatchRegularExpression(
            "/const isScopeStampRecoverableError = \(error\) => \{[\s\S]*?scope_token_invalid[\s\S]*?\n    \};/",
            $script,
        );
        self::assertMatchesRegularExpression(
            "/scope_token_expired: \{[\s\S]*?autoReload: true,/",
            $script,
        );
        self::assertMatchesRegularExpression(
            "/scope_token_context_conflict: \{[\s\S]*?autoReload: true,/",
            $script,
        );
    }

    public function testScopeBootstrapWaitingUsesPleaseWaitPhrase(): void
    {
        $tip = FrontendScopeReloadTip::build('scope_bootstrap_waiting');
        self::assertNotNull($tip);
        self::assertNotSame('', (string)$tip['message']);
        self::assertSame((string)$tip['title'], (string)$tip['message']);
    }

    public function testBuildReturnsLocalizedTipShape(): void
    {
        $tip = FrontendScopeReloadTip::build('deploy_reload_required');
        self::assertNotNull($tip);
        self::assertArrayHasKey('title', $tip);
        self::assertArrayHasKey('message', $tip);
        self::assertArrayHasKey('locale', $tip);
        self::assertNotSame('', (string)$tip['title']);
        self::assertNotSame('', (string)$tip['message']);
        self::assertNotSame('', (string)$tip['locale']);
    }

    public function testCatalogForCurrentLocaleCoversAllTipCodes(): void
    {
        $catalog = FrontendScopeReloadTip::catalogForCurrentLocale();
        foreach (FrontendScopeReloadTip::TIP_CODES as $code) {
            self::assertArrayHasKey($code, $catalog);
            self::assertNotSame('', (string)$catalog[$code]['title']);
            self::assertNotSame('', (string)$catalog[$code]['message']);
        }
    }

    public function testQueryBinAttachesTipOnFrontendQueryException(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Api/QueryBin.php',
        );
        self::assertStringContainsString('FrontendScopeReloadTip::build', $src);
        self::assertStringContainsString("\$errorPayload['tip'] = \$tip", $src);
    }

    /**
     * Tip 是「正在重新获取」这类进行时提示，只走 tip 通道给 toast；
     * 覆盖 message 会让只渲染 error.message 的页面（购物车错误面板）把中间态当终态错误。
     */
    public function testQueryBinKeepsRealErrorMessageInsteadOfTipCopy(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Api/QueryBin.php',
        );
        self::assertStringNotContainsString("\$errorPayload['message'] = \$tip['message']", $src);
        self::assertStringNotContainsString("\$errorPayload['message'] = \$tip", $src);
    }

    /**
     * 生产静态是 immutable 一年缓存；懒加载的模块脚本必须带版本查询，
     * 否则发布后的修复（含 Scope 自恢复）永远到不了老访客浏览器。
     */
    public function testModuleLoaderBustsPublishedScriptCache(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/weline.js');
        self::assertMatchesRegularExpression(
            '/getScriptUrl\(url\)\s*\{[\s\S]*?weline_asset=[\s\S]*?\n        \}/',
            $src,
        );
    }

    /** 主题侧加载器同一规则：生产静态 immutable，懒加载脚本必须带版本。 */
    public function testThemeModuleLoaderBustsPublishedScriptCache(): void
    {
        $src = (string)file_get_contents(
            BP . 'app/code/Weline/Theme/view/theme/frontend/assets/js/theme.js',
        );
        self::assertMatchesRegularExpression(
            '/getScriptUrl\(url\)\s*\{[\s\S]*?weline_asset=[\s\S]*?\n        \}/',
            $src,
        );
    }

    /** 静态 URL 必须带构建代次：/static/{buildId}/...，否则旧 FPC 与长缓存会混代。 */
    public function testPublicStaticUrlsCarryBuildGeneration(): void
    {
        $publicUrl = (string)file_get_contents(
            BP . 'app/code/Weline/Framework/View/PublicStaticUrl.php',
        );
        self::assertStringContainsString('StaticBuildId::versionedPath', $publicUrl);

        $header = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/blocks/header/base.phtml',
        );
        self::assertStringContainsString("'staticBaseUrl'", $header);
        self::assertStringContainsString('StaticBuildId::current', $header);
    }

    /** 模块加载器必须从 staticBaseUrl 取根，否则懒加载仍走无版本 legacy 路径。 */
    public function testModuleLoaderUsesStaticBuildBase(): void
    {
        $loader = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/weline.js');
        self::assertStringContainsString('staticBaseUrl', $loader);
    }

    public function testScopeBootstrapWarmsTipsCatalog(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Api/ScopeBootstrap.php',
        );
        self::assertStringContainsString('FrontendScopeReloadTip::catalogForCurrentLocale', $src);
        self::assertStringContainsString("'tips' =>", $src);
        self::assertStringContainsString("'tips_locale' =>", $src);
        self::assertStringContainsString('armRequestLocaleFromPagePath', $src);
        self::assertStringContainsString('setRequestLanguageOverride', $src);
    }
}
