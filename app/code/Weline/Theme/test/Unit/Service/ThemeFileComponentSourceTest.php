<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeFileComponentSource;
use Weline\Theme\Service\ThemeResourceCatalog;

final class ThemeFileComponentSourceTest extends TestCase
{
    public function testCollectBuildsLegacyWidgetsWithRealWidgetIdentity(): void
    {
        $catalog = $this->createMock(ThemeResourceCatalog::class);
        $catalog->method('getRawResources')
            ->willReturnCallback(static function (string $type): array {
                if ($type === 'components') {
                    return [];
                }

                return [[
                    'type' => 'widgets',
                    'category' => 'header',
                    'code' => 'logo',
                    'meta' => ['name' => 'Logo'],
                    'params' => [],
                    'slots' => [],
                    'widget_meta' => [
                        'code' => 'logo',
                        'name' => 'Logo',
                        'description' => 'Header Logo',
                        'type' => 'header',
                        'position' => ['header'],
                        'slot' => 'logo',
                        'page_layouts' => ['*'],
                        'exclusive' => true,
                        'compatible' => true,
                        'is_container' => false,
                    ],
                    'relative_path' => 'header/logo/default.phtml',
                    'layer_type' => 'default',
                    'theme_name' => 'default',
                    'file_path' => 'app/code/Weline/Theme/view/theme/frontend/widgets/header/logo/default.phtml',
                ]];
            });

        $source = new ThemeFileComponentSource($catalog);
        $definitions = $source->collect('frontend');

        self::assertCount(1, $definitions);
        $definition = $definitions[0];
        self::assertSame('header', $definition->type);
        self::assertSame('logo', $definition->code);
        self::assertSame(['header'], $definition->position);
        self::assertSame('logo', $definition->slot);
        self::assertTrue($definition->exclusive);
        self::assertSame(
            'Weline_Theme::theme/frontend/widgets/header/logo/default.phtml',
            $definition->templatePath
        );
    }
}
