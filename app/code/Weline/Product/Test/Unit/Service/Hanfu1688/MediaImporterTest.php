<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service\Hanfu1688;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Product\Service\Hanfu1688\MediaImporter;
use Weline\Product\Service\Hanfu1688\PublicHttpClient;
use Weline\Storage\Api\Data\StorageDiskCode;

final class MediaImporterTest extends TestCase
{
    public function testDownloadsValidatesAndRegistersLocalProductMedia(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        self::assertIsString($png);
        $http = new PublicHttpClient(
            static fn(string $url): array => ['status' => 200, 'headers' => [], 'body' => $png],
            static fn(int $microseconds): null => null,
            0,
        );
        $assets = $this->createMock(FileAssetLibraryInterface::class);
        $assets->expects(self::once())
            ->method('describe')
            ->willReturn(['asset_id' => null, 'asset_selectable' => false]);
        $assets->expects(self::once())
            ->method('upload')
            ->with(
                self::equalTo(StorageDiskCode::BUILTIN_LOCAL_MEDIA),
                self::matchesRegularExpression('#^catalog/hanfu/1688/qiyige/604560496347/01-[a-f0-9]{12}\.png$#'),
                self::callback(static function (mixed $stream) use ($png): bool {
                    return is_resource($stream) && stream_get_contents($stream) === $png;
                }),
                self::matchesRegularExpression('/\.png$/'),
                'image/png',
                'zh_Hans_CN',
                self::anything(),
                self::callback(static fn(array $metadata): bool => ($metadata['translation_state'] ?? '') === 'reviewed'
                    && ($metadata['translation_origin'] ?? '') === 'manual'
                    && trim((string)($metadata['display_name'] ?? '')) !== ''
                    && trim((string)($metadata['default_alt'] ?? '')) !== ''
                    && trim((string)($metadata['description'] ?? '')) !== ''),
                FileAssetLibraryInterface::VISIBILITY_PUBLIC,
                self::callback(static fn(array $metadata): bool => ($metadata['source_platform'] ?? '') === '1688'),
                1,
                1,
            )
            ->willReturn([
                'asset_id' => '123e4567-e89b-42d3-a456-426614174000',
                'asset_selectable' => true,
                'mime' => 'image/png',
                'object_key' => 'catalog/hanfu/1688/qiyige/604560496347/image.png',
            ]);

        $result = (new MediaImporter($http, $assets))->import(
            'qiyige',
            '604560496347',
            '儿童汉服',
            ['https://cbu01.alicdn.com/product.png'],
            [[
                'combination' => ['color' => 'red', 'size' => 'm'],
                'image_url' => 'https://cbu01.alicdn.com/product.png',
            ]],
        );

        self::assertSame([
            [
                'asset_id' => '123e4567-e89b-42d3-a456-426614174000',
                'role' => 'main',
                'position' => 0,
            ],
            [
                'asset_id' => '123e4567-e89b-42d3-a456-426614174000',
                'role' => 'variant',
                'position' => 0,
                'combination' => ['color' => 'red', 'size' => 'm'],
            ],
        ], $result['assignments']);
        self::assertCount(1, $result['assets']);
    }

    public function testDetailImageIsLocalizedWithoutEnteringProductGallery(): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($png);
        $http = new PublicHttpClient(
            static fn(string $url, array $headers): array => [
                'status' => 200,
                'headers' => [],
                'body' => str_contains($url, 'detail') ? $png . "\0" : $png,
            ],
            static fn(int $microseconds): null => null,
            0,
        );

        $keys = [];
        $sequence = 0;
        $assets = $this->createMock(FileAssetLibraryInterface::class);
        $assets->method('describe')->willReturn([]);
        $assets->expects(self::exactly(2))
            ->method('upload')
            ->willReturnCallback(static function (...$arguments) use (&$keys, &$sequence): array {
                $keys[] = (string)($arguments[1] ?? '');
                ++$sequence;
                return [
                    'asset_id' => sprintf('00000000-0000-4000-8000-%012d', $sequence),
                    'asset_selectable' => true,
                ];
            });

        $result = (new MediaImporter($http, $assets))->import(
            'qiyige',
            '123456',
            '明制汉服',
            ['https://cbu01.alicdn.com/gallery.png'],
            [],
            ['https://cbu01.alicdn.com/detail.png'],
        );

        self::assertCount(1, $result['assignments']);
        self::assertSame('main', $result['assignments'][0]['role']);
        self::assertArrayHasKey('https://cbu01.alicdn.com/detail.png', $result['asset_ids_by_url']);
        self::assertTrue((bool)array_filter(
            $keys,
            static fn(string $key): bool => str_contains($key, '/detail-'),
        ));
    }

    public function testSkipsUnavailableDetailDownloadAndReturnsAudit(): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($png);
        $http = new PublicHttpClient(
            static fn(string $url): array => str_contains($url, 'broken-detail')
                ? ['status' => 404, 'headers' => [], 'body' => 'not found']
                : ['status' => 200, 'headers' => [], 'body' => str_contains($url, 'good-detail') ? $png . "\0" : $png],
            static fn(int $microseconds): null => null,
            0,
        );
        $sequence = 0;
        $assets = $this->createMock(FileAssetLibraryInterface::class);
        $assets->method('describe')->willReturn([]);
        $assets->expects(self::exactly(2))->method('upload')
            ->willReturnCallback(static function (...$arguments) use (&$sequence): array {
                ++$sequence;
                return ['asset_id' => sprintf('00000000-0000-4000-8000-%012d', $sequence), 'asset_selectable' => true];
            });
        $result = (new MediaImporter($http, $assets))->import(
            'qiyige', '604560496347', '汉服套装',
            ['https://cbu01.alicdn.com/catalog.png'], [],
            ['https://cbu01.alicdn.com/good-detail.png', 'https://cbu01.alicdn.com/broken-detail.jpg'],
        );
        self::assertCount(1, $result['assignments']);
        self::assertArrayHasKey('https://cbu01.alicdn.com/good-detail.png', $result['asset_ids_by_url']);
        self::assertSame([[
            'url' => 'https://cbu01.alicdn.com/broken-detail.jpg',
            'error_code' => 'hanfu_1688_detail_media_download_failed',
            'message' => 'hanfu_1688_http_status_404',
        ]], $result['skipped_detail_media']);
    }

    public function testCatalogDownloadFailureRemainsFatal(): void
    {
        $http = new PublicHttpClient(
            static fn(string $url): array => ['status' => 404, 'headers' => [], 'body' => 'not found'],
            static fn(int $microseconds): null => null,
            0,
        );
        $this->expectExceptionMessage('hanfu_1688_http_status_404');
        (new MediaImporter($http, $this->createMock(FileAssetLibraryInterface::class)))->import(
            'qiyige', '604560496347', '汉服套装', ['https://cbu01.alicdn.com/catalog.jpg'],
        );
    }

    public function testRejectsNonImageResponse(): void
    {
        $http = new PublicHttpClient(
            static fn(string $url): array => ['status' => 200, 'headers' => [], 'body' => '<html>blocked</html>'],
            static fn(int $microseconds): null => null,
            0,
        );

        $this->expectExceptionMessage('hanfu_1688_media_image_invalid');
        (new MediaImporter($http, $this->createMock(FileAssetLibraryInterface::class)))->import(
            'qiyige',
            '1',
            '汉服',
            ['https://cbu01.alicdn.com/not-image.jpg'],
        );
    }
}
