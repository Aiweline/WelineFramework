<?php
declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 契约：locale 发布必须流式读词典，禁止无界 fetchArray（阈值 10000）。
 */
final class AiTranslationPublisherStreamContractTest extends TestCase
{
    public function testPublishCommittedLocaleStreamsDictionaryRowsInsteadOfUnboundedFetchArray(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/AiTranslationPublisher.php',
        );

        self::assertStringContainsString('fetchIterator', $source);
        self::assertStringContainsString('foreach ($query->fetchIterator() as $row)', $source);
        self::assertStringNotContainsString('->select()->fetchArray()', $source);
        self::assertStringNotContainsString("->select()\n                    ->fetchArray()", $source);
    }
}
