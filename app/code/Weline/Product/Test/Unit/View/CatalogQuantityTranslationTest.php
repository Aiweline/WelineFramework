<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\I18n\Service\TranslationResolver;

final class CatalogQuantityTranslationTest extends TestCase
{
    #[DataProvider('quantityCopy')]
    public function testCatalogQuantityUsesEnglishAndPreservesChinese(string $source, string $english): void
    {
        $resolver = new TranslationResolver(
            dictionaryRepository: $this->createStub(DictionaryRepositoryInterface::class),
        );

        self::assertSame($english, $resolver->translate($source, 'en_US', ['Weline_Product']));
        self::assertSame($source, $resolver->translate($source, 'zh_Hans_CN', ['Weline_Product']));
    }

    public static function quantityCopy(): array
    {
        return [
            'paginated quantity retains all three placeholders' => [
                '第 %{1}–%{2} 件，共 %{3} 件商品',
                '%{1}–%{2} of %{3} products',
            ],
            'empty catalog quantity' => ['0 件商品', '0 products'],
        ];
    }
}
