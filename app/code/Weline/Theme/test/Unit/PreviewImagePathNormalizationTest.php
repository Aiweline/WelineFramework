<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemePreviewGenerator;

final class PreviewImagePathNormalizationTest extends TestCore
{
    public function testNormalizePreviewRelativePathStripsProjectAndPubPrefixes(): void
    {
        $absolutePath = PUB . 'theme_previews' . DIRECTORY_SEPARATOR . 'theme_9_backend.png';

        self::assertSame(
            'theme_previews/theme_9_backend.png',
            ThemePreviewGenerator::normalizePreviewRelativePath($absolutePath)
        );
    }

    public function testThemeModelNormalizesLegacyPubRelativePath(): void
    {
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->setPreviewImage('pub/theme_previews/theme_9_backend.png');
        $theme->setFrontendPreviewImage('\\pub\\theme_previews\\theme_9_frontend.png');
        $theme->setBackendPreviewImage('/pub/theme_previews/theme_9_backend.png');

        self::assertSame('theme_previews/theme_9_backend.png', $theme->getPreviewImage());
        self::assertSame('theme_previews/theme_9_frontend.png', $theme->getFrontendPreviewImage());
        self::assertSame('theme_previews/theme_9_backend.png', $theme->getBackendPreviewImage());
    }
}
