<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllCdnViewContractsPass().
 */
final class CdnViewContractsTest extends TestCase
{
    public function testAllCdnViewContractsPass(): void
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
            'AccountOauthErrorAlert::testIndexSurfacesCloudflareErrorCodesPersistently' => function () {
            $this->assert_AccountOauthErrorAlert_testIndexSurfacesCloudflareErrorCodesPersistently();
        },
            'CdnSystemConfigPage::testConfigTemplateEmbedsCdnBackendSystemConfig' => function () {
            $this->assert_CdnSystemConfigPage_testConfigTemplateEmbedsCdnBackendSystemConfig();
        },
            'CdnSystemConfigPage::testMenuRegistersSystemConfigEntry' => function () {
            $this->assert_CdnSystemConfigPage_testMenuRegistersSystemConfigEntry();
        },
            'CdnSystemConfigPage::testOauthConfigAclUsesSystemConfigSource' => function () {
            $this->assert_CdnSystemConfigPage_testOauthConfigAclUsesSystemConfigSource();
        },
            'CdnSystemConfigPage::testFpcDevModeConfigTemplateExists' => function () {
            $this->assert_CdnSystemConfigPage_testFpcDevModeConfigTemplateExists();
        },
            'CdnSystemConfigPage::testAlwaysOnlineConfigTemplateExists' => function () {
            $this->assert_CdnSystemConfigPage_testAlwaysOnlineConfigTemplateExists();
        },
            'CdnSystemConfigPage::testPackAnnotationsConfigTemplateExists' => function () {
            $this->assert_CdnSystemConfigPage_testPackAnnotationsConfigTemplateExists();
        },
            'WarmupProviderTreeUi::testIndexTemplateHasScopeProviderAndQueuedUrlSections' => function () {
            $this->assert_WarmupProviderTreeUi_testIndexTemplateHasScopeProviderAndQueuedUrlSections();
        },
        ];
    }

private function assert_AccountOauthErrorAlert_testIndexSurfacesCloudflareErrorCodesPersistently(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/view/templates/backend/Account/index.phtml');
    self::assertStringContainsString('oauth_cf_error', $source);
    self::assertStringContainsString('oauth_adapter', $source);
    self::assertStringContainsString('data-testid="cdn-oauth-error"', $source);
    self::assertStringContainsString('invalid_scope', $source);
    self::assertStringContainsString('cdn-oauth-scopes-hint', $source);
    self::assertStringContainsString('cdn-oauth-scopes-gate-hint', $source);
    self::assertStringContainsString('cdn-oauth-redirect-uri-gate-toggle', $source);
    self::assertStringContainsString('data-w-disclosure-trigger', $source);
    self::assertStringContainsString('data-w-disclosure-panel', $source);
    self::assertStringContainsString('data-state="closed"', $source);
    self::assertStringContainsString('本页仅提示', $source);
    self::assertStringContainsString('getOauthCapableAdapters', $source);
    self::assertStringContainsString('name="adapter"', $source);
    self::assertStringContainsString('w-alert__content', $source);
    self::assertStringNotContainsString('data-testid="cdn-oauth-required-scopes"', $source);
    self::assertStringNotContainsString('data-testid="cdn-oauth-required-scopes-gate"', $source);
    self::assertStringNotContainsString('data-testid="cdn-oauth-connect-hint"', $source);
}

private function assert_CdnSystemConfigPage_testConfigTemplateEmbedsCdnBackendSystemConfig(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/view/templates/backend/Config/index.phtml';
    self::assertFileExists($path);
    $html = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="cdn-system-config"', $html);
    self::assertStringContainsString('<w:config:embed', $html);
    self::assertStringContainsString('module="Weline_Cdn"', $html);
    self::assertStringContainsString('area="backend"', $html);
    self::assertStringContainsString('<w:scope', $html);
    self::assertStringContainsString('cdn/backend/account/index', $html);
    self::assertStringContainsString('weline_systemconfig/backend/config', $html);
}

private function assert_CdnSystemConfigPage_testMenuRegistersSystemConfigEntry(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/etc/backend/menu.xml';
    self::assertFileExists($path);
    $xml = (string) file_get_contents($path);
    self::assertStringContainsString('Weline_Cdn::cdn_system_config', $xml);
    self::assertStringContainsString('cdn/backend/config', $xml);
    self::assertStringNotContainsString('cdn/backend/config/index', $xml);
    self::assertStringContainsString('title="系统配置"', $xml);
}

private function assert_CdnSystemConfigPage_testOauthConfigAclUsesSystemConfigSource(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/extends/module/Weline_SystemConfig/Config/backend/cdn-cloudflare-oauth.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('@config.acl {Weline_Cdn::cdn_system_config}', $src);
}

private function assert_CdnSystemConfigPage_testFpcDevModeConfigTemplateExists(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/extends/module/Weline_SystemConfig/Config/backend/cdn-fpc-dev-mode.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('cdn/fpc/dev_mode', $src);
    self::assertStringContainsString('website,store,channel', $src);
}

private function assert_CdnSystemConfigPage_testAlwaysOnlineConfigTemplateExists(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/extends/module/Weline_SystemConfig/Config/backend/cdn-cloudflare-always-online.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('cdn/cloudflare/always_online', $src);
    self::assertStringContainsString('@config.acl {Weline_Cdn::cdn_system_config}', $src);
    self::assertStringContainsString('type="switch"', $src);
    self::assertStringContainsString('default="1"', $src);
    self::assertStringContainsString('scope="global,website"', $src);
}

private function assert_CdnSystemConfigPage_testPackAnnotationsConfigTemplateExists(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/extends/module/Weline_SystemConfig/Config/backend/cdn-rules-pack-annotations.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('cdn/rules/pack_annotations', $src);
    self::assertStringContainsString('@config.acl {Weline_Cdn::cdn_system_config}', $src);
    self::assertStringContainsString('type="switch"', $src);
    self::assertStringContainsString('default="1"', $src);
    self::assertStringContainsString('scope="global,website"', $src);
    self::assertStringContainsString('打包', $src);
}

private function assert_WarmupProviderTreeUi_testIndexTemplateHasScopeProviderAndQueuedUrlSections(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/view/templates/backend/Warmup/index.phtml';
    $src = (string) file_get_contents($path);
    $this->assertStringContainsString('cdn-warmup-management', $src);
    $this->assertStringContainsString('cdn-warmup-scope', $src);
    $this->assertStringContainsString('<w:scope', $src);
    $this->assertStringContainsString('name="target_scope"', $src);
    $this->assertStringContainsString('scope="global,website"', $src);
    $this->assertStringContainsString('target_scope', $src);
    $this->assertStringNotContainsString('<select id="cdn-warmup-scope"', $src);
    $this->assertStringNotContainsString('全部范围（全局）', $src);
    $this->assertStringContainsString('已入队 URL', $src);
    $this->assertStringContainsString('listWarmupProviders', $src);
    $this->assertStringContainsString('listWarmupUrls', $src);
    $this->assertStringContainsString('collectWarmup', $src);
    $this->assertStringContainsString('executeWarmup', $src);
    $this->assertStringContainsString('未处理任何 URL', $src);
    $this->assertStringContainsString('processed === 0', $src);
    $this->assertStringContainsString('写入 %{1} · 范围未命中过滤 %{2} · 去重跳过 %{3}', $src);
    $this->assertStringContainsString('执行本范围预热', $src);
    $this->assertStringContainsString('本 Provider×本范围暂无已入队 URL，请先收集', $src);
    $this->assertStringContainsString('范围使用官方作用范围标签', $src);
    $this->assertStringContainsString('goto-page', $src);
    $this->assertStringContainsString('w-pagination', $src);
    $this->assertStringContainsString('data-cdn-warmup-pager', $src);
    $this->assertStringNotContainsString('加载更多', $src);
    $this->assertStringNotContainsString('load-more', $src);
    $this->assertStringContainsString('confirmAction', $src);
    $this->assertStringContainsString('dialog.confirm', $src);
    $this->assertStringContainsString('UI.toast', $src);
    $this->assertStringNotContainsString('window.confirm(', $src);
    $this->assertStringNotContainsString('window.alert(', $src);
}
}
