<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class AiTranslationDbOnlyCandidatesContractTest extends TestCase
{
    public function testAiTranslationDoesNotScanOrCollectWords(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/AiTranslationService.php',
        );

        foreach ([
            'function collectCandidateWords',
            'function appendDictionaryWords',
            'function appendModuleCsvWords',
            'function appendBackendMenuWords',
            'function getActiveModuleBasePaths',
            'function readCsvWords',
            'function getCsvTranslatedWordIndex',
            'function getGeneratedTranslatedWordIndex',
            'function hasGeneratedTranslation',
            'function hasCsvTranslation',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }

        self::assertStringContainsString('NOT EXISTS', $source);
        self::assertStringContainsString('PENDING_READ_PAGE_SIZE = 100', $source);
        self::assertStringContainsString('self::PENDING_READ_PAGE_SIZE', $source);
        self::assertStringNotContainsString('SELECT COUNT(*) AS pending_count FROM', $source);
    }
}
