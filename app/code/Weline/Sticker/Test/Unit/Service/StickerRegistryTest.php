<?php

declare(strict_types=1);

namespace Weline\Sticker\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Sticker\Service\StickerRegistry;

class StickerRegistryTest extends TestCase
{
    private StickerRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = ObjectManager::getInstance(StickerRegistry::class);
        $this->registry->clearCache();
    }

    public function testSaveAndGetRegistry(): void
    {
        $testData = [
            'Weline_Test' => [
                'test/file.phtml' => [
                    [
                        'source_module' => 'Weline_Sticker',
                        'sticker_file' => '/path/to/sticker.phtml',
                        'actions' => [],
                    ],
                ],
            ],
        ];

        $this->assertTrue($this->registry->saveRegistry($testData));

        $cachedData = $this->registry->getRegistry(false);
        $this->assertSame($testData, $cachedData);
    }

    public function testHasSticker(): void
    {
        $this->registry->saveRegistry([
            'Weline_Test' => [
                'test/file.phtml' => [
                    [
                        'source_module' => 'Weline_Sticker',
                        'sticker_file' => '/path/to/sticker.phtml',
                        'actions' => [],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($this->registry->hasSticker('Weline_Test', 'test/file.phtml'));
        $this->assertFalse($this->registry->hasSticker('Weline_Test', 'test/missing.phtml'));
    }

    public function testHasModuleStickers(): void
    {
        $this->registry->saveRegistry([
            'Weline_Test' => [
                'test/file.phtml' => [
                    [
                        'source_module' => 'Weline_Sticker',
                        'sticker_file' => '/path/to/sticker.phtml',
                        'actions' => [],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($this->registry->hasModuleStickers('Weline_Test'));
        $this->assertFalse($this->registry->hasModuleStickers('Weline_Nonexistent'));
    }

    public function testGetFileStickers(): void
    {
        $this->registry->saveRegistry([
            'Weline_Test' => [
                'test/file.phtml' => [
                    [
                        'source_module' => 'Weline_Sticker',
                        'sticker_file' => '/path/to/sticker.phtml',
                        'actions' => [['type' => 'replace']],
                    ],
                ],
            ],
        ]);

        $stickers = $this->registry->getFileStickers('Weline_Test', 'test/file.phtml');
        $this->assertCount(1, $stickers);
        $this->assertEquals('Weline_Sticker', $stickers[0]['source_module']);
    }

    public function testClearCache(): void
    {
        $this->registry->saveRegistry([
            'Weline_Test' => [
                'test/file.phtml' => [
                    [
                        'source_module' => 'Weline_Sticker',
                        'sticker_file' => '/path/to/sticker.phtml',
                        'actions' => [],
                    ],
                ],
            ],
        ]);

        $this->registry->clearCache();
        $registry = $this->registry->getRegistry();
        $this->assertIsArray($registry);
    }

    protected function tearDown(): void
    {
        $this->registry->clearCache();
        parent::tearDown();
    }
}
