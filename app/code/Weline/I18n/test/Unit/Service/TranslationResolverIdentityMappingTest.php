<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Api\Translation\DictionaryEntry;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\I18n\Service\TranslationResolver;

final class TranslationResolverIdentityMappingTest extends TestCase
{
    public function testExplicitModuleIdentityTranslationsDoNotFallThroughToLegacyDictionaryValues(): void
    {
        // The real Theme CSV deliberately contains Hanfu,Hanfu and Silk,Silk.
        // Only the external dictionary is replaced; module CSV loading stays real.
        $dictionary = $this->createStub(DictionaryRepositoryInterface::class);
        $dictionary->method('getEntry')->willReturnMap([
            ['Hanfu', 'en_US', new DictionaryEntry('Hanfu', 'en_US', '汉服')],
            ['Silk', 'en_US', new DictionaryEntry('Silk', 'en_US', '真丝')],
        ]);
        $resolver = new TranslationResolver(dictionaryRepository: $dictionary);

        self::assertSame('Hanfu', $resolver->translate('Hanfu', 'en_US', ['Weline_Theme']));
        self::assertSame('Silk', $resolver->translate('Silk', 'en_US', ['Weline_Theme']));
    }
}
