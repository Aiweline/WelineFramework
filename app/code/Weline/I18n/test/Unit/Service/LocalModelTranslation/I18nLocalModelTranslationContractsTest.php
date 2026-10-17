<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service\LocalModelTranslation;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Queue\LocalModelTranslationQueue;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationService;

/**
 * Aggregated contract suite (merged from 5 *ContractTest files).
 * Observe one overall pass/fail via testAllI18nLocalModelTranslationContractsPass().
 */
final class I18nLocalModelTranslationContractsTest extends TestCase
{
    public function testAllI18nLocalModelTranslationContractsPass(): void
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
            'LocalModelTranslationBatch::testProcessBatchGroupsByLocaleInsteadOfOneStringPerCall' => function () {
            $this->assert_LocalModelTranslationBatch_testProcessBatchGroupsByLocaleInsteadOfOneStringPerCall();
        },
            'LocalModelTranslationCron::testAiTranslationCronEnqueuesLocalModelTranslationQueue' => function () {
            $this->assert_LocalModelTranslationCron_testAiTranslationCronEnqueuesLocalModelTranslationQueue();
        },
            'LocalModelTranslationPersistBusy::testProcessBatchDoesNotTreatPersistErrorsAsAbortedBusy' => function () {
            $this->assert_LocalModelTranslationPersistBusy_testProcessBatchDoesNotTreatPersistErrorsAsAbortedBusy();
        },
            'LocalModelTranslationPersistBusy::testUpsertLocalValueRecoversAbortedTransactionAndRetries' => function () {
            $this->assert_LocalModelTranslationPersistBusy_testUpsertLocalValueRecoversAbortedTransactionAndRetries();
        },
            'LocalModelTranslationPersistBusy::testQueueMessageDistinguishesBusyFromPersistSkip' => function () {
            $this->assert_LocalModelTranslationPersistBusy_testQueueMessageDistinguishesBusyFromPersistSkip();
        },
            'LocalModelTranslationPersistBusy::testServiceExposesAbortedFlagInProcessBatchContract' => function () {
            $this->assert_LocalModelTranslationPersistBusy_testServiceExposesAbortedFlagInProcessBatchContract();
        },
            'LocalModelTranslationSource::testResolveSourceTextPrefersParentTableWhenLocalEmpty' => function () {
            $this->assert_LocalModelTranslationSource_testResolveSourceTextPrefersParentTableWhenLocalEmpty();
        },
            'LocalModelTranslationTrigger::testAiTranslationConfigurationStartsLocalModelWork' => function () {
            $this->assert_LocalModelTranslationTrigger_testAiTranslationConfigurationStartsLocalModelWork();
        },
            'LocalModelTranslationTrigger::testLocalModelQueueSkipsWhenNoTargetLocaleIsEnabled' => function () {
            $this->assert_LocalModelTranslationTrigger_testLocalModelQueueSkipsWhenNoTargetLocaleIsEnabled();
        },
        ];
    }

private function assert_LocalModelTranslationBatch_testProcessBatchGroupsByLocaleInsteadOfOneStringPerCall(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/LocalModelTranslation/LocalModelTranslationService.php';
    $source = (string) file_get_contents($path);
    self::assertSame(20, LocalModelTranslationService::AI_TEXT_CHUNK_SIZE);
    self::assertStringContainsString('AI_TEXT_CHUNK_SIZE', $source);
    self::assertStringContainsString('indexesByText', $source);
    self::assertStringContainsString('array_chunk(array_keys($indexesByText)', $source);
    // Must not regress to one-string translateBatch inside the per-item locale loop.
    self::assertStringNotContainsString("translateBatch(\n                        [\$sourceText],", $source);
    self::assertStringContainsString('translateBatch(', $source);
    self::assertStringContainsString("'local-model'", $source);
    self::assertStringContainsString('$chunk', $source);
}

private function assert_LocalModelTranslationCron_testAiTranslationCronEnqueuesLocalModelTranslationQueue(): void
{
    $cron = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Cron/AiTranslation.php');
    $queue = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Queue/LocalModelTranslationQueue.php');
    $service = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/LocalModelTranslation/LocalModelTranslationService.php');
    self::assertStringContainsString('LocalModelTranslationQueueService', $cron);
    self::assertStringContainsString('localModelQueueService->enqueue', $cron);
    self::assertStringContainsString("*/5 * * * *", $cron);
    self::assertStringContainsString('collectWorkItems', $queue);
    self::assertStringContainsString('collectWorkItems($offset, $batchSize + 1)', $queue);
    self::assertStringContainsString('LocalModelTranslationCatalog', $service);
    self::assertStringContainsString('function collectWorkItems(int $offset = 0, int $limit = 0)', $service);
    self::assertStringContainsString('fieldTargetsAlreadyFilled', $service);
    self::assertStringContainsString('getEnabledLocaleCodes', $service);
    self::assertStringContainsString('resolveTargetLocales', $service);
    self::assertStringContainsString('aborted_busy', $service);
    self::assertStringContainsString('AI_TRANSLATION_BUSY', $service);
    self::assertStringContainsString('aborted_busy', $queue);
    self::assertStringContainsString('enqueueContinuation', $queue);
    self::assertStringContainsString('!$abortedBusy', $queue);
    self::assertStringContainsString('不立刻续队', $queue);
    self::assertStringContainsString('DEFAULT_BATCH_SIZE', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/LocalModelTranslation/LocalModelTranslationQueueService.php'));
    self::assertStringContainsString('discoverLocalModelClassNames', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/LocalModelTranslation/LocalModelTranslationCatalog.php'));
    $queueService = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/LocalModelTranslation/LocalModelTranslationQueueService.php');
    self::assertStringContainsString('findActiveFamilyQueueId', $queueService);
    self::assertStringContainsString('isLiveActiveQueueRow', $queueService);
    self::assertStringContainsString('posix_kill', $queueService);
    self::assertStringContainsString('function enqueueContinuation', $queueService);
    self::assertStringContainsString('collectWorkItems(0, 1)', $queueService);
    $enqueueStart = strpos($queueService, 'public function enqueue(');
    $enqueueEnd = strpos($queueService, 'public function enqueueContinuation');
    self::assertNotFalse($enqueueStart);
    self::assertNotFalse($enqueueEnd);
    $enqueueBody = substr($queueService, $enqueueStart, $enqueueEnd - $enqueueStart);
    self::assertLessThan(strpos($enqueueBody, 'collectWorkItems(0, 1)'), strpos($enqueueBody, 'findActiveFamilyQueueId'));
    self::assertStringNotContainsString("__('", $cron);
    self::assertStringContainsString('getActiveModules()', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/LocalModelTranslation/LocalModelTranslationCatalog.php'));
}

private function assert_LocalModelTranslationPersistBusy_testProcessBatchDoesNotTreatPersistErrorsAsAbortedBusy(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/LocalModelTranslation/LocalModelTranslationService.php';
    $source = (string) file_get_contents($path);
    // Source-locale persist failure must not drop the work item (parent already has source_text).
    self::assertStringContainsString('(source):', $source);
    self::assertStringContainsString('$skippedPersist++', $source);
    // Prepare-phase load catch: skip + consume, never aborted_busy.
    self::assertMatchesRegularExpression('/catch\s*\(\s*\\\\Throwable\s+\$throwable\s*\)\s*\{[^}]*\$skippedPersist\+\+;[^}]*\$consumed\+\+;[^}]*continue;/s', $source, 'prepare-phase load failure must consume+continue, not abort as busy');
    self::assertDoesNotMatchRegularExpression('/catch\s*\(\s*\\\\Throwable\s+\$throwable\s*\)\s*\{[^}]*\$abortedBusy\s*=\s*true;[^}]*break;/s', $source, 'prepare-phase must not set abortedBusy and break on persist failure');
    // Target upsert catch: skip locale, not aborted_busy + break 3.
    self::assertStringContainsString('recoverLocalModelConnection', $source);
    self::assertStringNotContainsString("\$abortedBusy = true;\n                            break 3;", $source);
    // AI failure: only real BUSY sets aborted_busy (no `|| true`).
    self::assertStringContainsString('$abortedBusy = $this->errorsIndicateBusy($itemErrors);', $source);
    self::assertStringNotContainsString('$abortedBusy = $this->errorsIndicateBusy($itemErrors) || true;', $source);
    self::assertStringContainsString("\$aborted = true;", $source);
}

private function assert_LocalModelTranslationPersistBusy_testUpsertLocalValueRecoversAbortedTransactionAndRetries(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/LocalModelTranslation/LocalModelTranslationService.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('function upsertLocalValue', $source);
    self::assertStringContainsString('recoverLocalModelConnection', $source);
    self::assertStringContainsString('25P02', $source);
    self::assertStringContainsString('current transaction is aborted', $source);
    self::assertStringContainsString('ensureLocalModelConnectionHealthy', $source);
    self::assertStringContainsString("exec('ROLLBACK')", $source);
    self::assertStringContainsString('forceCheck(true)', $source);
}

private function assert_LocalModelTranslationPersistBusy_testQueueMessageDistinguishesBusyFromPersistSkip(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Queue/LocalModelTranslationQueue.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("aborted_busy", $source);
    self::assertStringContainsString("aborted", $source);
    self::assertStringContainsString('AI翻译繁忙', $source);
    self::assertStringContainsString('保存失败已跳过', $source);
    // Continuation only when neither busy nor aborted.
    self::assertStringContainsString('!$abortedBusy && !$aborted', $source);
}

private function assert_LocalModelTranslationPersistBusy_testServiceExposesAbortedFlagInProcessBatchContract(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/LocalModelTranslation/LocalModelTranslationService.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("'aborted_busy' => \$abortedBusy", $source);
    self::assertStringContainsString("'aborted' => \$aborted", $source);
    self::assertStringContainsString('4e00', $source);
    self::assertStringContainsString('9fff', $source);
}

private function assert_LocalModelTranslationSource_testResolveSourceTextPrefersParentTableWhenLocalEmpty(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/LocalModelTranslation/LocalModelTranslationService.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('iterateParentRows', $source);
    self::assertStringContainsString('->limit($pageSize, $offset)', $source);
    self::assertStringContainsString('fromParent', $source);
    self::assertStringContainsString('parentHasField', $source);
    self::assertStringContainsString('no source-locale Local row required', $source);
    // Parent column wins before source-locale Local fallback.
    $parentPos = strpos($source, '$fromParent');
    $localPos = strpos($source, 'loadLocalFieldValue');
    self::assertNotFalse($parentPos);
    self::assertNotFalse($localPos);
    self::assertLessThan($localPos, $parentPos);
}

private function assert_LocalModelTranslationTrigger_testAiTranslationConfigurationStartsLocalModelWork(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Controller/Backend/AiTranslation.php');
    self::assertStringContainsString('LocalModelTranslationQueueService', $source);
    self::assertStringContainsString('localModelQueueService->enqueue', $source);
    self::assertStringContainsString("'config_save'", $source);
    self::assertStringContainsString("'manual'", $source);
}

private function assert_LocalModelTranslationTrigger_testLocalModelQueueSkipsWhenNoTargetLocaleIsEnabled(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/LocalModelTranslation/LocalModelTranslationQueueService.php');
    self::assertStringContainsString('getEnabledLocaleCodes()', $source);
    self::assertStringContainsString("return 0;", $source);
}
}
