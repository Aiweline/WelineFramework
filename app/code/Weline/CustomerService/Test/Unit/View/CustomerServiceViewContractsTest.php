<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 10 *ContractTest files).
 * Observe one overall pass/fail via testAllCustomerServiceViewContractsPass().
 */
final class CustomerServiceViewContractsTest extends TestCase
{
    public function testAllCustomerServiceViewContractsPass(): void
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
            'BackendConsoleChatHistory::testConsoleLoadsLatestThenOlderOnScroll' => function () {
            $this->assert_BackendConsoleChatHistory_testConsoleLoadsLatestThenOlderOnScroll();
        },
            'BackendConsoleChatShell::testChatShellLocksViewportAndScrollsMessages' => function () {
            $this->assert_BackendConsoleChatShell_testChatShellLocksViewportAndScrollsMessages();
        },
            'BackendConsoleComposer::testComposerSupportsEmojiImageFileAndPhrases' => function () {
            $this->assert_BackendConsoleComposer_testComposerSupportsEmojiImageFileAndPhrases();
        },
            'BackendConsoleComposer::testPhrasePanelSupportsUnifiedSearchAndCategoryTabs' => function () {
            $this->assert_BackendConsoleComposer_testPhrasePanelSupportsUnifiedSearchAndCategoryTabs();
        },
            'BackendConsoleSessionAccordion::testSessionGroupsAreExclusiveAccordion' => function () {
            $this->assert_BackendConsoleSessionAccordion_testSessionGroupsAreExclusiveAccordion();
        },
            'CustomerServiceChatTemplateShell::testChatWindowStaysInChromeBagWithoutHtmlTemplateWrapper' => function () {
            $this->assert_CustomerServiceChatTemplateShell_testChatWindowStaysInChromeBagWithoutHtmlTemplateWrapper();
        },
            'CustomerServiceUnreadBreath::testUnreadBadgeTogglesBreathClassAndPresenceDot' => function () {
            $this->assert_CustomerServiceUnreadBreath_testUnreadBadgeTogglesBreathClassAndPresenceDot();
        },
            'CustomerServiceUnreadBreath::testStandardLifecycleGatesPolling' => function () {
            $this->assert_CustomerServiceUnreadBreath_testStandardLifecycleGatesPolling();
        },
            'CustomerServiceUnreadBreath::testUnreadWidgetStacksAboveSocialQuickBar' => function () {
            $this->assert_CustomerServiceUnreadBreath_testUnreadWidgetStacksAboveSocialQuickBar();
        },
            'CustomerServiceUnreadBreath::testWidgetLoadsOnlyAfterWindowLoadPlusThreeSeconds' => function () {
            $this->assert_CustomerServiceUnreadBreath_testWidgetLoadsOnlyAfterWindowLoadPlusThreeSeconds();
        },
            'CustomerServiceWidgetAccessibility::testSettingsLabelsAreProgrammaticallyAssociatedWithSelects' => function () {
            $this->assert_CustomerServiceWidgetAccessibility_testSettingsLabelsAreProgrammaticallyAssociatedWithSelects();
        },
            'CustomerServiceWidgetAccessibility::testIconOnlySendButtonKeepsALocalizedAccessibleName' => function () {
            $this->assert_CustomerServiceWidgetAccessibility_testIconOnlySendButtonKeepsALocalizedAccessibleName();
        },
            'CustomerServiceWidgetAccessibility::testDynamicLocaleSwitchKeepsComponentLanguageAndDirectionInSync' => function () {
            $this->assert_CustomerServiceWidgetAccessibility_testDynamicLocaleSwitchKeepsComponentLanguageAndDirectionInSync();
        },
            'CustomerServiceWidgetAccessibility::testFloatWidgetKeepsSlimTranslationsAndLoadsDictionariesOnDemand' => function () {
            $this->assert_CustomerServiceWidgetAccessibility_testFloatWidgetKeepsSlimTranslationsAndLoadsDictionariesOnDemand();
        },
            'CustomerServiceWidgetAccessibility::testEveryJavascriptTranslationLiteralIsIncludedInTheWidgetDictionary' => function () {
            $this->assert_CustomerServiceWidgetAccessibility_testEveryJavascriptTranslationLiteralIsIncludedInTheWidgetDictionary();
        },
            'CustomerServiceWidgetUi::testBodyEndHookOmitsWidgetTranslationsDuringStaticErrorPublish' => function () {
            $this->assert_CustomerServiceWidgetUi_testBodyEndHookOmitsWidgetTranslationsDuringStaticErrorPublish();
        },
            'CustomerServiceWidgetUi::testFloatPrefetchesWidgetI18nLabelsBeforePerStringLabel' => function () {
            $this->assert_CustomerServiceWidgetUi_testFloatPrefetchesWidgetI18nLabelsBeforePerStringLabel();
        },
            'CustomerServiceWidgetUi::testBodyEndKeepsSlimConfigWithoutEmbeddedDictionaries' => function () {
            $this->assert_CustomerServiceWidgetUi_testBodyEndKeepsSlimConfigWithoutEmbeddedDictionaries();
        },
            'CustomerServiceWidgetUi::testFrontendWidgetUsesWelineFormControls' => function () {
            $this->assert_CustomerServiceWidgetUi_testFrontendWidgetUsesWelineFormControls();
        },
            'CustomerServiceWidgetUi::testBodyEndHookDoesNotSkipOnPreviewOrVisualEditor' => function () {
            $this->assert_CustomerServiceWidgetUi_testBodyEndHookDoesNotSkipOnPreviewOrVisualEditor();
        },
            'CustomerServiceWidgetUi::testFrontendStylesUseThemePanelAndResponsiveChat' => function () {
            $this->assert_CustomerServiceWidgetUi_testFrontendStylesUseThemePanelAndResponsiveChat();
        },
            'CustomerServiceWidgetUi::testFrontendWidgetUsesClassicChromeSurface' => function () {
            $this->assert_CustomerServiceWidgetUi_testFrontendWidgetUsesClassicChromeSurface();
        },
            'CustomerServiceWidgetUi::testFrontendStylesPinSurfaceBackgroundForMessageInput' => function () {
            $this->assert_CustomerServiceWidgetUi_testFrontendStylesPinSurfaceBackgroundForMessageInput();
        },
            'CustomerServiceWidgetUi::testFrontendWidgetJsSupportsComposerUploadAndScreenshot' => function () {
            $this->assert_CustomerServiceWidgetUi_testFrontendWidgetJsSupportsComposerUploadAndScreenshot();
        },
            'FooterContactServiceLinkWidget::testFooterContactServiceLinkRegistersHomepageDefaultAndRemainsGloballyAvailable' => function () {
            $this->assert_FooterContactServiceLinkWidget_testFooterContactServiceLinkRegistersHomepageDefaultAndRemainsGloballyAvailable();
        },
            'FooterContactServiceLinkWidget::testFooterContactServiceLinkOpensFloatingChat' => function () {
            $this->assert_FooterContactServiceLinkWidget_testFooterContactServiceLinkOpensFloatingChat();
        },
            'HeaderContactServiceLinkWidget::testHeaderContactServiceLinkRegistersHomepageDefaultInjection' => function () {
            $this->assert_HeaderContactServiceLinkWidget_testHeaderContactServiceLinkRegistersHomepageDefaultInjection();
        },
            'HeaderContactServiceLinkWidget::testHeaderContactServiceLinkOpensFloatingChat' => function () {
            $this->assert_HeaderContactServiceLinkWidget_testHeaderContactServiceLinkOpensFloatingChat();
        },
        ];
    }

private function assert_BackendConsoleChatHistory_testConsoleLoadsLatestThenOlderOnScroll(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/js/backend-console.js');
    $ctrl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/Controller/Backend/Console.php');
    $this->assertStringContainsString('正在加载最新消息', $js);
    $this->assertStringContainsString('before_id', $js);
    $this->assertStringContainsString('loadOlderMessages', $js);
    $this->assertStringContainsString('onChatMessagesScroll', $js);
    $this->assertStringContainsString('hasMoreHistory', $js);
    $this->assertStringContainsString("getParam('before_id'", $ctrl);
    $this->assertStringContainsString('getMessagesBefore', $ctrl);
    $this->assertStringContainsString("'has_more'", $ctrl);
}

private function assert_BackendConsoleChatShell_testChatShellLocksViewportAndScrollsMessages(): void
{
    $cssFile = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/css/backend-console.css';
    $protoFile = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/prototype/console-chat-shell.html';
    $css = (string) file_get_contents($cssFile);
    $proto = (string) file_get_contents($protoFile);
    $this->assertStringContainsString('--cs-layout-offset', $css);
    $this->assertStringContainsString('100dvh - var(--cs-layout-offset)', $css);
    $this->assertStringContainsString('.chat-messages', $css);
    $this->assertMatchesRegularExpression('/\.chat-messages\s*\{[^}]*min-height:\s*0/s', $css);
    $this->assertMatchesRegularExpression('/\.chat-messages\s*\{[^}]*overflow-y:\s*auto/s', $css);
    $this->assertMatchesRegularExpression('/\.chat-header\s*\{[^}]*flex-shrink:\s*0/s', $css);
    $this->assertMatchesRegularExpression('/\.chat-input-area\s*\{[^}]*flex-shrink:\s*0/s', $css);
    $this->assertStringContainsString('PROTOTYPE', $proto);
    $this->assertStringContainsString('variant=A', $proto);
}

private function assert_BackendConsoleComposer_testComposerSupportsEmojiImageFileAndPhrases(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService';
    $js = (string) file_get_contents($base . '/view/statics/js/backend-console.js');
    $css = (string) file_get_contents($base . '/view/statics/css/backend-console.css');
    $ctrl = (string) file_get_contents($base . '/Controller/Backend/Console.php');
    $codec = (string) file_get_contents($base . '/Service/ChatAttachmentCodec.php');
    $model = (string) file_get_contents($base . '/Model/AgentPhrase.php');
    $proto = (string) file_get_contents($base . '/view/statics/prototype/console-composer-toolbar.html');
    $this->assertStringContainsString('data-cs-tool="emoji"', $js);
    $this->assertStringContainsString('data-cs-tool="image"', $js);
    $this->assertStringContainsString('data-cs-tool="file"', $js);
    $this->assertStringContainsString('data-cs-tool="phrase"', $js);
    $this->assertStringContainsString('uploadAndSendAttachment', $js);
    $this->assertStringContainsString('/phrases', $js);
    $this->assertStringContainsString('cs-composer-toolbar', $css);
    $this->assertStringContainsString('function postUpload', $ctrl);
    $this->assertStringContainsString('function getPhrases', $ctrl);
    $this->assertStringContainsString('function postPhraseSave', $ctrl);
    $this->assertStringContainsString('__CSJSON__', $codec);
    $this->assertStringContainsString('cs_agent_phrase', $model);
    $this->assertStringContainsString('话术库', $proto);
}

private function assert_BackendConsoleComposer_testPhrasePanelSupportsUnifiedSearchAndCategoryTabs(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService';
    $js = (string) file_get_contents($base . '/view/statics/js/backend-console.js');
    $css = (string) file_get_contents($base . '/view/statics/css/backend-console.css');
    $ctrl = (string) file_get_contents($base . '/Controller/Backend/Console.php');
    $model = (string) file_get_contents($base . '/Model/AgentPhrase.php');
    $catModel = (string) file_get_contents($base . '/Model/AgentPhraseCategory.php');
    $proto = (string) file_get_contents($base . '/view/statics/prototype/console-phrase-search-tabs.html');
    $this->assertStringContainsString('schema_fields_CATEGORY', $model);
    $this->assertStringContainsString('cs_agent_phrase_category', $catModel);
    $this->assertStringContainsString('function postPhraseCategorySave', $ctrl);
    $this->assertStringContainsString('function postPhraseCategoryDelete', $ctrl);
    $this->assertStringContainsString("'category'", $ctrl);
    $this->assertStringContainsString('cs-phrase-search', $js);
    $this->assertStringContainsString('cs-phrase-tabs', $js);
    $this->assertStringContainsString('data-cs-phrase-tab', $js);
    $this->assertStringContainsString('filterPhraseRows', $js);
    $this->assertStringContainsString('cs-phrase-search', $css);
    $this->assertStringContainsString('cs-phrase-tabs', $css);
    $this->assertStringContainsString('variant=A', $proto);
    $this->assertStringContainsString('统一搜索', $proto);
}

private function assert_BackendConsoleSessionAccordion_testSessionGroupsAreExclusiveAccordion(): void
{
    $jsFile = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/js/backend-console.js';
    $tplFile = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/templates/backend/Console/index.phtml';
    $js = (string) file_get_contents($jsFile);
    $tpl = (string) file_get_contents($tplFile);
    $this->assertStringContainsString('initSessionGroupAccordion', $js);
    $this->assertStringContainsString('weline:ui:disclosure:open', $js);
    $this->assertStringContainsString('data-cs-accordion', $js);
    $this->assertStringContainsString('data-cs-accordion="exclusive"', $tpl);
    $this->assertStringContainsString('$openSessionGroup', $tpl);
    $this->assertStringContainsString('$mineGroupOpen', $tpl);
    $this->assertStringContainsString('$transferredGroupOpen', $tpl);
    $this->assertStringContainsString('$waitingGroupOpen', $tpl);
    $initPos = strpos($js, 'function init(options)');
    $accordionCallPos = strpos($js, 'initSessionGroupAccordion();', $initPos !== false ? $initPos : 0);
    $bindPos = strpos($js, 'bindEvents();', $initPos !== false ? $initPos : 0);
    $this->assertNotFalse($initPos);
    $this->assertNotFalse($accordionCallPos);
    $this->assertNotFalse($bindPos);
    $this->assertLessThan($bindPos, $accordionCallPos, '互斥手风琴须在 bindEvents 之前绑定');
}

private function assert_CustomerServiceChatTemplateShell_testChatWindowStaysInChromeBagWithoutHtmlTemplateWrapper(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/templates/frontend/widgets/customer-service-float.phtml');
    // Taglib compile forbids HTML template wrapping of $cs() nodes.
    self::assertStringNotContainsString('<template id="weline-cs-chat-template"', $tpl);
    self::assertStringContainsString('id="cs-chat-button"', $tpl);
    self::assertStringContainsString('id="cs-chat-window"', $tpl);
    self::assertStringContainsString('data-cs-shell="chrome-bag"', $tpl);
    self::assertStringContainsString('theme.storefront_chrome HIT', $tpl);
    // PHP terminates at question-mark greater-than even inside // comments.
    self::assertDoesNotMatchRegularExpression('/\/\/[^\n]*\x3f>/', $tpl, 'line-comment must not contain PHP close sequence');
}

private function assert_CustomerServiceUnreadBreath_testUnreadBadgeTogglesBreathClassAndPresenceDot(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/js/customer-service.js');
    $this->assertStringContainsString('function updateUnreadBadge()', $js);
    $this->assertStringContainsString("getElementById('cs-presence-dot')", $js);
    $this->assertStringContainsString("classList.toggle('has-unread'", $js);
    $this->assertStringContainsString('presenceDot.hidden', $js);
    $this->assertStringContainsString('lastReadMessageId', $js);
    $this->assertStringContainsString('syncUnreadFromServer', $js);
    $this->assertStringContainsString('applyUnreadFromMessages', $js);
    $this->assertStringContainsString('markChatRead', $js);
    $this->assertStringContainsString('markChatReadFromServer', $js);
    $this->assertStringContainsString('getLatestSeenMessageId', $js);
    $this->assertStringContainsString('messageRowId', $js);
    $this->assertStringContainsString('savedSessionId', $js);
    $this->assertStringContainsString('点开看过 = 看完', $js);
    $this->assertStringContainsString('还没有已读水位', $js);
    $this->assertStringContainsString('水位已覆盖当前页最大 id', $js);
    $this->assertStringContainsString('禁止先把 unread=0', $js);
    $this->assertStringContainsString('unreadCount', $js);
    $this->assertStringContainsString('pollIncomingMessages', $js);
    $this->assertStringContainsString('MESSAGE_POLL_INTERVAL_MS = 15000', $js);
    $this->assertStringContainsString('cs-lifecycle-standard-poll-20261010', $js);
    $this->assertStringContainsString('stopStatusPolling', $js);
    $this->assertStringContainsString('getUnreadDebugState', $js);
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/css/customer-service.css');
    $this->assertStringContainsString('weline-social-quick-bar', $css);
    $this->assertStringContainsString('2147483001', $css);
    $this->assertStringContainsString('.customer-service-widget.has-unread', $css);
    $this->assertStringContainsString('.cs-badge.is-visible', $css);
    $this->assertStringContainsString('勿把 transform 放进 transition', $css);
}

private function assert_CustomerServiceUnreadBreath_testStandardLifecycleGatesPolling(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/js/customer-service.js');
    $this->assertStringContainsString('function canPoll()', $js);
    $this->assertStringContainsString('function touchExchange()', $js);
    $this->assertStringContainsString('function markConversationOpen()', $js);
    $this->assertStringContainsString('function engageAfterCustomerSend()', $js);
    $this->assertStringContainsString('function startQuietUnreadWatch()', $js);
    $this->assertStringContainsString('function bindVisibilityLifecycle()', $js);
    $this->assertStringContainsString('MESSAGE_LONG_IDLE_MS = 600000', $js);
    $this->assertStringContainsString('MESSAGE_POLL_BACKOFF_MAX_MS = 60000', $js);
    $this->assertStringContainsString('MESSAGE_HIDDEN_POLL_MS = 120000', $js);
    $this->assertStringContainsString('conversationOpen', $js);
    $this->assertStringContainsString('lastExchangeAt', $js);
    $this->assertStringContainsString('禁止「有 token 就全站密刷」', $js);
    $this->assertStringContainsString('仅未结束会话才 QuietWatch', $js);
    $this->assertStringNotContainsString('startBackgroundUnreadWatch', $js);
    $this->assertStringNotContainsString('收起后仍轮询消息', $js);
    $this->assertStringNotContainsString('}, 3000);', $js);
}

private function assert_CustomerServiceUnreadBreath_testUnreadWidgetStacksAboveSocialQuickBar(): void
{
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/css/customer-service.css');
    $this->assertStringContainsString(':has(.cs-chat-button.has-unread)', $css);
    $this->assertStringContainsString('2147483001', $css);
}

private function assert_CustomerServiceUnreadBreath_testWidgetLoadsOnlyAfterWindowLoadPlusThreeSeconds(): void
{
    $hook = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/templates/frontend/widgets/customer-service-float.phtml');
    $this->assertStringContainsString('CS_POST_LOAD_DELAY_MS = 3000', $hook);
    $this->assertStringContainsString("addEventListener('load', scheduleCustomerServiceWidget", $hook);
    $this->assertStringContainsString('避免与正文抢', $hook);
    $this->assertStringNotContainsString('requestIdleCallback', $hook);
}

private function assert_CustomerServiceWidgetAccessibility_testSettingsLabelsAreProgrammaticallyAssociatedWithSelects(): void
{
    $template = $this->moduleFile_CustomerServiceWidgetAccessibility('view/templates/frontend/widgets/customer-service-float.phtml');
    self::assertStringContainsString('<label for="cs-locale-select">', $template);
    self::assertStringContainsString('<select id="cs-locale-select"', $template);
    self::assertStringContainsString('<label for="cs-display-mode">', $template);
    self::assertStringContainsString('<select id="cs-display-mode"', $template);
}

private function assert_CustomerServiceWidgetAccessibility_testIconOnlySendButtonKeepsALocalizedAccessibleName(): void
{
    $template = $this->moduleFile_CustomerServiceWidgetAccessibility('view/templates/frontend/widgets/customer-service-float.phtml');
    $script = $this->moduleFile_CustomerServiceWidgetAccessibility('view/statics/js/customer-service.js');
    self::assertMatchesRegularExpression('/<button\b(?=[^>]*\bcs-send-button\b)(?=[^>]*\baria-label="@lang\(发送\)")(?=[^>]*\bdata-cs-send-message\b)[^>]*>/s', $template);
    self::assertStringContainsString("sendButton.setAttribute('aria-label', __('发送'));", $script);
}

private function assert_CustomerServiceWidgetAccessibility_testDynamicLocaleSwitchKeepsComponentLanguageAndDirectionInSync(): void
{
    $script = $this->moduleFile_CustomerServiceWidgetAccessibility('view/statics/js/customer-service.js');
    self::assertStringContainsString("const isRtl = ['ar', 'fa', 'he', 'ur'].includes(language);", $script);
    self::assertStringContainsString("widgetRoot.lang = locale.replace(/_/g, '-');", $script);
    self::assertStringContainsString("widgetRoot.dir = isRtl ? 'rtl' : 'ltr';", $script);
    self::assertStringContainsString("updateWidgetLocaleDirection();", $script);
}

private function assert_CustomerServiceWidgetAccessibility_testFloatWidgetKeepsSlimTranslationsAndLoadsDictionariesOnDemand(): void
{
    // P10: dictionaries never SSR into the float widget (open-time Query batch).
    $template = $this->moduleFile_CustomerServiceWidgetAccessibility('view/templates/frontend/widgets/customer-service-float.phtml');
    self::assertStringContainsString('new \stdClass()', $template);
    self::assertStringContainsString('dictionaries load on first chat open', $template);
    self::assertStringNotContainsString('$widgetLocaleCodes = array_column($supportedLocales, \'code\');', $template);
    self::assertStringNotContainsString('getWidgetTranslationsForLocales', $template);
}

private function assert_CustomerServiceWidgetAccessibility_testEveryJavascriptTranslationLiteralIsIncludedInTheWidgetDictionary(): void
{
    $script = $this->moduleFile_CustomerServiceWidgetAccessibility('view/statics/js/customer-service.js');
    $service = $this->moduleFile_CustomerServiceWidgetAccessibility('Service/WidgetTranslationService.php');
    self::assertSame(1, preg_match('/private const WIDGET_KEYS = \[(.*?)\n    \];/s', $service, $widgetKeysBlock));
    self::assertNotFalse(preg_match_all("/__\\(\\s*'([^']+)'\\s*\\)/", $script, $singleQuotedJsKeys));
    self::assertNotFalse(preg_match_all('/__\(\s*"([^"]+)"\s*\)/', $script, $doubleQuotedJsKeys));
    self::assertNotFalse(preg_match_all("/'([^']+)'/", $widgetKeysBlock[1], $serviceKeys));
    $javascriptKeys = array_values(array_unique(array_merge($singleQuotedJsKeys[1], $doubleQuotedJsKeys[1])));
    $missingKeys = array_values(array_diff($javascriptKeys, array_unique($serviceKeys[1])));
    sort($missingKeys);
    self::assertNotEmpty($javascriptKeys);
    self::assertSame([], $missingKeys, 'Missing WidgetTranslationService keys: ' . implode(', ', $missingKeys));
}

private function moduleFile_CustomerServiceWidgetAccessibility(string $relativePath): string
{
    $contents = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/' . $relativePath);
    self::assertIsString($contents, 'CustomerService contract source must be readable: ' . $relativePath);
    return $contents;
}

private function assert_CustomerServiceWidgetUi_testBodyEndHookOmitsWidgetTranslationsDuringStaticErrorPublish(): void
{
    $hookFile = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/templates/frontend/widgets/customer-service-float.phtml';
    $content = (string) file_get_contents($hookFile);
    // P10: dictionaries never SSR into body-end (open-time Query batch instead).
    $this->assertStringContainsString('new \stdClass()', $content);
    $this->assertStringContainsString('dictionaries load on first chat open', $content);
    $this->assertStringNotContainsString('getWidgetTranslationsForLocales', $content);
    $this->assertStringNotContainsString('omitClientDictionaries', $content);
}

private function assert_CustomerServiceWidgetUi_testFloatPrefetchesWidgetI18nLabelsBeforePerStringLabel(): void
{
    $hookFile = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/templates/frontend/widgets/customer-service-float.phtml';
    $content = (string) file_get_contents($hookFile);
    $prefetch = strpos($content, 'WidgetI18n::prefetchLabels');
    $csFn = strpos($content, '$cs = static fn');
    $this->assertNotFalse($prefetch);
    $this->assertNotFalse($csFn);
    $this->assertLessThan($csFn, $prefetch);
    $this->assertStringContainsString("'客服服务'", $content);
}

private function assert_CustomerServiceWidgetUi_testBodyEndKeepsSlimConfigWithoutEmbeddedDictionaries(): void
{
    $hookFile = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/templates/frontend/widgets/customer-service-float.phtml';
    $content = (string) file_get_contents($hookFile);
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/js/customer-service.js');
    $provider = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/extends/module/Weline_Framework/Query/CustomerServiceQueryProvider.php');
    $this->assertStringContainsString('widgetTranslations: <?= json_encode($widgetTranslations', $content);
    $this->assertStringContainsString('ensureWidgetTranslations', $js);
    // One locale at a time — never dump the full matrix with widgetTranslations({}).
    // Worker descriptor only allows locales — do not send singular locale (unknown param reject).
    $this->assertStringContainsString('{locales: locale}', $js);
    $this->assertStringNotContainsString('{locales: locale, locale: locale}', $js);
    $this->assertStringContainsString('hasLoadedWidgetTranslationsFor', $js);
    $this->assertStringContainsString('storefrontLocale', $js);
    // P0: init must not overwrite SSR chrome with Chinese source before the locale bag loads.
    $this->assertMatchesRegularExpression('/function init\([\s\S]*?hasLoadedWidgetTranslationsFor\(state\.locale\)[\s\S]*?ensureWidgetTranslations\(false,\s*state\.locale\)/', $js, 'init must gate updateWidgetLocaleText behind hasLoadedWidgetTranslationsFor / ensureWidgetTranslations');
    // P0 /shop hub: updateWidgetLocaleText itself must refuse to paint without a loaded bag
    // (empty translations[] / initSession race otherwise overwrites SSR EN via Chinese source).
    $this->assertMatchesRegularExpression('/function updateWidgetLocaleText\(\)\s*\{[\s\S]*?hasLoadedWidgetTranslationsFor\(state\.locale\)/', $js, 'updateWidgetLocaleText must hard-gate on hasLoadedWidgetTranslationsFor');
    $this->assertStringContainsString('!Array.isArray(translations)', $js);
    $this->assertStringContainsString("aria-label=\"<?= \$cs('发送') ?>\"", $content);
    $this->assertStringNotContainsString('widgetTranslations({}, {silent: true})', $js);
    $this->assertStringContainsString("'name' => 'widgetTranslations'", $provider);
    $this->assertStringContainsString("'widgetTranslations' => \$this->widgetTranslations(\$params)", $provider);
    $this->assertStringContainsString('Fail-closed empty: storefront loads one locale at a time', $provider);
    $this->assertStringContainsString("\$params['locales'] ?? \$params['locale']", $provider);
    $this->assertStringContainsString('translations\' => new \stdClass()', $provider);
}

private function assert_CustomerServiceWidgetUi_testFrontendWidgetUsesWelineFormControls(): void
{
    $hookFile = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/templates/frontend/widgets/customer-service-float.phtml';
    $this->assertFileExists($hookFile);
    $content = (string) file_get_contents($hookFile);
    $this->assertStringContainsString('class="w-textarea cs-message-input"', $content);
    $this->assertStringContainsString('class="w-select"', $content);
    $this->assertStringContainsString('class="w-input cs-form-input"', $content);
    $this->assertStringContainsString('class="w-button cs-send-button"', $content);
    $this->assertStringContainsString('data-cs-tool="emoji"', $content);
    $this->assertStringContainsString('data-cs-tool="image"', $content);
    $this->assertStringContainsString('data-cs-tool="file"', $content);
    $this->assertStringContainsString('data-cs-tool="screenshot"', $content);
    $this->assertStringContainsString('cs-composer-toolbar', $content);
    $this->assertStringContainsString('class="cs-chat-window w-panel"', $content);
    $this->assertStringContainsString('class="cs-chat-header w-panel-header"', $content);
    $this->assertStringContainsString('class="cs-chat-body w-panel-body"', $content);
    $this->assertStringContainsString('class="cs-chat-footer w-panel-footer"', $content);
    $this->assertStringContainsString('class="cs-modal w-modal"', $content);
    $this->assertStringContainsString('class="w-modal-dialog w-modal-sm"', $content);
    $this->assertStringContainsString('id="cs-bind-form"', $content);
    $this->assertStringContainsString('data-weline-form="1"', $content);
    $this->assertStringContainsString('data-weline-form-intent="customerservice_bind_email"', $content);
    $this->assertStringContainsString('data-weline-form-captcha-slot', $content);
    $this->assertStringContainsString('BindCaptchaGuard', $content);
}

private function assert_CustomerServiceWidgetUi_testBodyEndHookDoesNotSkipOnPreviewOrVisualEditor(): void
{
    $hookFile = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/templates/frontend/widgets/customer-service-float.phtml';
    $content = (string) file_get_contents($hookFile);
    $this->assertStringContainsString('$isAccountRoute', $content);
    $this->assertStringContainsString('preview_storefront_delivery_parity', $content);
    $this->assertStringNotContainsString('$isWorkspacePreview', $content);
    $this->assertStringNotContainsString("getGet('visual_editor'", $content);
    $this->assertStringNotContainsString("getGet('preview'", $content);
    $this->assertDoesNotMatchRegularExpression('/if\s*\(\s*\$isAccountRoute\s*\|\|\s*\$isWorkspacePreview\s*\)/', $content, 'preview must not early-return the customer-service Hook');
    $this->assertMatchesRegularExpression('/\/\/[^\n]*FORBIDDEN[^\n]*workspace-preview[^\n]*early-return/', $content, 'comment must document the forbidden preview skip');
    $this->assertMatchesRegularExpression('/if\s*\(\s*\$isAccountRoute\s*\)\s*\{\s*return;/', $content, 'only account-route early-return remains');
}

private function assert_CustomerServiceWidgetUi_testFrontendStylesUseThemePanelAndResponsiveChat(): void
{
    $cssFile = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/css/customer-service.css';
    $this->assertFileExists($cssFile);
    $content = (string) file_get_contents($cssFile);
    $this->assertStringContainsString('.customer-service-widget--classic', $content);
    $this->assertStringContainsString('--weline-chrome-bg-dark', $content);
    $this->assertStringContainsString('--weline-chrome-primary', $content);
    $this->assertStringContainsString('.cs-chat-header.w-panel-header', $content);
    $this->assertStringContainsString('Unlayered: Theme .w-panel-header', $content);
    $this->assertStringContainsString('.customer-service-widget .cs-chat-header.w-panel-header', $content);
    $this->assertStringContainsString('background: var(--cs-header-bg)', $content);
    $this->assertStringContainsString('color: var(--cs-header-text)', $content);
    $this->assertStringContainsString('.cs-modal.w-modal', $content);
    $this->assertStringContainsString('@media (max-width: 720px)', $content);
    $this->assertStringContainsString('overflow: visible', $content);
    // Bind/modal overlay may stay fixed; mobile chat window must be slot-anchored absolute.
    $this->assertMatchesRegularExpression('/@media\s*\(\s*max-width:\s*720px\s*\)\s*\{[\s\S]*?\.cs-chat-window(?:[\s\S]*?\.cs-chat-window\.w-panel)?\s*\{[^}]*position:\s*absolute/m', $content, 'mobile .cs-chat-window must be position:absolute (in-slot, not viewport-fixed)');
    $this->assertDoesNotMatchRegularExpression('/@media\s*\(\s*max-width:\s*720px\s*\)\s*\{[\s\S]*?\.cs-chat-window[^}]*position:\s*fixed/m', $content, 'mobile media must not set .cs-chat-window to position:fixed');
    $this->assertStringContainsString('.customer-service-widget.is-open .cs-chat-button', $content);
    $this->assertStringContainsString('cs-chat-button-breathe', $content);
    $this->assertStringContainsString('.cs-chat-button.has-unread', $content);
    $this->assertStringContainsString('.cs-presence-dot', $content);
    $this->assertStringContainsString('cs-bind-modal-open', $content);
    $this->assertStringContainsString('#cs-bind-modal.cs-modal', $content);
    $this->assertStringContainsString('calc(var(--weline-z-overlay) + 350)', $content);
    $this->assertStringContainsString('body.cs-bind-modal-open #customer-service-widget .cs-chat-window', $content);
    $this->assertStringContainsString('.cs-notice-alert', $content);
    $this->assertStringContainsString('.cs-notice-alert.is-open', $content);
}

private function assert_CustomerServiceWidgetUi_testFrontendWidgetUsesClassicChromeSurface(): void
{
    $hookFile = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/templates/frontend/widgets/customer-service-float.phtml';
    $this->assertFileExists($hookFile);
    $content = (string) file_get_contents($hookFile);
    $this->assertStringContainsString('customer-service-widget--classic', $content);
}

private function assert_CustomerServiceWidgetUi_testFrontendStylesPinSurfaceBackgroundForMessageInput(): void
{
    $cssFile = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/css/customer-service.css';
    $this->assertFileExists($cssFile);
    $content = (string) file_get_contents($cssFile);
    $this->assertStringContainsString('@layer page', $content);
    $this->assertStringContainsString('background: var(--cs-surface)', $content);
    $this->assertStringContainsString('color: var(--cs-text)', $content);
    $this->assertStringContainsString('.cs-message-input', $content);
    $this->assertStringContainsString('color-scheme: inherit', $content);
    $this->assertStringContainsString('.cs-composer-toolbar', $content);
    $this->assertStringContainsString('.cs-composer-panel', $content);
    $this->assertStringContainsString('.cs-shot-crop', $content);
    $this->assertStringContainsString('.cs-shot-crop__rect', $content);
}

private function assert_CustomerServiceWidgetUi_testFrontendWidgetJsSupportsComposerUploadAndScreenshot(): void
{
    $jsFile = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/js/customer-service.js';
    $this->assertFileExists($jsFile);
    $js = (string) file_get_contents($jsFile);
    $this->assertStringContainsString('bindComposerTools', $js);
    $this->assertStringContainsString('uploadAndSendAttachment', $js);
    $this->assertStringContainsString('captureAndSendScreenshot', $js);
    $this->assertStringContainsString('openLiveRegionPicker', $js);
    $this->assertStringContainsString('captureClientRegionDirect', $js);
    $this->assertStringContainsString('ensureModernScreenshot', $js);
    $this->assertStringContainsString('captureWithModernScreenshot', $js);
    $this->assertStringContainsString('CS_SHOT_CAPTURE_MS', $js);
    $this->assertStringContainsString('withCaptureTimeout', $js);
    $this->assertStringContainsString('region-only', $js);
    $this->assertStringContainsString('restoreChatWidgetAfterShot', $js);
    $this->assertStringContainsString('hideChatWidgetForShotCapture', $js);
    // Theme CSS color() breaks FO libs — primary path is native getDisplayMedia.
    $this->assertStringContainsString('canvas = await captureWithTabDisplayMedia(region);', $js);
    $this->assertStringContainsString('Primary: browser getDisplayMedia', $js);
    $this->assertStringContainsString('NotAllowedError', $js);
    $this->assertStringContainsString('modernScreenshot.domToCanvas', $js);
    $this->assertStringContainsString('html2CanvasScaleForRegion', $js);
    $this->assertStringContainsString('canvasLooksBlank', $js);
    $this->assertStringContainsString('cs-shot-crop--ready', $js);
    $this->assertStringContainsString('已选中区域，可点击「完成」发送', $js);
    $this->assertStringContainsString('生成中…', $js);
    $this->assertStringContainsString('ensureModernScreenshot().catch', $js);
    // Confirm must not wait on full-viewport prefetch + crop (main-thread lag).
    $this->assertStringNotContainsString('ensureSnapshotPrefetch', $js);
    $this->assertStringNotContainsString('captureVisibleViewport', $js);
    $this->assertStringNotContainsString('cropRegionFromViewportSnapshot', $js);
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/css/customer-service.css');
    $this->assertStringContainsString('cs-shot-crop--ready', $css);
    $this->assertStringContainsString('cs-shot-confirm-pulse', $css);
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/templates/frontend/widgets/customer-service-float.phtml');
    $this->assertStringContainsString('modernScreenshotUrl', $tpl);
    $this->assertStringContainsString('js/vendor/modern-screenshot.js', $tpl);
    $this->assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/js/vendor/modern-screenshot.js');
    $this->assertStringContainsString('html2canvasUrl', $tpl);
    $this->assertStringContainsString('js/vendor/html2canvas.min.js', $tpl);
    $this->assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/js/vendor/html2canvas.min.js');
    $this->assertStringContainsString('.upload(', $js);
    $this->assertStringContainsString("session_id: state.sessionId", $js);
    // upload descriptor has no locale — must not send it (Unknown frontend worker param: locale).
    $this->assertMatchesRegularExpression("/\\.upload\\(\\{[\\s\\S]*?data:\\s*dataUrl\\s*\\}/", $js);
    $this->assertStringNotContainsString("data: dataUrl,\n                locale: state.locale\n            }, {silent: true})", $js);
    $provider = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/extends/module/Weline_Framework/Query/CustomerServiceQueryProvider.php');
    $this->assertStringContainsString("'upload' =>", $provider);
    $this->assertStringContainsString("'name' => 'upload'", $provider);
    $uploadBlockStart = strpos($provider, "'name' => 'upload'");
    $this->assertNotFalse($uploadBlockStart);
    $uploadBlock = substr($provider, (int) $uploadBlockStart, 700);
    $this->assertStringContainsString("'session_id'", $uploadBlock);
    $this->assertStringContainsString("'data'", $uploadBlock);
    $this->assertStringNotContainsString("'locale'", $uploadBlock);
    $this->assertStringContainsString('attachment_type', $provider);
    $this->assertStringContainsString('ChatMediaUploader', $provider);
    $uploader = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/Service/ChatMediaUploader.php');
    $this->assertStringContainsString('storeBase64', $uploader);
    $proto = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/statics/prototype/frontend-composer-screenshot.html');
    $this->assertStringContainsString('截图', $proto);
    $this->assertStringContainsString('variant=A', $proto);
    $this->assertStringContainsString('框选', $proto);
    $this->assertStringContainsString('2MB', $proto);
}

private function assert_FooterContactServiceLinkWidget_testFooterContactServiceLinkRegistersHomepageDefaultAndRemainsGloballyAvailable(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/extends/module/Weline_Widget/Weline_CustomerService/widget.php';
    $tpl = 'Weline_CustomerService::templates/frontend/widgets/footer-contact-service-link.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/templates/frontend/widgets/footer-contact-service-link.phtml');
    self::assertStringContainsString('@widget.code {footer-contact-service-link}', $src);
    self::assertStringContainsString('@widget.page_layouts {["*"]}', $src);
    self::assertStringContainsString('@widget.slot {footer-help-links}', $src);
    self::assertStringContainsString('"slot":"footer-help-links"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_FooterContactServiceLinkWidget_testFooterContactServiceLinkOpensFloatingChat(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/templates/frontend/widgets/footer-contact-service-link.phtml');
    self::assertStringContainsString('data-testid="footer-contact-service-link"', $template);
    self::assertStringContainsString('data-cs-footer-open-chat', $template);
    self::assertStringContainsString('__WelineLoadCustomerServiceWidget', $template);
    self::assertStringContainsString('footer-help-links', $template);
    self::assertStringContainsString('联系客服', $template);
}

private function assert_HeaderContactServiceLinkWidget_testHeaderContactServiceLinkRegistersHomepageDefaultInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/extends/module/Weline_Widget/Weline_CustomerService/widget.php';
    $tpl = 'Weline_CustomerService::templates/frontend/widgets/header-contact-service-link.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/templates/frontend/widgets/header-contact-service-link.phtml');
    self::assertStringContainsString('@widget.code {header-contact-service-link}', $src);
    self::assertStringContainsString('"required":true', $src);
    self::assertStringContainsString('@widget.page_layouts {["*"]}', $src);
}

private function assert_HeaderContactServiceLinkWidget_testHeaderContactServiceLinkOpensFloatingChat(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/templates/frontend/widgets/header-contact-service-link.phtml');
    self::assertStringContainsString('data-testid="header-contact-service-link"', $template);
    self::assertStringContainsString('data-cs-header-open-chat', $template);
    self::assertStringContainsString('__WelineLoadCustomerServiceWidget', $template);
    self::assertStringContainsString('@widget.slot {header-nav-extensions}', $template);
    self::assertStringContainsString('客户服务', $template);
}
}
