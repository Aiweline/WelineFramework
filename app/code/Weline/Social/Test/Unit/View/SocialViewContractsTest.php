<?php

declare(strict_types=1);

namespace Weline\Social\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSocialViewContractsPass().
 */
final class SocialViewContractsTest extends TestCase
{
    public function testAllSocialViewContractsPass(): void
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
            'SocialChoiceFloating::testAdminChoiceUsesThemeFloatingAttach' => function () {
            $this->assert_SocialChoiceFloating_testAdminChoiceUsesThemeFloatingAttach();
        },
            'SocialChoiceFloating::testAdminChoiceCssSupportsFloatingPositionedPortal' => function () {
            $this->assert_SocialChoiceFloating_testAdminChoiceCssSupportsFloatingPositionedPortal();
        },
            'SocialChoiceFloating::testScopeTypeDoesNotShowTechnicalWebsiteCodeChip' => function () {
            $this->assert_SocialChoiceFloating_testScopeTypeDoesNotShowTechnicalWebsiteCodeChip();
        },
            'SocialScopeMailboxStrip::testTabsBarHostsMailboxStripOnRight' => function () {
            $this->assert_SocialScopeMailboxStrip_testTabsBarHostsMailboxStripOnRight();
        },
            'WebsiteEditorEmbed::testHookInjectsSocialDisclosure' => function () {
            $this->assert_WebsiteEditorEmbed_testHookInjectsSocialDisclosure();
        },
            'WebsiteEditorEmbed::testIndexSupportsEmbedLock' => function () {
            $this->assert_WebsiteEditorEmbed_testIndexSupportsEmbedLock();
        },
            'SocialAccountEdit::testLocksPlatformIdentity' => function () {
            $this->assert_SocialAccountEditLocksPlatformIdentity();
        },
        ];
    }

private function assert_SocialChoiceFloating_testAdminChoiceUsesThemeFloatingAttach(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/view/templates/backend/Social/index.phtml');
    // 平台/范围/授权下拉：主题 SearchSelect（自带 floating portal），禁止再自研 social-choice 面板。
    self::assertStringContainsString('theme:search-select', $src);
    self::assertStringContainsString('WelineThemeSearchSelect', $src);
    self::assertStringContainsString('data-social-ss-host', $src);
    self::assertStringContainsString('mountInto', $src);
    self::assertStringNotContainsString('class="social-choice"', $src);
    self::assertStringNotContainsString('social-choice-panel', $src);
}

private function assert_SocialChoiceFloating_testAdminChoiceCssSupportsFloatingPositionedPortal(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/view/statics/css/backend/social-admin.css');
    // SearchSelect 浮层样式由 Theme Taglib 注入；本页仅需 scope 卡片不裁切。
    self::assertStringContainsString('overflow: visible', $src);
    self::assertStringContainsString('.scope-selector-grid', $src);
}

private function assert_SocialChoiceFloating_testScopeTypeDoesNotShowTechnicalWebsiteCodeChip(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/view/templates/backend/Social/index.phtml');
    self::assertStringContainsString("label: String(group.scope_label || group.scope_type || '')", $src);
    self::assertStringContainsString("meta: ''", $src);
    self::assertStringContainsString('typeWrap.hidden', $src);
    self::assertStringContainsString('meta: String(item.scope_code', $src);
    self::assertStringNotContainsString("meta: String(group.scope_type || '')", $src);
}

private function assert_SocialAccountEditLocksPlatformIdentity(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/view/templates/backend/Social/index.phtml');
    $service = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/Service/SocialAccountService.php');
    self::assertStringContainsString('function setAccountIdentityLocked', $src);
    self::assertStringContainsString('setAccountIdentityLocked(true)', $src);
    self::assertStringContainsString('setAccountIdentityLocked(false)', $src);
    self::assertStringContainsString('data-social-identity-lock-hint', $src);
    self::assertStringContainsString('data-account-edit', $src);
    self::assertStringContainsString("var ssRoot = root.querySelector('[data-w-search-select=\"1\"]')", $src);
    self::assertStringContainsString('ss.setDisabled(ssRoot, disabled)', $src);
    self::assertStringContainsString('编辑已有账户时不可更改平台。', $service);
    self::assertStringContainsString('编辑已有账户时不可更改平台。', $src);
}

private function assert_SocialScopeMailboxStrip_testTabsBarHostsMailboxStripOnRight(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/view/templates/backend/Social/index.phtml');
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/view/statics/css/backend/social-admin.css');
    self::assertStringContainsString('weline-social-admin__tabs-bar', $tpl);
    self::assertStringContainsString('data-testid="social-scope-mailbox"', $tpl);
    self::assertStringContainsString('getScopeSocialMailbox', $tpl);
    self::assertStringContainsString('ensureScopeSocialMailbox', $tpl);
    self::assertStringContainsString('refreshScopeMailboxStrip', $tpl);
    self::assertStringContainsString('data-mailbox-copy-password', $tpl);
    self::assertStringContainsString('mailboxCopyPassword', $tpl);
    self::assertStringContainsString('Weline.UI.dialog.confirm', $tpl);
    self::assertStringContainsString('uiConfirm(', $tpl);
    self::assertStringContainsString('uiToast(', $tpl);
    self::assertStringNotContainsString('window.confirm', $tpl);
    self::assertStringNotContainsString('window.alert', $tpl);
    self::assertStringNotContainsString('window.prompt', $tpl);
    self::assertStringNotContainsString(
        "if (mailboxState.password_once) {\n       html += '<button type=\"button\" class=\"w-button\" data-tone=\"quiet\" data-size=\"sm\" data-mailbox-copy-password>",
        $tpl
    );
    self::assertStringContainsString('.weline-social-admin__mailbox-strip', $css);
    self::assertStringContainsString('margin-inline-start: auto', $css);
    self::assertStringContainsString('border: 0', $css);
    self::assertStringContainsString('.weline-social-admin__mailbox-actions', $css);
    self::assertStringContainsString('flex-wrap: nowrap', $css);
}

private function assert_WebsiteEditorEmbed_testHookInjectsSocialDisclosure(): void
{
    $hook = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/view/hooks/Weline_Websites/backend/website/form/sections-after.phtml');
    $partial = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/view/templates/backend/partials/website-social-embed-section.phtml');
    $service = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/Service/SocialWebsiteAccountService.php');
    self::assertStringContainsString('website-social-embed-section.phtml', $hook);
    self::assertStringContainsString('data-testid="website-social-embed"', $partial);
    self::assertStringContainsString('data-testid="website-social-embed-frame"', $partial);
    self::assertStringContainsString('websiteEditorEmbedQuery', $partial);
    self::assertStringContainsString('function websiteEditorEmbedQuery', $service);
    self::assertStringContainsString("'isIframe' => 'true'", $service);
}

private function assert_WebsiteEditorEmbed_testIndexSupportsEmbedLock(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/view/templates/backend/Social/index.phtml');
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/Controller/Backend/Social.php');
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/view/statics/css/backend/social-admin.css');
    self::assertStringContainsString("layoutType = 'default.blank'", $controller);
    self::assertStringContainsString("assign('social_embed'", $controller);
    self::assertStringContainsString("assign('scope_locked'", $controller);
    self::assertStringContainsString('weline-social-admin--embed', $tpl);
    self::assertStringContainsString('data-scope-locked', $tpl);
    self::assertStringContainsString('网站编辑锁定', $tpl);
    self::assertStringContainsString('.weline-social-admin--embed .w-stat-tiles', $css);
}
}
