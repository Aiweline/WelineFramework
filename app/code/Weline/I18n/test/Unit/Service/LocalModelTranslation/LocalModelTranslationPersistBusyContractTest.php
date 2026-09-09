<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service\LocalModelTranslation;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Queue\LocalModelTranslationQueue;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationService;

/**
 * Persist/SQL failures must not be classified as AI_TRANSLATION_BUSY, or cron
 * will empty-spin forever on the same poison LocalModel row (prod: EAV 25P02).
 */
final class LocalModelTranslationPersistBusyContractTest extends TestCase
{
    public function testProcessBatchDoesNotTreatPersistErrorsAsAbortedBusy(): void
    {
        $path = dirname(__DIR__, 4) . '/Service/LocalModelTranslation/LocalModelTranslationService.php';
        $source = (string)file_get_contents($path);

        // Source-locale persist failure must not drop the work item (parent already has source_text).
        self::assertStringContainsString('(source):', $source);
        self::assertStringContainsString('$skippedPersist++', $source);

        // Prepare-phase load catch: skip + consume, never aborted_busy.
        self::assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\Throwable\s+\$throwable\s*\)\s*\{[^}]*\$skippedPersist\+\+;[^}]*\$consumed\+\+;[^}]*continue;/s',
            $source,
            'prepare-phase load failure must consume+continue, not abort as busy',
        );
        self::assertDoesNotMatchRegularExpression(
            '/catch\s*\(\s*\\\\Throwable\s+\$throwable\s*\)\s*\{[^}]*\$abortedBusy\s*=\s*true;[^}]*break;/s',
            $source,
            'prepare-phase must not set abortedBusy and break on persist failure',
        );

        // Target upsert catch: skip locale, not aborted_busy + break 3.
        self::assertStringContainsString('recoverLocalModelConnection', $source);
        self::assertStringNotContainsString("\$abortedBusy = true;\n                            break 3;", $source);

        // AI failure: only real BUSY sets aborted_busy (no `|| true`).
        self::assertStringContainsString(
            '$abortedBusy = $this->errorsIndicateBusy($itemErrors);',
            $source,
        );
        self::assertStringNotContainsString(
            '$abortedBusy = $this->errorsIndicateBusy($itemErrors) || true;',
            $source,
        );
        self::assertStringContainsString("\$aborted = true;", $source);
    }

    public function testUpsertLocalValueRecoversAbortedTransactionAndRetries(): void
    {
        $path = dirname(__DIR__, 4) . '/Service/LocalModelTranslation/LocalModelTranslationService.php';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('function upsertLocalValue', $source);
        self::assertStringContainsString('recoverLocalModelConnection', $source);
        self::assertStringContainsString('25P02', $source);
        self::assertStringContainsString('current transaction is aborted', $source);
        self::assertStringContainsString('ensureLocalModelConnectionHealthy', $source);
        self::assertStringContainsString("exec('ROLLBACK')", $source);
        self::assertStringContainsString('forceCheck(true)', $source);
    }

    public function testQueueMessageDistinguishesBusyFromPersistSkip(): void
    {
        $path = dirname(__DIR__, 4) . '/Queue/LocalModelTranslationQueue.php';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString("aborted_busy", $source);
        self::assertStringContainsString("aborted", $source);
        self::assertStringContainsString('AI翻译繁忙', $source);
        self::assertStringContainsString('保存失败已跳过', $source);
        // Continuation only when neither busy nor aborted.
        self::assertStringContainsString('!$abortedBusy && !$aborted', $source);
    }

    public function testServiceExposesAbortedFlagInProcessBatchContract(): void
    {
        $path = dirname(__DIR__, 4) . '/Service/LocalModelTranslation/LocalModelTranslationService.php';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString("'aborted_busy' => \$abortedBusy", $source);
        self::assertStringContainsString("'aborted' => \$aborted", $source);
        self::assertStringContainsString('4e00', $source);
        self::assertStringContainsString('9fff', $source);
    }
}
