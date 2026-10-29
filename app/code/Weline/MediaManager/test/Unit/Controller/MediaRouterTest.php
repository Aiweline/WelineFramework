<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\MediaManager\Controller\Router;

/**
 * /media/image 路由解析：前缀大小写不敏感，但文件名必须保留原始大小写。
 */
final class MediaRouterTest extends TestCase
{
    protected function setUp(): void
    {
        WelineEnv::setServer('WELINE_IS_MEDIA', true, 'MediaRouterTest');
    }

    public function testMixedCaseFilenameIsPreserved(): void
    {
        $path = '/media/image/websites/playinginchina/catalog/XRYDLLbeKI.webp';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('/media/image/index', $path);
        self::assertSame('websites/playinginchina/catalog/XRYDLLbeKI.webp', $rule['file'] ?? null);
    }

    public function testMediaFileRoutePreservesCase(): void
    {
        $path = '/media/file/websites/playinginchina/Catalog/Brochure-EN.pdf';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('/media/file/index', $path);
        self::assertSame('websites/playinginchina/Catalog/Brochure-EN.pdf', $rule['file'] ?? null);
    }
}
