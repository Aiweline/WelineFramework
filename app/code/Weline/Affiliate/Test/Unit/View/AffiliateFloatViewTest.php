<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AffiliateFloatViewTest extends TestCase
{
    private const MODULE_ROOT = BP . '/app/code/Weline/Affiliate';

    public function testFloatTemplateRendersScopeRulesAndUsesExistingCenterRoute(): void
    {
        $path = self::MODULE_ROOT . '/view/templates/frontend/widgets/affiliate-float.phtml';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('AffiliateRuleService', $src);
        self::assertStringContainsString('data-affiliate-float-panel', $src);
        self::assertStringContainsString('data-affiliate-float-rules', $src);
        self::assertStringContainsString('data-affiliate-float-member', $src);
        self::assertStringContainsString('data-affiliate-center-url', $src);
        self::assertStringContainsString("getUrl('affiliate')", $src);
        self::assertStringNotContainsString('affiliate/customer/affiliate', $src);
        self::assertStringContainsString('data-weline-load="api,account,affiliateFloat"', $src);
        self::assertStringContainsString('data-affiliate-level-name', $src);
        self::assertStringContainsString('data-affiliate-i18n', $src);
    }

    public function testFloatJsChecksLoginBeforeFetchingMemberAndStaysSilent(): void
    {
        $path = self::MODULE_ROOT . '/view/statics/js/affiliate-float.js';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('checkFrontendUserLogin', $src);
        self::assertStringContainsString('getMySummary', $src);
        self::assertStringContainsString('silent: true', $src);
        self::assertStringContainsString('login_required', $src);
        self::assertStringContainsString('weline:account:frontend:login', $src);
        self::assertStringContainsString('weline:account:frontend:logout', $src);
        self::assertStringContainsString('renderGuest', $src);
        self::assertStringContainsString('renderMember', $src);
        self::assertStringContainsString('clearMember', $src);
        self::assertStringContainsString('function resetWidgets', $src);
        self::assertStringContainsString('data-affiliate-center-url', $src);

        $loginPos = strpos($src, 'checkFrontendUserLogin');
        $memberCallPos = strpos($src, 'return loadMember(widget, i18n)');
        self::assertNotFalse($loginPos);
        self::assertNotFalse($memberCallPos);
        self::assertStringContainsString('if (!status || !status.isLogin)', $src);
        self::assertLessThan($memberCallPos, $loginPos, 'member hydration must live behind the login check');
    }

    public function testSystemConfigDeclaresScopedRulesAndBackendPageEmbedsThem(): void
    {
        $declarationPath = self::MODULE_ROOT . '/extends/module/Weline_SystemConfig/Config/frontend/product-share.phtml';
        self::assertFileExists($declarationPath);
        $declaration = (string) file_get_contents($declarationPath);

        foreach (['rule_title', 'rule_summary', 'default_commission_rate', 'level_name'] as $key) {
            self::assertStringContainsString('key="' . $key . '"', $declaration);
        }
        self::assertStringContainsString('scope="global,website,store,channel"', $declaration);
        self::assertStringContainsString('网站规则默认向下覆盖店铺与渠道', $declaration);

        $pagePath = self::MODULE_ROOT . '/view/templates/backend/Config/index.phtml';
        self::assertFileExists($pagePath);
        $page = (string) file_get_contents($pagePath);
        self::assertStringContainsString('rule_title', $page);
        self::assertStringContainsString('rule_summary', $page);
        self::assertStringContainsString('default_commission_rate', $page);
        self::assertStringContainsString('level_name', $page);
    }
}
