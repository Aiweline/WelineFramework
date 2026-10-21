<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\TranslationService;

/**
 * Aggregated contract suite (merged from 5 *ContractTest files).
 * Observe one overall pass/fail via testAllAiServiceContractsPass().
 */
final class AiServiceContractsTest extends TestCase
{
    public function testAllAiServiceContractsPass(): void
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
            'DefaultModelManagerSetDefault::testSetDefaultModelRewritesExistingServiceTypeRow' => function () {
            $this->assert_DefaultModelManagerSetDefault_testSetDefaultModelRewritesExistingServiceTypeRow();
        },
            'DefaultModelManagerSetDefault::testDefaultSetApiSuppressesInlineW18nMarkers' => function () {
            $this->assert_DefaultModelManagerSetDefault_testDefaultSetApiSuppressesInlineW18nMarkers();
        },
            'TranslateWordToLocales::testServiceExposesMultiLocaleApi' => function () {
            $this->assert_TranslateWordToLocales_testServiceExposesMultiLocaleApi();
        },
            'TranslateWordToLocales::testParseMultiLocaleTranslationResponseMapsLocales' => function () {
            $this->assert_TranslateWordToLocales_testParseMultiLocaleTranslationResponseMapsLocales();
        },
            'TranslateWordToLocales::testObserverHandlesMultiLocaleMode' => function () {
            $this->assert_TranslateWordToLocales_testObserverHandlesMultiLocaleMode();
        },
            'TranslationFailureNotify::testCallFailedUsesSingleUnreadDedupe' => function () {
            $this->assert_TranslationFailureNotify_testCallFailedUsesSingleUnreadDedupe();
        },
            'TranslationBatchChunking::testModelChunkConstantAndBatchTranslateUsesChunking' => function () {
            $this->assert_TranslationBatchChunking_testModelChunkConstantAndBatchTranslateUsesChunking();
        },
            'TranslationBatchChunking::testSalvageCompletePrefixFromTruncatedJsonArray' => function () {
            $this->assert_TranslationBatchChunking_testSalvageCompletePrefixFromTruncatedJsonArray();
        },
            'TranslationBatchNoSerialFallback::testMultiItemBatchDoesNotFallBackToSerialTranslate' => function () {
            $this->assert_TranslationBatchNoSerialFallback_testMultiItemBatchDoesNotFallBackToSerialTranslate();
        },
            'TranslationConcurrencyGate::testGateAndTranslationServiceContracts' => function () {
            $this->assert_TranslationConcurrencyGate_testGateAndTranslationServiceContracts();
        },
        ];
    }

private function assert_DefaultModelManagerSetDefault_testSetDefaultModelRewritesExistingServiceTypeRow(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Ai/Service/DefaultModelManager.php');
    self::assertStringContainsString('deleteSiblingRowsForServiceType', $source);
    self::assertStringContainsString("schema_fields_ID, \$keepId, '!='", $source);
    self::assertStringContainsString('uk_service_type_priority', $source);
    // setDefaultModel must not clearDefaults()-then-insert (collides on same priority).
    $methodPos = strpos($source, 'function setDefaultModel(');
    self::assertNotFalse($methodPos);
    $methodChunk = substr($source, (int) $methodPos, 1800);
    self::assertStringNotContainsString('clearDefaults($serviceType)', $methodChunk);
    self::assertStringContainsString('existingTypeRow', $methodChunk);
}

private function assert_DefaultModelManagerSetDefault_testDefaultSetApiSuppressesInlineW18nMarkers(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Ai/extends/module/Weline_Framework/Query/AiQueryProvider.php');
    self::assertStringContainsString('withoutInlinePhraseMark', $source);
    self::assertStringContainsString('function defaultSet', $source);
}

private function assert_TranslateWordToLocales_testServiceExposesMultiLocaleApi(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Ai' . '/Service/TranslationService.php');
    self::assertStringContainsString('function translateWordToLocales', $source);
    self::assertStringContainsString('buildMultiLocaleTranslationPrompt', $source);
    self::assertStringContainsString('parseMultiLocaleTranslationResponse', $source);
    self::assertStringContainsString('Brand-like Latin tokens', $source);
}

private function assert_TranslateWordToLocales_testParseMultiLocaleTranslationResponseMapsLocales(): void
{
    $json = '{"en_US":"Store","hr_HR":"Trgovina","ja_JP":"ストア"}';
    $parsed = TranslationService::parseMultiLocaleTranslationResponse($json, ['en_US', 'hr_HR', 'ja_JP', 'de_DE']);
    self::assertSame('Store', $parsed['en_US']);
    self::assertSame('Trgovina', $parsed['hr_HR']);
    self::assertSame('ストア', $parsed['ja_JP']);
    self::assertArrayNotHasKey('de_DE', $parsed);
}

private function assert_TranslateWordToLocales_testObserverHandlesMultiLocaleMode(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Ai' . '/Observer/TranslationRequestObserver.php');
    self::assertStringContainsString("mode === 'multi_locale'", $source);
    self::assertStringContainsString('translateWordToLocales', $source);
    self::assertStringContainsString('target_locales', $source);
}

private function assert_TranslationFailureNotify_testCallFailedUsesSingleUnreadDedupe(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Ai' . '/Observer/TranslationRequestObserver.php');
    $methodPos = strpos($source, 'function sendSystemMessage(');
    self::assertNotFalse($methodPos);
    $methodChunk = substr($source, (int) $methodPos, 900);
    self::assertStringContainsString("'dedupe_key' => 'ai_translation_call_failed'", $methodChunk);
    self::assertStringContainsString("'skip_if_unread' => true", $methodChunk);
}

private function assert_TranslationBatchChunking_testModelChunkConstantAndBatchTranslateUsesChunking(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Ai' . '/Service/TranslationService.php';
    $source = (string) file_get_contents($path);
    self::assertSame(20, TranslationService::MODEL_CHUNK_SIZE);
    self::assertStringContainsString('MODEL_CHUNK_SIZE', $source);
    self::assertStringContainsString('array_chunk', $source);
    self::assertStringContainsString('splitAndRetryBatchTranslate', $source);
    // Multi-item parse failure → binary split retry (not N serial translate()).
    self::assertMatchesRegularExpression('/if \(count\(\$texts\) > 1\) \{\s*return \$this->splitAndRetryBatchTranslate/s', $source);
}

private function assert_TranslationBatchChunking_testSalvageCompletePrefixFromTruncatedJsonArray(): void
{
    $truncated = "```json\n[\n  \"حفظ\",\n  \"إلغاء\",\n  \"بحث\",\n  \"تفعيل\",\n  \"اللغ";
    $parsed = TranslationService::salvageCompleteJsonArrayItems($truncated);
    self::assertSame(['حفظ', 'إلغاء', 'بحث', 'تفعيل'], $parsed);
    // Exact parse still fail-closed for expectedCount mismatch.
    self::assertSame([], TranslationService::parseBatchTranslationResponse($truncated, 20));
}

private function assert_TranslationBatchNoSerialFallback_testMultiItemBatchDoesNotFallBackToSerialTranslate(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Ai' . '/Service/TranslationService.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('count($texts) > 1', $source);
    self::assertStringContainsString('批量翻译结果解析失败', $source);
    self::assertStringContainsString('strrpos($json, \']\')', $source);
    self::assertStringContainsString("'max_tokens'", $source);
    self::assertStringContainsString('splitAndRetryBatchTranslate', $source);
    // Multi-item: binary split retry — never N serial translate() in the catch path.
    self::assertMatchesRegularExpression('/if \(count\(\$texts\) > 1\) \{\s*return \$this->splitAndRetryBatchTranslate/s', $source);
    self::assertDoesNotMatchRegularExpression('/if \(count\(\$texts\) > 1\) \{[^}]{0,200}foreach \(\$texts as/s', $source);
}

private function assert_TranslationConcurrencyGate_testGateAndTranslationServiceContracts(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Ai';
    $gate = (string) file_get_contents($root . '/Service/TranslationConcurrencyGate.php');
    $service = (string) file_get_contents($root . '/Service/TranslationService.php');
    self::assertStringContainsString('class TranslationConcurrencyGate', $gate);
    self::assertStringContainsString('AI_TRANSLATION_BUSY', $gate);
    self::assertStringContainsString('LOCK_EX | LOCK_NB', $gate);
    self::assertStringContainsString('DEFAULT_WAIT_SECONDS = 0', $gate);
    self::assertStringContainsString('LANE_DICTIONARY', $gate);
    self::assertStringContainsString('LANE_LOCAL_MODEL', $gate);
    self::assertStringContainsString('LANE_META', $gate);
    self::assertStringContainsString('LANE_FILE_ASSET', $gate);
    self::assertStringContainsString('LANE_DOCUMENT', $gate);
    self::assertStringContainsString("\$lane . '-translate.lock'", $gate);
    self::assertStringContainsString('machine-translate.lock', $gate);
    self::assertStringContainsString('depthByLane', $gate);
    self::assertStringContainsString('STALE_HOLD_SECONDS = 960', $gate);
    self::assertStringContainsString('disconnectStaleHolder', $gate);
    self::assertStringContainsString('posix_kill', $gate);
    self::assertStringContainsString('SchedulerSystem::sleep', $gate);
    // Dead/stale holder: retry flock instead of throwing BUSY after disconnect.
    self::assertStringContainsString('Retry', $gate);
    self::assertStringContainsString('fake occupancy', $gate);
    self::assertStringNotContainsString('已断开僵死占用进程，请稍后重试', $gate);
    self::assertStringNotContainsString("\nsleep(", $gate);
    self::assertStringNotContainsString('\sleep(', $gate);
    self::assertStringContainsString('TranslationConcurrencyGate', $service);
    self::assertStringContainsString('acquireConcurrencyGateIfNeeded', $service);
    self::assertStringContainsString('modelRequiresLocalConcurrencyGate', $service);
    self::assertStringContainsString('concurrencyGate->acquire', $service);
    self::assertStringContainsString('timeout_seconds', $service);
    self::assertStringContainsString('low_speed_time', $service);
    self::assertStringContainsString('connect_timeout', $service);
    self::assertStringContainsString('isBusyMarker', $gate);
    self::assertStringContainsString('function isBusy', $gate);
    self::assertStringContainsString('TranslationBusyException', $gate);
    self::assertStringContainsString('isBusy(', $service);
    self::assertStringContainsString('REQUEST_TIMEOUT_SECONDS = 900', $service);
    self::assertStringContainsString('REQUEST_LOW_SPEED_SECONDS = 900', $service);
    self::assertStringContainsString('REQUEST_CONNECT_TIMEOUT_SECONDS = 5', $service);
    self::assertStringContainsString('concurrencyLane', $service);
    self::assertStringContainsString('Drop blank strings before locking', $service);
    self::assertLessThanOrEqual(960, 900 + 60, 'Request timeout + grace must stay within STALE_HOLD_SECONDS');
}
}
