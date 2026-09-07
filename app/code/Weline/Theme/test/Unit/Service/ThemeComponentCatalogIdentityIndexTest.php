<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeComponentCatalog;
use Weline\Theme\Service\ThemeDirectoryResolver;
use Weline\Theme\Service\ThemeFileComponentSource;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\VirtualThemeComponentSource;
use Weline\Theme\Service\WidgetRegistryComponentSource;

final class ThemeComponentCatalogIdentityIndexTest extends TestCase
{
    public function testDefinitionsBuildAnIdentityIndexForRepeatedPlaceableLookups(): void
    {
        $theme = $this->createMock(WelineTheme::class);
        $theme->method('getId')->willReturn(7);

        $definition = new ThemeComponentDefinition(
            module: 'Weline_Theme',
            type: 'theme_component',
            code: 'content/card-grid',
            name: 'Card grid',
            isContainer: true,
        );

        $directoryResolver = $this->createMock(ThemeDirectoryResolver::class);
        $directoryResolver->method('getThemeChain')->willReturn([$theme]);

        $widgetSource = $this->createMock(WidgetRegistryComponentSource::class);
        $widgetSource->method('collect')->willReturn([$definition]);

        $catalog = new ThemeComponentCatalog(
            $this->createMock(WelineTheme::class),
            $directoryResolver,
            $this->createMock(ThemeFileComponentSource::class),
            $this->createMock(VirtualThemeComponentSource::class),
            $widgetSource,
            $this->createMock(ThemeContextService::class),
        );

        self::assertSame($definition, $catalog->find('Weline_Theme', 'theme_component', 'content/card-grid', 'frontend', $theme));

        $property = new \ReflectionProperty(ThemeComponentCatalog::class, 'identityIndex');
        $index = $property->getValue($catalog);

        self::assertIsArray($index);
        self::assertSame($definition, $index['7:frontend']['Weline_Theme::theme_component::content/card-grid']);
    }
}
