<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\MarketplaceMeta;

use PHPUnit\Framework\TestCase;
use Weline\Framework\MarketplaceMeta\MarketplaceMetaReader;

final class MarketplaceMetaReaderTest extends TestCase
{
    public function testStrictPackageMetaRequiresSourceLocaleAndTagLabel(): void
    {
        $root = $this->makePackageDir();
        $moduleDir = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Acme' . DIRECTORY_SEPARATOR . 'Demo';
        $this->writeMeta($moduleDir, [
            'schema_version' => 1,
            'module_name' => 'Acme_Demo',
            'i18n' => [
                'source_locale' => 'zh_Hans_CN',
                'locales' => [
                    'zh_Hans_CN' => [
                        'display_name' => '演示模块',
                    ],
                ],
            ],
            'tags' => [
                [
                    'code' => 'surface.backend',
                    'label' => [
                        'zh_Hans_CN' => '后台应用',
                    ],
                ],
            ],
        ]);

        try {
            $result = (new MarketplaceMetaReader())->readFromPackageDir($root, $moduleDir, [], 'Acme_Demo', true);

            self::assertSame('Acme_Demo', $result['meta']['module_name'] ?? '');
            self::assertSame('后台应用', $result['meta']['tags'][0]['labels']['zh_Hans_CN'] ?? '');
            self::assertArrayNotHasKey('label', $result['meta']['tags'][0]);
            self::assertTrue((bool)($result['meta']['tags'][0]['primary'] ?? false));
            self::assertContains('meta_primary_defaulted', $result['warnings']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testStrictPackageMetaRejectsMissingMeta(): void
    {
        $root = $this->makePackageDir();
        $moduleDir = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Acme' . DIRECTORY_SEPARATOR . 'Demo';

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('marketplace_meta_missing_file');
            (new MarketplaceMetaReader())->readFromPackageDir($root, $moduleDir, [], 'Acme_Demo', true);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testStrictPackageMetaRejectsMissingSourceTagLabel(): void
    {
        $root = $this->makePackageDir();
        $moduleDir = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Acme' . DIRECTORY_SEPARATOR . 'Demo';
        $this->writeMeta($moduleDir, [
            'schema_version' => 1,
            'module_name' => 'Acme_Demo',
            'i18n' => [
                'source_locale' => 'zh_Hans_CN',
                'locales' => [
                    'zh_Hans_CN' => [
                        'display_name' => '演示模块',
                    ],
                ],
            ],
            'tags' => [
                ['code' => 'surface.backend'],
            ],
        ]);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('meta_tag_missing_source_label');
            (new MarketplaceMetaReader())->readFromPackageDir($root, $moduleDir, [], 'Acme_Demo', true);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testNonStrictKeepsLegacyStringTagsCompatible(): void
    {
        $root = $this->makePackageDir();
        $moduleDir = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Acme' . DIRECTORY_SEPARATOR . 'Demo';
        $this->writeMeta($moduleDir, [
            'schema_version' => 1,
            'module_name' => 'Acme_Demo',
            'tags' => ['surface.backend'],
        ]);

        try {
            $result = (new MarketplaceMetaReader())->readFromPackageDir($root, $moduleDir, [], 'Acme_Demo');

            self::assertSame('surface.backend', $result['meta']['tags'][0]['code'] ?? '');
            self::assertSame([], $result['meta']['tags'][0]['labels'] ?? null);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testStrictPackageMetaAcceptsTypedColonTags(): void
    {
        $root = $this->makePackageDir();
        $moduleDir = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Acme' . DIRECTORY_SEPARATOR . 'WlsFileManager';
        $this->writeMeta($moduleDir, [
            'schema_version' => 1,
            'module_name' => 'Acme_WlsFileManager',
            'i18n' => [
                'source_locale' => 'zh_Hans_CN',
                'locales' => [
                    'zh_Hans_CN' => [
                        'display_name' => 'WLS File Manager',
                    ],
                ],
            ],
            'tags' => [
                [
                    'code' => 'module:wls',
                    'label' => [
                        'zh_Hans_CN' => 'WLS Panel',
                    ],
                ],
                [
                    'code' => 'custom:wls-file-manager',
                    'label' => [
                        'zh_Hans_CN' => 'WLS File Manager',
                    ],
                ],
                [
                    'code' => 'system:false',
                    'label' => [
                        'zh_Hans_CN' => 'Third-party Module',
                    ],
                ],
            ],
        ]);

        try {
            $result = (new MarketplaceMetaReader())->readFromPackageDir($root, $moduleDir, [], 'Acme_WlsFileManager', true);

            self::assertSame('module:wls', $result['meta']['tags'][0]['code'] ?? '');
            self::assertSame('module', $result['meta']['tags'][0]['type'] ?? '');
            self::assertSame('custom:wls-file-manager', $result['meta']['tags'][1]['code'] ?? '');
            self::assertSame('custom', $result['meta']['tags'][1]['type'] ?? '');
            self::assertSame('system:false', $result['meta']['tags'][2]['code'] ?? '');
            self::assertSame('system', $result['meta']['tags'][2]['type'] ?? '');
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testDeclaredHashMismatchStillBlocks(): void
    {
        $root = $this->makePackageDir();
        $path = $root . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'marketplace' . DIRECTORY_SEPARATOR . 'meta.json';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'module_name' => 'Acme_Demo',
            'tags' => ['surface.backend'],
        ], JSON_UNESCAPED_UNICODE));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('marketplace_meta_hash_mismatch');
            (new MarketplaceMetaReader())->readFromPackageDir(
                $root,
                $root,
                ['marketplace_meta' => ['path' => 'etc/marketplace/meta.json', 'sha256' => str_repeat('0', 64)]],
                'Acme_Demo'
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function writeMeta(string $moduleDir, array $meta): void
    {
        $metaDir = $moduleDir . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'marketplace';
        if (!is_dir($metaDir)) {
            mkdir($metaDir, 0777, true);
        }
        file_put_contents($metaDir . DIRECTORY_SEPARATOR . 'meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE));
    }

    private function makePackageDir(): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wmp-meta-test-' . uniqid('', true);
        mkdir($root, 0777, true);
        return $root;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
