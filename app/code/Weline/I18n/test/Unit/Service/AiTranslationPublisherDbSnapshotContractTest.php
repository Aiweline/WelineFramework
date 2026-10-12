<?php
declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 契约：locale 袋为系统词典纯快照，禁止 merge 旧 generated 文件（防孤儿词撑大）。
 */
final class AiTranslationPublisherDbSnapshotContractTest extends TestCase
{
    public function testPublishCommittedLocaleBuildsBagFromDbOnly(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/AiTranslationPublisher.php',
        );

        self::assertStringContainsString('loadBaseWordAreas', $source);
        self::assertStringContainsString('fetchIterator', $source);
        self::assertStringNotContainsString(
            '$words = $this->readExistingWords($localeCode);',
            $source,
        );
        self::assertStringContainsString('empty DB snapshot', $source);
    }
}
