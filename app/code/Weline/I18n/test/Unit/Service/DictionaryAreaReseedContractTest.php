<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class DictionaryAreaReseedContractTest extends TestCase
{
    public function testReseedServiceFailClosedAndFullCollectHook(): void
    {
        $reseed = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/DictionaryAreaReseedService.php',
        );
        self::assertStringContainsString('passesFailClosedGate', $reseed);
        self::assertStringContainsString('fail_closed_insufficient_path_evidence', $reseed);
        self::assertStringContainsString('demoteAllNonMetaToShared', $reseed);
        self::assertStringContainsString('applyEvidenceAreas', $reseed);

        $observer = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Observer/DictionaryCompileAfterObserver.php',
        );
        self::assertStringContainsString('DictionaryAreaReseedService', $observer);
        self::assertStringContainsString('reseedFromPathEvidence', $observer);
    }

    public function testUpgradeDoesNotMapIsBackendZeroToFrontend(): void
    {
        $upgrade = (string)file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertStringNotContainsString('AREA_FRONTEND', $upgrade);
        self::assertStringNotContainsString('is_backend_v140', $upgrade);
    }

    public function testDesignScanAndCollectDedupe(): void
    {
        $i18n = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/I18n.php');
        self::assertStringContainsString('collectDesignThemeAreaEvidence', $i18n);
        self::assertStringContainsString('app/design', $i18n);

        $collect = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/DictionaryCollectService.php');
        self::assertStringContainsString('pullCompileAfterSummary', $collect);
        self::assertStringNotContainsString('$this->persistCollectedWords($words, \'\', $onProgress)', $collect);
    }

    public function testPublisherEmptyAreaFallsBackToSharedNotLegacyIsBackend(): void
    {
        $publisher = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/AiTranslationPublisher.php',
        );
        self::assertStringContainsString('AREA_SHARED', $publisher);
        self::assertStringNotContainsString('fromLegacyIsBackend', $publisher);
    }
}
