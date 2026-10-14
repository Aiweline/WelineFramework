<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 源码契约：词典编辑弹窗 AI 一键翻译后端落点。
 */
final class DictionaryEntryAiTranslateContractTest extends TestCase
{
    public function testServiceExposesPreviewStreamAndSaveMethods(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/DictionaryEntryAiTranslateService.php',
        );

        self::assertStringContainsString('function previewCurrentLocale', $source);
        self::assertStringContainsString('function streamCurrentLocale', $source);
        self::assertStringContainsString('function streamOtherLocales', $source);
        self::assertStringContainsString('function saveOtherLocales', $source);
        self::assertStringContainsString('function resolveOtherLocales', $source);
        self::assertStringContainsString('chunkLocalesForMultiTranslate', $source);
        self::assertStringContainsString('translateWordToLocales', $source);
        self::assertStringContainsString('正在批量翻译', $source);
        self::assertStringContainsString('I18nAiTranslationAdapter', $source);
        self::assertStringContainsString('getDictionaryPublishLocaleCodes', $source);
        self::assertStringContainsString('AiTranslationPublisher', $source);
        self::assertStringContainsString('publishLocale', $source);
        self::assertStringContainsString('SseWriter', $source);
        self::assertStringContainsString('sendEvent(\'complete\'', $source);
        self::assertStringContainsString('sendEvent(\'failed\'', $source);
        self::assertStringContainsString('确认后', $source);
        self::assertStringContainsString('isUntranslatedEcho', $source);
        self::assertStringContainsString('未能译出有效译文', $source);
        self::assertStringContainsString('resolveOtherLocalesFailureMessage', $source);
        self::assertStringContainsString('humanizeProviderTranslateError', $source);
        self::assertStringContainsString('Insufficient Balance', $source);
        self::assertStringNotContainsString('Weline\\Ai\\', $source);
        self::assertStringNotContainsString('LocalModelTranslationService', $source);
        self::assertStringNotContainsString('ollama', strtolower($source));
    }

    public function testDictionaryControllerExposesSseAndSaveEndpoints(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/Dictionary.php',
        );

        self::assertStringContainsString('DictionaryEntryAiTranslateService', $source);
        self::assertStringContainsString('function postEntryAiTranslateStream', $source);
        self::assertStringContainsString('function getEntryAiTranslateStream', $source);
        self::assertStringContainsString('function postEntryAiTranslateOthersStream', $source);
        self::assertStringContainsString('function getEntryAiTranslateOthersStream', $source);
        self::assertStringContainsString('function postEntryAiSaveOthers', $source);
        self::assertStringContainsString('SseWriter', $source);
        self::assertStringContainsString('streamCurrentLocale', $source);
        self::assertStringContainsString('streamOtherLocales', $source);
        self::assertStringContainsString('saveOtherLocales', $source);
        self::assertStringNotContainsString('use Weline\\Ai\\', $source);
    }

    public function testAssertEntryAiSseCsrfFailsWithErrorOnlyNoCompleteDone(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/Dictionary.php',
        );

        self::assertMatchesRegularExpression(
            '/private function assertEntryAiSseCsrf\(SseWriter \$sse\): bool\s*\{(?P<body>.*?)\n    \}/s',
            $source,
            'assertEntryAiSseCsrf method must exist',
        );
        \preg_match(
            '/private function assertEntryAiSseCsrf\(SseWriter \$sse\): bool\s*\{(?P<body>.*?)\n    \}/s',
            $source,
            $m,
        );
        $body = (string)($m['body'] ?? '');
        self::assertStringContainsString('sendError', $body);
        self::assertStringContainsString('close()', $body);
        self::assertStringNotContainsString('complete(', $body);
        self::assertStringNotContainsString("'success' => false", $body);
    }

    public function testDictionaryPhtmlRejectsSuccessFalseDoneAsFalseGreen(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/backend/Dictionary.phtml',
        );

        self::assertStringContainsString('function startDictionaryAiSse', $source);
        self::assertStringContainsString('data.success === false', $source);
        self::assertStringContainsString('if (settled || failed)', $source);
        self::assertStringContainsString('handlers.onFailed', $source);
        self::assertStringContainsString('dictionary-edit-sse-csrf', $source);
        self::assertStringContainsString('getDictionaryEditAiCsrf', $source);
        self::assertStringContainsString('#dictionary-edit-modal input[name="csrf"]', $source);
        // onDone 须校验 translation 非空，禁止空译文仍显示「AI 翻译完成」
        self::assertStringContainsString("if (translation === '')", $source);
        self::assertStringContainsString('aiTranslateDone', $source);
        self::assertStringContainsString('aiTranslateFailed', $source);
        // success===false 路径必须走失败文案，不得仅依赖 onDone 成功 toast
        self::assertMatchesRegularExpression(
            '/data\.success === false[\s\S]{0,220}aiTranslateFailed/',
            $source,
        );
        // 其他语种：空草稿 / 全文回声原文不得弹确认保存
        self::assertStringContainsString('aiOthersEchoFailed', $source);
        self::assertStringContainsString('aiOthersAllSkipped', $source);
        self::assertStringContainsString('echoCount === draftCodes.length', $source);
        self::assertStringContainsString('!draftCodes.length', $source);
        // 回声失败禁止复用成功文案「草稿已就绪」
        self::assertMatchesRegularExpression(
            '/echoCount === draftCodes\.length[\s\S]{0,280}aiOthersEchoFailed/',
            $source,
        );
        self::assertStringContainsString('其他语言均已有译文，已跳过', $service = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/DictionaryEntryAiTranslateService.php',
        ));
        unset($service);
    }

    public function testDictionaryPhtmlAiSseUrlsAreSameOriginPath(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/backend/Dictionary.phtml',
        );

        // 禁止 AI SSE/save 再用绝对 @backend-url（易剥 :9555 / 改 scheme 丢 Cookie）
        self::assertStringNotContainsString(
            "@backend-url{'*/backend/dictionary/entry-ai-translate-stream'}",
            $source,
        );
        self::assertStringNotContainsString(
            "@backend-url{'*/backend/dictionary/entry-ai-translate-others-stream'}",
            $source,
        );
        self::assertStringNotContainsString(
            "@backend-url{'*/backend/dictionary/entry-ai-save-others'}",
            $source,
        );
        self::assertStringContainsString(
            "getBackendUrlPath('*/backend/dictionary/entry-ai-translate-stream')",
            $source,
        );
        self::assertStringContainsString(
            "getBackendUrlPath('*/backend/dictionary/entry-ai-translate-others-stream')",
            $source,
        );
        self::assertStringContainsString(
            "getBackendUrlPath('*/backend/dictionary/entry-ai-save-others')",
            $source,
        );
        self::assertStringContainsString('function resolveDictionarySameOriginUrl', $source);
        self::assertStringContainsString(
            "xhr.open('POST', resolveDictionarySameOriginUrl(url), true)",
            $source,
        );
        self::assertStringContainsString(
            'fetch(resolveDictionarySameOriginUrl(entryAiSaveOthersUrl)',
            $source,
        );
    }

    public function testAdminQueryProviderMapsEntryAiActionsAndAcl(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/I18nAdminQueryProvider.php',
        );

        self::assertStringContainsString("'dictionary-entry-ai-translate-stream'", $source);
        self::assertStringContainsString("'dictionary-entry-ai-translate-others-stream'", $source);
        self::assertStringContainsString("'dictionary-entry-ai-save-others'", $source);
        self::assertStringContainsString("'dictionary-entry-ai-translate-stream' => 'Weline_I18n::i18n_ai_translation'", $source);
        self::assertStringContainsString("'dictionary-entry-ai-translate-others-stream' => 'Weline_I18n::i18n_ai_translation'", $source);
        self::assertStringContainsString("'dictionary-entry-ai-save-others' => 'Weline_I18n::i18n_ai_translation'", $source);
        self::assertStringContainsString("'postEntryAiTranslateStream'", $source);
        self::assertStringContainsString("'postEntryAiTranslateOthersStream'", $source);
        self::assertStringContainsString("'postEntryAiSaveOthers'", $source);
        self::assertStringContainsString("'dictionary-edit' => 'Weline_I18n::i18n_dictionaries'", $source);
    }
}
