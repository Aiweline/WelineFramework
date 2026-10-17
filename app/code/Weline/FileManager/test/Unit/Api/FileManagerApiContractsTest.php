<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\Api;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use ReflectionMethod as ReflectionMethod;
use Weline\FileManager\Api\Block\FileManager;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllFileManagerApiContractsPass().
 */
final class FileManagerApiContractsTest extends TestCase
{
    public function testAllFileManagerApiContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'FileManagerMediaConstraintHint::testBlockResolvesColonAspectRatioAndRecommendSizeHints' => function () {
            $this->assert_FileManagerMediaConstraintHint_testBlockResolvesColonAspectRatioAndRecommendSizeHints();
        },
            'FileManagerMediaConstraintHint::testBlockDerivesAspectRatioFromRecommendDimensions' => function () {
            $this->assert_FileManagerMediaConstraintHint_testBlockDerivesAspectRatioFromRecommendDimensions();
        },
        ];
    }

private function assert_FileManagerMediaConstraintHint_testBlockResolvesColonAspectRatioAndRecommendSizeHints(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/FileManager' . '/Api/Block/FileManager.php');
    self::assertStringContainsString('assignMediaConstraintHints()', $source);
    self::assertStringContainsString('resolveAspectRatioDisplay()', $source);
    self::assertStringContainsString("__('推荐比例：%{1}'", $source);
    self::assertStringContainsString("__('建议尺寸：%{1} × %{2} px'", $source);
    $block = $this->newFileManagerStub_FileManagerMediaConstraintHint(['aspect_ratio' => '16/5', 'recommend_width' => '1200', 'recommend_height' => '375']);
    $ratio = $this->invokeProtected_FileManagerMediaConstraintHint($block, 'resolveAspectRatioDisplay');
    self::assertSame('16:5', $ratio);
    $this->invokeProtected_FileManagerMediaConstraintHint($block, 'assignMediaConstraintHints');
    self::assertSame('16:5', $block->getData('aspect_ratio_display'));
    self::assertStringContainsString('16:5', (string) $block->getData('aspect_ratio_hint'));
    self::assertStringContainsString('1200', (string) $block->getData('recommend_size_display'));
    self::assertStringContainsString('375', (string) $block->getData('recommend_size_display'));
}

private function assert_FileManagerMediaConstraintHint_testBlockDerivesAspectRatioFromRecommendDimensions(): void
{
    $block = $this->newFileManagerStub_FileManagerMediaConstraintHint(['recommend_width' => '1920', 'recommend_height' => '600']);
    self::assertSame('16:5', $this->invokeProtected_FileManagerMediaConstraintHint($block, 'resolveAspectRatioDisplay'));
}

/**
 * @param array<string, mixed> $data
 */
private function newFileManagerStub_FileManagerMediaConstraintHint(array $data): FileManager
{
    $ref = new ReflectionClass(FileManager::class);
    /** @var FileManager $block */
    $block = $ref->newInstanceWithoutConstructor();
    foreach ($data as $key => $value) {
        $block->setData($key, $value);
    }
    return $block;
}

private function invokeProtected_FileManagerMediaConstraintHint(object $target, string $method): mixed
{
    $ref = new ReflectionMethod($target, $method);
    $ref->setAccessible(true);
    return $ref->invoke($target);
}
}
