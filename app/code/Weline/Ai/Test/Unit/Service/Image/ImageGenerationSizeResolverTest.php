<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Image;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\Image\ImageGenerationSizeResolver;

final class ImageGenerationSizeResolverTest extends TestCase
{
    public function testExplicitSizeWins(): void
    {
        $resolver = new ImageGenerationSizeResolver();

        self::assertSame('1232x928', $resolver->resolve([
            'size' => '1232x928',
            'aspect_ratio' => '2560:900',
        ], 'doubao-seedream-4-5-251128'));
    }

    public function testPanoramicSeedreamUsesHighResWide(): void
    {
        $resolver = new ImageGenerationSizeResolver();

        self::assertSame('1920x1080', $resolver->resolve([
            'aspect_ratio' => '2560:900',
            'target_size' => '2560x900',
        ], 'doubao-seedream-4-5-251128'));
    }

    public function testPanoramicNonSeedreamUsesOpenAiWide(): void
    {
        $resolver = new ImageGenerationSizeResolver();

        self::assertSame('1792x1024', $resolver->resolve([
            'target_aspect_ratio' => '16:9',
        ], 'z-image-turbo'));
    }

    public function testSquareDefault(): void
    {
        $resolver = new ImageGenerationSizeResolver();

        self::assertSame('1024x1024', $resolver->resolve([], 'z-image-turbo'));
    }
}
