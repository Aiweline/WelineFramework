<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Widgets that expose image fields must not treat them as free-form URL strings.
 */
final class WidgetImageFileImageContractTest extends TestCase
{
    public function testVideoPlayerPosterUsesLegacyMediaUrlNotRawSafeHttpOnly(): void
    {
        $file = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/video/video-player/default.phtml';
        self::assertFileExists($file);
        $src = (string)file_get_contents($file);
        self::assertMatchesRegularExpression(
            '/@param\s+poster\s+\{[^}]*type\s*=\s*"media_image"[^}]*\}/u',
            $src,
        );
        self::assertStringContainsString('LegacyMediaUrl::sanitize', $src);
        self::assertDoesNotMatchRegularExpression(
            '/\$poster\s*=\s*VideoEmbedResolver::safeHttpUrl\(\s*\$this->getData\(\s*[\'"]poster[\'"]/u',
            $src,
        );
        self::assertMatchesRegularExpression(
            '/description\s*=\s*"[^"]*媒体库[^"]*"/u',
            $src,
        );
    }

    public function testSidebarSocialWechatQrIsMediaImageNotStringUrl(): void
    {
        $file = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/sidebar/sidebar-social/default.phtml';
        self::assertFileExists($file);
        $src = (string)file_get_contents($file);
        self::assertMatchesRegularExpression(
            '/@param\s+wechat_qr\s+\{[^}]*type\s*=\s*media_image[^}]*\}/u',
            $src,
        );
        self::assertStringContainsString('wechat_qr_file_html', $src);
        self::assertStringNotContainsString('二维码图片URL', $src);
    }

    public function testLogoImageDescriptionsPreferMediaLibraryWording(): void
    {
        $root = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/header';
        foreach (['logo/default.phtml', 'full-header/default.phtml'] as $rel) {
            $file = $root . '/' . $rel;
            self::assertFileExists($file);
            $src = (string)file_get_contents($file);
            self::assertStringContainsString('从媒体库选择', $src);
            self::assertStringNotContainsString('Logo图片URL', $src);
            self::assertMatchesRegularExpression(
                '/@param\s+logo_image\s+\{[^}]*type\s*=\s*"media_image"/u',
                $src,
            );
        }
    }

    public function testArrayImageSchemasPreferMediaImage(): void
    {
        $root = dirname(__DIR__, 3) . '/Ui/ParamSchema';
        $files = [
            'banner_items.php',
            'brand_logo_items.php',
            'testimonial_items.php',
            'sidebar_ad_items.php',
            'site_gallery_items.php',
            'site_card_items.php',
            'site_team_items.php',
            'all_menu_tree.php',
        ];
        foreach ($files as $rel) {
            $file = $root . '/' . $rel;
            self::assertFileExists($file);
            /** @var array<string,mixed> $schema */
            $schema = require $file;
            $item = is_array($schema['item_schema'] ?? null) ? $schema['item_schema'] : [];
            $imageKey = isset($item['image']) ? 'image' : (isset($item['avatar']) ? 'avatar' : '');
            self::assertNotSame('', $imageKey, $rel);
            self::assertSame('media_image', $item[$imageKey]['type'] ?? null, $rel);
        }
    }

    public function testBuilderComponentImageFieldsPreferMediaImage(): void
    {
        $root = dirname(__DIR__, 3) . '/view/theme/frontend/components';
        $cases = [
            'image.phtml' => 'src',
            'card.phtml' => 'backgroundImage',
            'section.phtml' => 'backgroundImage',
        ];
        foreach ($cases as $rel => $field) {
            $file = $root . '/' . $rel;
            self::assertFileExists($file);
            $src = (string)file_get_contents($file);
            self::assertMatchesRegularExpression(
                '/@param\.' . preg_quote($field, '/') . '\s+\{[^}]*type\s*=\s*"media_image"/u',
                $src,
                $rel,
            );
            self::assertDoesNotMatchRegularExpression(
                '/@param\.' . preg_quote($field, '/') . '\s+\{[^}]*type\s*=\s*"image"/u',
                $src,
                $rel,
            );
        }
    }

    public function testTextileHeritageRendersHydratedFileHtml(): void
    {
        $file = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/content/textile-heritage/default.phtml';
        self::assertFileExists($file);
        $src = (string)file_get_contents($file);
        self::assertStringContainsString('image_file_html', $src);
        self::assertStringContainsString('heritage-image--asset', $src);
    }
}
