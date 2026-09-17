<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\Api;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\FileManager\Api\Block\FileManager;

/**
 * media_options.aspect_ratio / recommend_* must surface as picker hint copy.
 */
final class FileManagerMediaConstraintHintContractTest extends TestCase
{
    public function testBlockResolvesColonAspectRatioAndRecommendSizeHints(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Api/Block/FileManager.php'
        );
        self::assertStringContainsString('assignMediaConstraintHints()', $source);
        self::assertStringContainsString('resolveAspectRatioDisplay()', $source);
        self::assertStringContainsString("__('推荐比例：%{1}'", $source);
        self::assertStringContainsString("__('建议尺寸：%{1} × %{2} px'", $source);

        $block = $this->newFileManagerStub([
            'aspect_ratio' => '16/5',
            'recommend_width' => '1200',
            'recommend_height' => '375',
        ]);
        $ratio = $this->invokeProtected($block, 'resolveAspectRatioDisplay');
        self::assertSame('16:5', $ratio);

        $this->invokeProtected($block, 'assignMediaConstraintHints');
        self::assertSame('16:5', $block->getData('aspect_ratio_display'));
        self::assertStringContainsString('16:5', (string)$block->getData('aspect_ratio_hint'));
        self::assertStringContainsString('1200', (string)$block->getData('recommend_size_display'));
        self::assertStringContainsString('375', (string)$block->getData('recommend_size_display'));
    }

    public function testBlockDerivesAspectRatioFromRecommendDimensions(): void
    {
        $block = $this->newFileManagerStub([
            'recommend_width' => '1920',
            'recommend_height' => '600',
        ]);
        self::assertSame('16:5', $this->invokeProtected($block, 'resolveAspectRatioDisplay'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function newFileManagerStub(array $data): FileManager
    {
        $ref = new ReflectionClass(FileManager::class);
        /** @var FileManager $block */
        $block = $ref->newInstanceWithoutConstructor();
        foreach ($data as $key => $value) {
            $block->setData($key, $value);
        }

        return $block;
    }

    private function invokeProtected(object $target, string $method): mixed
    {
        $ref = new ReflectionMethod($target, $method);
        $ref->setAccessible(true);

        return $ref->invoke($target);
    }
}
