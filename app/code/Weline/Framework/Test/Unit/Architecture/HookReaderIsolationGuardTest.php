<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;

final class HookReaderIsolationGuardTest extends TestCase
{
    /**
     * HookReader carries mutable `path` state, so render-time code must not
     * share it as a singleton across concurrent WLS fibers.
     */
    private const GUARDED_FILES = [
        'app/code/Weline/Framework/View/Template.php',
        'app/code/Weline/Framework/View/Taglib.php',
        'app/code/Weline/Framework/Hook/Hooker.php',
        'app/code/Weline/Hook/Service/HookDataService.php',
    ];

    public function testRenderPathDoesNotUseSharedHookReaderSingleton(): void
    {
        $root = \dirname(__DIR__, 7);
        $violations = [];

        foreach (self::GUARDED_FILES as $relativePath) {
            $path = $root . '/' . $relativePath;
            $contents = \file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            if (\str_contains($contents, 'getInstance(HookReader::class)')
                || \str_contains($contents, 'getInstance(\\Weline\\Framework\\Hook\\Config\\HookReader::class)')
            ) {
                $violations[] = $relativePath;
            }
        }

        self::assertSame([], $violations, "HookReader must not be shared as a singleton in render/request paths.\n" . \implode("\n", $violations));
    }

    public function testMergeRegistryWithFilesystemSupplementsMissingModules(): void
    {
        $registry = [
            'Weline_B2B' => [
                'file' => 'header-account-links.phtml',
                'priority' => 45,
                'sort_order' => 8,
                'solo' => false,
            ],
        ];
        $filesystem = [
            'Weline_B2B' => [
                'file' => 'header-account-links.phtml',
                'priority' => 99,
                'sort_order' => 0,
                'solo' => false,
            ],
            'Weline_Customer' => [
                'file' => 'header-account-links.phtml',
                'priority' => 50,
                'sort_order' => 0,
                'solo' => false,
            ],
        ];

        $merged = \Weline\Framework\Hook\Config\HookReader::mergeRegistryWithFilesystem($registry, $filesystem);

        self::assertSame(['Weline_B2B', 'Weline_Customer'], array_keys($merged));
        self::assertSame(45, $merged['Weline_B2B']['priority'], 'Registry priority must win on overlap');
        self::assertSame(50, $merged['Weline_Customer']['priority']);
    }

    public function testGetFileListMergesFilesystemWhenRegistryNonEmpty(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Hook/Config/HookReader.php');
        self::assertStringContainsString('mergeRegistryWithFilesystem', $source);
        self::assertMatchesRegularExpression(
            '/getHookFilesFromRegistry\([^\)]*\);\s*if\s*\(!empty\(\$data\)\)\s*\{\s*\$data\s*=\s*self::mergeRegistryWithFilesystem\(/s',
            $source
        );
    }

    public function testGetFileListWithMetaMergesFilesystemWhenRegistryNonEmpty(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Hook/Config/HookReader.php');
        self::assertMatchesRegularExpression(
            '/function getFileListWithMeta\(\): array\s*\{.*?getHookFilesFromRegistry\([^\)]*\);\s*if\s*\(\$data\s*===\s*\[\]\)\s*\{.*?\} else \{\s*\$data\s*=\s*self::mergeRegistryWithFilesystem\(/s',
            $source
        );
    }
}
