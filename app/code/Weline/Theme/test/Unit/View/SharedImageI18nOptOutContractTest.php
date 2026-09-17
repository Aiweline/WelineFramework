<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Widget\Api\Param\ParamDefinition;

final class SharedImageI18nOptOutContractTest extends TestCase
{
    public function testLogoAndPosterExplicitlyOptOutOfImageI18n(): void
    {
        $root = dirname(__DIR__, 3) . '/view/theme/frontend/widgets';
        $files = [
            $root . '/header/logo/default.phtml',
            $root . '/header/full-header/default.phtml',
            $root . '/video/video-player/default.phtml',
        ];
        foreach ($files as $file) {
            self::assertFileExists($file);
            $src = (string)file_get_contents($file);
            self::assertMatchesRegularExpression(
                '/@param\s+(?:logo_image|poster)\s+\{[^}]*i18n\s*=\s*false/u',
                $src,
                $file . ' must opt out shared image i18n'
            );
        }

        self::assertFalse(ParamDefinition::isTranslatable([
            'type' => 'image',
            'i18n' => false,
        ]));
        self::assertTrue(ParamDefinition::isTranslatable([
            'type' => 'media_image',
        ]));
    }
}
