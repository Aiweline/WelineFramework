<?php
declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Api\Translation\DictionaryEntry;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\I18n\Service\TranslationResolver;

final class TranslationResolverBatchTest extends TestCase
{
    public function testBatchKeepsCsvPrecedenceAndUsesOneDictionaryRead(): void
    {
        $dictionary = $this->createMock(DictionaryRepositoryInterface::class);
        $dictionary->expects(self::never())->method('getEntry');
        $dictionary->expects(self::once())->method('getEntries')->willReturnCallback(
            static function (array $words, string $locale): array {
                self::assertSame('en_US', $locale);
                self::assertNotContains('Hanfu', $words);
                self::assertContains('批量测试未收录词', $words);
                return ['批量测试未收录词' => new DictionaryEntry('批量测试未收录词', $locale, 'Batch word')];
            },
        );
        $resolver = new TranslationResolver(dictionaryRepository: $dictionary);
        self::assertSame(['Hanfu' => 'Hanfu', '批量测试未收录词' => 'Batch word', 'unknown-batch-key' => 'unknown-batch-key'],
            $resolver->translateMany(['Hanfu', '批量测试未收录词', 'unknown-batch-key', 'Hanfu', ''], 'en_US', ['Weline_Theme']));
    }

    public function testLargeInputChunksDatabaseReadsAndPreservesEverySource(): void
    {
        $sources = array_map(static fn(int $id): string => 'batch-only-' . $id, range(1, 401));
        $dictionary = $this->createMock(DictionaryRepositoryInterface::class);
        $dictionary->expects(self::never())->method('getEntry');
        $dictionary->expects(self::exactly(3))->method('getEntries')->willReturnCallback(
            static function (array $words): array {
                self::assertLessThanOrEqual(200, count($words));
                return [];
            },
        );
        $resolver = new TranslationResolver(dictionaryRepository: $dictionary);
        self::assertSame(array_combine($sources, $sources), $resolver->translateMany($sources, 'en_US'));
    }
}
