<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutConfigurationSnapshot;
use Weline\Meta\Api\Data\MetaConfigRecord;
use Weline\I18n\Api\Translation\DictionaryEntry;
final class ThemeLayoutConfigurationSnapshotTest extends TestCase
{
    public function testOwnerDefaultsPartialOptionsAndLocaleValuesAreFrozenTogether(): void
    {
        self::assertTrue(class_exists(ThemeLayoutConfigurationSnapshot::class));
        $record=static fn(string $key,string $value):MetaConfigRecord=>new MetaConfigRecord(1,'theme.frontend',$key,$value,'shop.cn.app',null,'19',null,null);
        $snapshot=(new ThemeLayoutConfigurationSnapshot())->fromRecords([
            $record('partials.value','{"header":"compact","notice":"default"}'),
            $record('partials.header.compact.param.title.value','Header title'),
            $record('widgets.Weline_Theme.text-block.param.content.value','Owner default'),
        ],[new DictionaryEntry('@meta::theme.frontend.widgets.Weline_Theme.text-block.param.content.value|scope:shop.cn.app','fr_FR','Bonjour')],'theme.frontend',['shop.cn.app']);
        self::assertSame('compact',$snapshot['partial_options']['header']);
        self::assertSame('default',$snapshot['partial_options']['notice']);
        self::assertSame('Header title',$snapshot['params']['partials.header.compact']['title']);
        self::assertSame('Owner default',$snapshot['params']['widgets.Weline_Theme.text-block']['content']);
        self::assertSame('Bonjour',$snapshot['locale_params']['fr_FR']['widgets.Weline_Theme.text-block']['content']);
    }
}
