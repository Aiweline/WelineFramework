<?php

declare(strict_types=1);

namespace Weline\Meta\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Meta\Controller\Backend\Config\File as ConfigFileController;
use Weline\Meta\Controller\Backend\Meta as MetaController;
use Weline\Meta\Controller\Backend\Taglib\Meta as TaglibMetaController;
use Weline\Meta\Extends\Module\Weline_Framework\Query\MetaAdminQueryProvider;

/**
 * Nested Meta backend controllers must resolve through adminRequest path parsing.
 */
final class MetaAdminQueryProviderNestedPathContractTest extends TestCase
{
    /**
     * @dataProvider nestedPathProvider
     */
    public function testResolveControllerActionSupportsNestedBackendPaths(
        string $path,
        string $expectedClass,
        string $expectedAction
    ): void {
        $provider = new MetaAdminQueryProvider();
        $method = new ReflectionMethod(MetaAdminQueryProvider::class, 'resolveControllerAction');
        $method->setAccessible(true);

        $resolved = $method->invoke($provider, $path);

        self::assertIsArray($resolved);
        self::assertSame($expectedClass, $resolved[0]);
        self::assertSame($expectedAction, $resolved[1]);
    }

    /**
     * @return array<string, array{0:string,1:string,2:string}>
     */
    public static function nestedPathProvider(): array
    {
        return [
            'config_file_tree' => [
                '/meta/backend/config/file/tree',
                ConfigFileController::class,
                'tree',
            ],
            'config_file_fileMeta' => [
                '/meta/backend/config/file/fileMeta',
                ConfigFileController::class,
                'fileMeta',
            ],
            'config_file_index' => [
                '/meta/backend/config/file',
                ConfigFileController::class,
                'index',
            ],
            'taglib_meta_get' => [
                '/meta/backend/taglib/meta/get',
                TaglibMetaController::class,
                'get',
            ],
            'flat_meta_index' => [
                '/meta/backend/meta',
                MetaController::class,
                'index',
            ],
        ];
    }

    public function testAdminRequestRejectsUnknownNestedPath(): void
    {
        $provider = new MetaAdminQueryProvider();
        $unknown = $provider->execute('adminRequest', [
            'url' => '/meta/backend/not-a-real/controller/path',
            'method' => 'GET',
        ]);
        self::assertIsArray($unknown);
        self::assertFalse($unknown['success'] ?? true);
        self::assertStringContainsString('Unsupported admin path', (string)($unknown['message'] ?? ''));
    }
}
