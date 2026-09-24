<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\I18n\Api\Translation\DictionaryEntry;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\I18n\Api\Translation\TranslationResolverInterface;
use Weline\I18n\Service\TranslationResolver;
use Weline\Theme\Helper\WidgetI18n;

final class WidgetI18nBatchTest extends TestCase
{
    private ?object $originalResolver;

    protected function setUp(): void
    {
        Context::enter(new Context());
        RequestContext::init();
        RequestContext::setWelineUserLang('en_US');
        $this->originalResolver = ObjectManager::getInstances()[TranslationResolverInterface::class] ?? null;
    }

    protected function tearDown(): void
    {
        ObjectManager::removeInstance(TranslationResolverInterface::class);
        if ($this->originalResolver !== null) {
            ObjectManager::setInstance(TranslationResolverInterface::class, $this->originalResolver);
        }
        RequestContext::cleanup();
        Context::leave();
    }

    public function testPrefetchKeepsAliasMissesAndRepeatedLabelsOutOfSingleQueries(): void
    {
        $dictionary = $this->createMock(DictionaryRepositoryInterface::class);
        $dictionary->expects(self::never())->method('getEntry');
        $dictionary->expects(self::once())->method('getEntries')->willReturnCallback(
            static function (array $words, string $locale): array {
                self::assertContains('batch-label', $words);
                self::assertContains('Batch-label', $words);
                return [
                    'Batch-label' => new DictionaryEntry('Batch-label', $locale, 'Batch translated'),
                    'batch-format %{1}' => new DictionaryEntry('batch-format %{1}', $locale, 'Value %{1}'),
                ];
            },
        );
        ObjectManager::setInstance(TranslationResolverInterface::class, new TranslationResolver(dictionaryRepository: $dictionary));
        \Weline\Theme\Helper\HeaderCommerceData::prefetchSearchTypeLabels([
            ['code' => 'batch-label', 'children' => [
                ['label' => 'batch-missing', 'children' => [['label' => 'batch-format %{1}']]],
                ['label' => 'batch-label'],
            ]],
        ]);
        self::assertSame('Batch translated', WidgetI18n::label('batch-label'));
        self::assertSame('batch-missing', WidgetI18n::label('batch-missing'));
        self::assertSame('Value one', WidgetI18n::label('batch-format %{1}', '', ['one']));
        self::assertSame('Value two', WidgetI18n::label('batch-format %{1}', '', ['two']));
        WidgetI18n::prefetchLabels(['batch-label', 'batch-missing']);
    }
}
